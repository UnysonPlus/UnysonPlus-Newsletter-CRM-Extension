<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Subscribers repository (DAO).
 *
 * THE contract for this file: it is the only place in the extension that writes
 * SQL about subscribers, and it contains NO business rules and fires NO hooks.
 * Decisions ("should a re-signup reactivate?", "is double opt-in on?") belong to
 * FW_Newsletter_CRM_Service; presentation belongs to the admin classes. When a
 * future campaign or automation feature needs data, it gets a method here — it
 * does not write its own $wpdb->prepare().
 *
 * `query()` deliberately accepts the same arg shape the admin list table, the
 * CSV exporter and saved segments all use, so those three are one code path.
 */
class FW_Newsletter_CRM_Subscribers {

	/** Every status the store recognises. varchar, not ENUM — see the installer. */
	const STATUSES = array( 'subscribed', 'unsubscribed', 'pending', 'bounced', 'complained' );

	/** @return string */
	private static function table() {
		return FW_Newsletter_CRM_Installer::table( 'subscribers' );
	}

	/** @return string */
	private static function pivot() {
		return FW_Newsletter_CRM_Installer::table( 'subscriber_pivot' );
	}

	/** @return string */
	private static function meta_table() {
		return FW_Newsletter_CRM_Installer::table( 'subscriber_meta' );
	}

	/**
	 * Lowercase + trim. The single normalisation used for storage, lookups and
	 * dedupe — never compare raw input against a stored address.
	 *
	 * @param string $email
	 *
	 * @return string
	 */
	public static function normalize_email( $email ) {
		return strtolower( trim( (string) $email ) );
	}

	/**
	 * @param string $status
	 *
	 * @return bool
	 */
	public static function is_valid_status( $status ) {
		return in_array( $status, self::STATUSES, true );
	}

