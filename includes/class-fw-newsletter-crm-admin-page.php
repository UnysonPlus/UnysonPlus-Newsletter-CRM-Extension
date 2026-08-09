<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Unyson+ → Subscribers screen.
 *
 * House conventions, matched deliberately (see Post Types / Asset Optimizer):
 *  - add_submenu_page() under the shared `fw-extensions` parent, position declared
 *    via the `fw_unysonplus_admin_submenu_order` filter rather than hard-coded.
 *  - EVERY POST/GET action runs on `load-{$hook_suffix}`, before any output, so
 *    each one can PRG-redirect and the CSV export can stream a download and exit.
 *  - Native `nav-tab-wrapper` tabs — no hand-rolled pill UI.
 *  - Notices are handed across the redirect in a transient.
 *  - Settings are stored with fw_set_db_ext_settings_option() and rendered by
 *    fw()->backend->render_options(), because a settings form IS an options form —
 *    while the subscriber grid is not, so that part is a real WP_List_Table.
 */
class FW_Newsletter_CRM_Admin_Page {

	const PARENT_SLUG      = 'fw-extensions';
	const PAGE_SLUG        = 'fw-newsletter-crm';
	const NONCE            = 'fw_newsletter_crm_action';
	const TRANSIENT_NOTICE = 'fw_ext_newsletter_crm_notice_';
	const TRANSIENT_IMPORT = 'fw_ext_newsletter_crm_import_';

	/** @var FW_Extension_Newsletter_CRM */
	private $ext;

	/** @var string|null */
	private $hook_suffix = null;

	/** @var FW_Newsletter_CRM_List_Table|null */
	private $table = null;

