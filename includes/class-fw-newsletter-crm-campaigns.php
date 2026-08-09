<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Campaigns repository (DAO) — campaigns and their per-recipient send queue.
 *
 * Same contract as the other repositories: the only place that writes SQL about
 * these two tables, no business rules, no hooks. The rules (who is eligible,
 * when a send may start, what happens on failure) live in the service and the
 * sender.
 */
class FW_Newsletter_CRM_Campaigns {

	/**
	 * draft      — being written.
	 * scheduled  — waiting for scheduled_at.
	 * sending    — queue built, batches going out.
	 * paused     — stopped mid-send; the queue is intact and resumable.
	 * sent       — queue drained.
	 */
	const STATUSES = array( 'draft', 'scheduled', 'sending', 'paused', 'sent' );

	/** @return string */
	private static function table() {
		return FW_Newsletter_CRM_Installer::table( 'campaigns' );
	}

	/** @return string */
	private static function queue_table() {
		return FW_Newsletter_CRM_Installer::table( 'campaign_queue' );
	}

	/* ---------------------------------------------------------------------- *
	 * Campaigns
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int $id
	 *
	 * @return object|null
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args status, per_page, paged
	 *
	 * @return array
	 */
	public static function all( array $args = array() ) {
		global $wpdb;

		$table = self::table();
		$where = '1=1';

		if ( ! empty( $args['status'] ) ) {
			$where .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}

		$sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC";

		if ( ! empty( $args['per_page'] ) ) {
			$per  = (int) $args['per_page'];
			$page = max( 1, isset( $args['paged'] ) ? (int) $args['paged'] : 1 );
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per );
		}

		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Campaigns whose moment has come — scheduled and due.
	 *
	 * @return array
	 */
	public static function due() {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = %s AND scheduled_at IS NOT NULL AND scheduled_at <= %s ORDER BY scheduled_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			'scheduled',
			current_time( 'mysql' )
		) );
	}

	/**
	 * Campaigns with a queue still draining.
	 *
	 * @return array
	 */
	public static function sending() {
		global $wpdb;

		$table = self::table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = %s ORDER BY started_at ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			'sending'
		) );
	}

	/**
	 * @param array $data
	 *
	 * @return int
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		$row = array_merge( self::sanitize( $data ), array(
			'created_at' => $now,
			'updated_at' => $now,
		) );

		$ok = $wpdb->insert( self::table(), $row );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @param int   $id
	 * @param array $data
	 *
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;

		$row = self::sanitize( $data );

		if ( ! $row ) {
			return false;
		}

		$row['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * @param array $data
	 *
	 * @return array
	 */
	private static function sanitize( array $data ) {
		$row = array();

		if ( isset( $data['title'] ) ) {
			$row['title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['subject'] ) ) {
			$row['subject'] = sanitize_text_field( $data['subject'] );
		}

		if ( isset( $data['body'] ) ) {
			// wp_kses_post, not sanitize_textarea_field — a campaign body is HTML
			// on purpose, but must not be able to carry script.
			$row['body'] = wp_kses_post( $data['body'] );
		}

		if ( isset( $data['body_json'] ) ) {
			// Already JSON-encoded by the service; stored verbatim so the builder
			// gets back exactly the tree it saved.
			$row['body_json'] = is_array( $data['body_json'] )
				? wp_json_encode( $data['body_json'] )
				: (string) $data['body_json'];
		}

		if ( isset( $data['audience'] ) ) {
			$row['audience'] = is_array( $data['audience'] )
				? wp_json_encode( $data['audience'] )
				: (string) $data['audience'];
		}

		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) {
			$row['status'] = $data['status'];
		}

		foreach ( array( 'scheduled_at', 'started_at', 'finished_at' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ] ? $data[ $key ] : null;
			}
		}

		foreach ( array( 'total', 'sent', 'failed' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$row[ $key ] = (int) $data[ $key ];
			}
		}

		return $row;
	}

	/**
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$wpdb->delete( self::queue_table(), array( 'campaign_id' => (int) $id ), array( '%d' ) );

		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Decode the stored audience into subscriber query args.
	 *
	 * @param object $campaign
	 *
	 * @return array
	 */
	public static function audience_args( $campaign ) {
		$args = array();

		if ( ! empty( $campaign->audience ) ) {
			$decoded = json_decode( $campaign->audience, true );
			$args    = is_array( $decoded ) ? $decoded : array();
		}

		// A campaign ALWAYS goes to confirmed subscribers only. Pending people
		// never agreed, and unsubscribed/bounced/complained ones must never be
		// mailed again — so this is forced, not merely defaulted.
		$args['status'] = 'subscribed';

		return $args;
	}

	/* ---------------------------------------------------------------------- *
	 * Queue
	 * ---------------------------------------------------------------------- */

	/**
	 * Materialise the recipient list into the queue.
	 *
	 * The audience is a live query, so it is resolved ONCE here — at send time —
	 * and frozen into rows. That is deliberate: a send must have a fixed,
	 * countable recipient set, or progress is meaningless and someone added
	 * mid-send might get the mail while someone removed might already have.
	 *
	 * @param object $campaign
	 *
	 * @return int Rows queued.
	 */
	public static function build_queue( $campaign ) {
		global $wpdb;

		$args = self::audience_args( $campaign );
		$args['per_page'] = 0; // everything

		$subscribers = FW_Newsletter_CRM_Subscribers::query( $args );
		$queue       = self::queue_table();
		$now         = current_time( 'mysql' );
		$queued      = 0;

		foreach ( array_chunk( $subscribers, 200 ) as $chunk ) {
			$values = array();

			foreach ( $chunk as $subscriber ) {
				$values[] = $wpdb->prepare(
					'(%d, %d, %s, %s, %s)',
					$campaign->id,
					$subscriber->id,
					$subscriber->email,
					'pending',
					$now
				);
			}

			if ( ! $values ) {
				continue;
			}

			// INSERT IGNORE against the UNIQUE (campaign_id, subscriber_id) index:
			// rebuilding a queue can never duplicate a recipient.
			$queued += (int) $wpdb->query(
				"INSERT IGNORE INTO {$queue} (campaign_id, subscriber_id, email, status, created_at) VALUES " // phpcs:ignore WordPress.DB.PreparedSQL
				. implode( ',', $values )
			);
		}

		return $queued;
	}

	/**
	 * @param int $campaign_id
	 * @param int $limit
	 *
	 * @return array Pending queue rows.
	 */
	public static function next_batch( $campaign_id, $limit ) {
		global $wpdb;

		$queue = self::queue_table();

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$queue} WHERE campaign_id = %d AND status = %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $campaign_id,
			'pending',
			(int) $limit
		) );
	}

	/**
	 * @param int    $queue_id
	 * @param string $status sent|failed|skipped
	 * @param string $error
	 *
	 * @return bool
	 */
	public static function mark( $queue_id, $status, $error = '' ) {
		global $wpdb;

		return false !== $wpdb->update(
			self::queue_table(),
			array(
				'status'  => $status,
				'error'   => substr( (string) $error, 0, 255 ),
				'sent_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $queue_id )
		);
	}

	/**
	 * @param int $campaign_id
	 *
	 * @return array [ status => count ]
	 */
	public static function queue_counts( $campaign_id ) {
		global $wpdb;

		$queue = self::queue_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS total FROM {$queue} WHERE campaign_id = %d GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $campaign_id
		) );

		$out = array( 'pending' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0 );

		foreach ( (array) $rows as $row ) {
			$out[ $row->status ] = (int) $row->total;
			$out['total']       += (int) $row->total;
		}

		return $out;
	}

	/**
	 * @param int $campaign_id
	 *
	 * @return bool
	 */
	public static function clear_queue( $campaign_id ) {
		global $wpdb;

		return false !== $wpdb->delete( self::queue_table(), array( 'campaign_id' => (int) $campaign_id ), array( '%d' ) );
	}
}