	/* ---------------------------------------------------------------------- *
	 * Reads
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
	 * @param string $email
	 *
	 * @return object|null
	 */
	public static function find_by_email( $email ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE email = %s", // phpcs:ignore WordPress.DB.PreparedSQL
			self::normalize_email( $email )
		) );
	}

	/**
	 * @param string $token
	 *
	 * @return object|null
	 */
	public static function find_by_token( $token, $kind = 'confirm' ) {
		global $wpdb;

		$token = (string) $token;

		if ( '' === $token ) {
			return null;
		}

		$table  = self::table();
		$column = 'unsubscribe' === $kind ? 'unsubscribe_token' : 'confirm_token';

		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE {$column} = %s", // phpcs:ignore WordPress.DB.PreparedSQL
			$token
		) );
	}

	/**
	 * Build the shared WHERE/JOIN for query() and count().
	 *
	 * Supported args: status, list (id or slug), tag (id or slug), search,
	 * ids (array), segment (id — resolved to these same args).
	 *
	 * @param array $args
	 *
	 * @return array [ 'join' => string, 'where' => string ]
	 */
	private static function build_clauses( array $args ) {
		global $wpdb;

		// A segment IS a saved set of these args — resolve and merge, with any
		// explicit arg winning over the stored one.
		if ( ! empty( $args['segment'] ) ) {
			$args = array_merge(
				FW_Newsletter_CRM_Lists::segment_query_args( $args['segment'] ),
				array_diff_key( $args, array( 'segment' => 1 ) )
			);
		}

		$pivot = self::pivot();
		$join  = '';
		$where = array( '1=1' );

		if ( ! empty( $args['status'] ) && self::is_valid_status( $args['status'] ) ) {
			$where[] = $wpdb->prepare( 's.status = %s', $args['status'] );
		}

		foreach ( array( 'list', 'tag' ) as $object_type ) {
			if ( empty( $args[ $object_type ] ) ) {
				continue;
			}

			$object = $args[ $object_type ];

			if ( ! is_numeric( $object ) ) {
				$row    = FW_Newsletter_CRM_Lists::find_by_slug( $object, $object_type );
				$object = $row ? $row->id : 0;
			}

			$alias = 'p_' . $object_type;
			$join .= " INNER JOIN {$pivot} {$alias} ON {$alias}.subscriber_id = s.id";
			$where[] = $wpdb->prepare(
				"{$alias}.object_id = %d AND {$alias}.object_type = %s", // phpcs:ignore WordPress.DB.PreparedSQL
				(int) $object,
				$object_type
			);
		}

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[] = $wpdb->prepare(
				'( s.email LIKE %s OR s.first_name LIKE %s OR s.last_name LIKE %s )',
				$like,
				$like,
				$like
			);
		}

		if ( ! empty( $args['ids'] ) ) {
			$ids = array_filter( array_map( 'intval', (array) $args['ids'] ) );

			if ( $ids ) {
				$where[] = 's.id IN (' . implode( ',', $ids ) . ')';
			} else {
				$where[] = '1=0';
			}
		}

		return array(
			'join'  => $join,
			'where' => implode( ' AND ', $where ),
		);
	}

	/**
	 * @param array $args build_clauses() args + orderby, order, per_page, paged.
	 *
	 * @return array Subscriber row objects.
	 */
	public static function query( array $args = array() ) {
		global $wpdb;

		$args = array_merge( array(
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'paged'    => 1,
		), $args );

		$clauses = self::build_clauses( $args );
		$table   = self::table();

		$allowed_orderby = array( 'id', 'email', 'first_name', 'last_name', 'status', 'created_at', 'updated_at' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$per_page = (int) $args['per_page'];
		$sql      = "SELECT DISTINCT s.* FROM {$table} s{$clauses['join']} WHERE {$clauses['where']} ORDER BY s.{$orderby} {$order}";

		// per_page <= 0 means "everything" (the CSV exporter uses that).
		if ( $per_page > 0 ) {
			$offset = ( max( 1, (int) $args['paged'] ) - 1 ) * $per_page;
			$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per_page, $offset );
		}

		return (array) $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * @param array $args Same as query().
	 *
	 * @return int
	 */
	public static function count( array $args = array() ) {
		global $wpdb;

		$clauses = self::build_clauses( $args );
		$table   = self::table();

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT s.id) FROM {$table} s{$clauses['join']} WHERE {$clauses['where']}" // phpcs:ignore WordPress.DB.PreparedSQL
		);
	}

	/**
	 * Counts per status, for the list table's "views" links.
	 *
	 * @return array [ status => count ] including an 'all' key.
	 */
	public static function counts_by_status() {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status" ); // phpcs:ignore WordPress.DB.PreparedSQL

		$out = array( 'all' => 0 );

		foreach ( self::STATUSES as $status ) {
			$out[ $status ] = 0;
		}

		foreach ( (array) $rows as $row ) {
			$out[ $row->status ] = (int) $row->total;
			$out['all']         += (int) $row->total;
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Writes
	 * ---------------------------------------------------------------------- */

	/**
	 * Whitelist + type-cast an arbitrary data array into a storable row.
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	private static function sanitize_row( array $data ) {
		$row = array();

		$text = array( 'first_name', 'last_name', 'source', 'confirm_token', 'unsubscribe_token', 'ip' );

		foreach ( $text as $key ) {
			if ( isset( $data[ $key ] ) ) {
				$row[ $key ] = sanitize_text_field( $data[ $key ] );
			}
		}

		if ( isset( $data['email'] ) ) {
			$row['email'] = self::normalize_email( $data['email'] );
		}

		if ( isset( $data['status'] ) && self::is_valid_status( $data['status'] ) ) {
			$row['status'] = $data['status'];
		}

		if ( isset( $data['source_url'] ) ) {
			$row['source_url'] = esc_url_raw( $data['source_url'] );
		}

		foreach ( array( 'confirm_token_at', 'consent_at', 'confirmed_at', 'unsubscribed_at' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ] ? $data[ $key ] : null;
			}
		}

		if ( isset( $data['meta'] ) ) {
			$row['meta'] = is_array( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : (string) $data['meta'];
		}

		return $row;
	}

	/**
	 * @param array $data
	 *
	 * @return int Inserted ID, or 0 (including when the UNIQUE email index rejects it).
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$row = self::sanitize_row( $data );

		if ( empty( $row['email'] ) ) {
			return 0;
		}

		$now = current_time( 'mysql' );

		$row = array_merge( array(
			'status'     => 'subscribed',
			'created_at' => $now,
			'updated_at' => $now,
		), $row );

		$row['created_at'] = $now;
		$row['updated_at'] = $now;

		if ( empty( $row['unsubscribe_token'] ) ) {
			$row['unsubscribe_token'] = self::generate_token();
		}

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

		$row = self::sanitize_row( $data );

		if ( ! $row ) {
			return false;
		}

		$row['updated_at'] = current_time( 'mysql' );

		return false !== $wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
	}

	/**
	 * Insert or update, keyed on the normalised email. The UNIQUE index makes
	 * this the atomic dedupe point — never SELECT-then-INSERT, which races.
	 *
	 * @param array $data
	 *
	 * @return array [ 'id' => int, 'created' => bool ]
	 */
	public static function upsert( array $data ) {
		$email    = self::normalize_email( isset( $data['email'] ) ? $data['email'] : '' );
		$existing = '' !== $email ? self::find_by_email( $email ) : null;

		if ( $existing ) {
			self::update( $existing->id, $data );

			return array( 'id' => (int) $existing->id, 'created' => false );
		}

		$id = self::insert( $data );

		if ( ! $id ) {
			// Lost a race against a concurrent insert of the same address — the
			// UNIQUE index did its job; re-read and update instead.
			$existing = self::find_by_email( $email );

			if ( $existing ) {
				self::update( $existing->id, $data );

				return array( 'id' => (int) $existing->id, 'created' => false );
			}
		}

		return array( 'id' => (int) $id, 'created' => (bool) $id );
	}

	/**
	 * Hard delete a subscriber plus their pivot and meta rows.
	 *
	 * NOTE: this is an explicit admin/GDPR action only. Unsubscribing must NEVER
	 * delete — a forgotten address is silently re-subscribed by the next import,
	 * which is a legal problem, not just a bug.
	 *
	 * @param int $id
	 *
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;

		$id = (int) $id;

		$wpdb->delete( self::pivot(), array( 'subscriber_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::meta_table(), array( 'subscriber_id' => $id ), array( '%d' ) );

		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * @return string A 64-char-safe random token.
	 */
	public static function generate_token() {
		return wp_generate_password( 40, false, false );
	}

	/* ---------------------------------------------------------------------- *
	 * Membership (the polymorphic pivot)
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int    $subscriber_id
	 * @param int    $object_id
	 * @param string $object_type list|tag
	 *
	 * @return bool
	 */
	public static function add_to_list( $subscriber_id, $object_id, $object_type = 'list' ) {
		global $wpdb;

		if ( ! $subscriber_id || ! $object_id ) {
			return false;
		}

		// INSERT IGNORE leans on the UNIQUE membership index, so adding twice is
		// a no-op rather than a duplicate row or a SELECT round-trip.
		$pivot = self::pivot();

		return false !== $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$pivot} (subscriber_id, object_id, object_type, status, created_at)
			 VALUES (%d, %d, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $subscriber_id,
			(int) $object_id,
			$object_type,
			'subscribed',
			current_time( 'mysql' )
		) );
	}

	/**
	 * @param int    $subscriber_id
	 * @param int    $object_id
	 * @param string $object_type
	 *
	 * @return bool
	 */
	public static function remove_from_list( $subscriber_id, $object_id, $object_type = 'list' ) {
		global $wpdb;

		return (bool) $wpdb->delete( self::pivot(), array(
			'subscriber_id' => (int) $subscriber_id,
			'object_id'     => (int) $object_id,
			'object_type'   => $object_type,
		), array( '%d', '%d', '%s' ) );
	}

	/**
	 * Every list (or tag) a subscriber belongs to.
	 *
	 * @param int    $subscriber_id
	 * @param string $object_type
	 *
	 * @return array List row objects.
	 */
	public static function get_lists( $subscriber_id, $object_type = 'list' ) {
		global $wpdb;

		$pivot = self::pivot();
		$lists = FW_Newsletter_CRM_Installer::table( 'lists' );

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT l.* FROM {$lists} l
			 INNER JOIN {$pivot} p ON p.object_id = l.id AND p.object_type = l.type
			 WHERE p.subscriber_id = %d AND p.object_type = %s
			 ORDER BY l.title ASC", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $subscriber_id,
			$object_type
		) );
	}

	/**
	 * Replace a subscriber's whole tag set (or list set) with the given IDs.
	 *
	 * @param int    $subscriber_id
	 * @param array  $object_ids
	 * @param string $object_type
	 */
	public static function set_lists( $subscriber_id, array $object_ids, $object_type = 'list' ) {
		global $wpdb;

		$subscriber_id = (int) $subscriber_id;
		$object_ids    = array_filter( array_map( 'intval', $object_ids ) );

		$wpdb->delete( self::pivot(), array(
			'subscriber_id' => $subscriber_id,
			'object_type'   => $object_type,
		), array( '%d', '%s' ) );

		foreach ( $object_ids as $object_id ) {
			self::add_to_list( $subscriber_id, $object_id, $object_type );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Meta (custom fields + per-provider remote IDs)
	 * ---------------------------------------------------------------------- */

	/**
	 * @param int    $subscriber_id
	 * @param string $key
	 * @param mixed  $default
	 *
	 * @return mixed
	 */
	public static function get_meta( $subscriber_id, $key, $default = null ) {
		global $wpdb;

		$table = self::meta_table();

		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$table} WHERE subscriber_id = %d AND meta_key = %s", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $subscriber_id,
			(string) $key
		) );

		return null === $value ? $default : maybe_unserialize( $value );
	}

	/**
	 * @param int    $subscriber_id
	 * @param string $key
	 * @param mixed  $value
	 *
	 * @return bool
	 */
	public static function set_meta( $subscriber_id, $key, $value ) {
		global $wpdb;

		$table = self::meta_table();

		return false !== $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (subscriber_id, meta_key, meta_value) VALUES (%d, %s, %s)
			 ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $subscriber_id,
			(string) $key,
			maybe_serialize( $value )
		) );
	}

	/**
	 * @param int $subscriber_id
	 *
	 * @return array [ key => value ]
	 */
	public static function all_meta( $subscriber_id ) {
		global $wpdb;

		$table = self::meta_table();

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$table} WHERE subscriber_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $subscriber_id
		) );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[ $row->meta_key ] = maybe_unserialize( $row->meta_value );
		}

		return $out;
	}
}