	/**
	 * @param FW_Extension_Newsletter_CRM $ext
	 */
	public function __construct( $ext ) {
		$this->ext = $ext;

		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
		add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Menu + assets
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Newsletter / Subscriber CRM', 'fw' ), // page title
			__( 'Subscribers', 'fw' ),                 // menu label (short — the submenu is narrow)
			FW_Newsletter_CRM_Service::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_action_load' ) );
			add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );
		}
	}

	/**
	 * @internal
	 * We just declare where we want to sit; the Post Types extension owns the sort.
	 *
	 * @param array $order
	 *
	 * @return array
	 */
	public function _filter_submenu_order( $order ) {
		if ( in_array( self::PAGE_SLUG, (array) $order, true ) ) {
			return $order;
		}

		$order[] = self::PAGE_SLUG;

		return $order;
	}

	/**
	 * @internal
	 *
	 * @param string $hook
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		fw()->backend->enqueue_options_static( $this->ext->get_settings_options() );

		wp_enqueue_style(
			'fw-ext-newsletter-crm',
			$this->ext->get_uri() . '/static/css/admin.css',
			array(),
			$this->ext->manifest->get_version()
		);
	}

	/* ---------------------------------------------------------------------- *
	 * URLs
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $tab
	 *
	 * @return string
	 */
	public static function get_page_url( $tab = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' !== $tab ? add_query_arg( 'tab', $tab, $url ) : $url;
	}

	/**
	 * A nonced single-row action link.
	 *
	 * @param string $action
	 * @param int    $id
	 *
	 * @return string
	 */
	public static function action_url( $action, $id ) {
		return wp_nonce_url(
			add_query_arg( array( 'fw_crm_action' => $action, 'id' => (int) $id ), self::get_page_url() ),
			self::NONCE
		);
	}

	/**
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'subscribers'; // phpcs:ignore WordPress.Security.NonceVerification

		return in_array( $tab, array( 'subscribers', 'tools', 'settings' ), true ) ? $tab : 'subscribers';
	}

	/* ---------------------------------------------------------------------- *
	 * Request handling — all of it before any output
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function _action_load() {
		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			return;
		}

		// The list table must exist before the screen renders so its per-page
		// option and column headers are registered.
		$this->table = new FW_Newsletter_CRM_List_Table();

		add_screen_option( 'per_page', array(
			'label'   => __( 'Subscribers per page', 'fw' ),
			'default' => 20,
			'option'  => 'fw_crm_subscribers_per_page',
		) );

		// Our own actions carry `fw_crm_action` (POST wins over GET); a bulk action
		// instead arrives through the list table's own fields, and is nonced by
		// WP_List_Table under a different name — so resolve which it is FIRST and
		// then verify the matching nonce. Never one check standing in for both.
		$action = '';
		$bulk   = $this->table->current_action();

		if ( isset( $_POST['fw_crm_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['fw_crm_action'] ) );
		} elseif ( isset( $_GET['fw_crm_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_GET['fw_crm_action'] ) );
		}

		if ( '' !== $action ) {
			check_admin_referer( self::NONCE );
		} elseif ( $bulk ) {
			check_admin_referer( 'bulk-subscribers' );
			$action = 'bulk_' . $bulk;
		} else {
			return;
		}

		switch ( $action ) {
			case 'export':
				// Streams and exits — hence the `load-` hook.
				FW_Newsletter_CRM_CSV::export( FW_Newsletter_CRM_List_Table::request_query_args() );
				return;

			case 'add':
				$this->handle_add();
				break;

			case 'unsubscribe':
				$this->handle_single( 'unsubscribe' );
				break;

			case 'delete':
				$this->handle_single( 'delete' );
				break;

			case 'resend':
				$this->handle_single( 'resend' );
				break;

			case 'import_upload':
				$this->handle_import_upload();
				break;

			case 'import_run':
				$this->handle_import_run();
				break;

			case 'save_settings':
				$this->handle_save_settings();
				break;

			case 'remove_data':
				$this->handle_remove_data();
				break;

			default:
				if ( 0 === strpos( $action, 'bulk_' ) ) {
					$this->handle_bulk( substr( $action, 5 ) );
				}
				break;
		}

		$this->redirect();
	}

	/**
	 * PRG back to the current tab, preserving the active filters.
	 *
	 * @param array $args
	 */
	private function redirect( array $args = array() ) {
		$url = self::get_page_url( $this->current_tab() );

		foreach ( array( 'status', 'list', 's' ) as $key ) {
			if ( ! empty( $_REQUEST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$url = add_query_arg( $key, sanitize_text_field( wp_unslash( $_REQUEST[ $key ] ) ), $url );
			}
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	/**
	 * @param string $type success|error|warning
	 * @param string $message
	 */
	private function notice( $type, $message ) {
		set_transient( self::TRANSIENT_NOTICE . get_current_user_id(), array(
			'type'    => $type,
			'message' => $message,
		), MINUTE_IN_SECONDS );
	}

	/* ---------------------------------------------------------------------- *
	 * Actions
	 * ---------------------------------------------------------------------- */

	private function handle_add() {
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$list  = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
		$tags  = isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '';

		$result = FW_Newsletter_CRM_Service::subscribe( $email, array(
			'name'         => $name,
			'list'         => $list,
			'tags'         => array_filter( array_map( 'trim', explode( ',', $tags ) ) ),
			'source'       => 'manual',
			'double_optin' => false, // An admin adding someone by hand means it.
		) );

		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );

			return;
		}

		$this->notice( 'success', sprintf(
			/* translators: %s: email address */
			__( '%s added.', 'fw' ),
			$result->email
		) );
	}

	/**
	 * @param string $what unsubscribe|delete
	 */
	private function handle_single( $what ) {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $id ) {
			return;
		}

		if ( 'delete' === $what ) {
			FW_Newsletter_CRM_Service::delete( $id );
			$this->notice( 'success', __( 'Subscriber deleted.', 'fw' ) );

			return;
		}

		if ( 'resend' === $what ) {
			$result = FW_Newsletter_CRM_Service::resend_confirmation( $id );

			$this->notice(
				is_wp_error( $result ) ? 'error' : 'success',
				is_wp_error( $result ) ? $result->get_error_message() : __( 'A fresh confirmation link was emailed.', 'fw' )
			);

			return;
		}

		$result = FW_Newsletter_CRM_Service::unsubscribe( $id );

		$this->notice(
			is_wp_error( $result ) ? 'error' : 'success',
			is_wp_error( $result ) ? $result->get_error_message() : __( 'Subscriber unsubscribed.', 'fw' )
		);
	}

	/**
	 * @param string $what unsubscribe|subscribe|delete
	 */
	private function handle_bulk( $what ) {
		$ids = isset( $_REQUEST['subscribers'] ) ? array_map( 'intval', (array) $_REQUEST['subscribers'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$ids = array_filter( $ids );

		if ( ! $ids ) {
			$this->notice( 'warning', __( 'No subscribers selected.', 'fw' ) );

			return;
		}

		$done = 0;

		foreach ( $ids as $id ) {
			switch ( $what ) {
				case 'delete':
					$done += FW_Newsletter_CRM_Service::delete( $id ) ? 1 : 0;
					break;

				case 'subscribe':
					$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

					if ( $subscriber ) {
						$result = FW_Newsletter_CRM_Service::subscribe( $subscriber->email, array(
							'status'       => 'subscribed',
							'source'       => $subscriber->source,
							'double_optin' => false,
						) );
						$done  += is_wp_error( $result ) ? 0 : 1;
					}
					break;

				case 'unsubscribe':
				default:
					$done += is_wp_error( FW_Newsletter_CRM_Service::unsubscribe( $id ) ) ? 0 : 1;
					break;
			}
		}

		$this->notice( 'success', sprintf(
			/* translators: %s: number of subscribers */
			_n( '%s subscriber updated.', '%s subscribers updated.', $done, 'fw' ),
			number_format_i18n( $done )
		) );
	}

	/**
	 * Step 1 of import: take the upload, park it, and move to column mapping.
	 */
	private function handle_import_upload() {
		if ( empty( $_FILES['fw_crm_csv']['tmp_name'] ) ) {
			$this->notice( 'error', __( 'Choose a CSV file to import.', 'fw' ) );

			return;
		}

		$file = $_FILES['fw_crm_csv']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( ! empty( $file['error'] ) ) {
			$this->notice( 'error', __( 'The upload failed. The file may be larger than this server allows.', 'fw' ) );

			return;
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : 'import.csv';

		if ( 'csv' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$this->notice( 'error', __( 'That is not a .csv file.', 'fw' ) );

			return;
		}

		// Park it under the shared uploads parent (uploads/unysonplus/<subdir>),
		// never a loose sibling folder — see the workspace uploads rule.
		$dir = function_exists( 'fw_upw_uploads_dir' )
			? fw_upw_uploads_dir( 'newsletter-crm' )
			: array( 'path' => get_temp_dir() );

		if ( ! file_exists( $dir['path'] ) ) {
			wp_mkdir_p( $dir['path'] );
		}

		$target = trailingslashit( $dir['path'] ) . 'import-' . get_current_user_id() . '-' . wp_generate_password( 8, false, false ) . '.csv';

		if ( ! @move_uploaded_file( $file['tmp_name'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->notice( 'error', __( 'Could not store the uploaded file.', 'fw' ) );

			return;
		}

		$peek = FW_Newsletter_CRM_CSV::peek( $target );

		if ( is_wp_error( $peek ) ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->notice( 'error', $peek->get_error_message() );

			return;
		}

		set_transient( self::TRANSIENT_IMPORT . get_current_user_id(), array(
			'file'   => $target,
			'header' => $peek['header'],
			'rows'   => $peek['rows'],
		), HOUR_IN_SECONDS );

		$this->redirect( array( 'tab' => 'tools', 'step' => 'map' ) );
	}

	/**
	 * Step 2 of import: apply the mapping.
	 */
	private function handle_import_run() {
		$parked = get_transient( self::TRANSIENT_IMPORT . get_current_user_id() );

		if ( ! $parked || empty( $parked['file'] ) ) {
			$this->notice( 'error', __( 'That import expired. Please upload the file again.', 'fw' ) );

			return;
		}

		$mapping = array();

		if ( isset( $_POST['map'] ) && is_array( $_POST['map'] ) ) {
			foreach ( wp_unslash( $_POST['map'] ) as $index => $field ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$field = sanitize_key( $field );

				if ( '' !== $field ) {
					$mapping[ (int) $index ] = $field;
				}
			}
		}

		if ( ! in_array( 'email', $mapping, true ) ) {
			$this->notice( 'error', __( 'One column must be mapped to Email.', 'fw' ) );

			return;
		}

		$list = isset( $_POST['import_list'] ) ? sanitize_key( wp_unslash( $_POST['import_list'] ) ) : '';

		$stats = FW_Newsletter_CRM_CSV::import( $parked['file'], $mapping, array(
			'lists'                  => '' !== $list ? array( $list ) : array(),
			'overwrite_unsubscribed' => ! empty( $_POST['overwrite_unsubscribed'] ),
			'source'                 => 'import',
		) );

		@unlink( $parked['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		delete_transient( self::TRANSIENT_IMPORT . get_current_user_id() );

		if ( is_wp_error( $stats ) ) {
			$this->notice( 'error', $stats->get_error_message() );

			return;
		}

		$message = sprintf(
			/* translators: 1: created, 2: updated, 3: skipped, 4: failed */
			__( 'Import finished — %1$s added, %2$s updated, %3$s skipped, %4$s failed.', 'fw' ),
			number_format_i18n( $stats['created'] ),
			number_format_i18n( $stats['updated'] ),
			number_format_i18n( $stats['skipped'] ),
			number_format_i18n( $stats['failed'] )
		);

		if ( ! empty( $stats['errors'] ) ) {
			$message .= '<br>' . esc_html( implode( ' · ', $stats['errors'] ) );
		}

		$this->notice( $stats['failed'] ? 'warning' : 'success', $message );
	}

	private function handle_save_settings() {
		$before = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		$values = array_merge( $before, fw_get_options_values_from_input( $this->ext->get_settings_options() ) );

		fw_set_db_ext_settings_option( $this->ext->get_name(), null, $values );

		$this->notice( 'success', __( 'Settings saved.', 'fw' ) );
	}

	/**
	 * The explicit, opt-in data removal. Never happens on deactivation.
	 */
	private function handle_remove_data() {
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';

		if ( 'DELETE' !== strtoupper( $confirm ) ) {
			$this->notice( 'error', __( 'Type DELETE to confirm removing all subscriber data.', 'fw' ) );

			return;
		}

		FW_Newsletter_CRM_Installer::uninstall();

		$this->notice( 'success', __( 'All subscriber data was removed. The tables will be recreated empty on the next page load.', 'fw' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	public function render_page() {
		if ( ! current_user_can( FW_Newsletter_CRM_Service::capability() ) ) {
			return;
		}

		$tab = $this->current_tab();

		$tabs = array(
			'subscribers' => __( 'Subscribers', 'fw' ),
			'tools'       => __( 'Import / Export', 'fw' ),
			'settings'    => __( 'Settings', 'fw' ),
		);
		?>
		<div class="wrap fw-ext-newsletter-crm">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'fw' ); ?></h1>

			<?php $this->render_notice(); ?>

			<h2 class="nav-tab-wrapper" style="margin:.6em 0 1.4em">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( self::get_page_url( $slug ) ); ?>"
					   class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'settings' === $tab ) {
				$this->render_settings_tab();
			} elseif ( 'tools' === $tab ) {
				$this->render_tools_tab();
			} else {
				$this->render_subscribers_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_notice() {
		$key    = self::TRANSIENT_NOTICE . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			wp_kses_post( $notice['message'] )
		);
	}

	private function render_subscribers_tab() {
		$single = isset( $_GET['subscriber'] ) ? (int) $_GET['subscriber'] : 0; // phpcs:ignore WordPress.Security.NonceVerification

		if ( $single ) {
			$this->render_single( $single );

			return;
		}

		if ( ! $this->table ) {
			$this->table = new FW_Newsletter_CRM_List_Table();
		}

		$this->table->prepare_items();

		$export_url = wp_nonce_url(
			add_query_arg(
				array_merge(
					array_filter( FW_Newsletter_CRM_List_Table::request_query_args() ),
					array( 'fw_crm_action' => 'export' )
				),
				self::get_page_url()
			),
			self::NONCE
		);
		?>
		<p class="fw-crm-toolbar">
			<a href="<?php echo esc_url( $export_url ); ?>" class="button"><?php esc_html_e( 'Export these results (CSV)', 'fw' ); ?></a>
			<a href="<?php echo esc_url( self::get_page_url( 'tools' ) ); ?>" class="button"><?php esc_html_e( 'Import CSV', 'fw' ); ?></a>
			<button type="button" class="button button-primary" id="fw-crm-add-toggle"><?php esc_html_e( 'Add subscriber', 'fw' ); ?></button>
		</p>

		<div class="fw-crm-add" id="fw-crm-add" hidden>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="add" />
				<p>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'Email', 'fw' ); ?></span>
						<input type="email" name="email" required class="regular-text" />
					</label>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'Name', 'fw' ); ?></span>
						<input type="text" name="name" class="regular-text" />
					</label>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'List', 'fw' ); ?></span>
						<?php $this->render_list_select( 'list' ); ?>
					</label>
					<label class="fw-crm-field">
						<span><?php esc_html_e( 'Tags', 'fw' ); ?></span>
						<input type="text" name="tags" class="regular-text" placeholder="<?php esc_attr_e( 'comma, separated', 'fw' ); ?>" />
					</label>
				</p>
				<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Add subscriber', 'fw' ); ?></button></p>
			</form>
		</div>

		<form method="get" action="">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<?php
			$this->table->views();
			$this->table->search_box( __( 'Search subscribers', 'fw' ), 'fw-crm-search' );
			?>
		</form>

		<form method="post" action="">
			<?php wp_nonce_field( 'bulk-subscribers' ); ?>
			<?php $this->table->display(); ?>
		</form>

		<script>
		( function () {
			var btn = document.getElementById( 'fw-crm-add-toggle' );
			var box = document.getElementById( 'fw-crm-add' );
			if ( ! btn || ! box ) { return; }
			btn.addEventListener( 'click', function () {
				box.hidden = ! box.hidden;
				if ( ! box.hidden ) { var f = box.querySelector( 'input' ); if ( f ) { f.focus(); } }
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * The single-subscriber view — the seed of the future contact profile.
	 *
	 * @param int $id
	 */
	private function render_single( $id ) {
		$subscriber = FW_Newsletter_CRM_Subscribers::find( $id );

		if ( ! $subscriber ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Subscriber not found.', 'fw' ) . '</p></div>';

			return;
		}

		$lists = wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $id, 'list' ), 'title' );
		$tags  = wp_list_pluck( FW_Newsletter_CRM_Subscribers::get_lists( $id, 'tag' ), 'title' );
		$meta  = $subscriber->meta ? json_decode( $subscriber->meta, true ) : array();

		$rows = array(
			__( 'Email', 'fw' )        => $subscriber->email,
			__( 'Name', 'fw' )         => trim( $subscriber->first_name . ' ' . $subscriber->last_name ),
			__( 'Status', 'fw' )       => $subscriber->status,
			__( 'Lists', 'fw' )        => implode( ', ', $lists ),
			__( 'Tags', 'fw' )         => implode( ', ', $tags ),
			__( 'Source', 'fw' )       => $subscriber->source,
			__( 'Signed up on', 'fw' ) => $subscriber->source_url,
			__( 'IP', 'fw' )           => $subscriber->ip,
			__( 'Created', 'fw' )      => $subscriber->created_at,
			__( 'Confirmed', 'fw' )    => $subscriber->confirmed_at,
			__( 'Unsubscribed', 'fw' ) => $subscriber->unsubscribed_at,
		);

		if ( is_array( $meta ) && ! empty( $meta['consent_text'] ) ) {
			$rows[ __( 'Consent text', 'fw' ) ] = $meta['consent_text'];
		}
		?>
		<p><a href="<?php echo esc_url( self::get_page_url() ); ?>">&larr; <?php esc_html_e( 'Back to all subscribers', 'fw' ); ?></a></p>

		<table class="widefat striped fw-crm-single">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row" style="width:180px"><?php echo esc_html( $label ); ?></th>
					<td><?php echo '' !== (string) $value ? esc_html( $value ) : '<span aria-hidden="true">—</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:1em">
			<?php if ( 'pending' === $subscriber->status ) : ?>
				<a class="button" href="<?php echo esc_url( self::action_url( 'resend', $subscriber->id ) ); ?>"><?php esc_html_e( 'Resend confirmation', 'fw' ); ?></a>
			<?php endif; ?>
			<?php if ( 'unsubscribed' !== $subscriber->status ) : ?>
				<a class="button" href="<?php echo esc_url( self::action_url( 'unsubscribe', $subscriber->id ) ); ?>"><?php esc_html_e( 'Unsubscribe', 'fw' ); ?></a>
			<?php endif; ?>
			<a class="button button-link-delete" href="<?php echo esc_url( self::action_url( 'delete', $subscriber->id ) ); ?>"
			   onclick="return confirm('<?php echo esc_js( __( 'Delete this subscriber permanently?', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete', 'fw' ); ?></a>
		</p>
		<?php
	}

	private function render_tools_tab() {
		$step   = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$parked = get_transient( self::TRANSIENT_IMPORT . get_current_user_id() );

		if ( 'map' === $step && $parked ) {
			$this->render_import_mapping( $parked );

			return;
		}
		?>
		<div class="fw-crm-panels">
			<div class="fw-crm-panel">
				<h2><?php esc_html_e( 'Import subscribers', 'fw' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Upload a CSV with a header row. The next step lets you match its columns to subscriber fields. Existing addresses are updated, not duplicated, and people who previously unsubscribed are skipped unless you say otherwise.', 'fw' ); ?>
				</p>
				<form method="post" action="" enctype="multipart/form-data">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="fw_crm_action" value="import_upload" />
					<p><input type="file" name="fw_crm_csv" accept=".csv,text/csv" required /></p>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Upload and continue', 'fw' ); ?></button></p>
				</form>
			</div>

			<div class="fw-crm-panel">
				<h2><?php esc_html_e( 'Export subscribers', 'fw' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Downloads every subscriber as CSV. To export a subset instead, filter the Subscribers tab first and use the export button there.', 'fw' ); ?>
				</p>
				<p>
					<a class="button button-primary"
					   href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'fw_crm_action', 'export', self::get_page_url() ), self::NONCE ) ); ?>">
						<?php esc_html_e( 'Export all (CSV)', 'fw' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $parked
	 */
	private function render_import_mapping( array $parked ) {
		$fields  = FW_Newsletter_CRM_CSV::fields();
		$fields['name'] = __( 'Name (whole)', 'fw' );
		$guess   = FW_Newsletter_CRM_CSV::guess_mapping( $parked['header'] );
		?>
		<h2><?php esc_html_e( 'Match the columns', 'fw' ); ?></h2>
		<p class="description"><?php esc_html_e( 'One column must be mapped to Email. Anything left as “Ignore” is skipped.', 'fw' ); ?></p>

		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="import_run" />

			<table class="widefat striped" style="max-width:900px">
				<thead>
				<tr>
					<th><?php esc_html_e( 'CSV column', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Sample', 'fw' ); ?></th>
					<th><?php esc_html_e( 'Import as', 'fw' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( (array) $parked['header'] as $index => $label ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td>
							<?php
							$samples = array();

							foreach ( (array) $parked['rows'] as $row ) {
								if ( isset( $row[ $index ] ) && '' !== $row[ $index ] ) {
									$samples[] = $row[ $index ];
								}
							}

							echo $samples ? esc_html( implode( ', ', array_slice( $samples, 0, 2 ) ) ) : '<span aria-hidden="true">—</span>';
							?>
						</td>
						<td>
							<select name="map[<?php echo (int) $index; ?>]">
								<option value=""><?php esc_html_e( '— Ignore —', 'fw' ); ?></option>
								<?php foreach ( $fields as $key => $field_label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"
										<?php selected( isset( $guess[ $index ] ) ? $guess[ $index ] : '', $key ); ?>>
										<?php echo esc_html( $field_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:1em">
				<label>
					<?php esc_html_e( 'Add everyone to this list:', 'fw' ); ?>
					<?php $this->render_list_select( 'import_list' ); ?>
				</label>
			</p>
			<p>
				<label>
					<input type="checkbox" name="overwrite_unsubscribed" value="1" />
					<?php esc_html_e( 'Also re-subscribe people who previously unsubscribed (only tick this if you have their fresh consent)', 'fw' ); ?>
				</label>
			</p>
			<p>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Run import', 'fw' ); ?></button>
				<a class="button" href="<?php echo esc_url( self::get_page_url( 'tools' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	private function render_settings_tab() {
		$schema = $this->ext->get_settings_options();
		$values = (array) fw_get_db_ext_settings_option( $this->ext->get_name() );
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="save_settings" />
			<?php echo fw()->backend->render_options( $schema, $values ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Changes', 'fw' ); ?></button>
			</p>
		</form>

		<div class="fw-crm-danger">
			<h2><?php esc_html_e( 'Remove all data', 'fw' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: subscriber count */
					esc_html__( 'Permanently deletes the %s stored subscribers along with the lists, tags, segments and their tables. Deactivating the extension does NOT do this — data is only ever removed here, on purpose.', 'fw' ),
					esc_html( number_format_i18n( FW_Newsletter_CRM_Subscribers::count() ) )
				);
				?>
			</p>
			<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'This permanently deletes every subscriber. Continue?', 'fw' ) ); ?>');">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="remove_data" />
				<p>
					<input type="text" name="confirm" placeholder="<?php esc_attr_e( 'Type DELETE', 'fw' ); ?>" />
					<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Remove all subscriber data', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * @param string $name
	 */
	private function render_list_select( $name ) {
		$lists = FW_Newsletter_CRM_Lists::all( 'list' );
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value=""><?php esc_html_e( '— Default list —', 'fw' ); ?></option>
			<?php foreach ( $lists as $list ) : ?>
				<option value="<?php echo esc_attr( $list->slug ); ?>"><?php echo esc_html( $list->title ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
