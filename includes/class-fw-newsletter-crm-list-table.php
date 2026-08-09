<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Subscribers table.
 *
 * Subclasses core's WP_List_Table (as the Update extension's own list table
 * does) rather than the vendored FW_WP_List_Table helper — this screen is
 * admin-only, so core's current implementation is available and is the one that
 * keeps up with WordPress's markup and a11y.
 *
 * Presentation only: every row comes from the repository, and every action posts
 * back to the admin page, which calls the service on its `load-` hook.
 */
class FW_Newsletter_CRM_List_Table extends WP_List_Table {

	/** @var array The query args this render used — reused by the CSV export. */
	private $query_args = array();

	public function __construct() {
		parent::__construct( array(
			'singular' => 'subscriber',
			'plural'   => 'subscribers',
			'ajax'     => false,
			'screen'   => 'fw-newsletter-crm',
		) );
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'email'      => __( 'Email', 'fw' ),
			'name'       => __( 'Name', 'fw' ),
			'status'     => __( 'Status', 'fw' ),
			'lists'      => __( 'Lists', 'fw' ),
			'tags'       => __( 'Tags', 'fw' ),
			'source'     => __( 'Source', 'fw' ),
			'created_at' => __( 'Signed up', 'fw' ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'unsubscribe' => __( 'Mark unsubscribed', 'fw' ),
			'subscribe'   => __( 'Mark subscribed', 'fw' ),
			'delete'      => __( 'Delete permanently', 'fw' ),
		);
	}

	/**
	 * The status filter links above the table, with counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = FW_Newsletter_CRM_Subscribers::counts_by_status();
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base    = FW_Newsletter_CRM_Admin_Page::get_page_url();

		$labels = array(
			'all'          => __( 'All', 'fw' ),
			'subscribed'   => __( 'Subscribed', 'fw' ),
			'pending'      => __( 'Pending', 'fw' ),
			'unsubscribed' => __( 'Unsubscribed', 'fw' ),
			'bounced'      => __( 'Bounced', 'fw' ),
			'complained'   => __( 'Complained', 'fw' ),
		);

		$views = array();

		foreach ( $labels as $status => $label ) {
			$count = isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;

			// Hide the rare states until they actually have rows.
			if ( 0 === $count && in_array( $status, array( 'bounced', 'complained', 'pending' ), true ) ) {
				continue;
			}

			$url     = 'all' === $status ? $base : add_query_arg( 'status', $status, $base );
			$is_here = ( 'all' === $status && '' === $current ) || $status === $current;

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$is_here ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * The list dropdown, next to the bulk actions.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$lists   = FW_Newsletter_CRM_Lists::all( 'list' );
		$current = isset( $_REQUEST['list'] ) ? sanitize_key( wp_unslash( $_REQUEST['list'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $lists ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="fw-crm-list"><?php esc_html_e( 'Filter by list', 'fw' ); ?></label>
			<select name="list" id="fw-crm-list">
				<option value=""><?php esc_html_e( 'All lists', 'fw' ); ?></option>
				<?php foreach ( $lists as $list ) : ?>
					<option value="<?php echo esc_attr( $list->slug ); ?>" <?php selected( $current, $list->slug ); ?>>
						<?php echo esc_html( $list->title ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'fw' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * The query args derived from the current request — shared with the exporter
	 * so "Export these results" really means these results.
	 *
	 * @return array
	 */
	public static function request_query_args() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filtering.
		$args = array(
			'status'  => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'list'    => isset( $_REQUEST['list'] ) ? sanitize_key( wp_unslash( $_REQUEST['list'] ) ) : '',
			'tag'     => isset( $_REQUEST['tag'] ) ? sanitize_key( wp_unslash( $_REQUEST['tag'] ) ) : '',
			'search'  => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby' => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at',
			'order'   => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
		);
		// phpcs:enable

		return $args;
	}

	/**
	 * Fetch rows + set up pagination.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'fw_crm_subscribers_per_page', 20 );
		$paged    = $this->get_pagenum();

		$args = array_merge( self::request_query_args(), array(
			'per_page' => $per_page,
			'paged'    => $paged,
		) );

		$this->query_args = $args;

		$this->items = FW_Newsletter_CRM_Subscribers::query( $args );

		$this->set_pagination_args( array(
			'total_items' => FW_Newsletter_CRM_Subscribers::count( $args ),
			'per_page'    => $per_page,
		) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'email' );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="subscribers[]" value="%d" />', (int) $item->id );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_email( $item ) {
		$base = FW_Newsletter_CRM_Admin_Page::get_page_url();

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( array( 'subscriber' => (int) $item->id ), $base ) ),
				esc_html__( 'View', 'fw' )
			),
		);

		if ( 'unsubscribed' !== $item->status ) {
			$actions['unsubscribe'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( FW_Newsletter_CRM_Admin_Page::action_url( 'unsubscribe', $item->id ) ),
				esc_html__( 'Unsubscribe', 'fw' )
			);
		}

		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
			esc_url( FW_Newsletter_CRM_Admin_Page::action_url( 'delete', $item->id ) ),
			esc_js( __( 'Delete this subscriber permanently?', 'fw' ) ),
			esc_html__( 'Delete', 'fw' )
		);

		return sprintf(
			'<strong>%s</strong>%s',
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_name( $item ) {
		$name = trim( $item->first_name . ' ' . $item->last_name );

		return '' !== $name ? esc_html( $name ) : '<span aria-hidden="true">—</span>';
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_status( $item ) {
		$labels = array(
			'subscribed'   => __( 'Subscribed', 'fw' ),
			'unsubscribed' => __( 'Unsubscribed', 'fw' ),
			'pending'      => __( 'Pending', 'fw' ),
			'bounced'      => __( 'Bounced', 'fw' ),
			'complained'   => __( 'Complained', 'fw' ),
		);

		$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;

		return sprintf(
			'<span class="fw-crm-status fw-crm-status--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_lists( $item ) {
		return $this->membership_column( $item, 'list' );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_tags( $item ) {
		return $this->membership_column( $item, 'tag' );
	}

	/**
	 * @param object $item
	 * @param string $type
	 *
	 * @return string
	 */
	private function membership_column( $item, $type ) {
		$rows = FW_Newsletter_CRM_Subscribers::get_lists( $item->id, $type );

		if ( ! $rows ) {
			return '<span aria-hidden="true">—</span>';
		}

		$base  = FW_Newsletter_CRM_Admin_Page::get_page_url();
		$links = array();

		foreach ( $rows as $row ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( $type, $row->slug, $base ) ),
				esc_html( $row->title )
			);
		}

		return implode( ', ', $links );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_source( $item ) {
		if ( '' === $item->source ) {
			return '<span aria-hidden="true">—</span>';
		}

		if ( '' !== $item->source_url ) {
			return sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $item->source_url ),
				esc_html( $item->source )
			);
		}

		return esc_html( $item->source );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$time = strtotime( $item->created_at );

		if ( ! $time ) {
			return '<span aria-hidden="true">—</span>';
		}

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( date_i18n( 'Y-m-d H:i', $time ) ),
			esc_html( date_i18n( get_option( 'date_format' ), $time ) )
		);
	}

	/**
	 * @param object $item
	 * @param string $column_name
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Shown when the store is empty or a filter matched nothing.
	 */
	public function no_items() {
		esc_html_e( 'No subscribers yet. Add one manually, import a CSV, or drop a [newsletter] element on a page.', 'fw' );
	}
}
