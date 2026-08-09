<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Schema owner. The ONLY class in the extension that emits DDL.
 *
 * This is the plugin's first custom-table extension, so the discipline is set
 * here and documented in AGENTS.md:
 *
 *  - `dbDelta()` is picky by contract — two spaces after PRIMARY KEY, one field
 *    per line, lowercase types, `KEY` (never `INDEX`), and the collation string
 *    from `$wpdb->get_charset_collate()`. Break any of those and it silently
 *    re-runs ALTERs on every single load.
 *  - The installer is idempotent and guarded by an option (`DB_VERSION_OPTION`)
 *    so it costs one autoloaded `get_option()` per request when up to date.
 *  - `dbDelta()` ADDS columns and indexes; it never drops or renames. Anything
 *    destructive must be a numbered migration step in `migrate()`, guarded by
 *    the stored version.
 *  - Tables use `$wpdb->prefix` (per-site), NOT `base_prefix` — on multisite
 *    each site keeps its own subscribers, which is what you want for the demos
 *    network and for real networks alike.
 *  - Deactivating NEVER drops a table. Data removal is an explicit, opt-in
 *    action on the settings screen (see the extension class).
 */
class FW_Newsletter_CRM_Installer {

	/** Bump when the schema below changes. */
	const DB_VERSION = '1.2.0';

	const DB_VERSION_OPTION = 'fw_ext_newsletter_crm_db_version';

	/**
	 * Fully-qualified table name for a logical table.
	 *
	 * @param string $table subscribers|subscriber_meta|lists|subscriber_pivot|segments
	 *
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . 'fw_crm_' . $table;
	}

	/**
	 * Install or upgrade if the stored version differs. Cheap no-op otherwise.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create/upgrade every table, then run migrations and stamp the version.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$from    = (string) get_option( self::DB_VERSION_OPTION, '' );

		foreach ( self::schema( $collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::migrate( $from );
		self::seed_default_list();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * The schema. One CREATE TABLE per logical table, dbDelta-formatted.
	 *
	 * Design notes (see the Phase 0 report):
	 *  - `email` is varchar(190) so a UNIQUE index fits inside utf8mb4's key
	 *    limit. That unique index — not application code — is what makes dedupe
	 *    atomic and race-free.
	 *  - `status` is varchar, not ENUM, so adding `bounced`/`complained` later is
	 *    a PHP whitelist change, not a schema migration.
	 *  - Lists AND tags live in one `lists` table discriminated by `type`, with a
	 *    single polymorphic pivot carrying membership. One structure, one set of
	 *    queries, and any future object type joins the same pivot.
	 *  - A segment stores its FILTER JSON, never denormalised membership — it is
	 *    a saved query, evaluated on read.
	 *
	 * @param string $collate
	 *
	 * @return array
	 */
	private static function schema( $collate ) {
		$subscribers = self::table( 'subscribers' );
		$meta        = self::table( 'subscriber_meta' );
		$lists       = self::table( 'lists' );
		$pivot       = self::table( 'subscriber_pivot' );
		$segments    = self::table( 'segments' );

		$sql = array();

		$sql[] = "CREATE TABLE {$subscribers} (
	id bigint(20) unsigned NOT NULL auto_increment,
	email varchar(190) NOT NULL default '',
	first_name varchar(100) NOT NULL default '',
	last_name varchar(100) NOT NULL default '',
	status varchar(20) NOT NULL default 'subscribed',
	source varchar(50) NOT NULL default '',
	source_url varchar(255) NOT NULL default '',
	confirm_token varchar(64) NOT NULL default '',
	confirm_token_at datetime default NULL,
	unsubscribe_token varchar(64) NOT NULL default '',
	ip varchar(45) NOT NULL default '',
	consent_at datetime default NULL,
	confirmed_at datetime default NULL,
	unsubscribed_at datetime default NULL,
	meta longtext,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY email (email),
	KEY status (status),
	KEY created_at (created_at),
	KEY confirm_token (confirm_token),
	KEY unsubscribe_token (unsubscribe_token)
) {$collate};";

		$sql[] = "CREATE TABLE {$meta} (
	id bigint(20) unsigned NOT NULL auto_increment,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	meta_key varchar(100) NOT NULL default '',
	meta_value longtext,
	PRIMARY KEY  (id),
	UNIQUE KEY subscriber_key (subscriber_id,meta_key),
	KEY meta_key (meta_key)
) {$collate};";

		$sql[] = "CREATE TABLE {$lists} (
	id bigint(20) unsigned NOT NULL auto_increment,
	type varchar(20) NOT NULL default 'list',
	slug varchar(100) NOT NULL default '',
	title varchar(190) NOT NULL default '',
	description text,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY type_slug (type,slug)
) {$collate};";

		$sql[] = "CREATE TABLE {$pivot} (
	id bigint(20) unsigned NOT NULL auto_increment,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	object_id bigint(20) unsigned NOT NULL default 0,
	object_type varchar(20) NOT NULL default 'list',
	status varchar(20) NOT NULL default 'subscribed',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY membership (subscriber_id,object_id,object_type),
	KEY object (object_id,object_type)
) {$collate};";

		$campaigns = self::table( 'campaigns' );
		$queue     = self::table( 'campaign_queue' );

		$sql[] = "CREATE TABLE {$campaigns} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(190) NOT NULL default '',
	subject varchar(255) NOT NULL default '',
	body longtext,
	body_json longtext,
	audience longtext,
	status varchar(20) NOT NULL default 'draft',
	scheduled_at datetime default NULL,
	started_at datetime default NULL,
	finished_at datetime default NULL,
	total int unsigned NOT NULL default 0,
	sent int unsigned NOT NULL default 0,
	failed int unsigned NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status (status),
	KEY scheduled_at (scheduled_at)
) {$collate};";

		// The per-recipient send queue. This table is the ENTIRE reason a send can
		// survive a PHP timeout: a campaign row alone cannot answer "who did we
		// already send to?", so a batch that dies mid-flight would either
		// double-send or silently drop people. One row per recipient, flipped as
		// it goes, makes the send resumable and auditable.
		//
		// `email` is snapshotted so the log still reads correctly after a
		// subscriber is deleted.
		$sql[] = "CREATE TABLE {$queue} (
	id bigint(20) unsigned NOT NULL auto_increment,
	campaign_id bigint(20) unsigned NOT NULL default 0,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	email varchar(190) NOT NULL default '',
	status varchar(20) NOT NULL default 'pending',
	error varchar(255) NOT NULL default '',
	sent_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY recipient (campaign_id,subscriber_id),
	KEY campaign_status (campaign_id,status)
) {$collate};";

		$sql[] = "CREATE TABLE {$segments} (
	id bigint(20) unsigned NOT NULL auto_increment,
	slug varchar(100) NOT NULL default '',
	title varchar(190) NOT NULL default '',
	filters longtext,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug)
) {$collate};";

		return $sql;
	}

	/**
	 * Destructive / data-shape migrations that dbDelta cannot express.
	 *
	 * Add a numbered, version-guarded block here when the schema changes in a way
	 * that needs data moved. Empty for 1.0.0 by definition.
	 *
	 * @param string $from Previously installed version ('' on a fresh install).
	 */
	private static function migrate( $from ) {
		if ( '' === $from ) {
			return; // Fresh install — the schema above is already current.
		}

		// Example shape for the future:
		// if ( version_compare( $from, '1.1.0', '<' ) ) { ...move data...; }
	}

	/**
	 * Make sure the built-in "default" list exists, so a signup with no list ID
	 * still lands somewhere queryable.
	 */
	private static function seed_default_list() {
		FW_Newsletter_CRM_Lists::get_or_create( 'default', 'list', __( 'Default', 'fw' ) );
	}

	/**
	 * Drop everything. Only ever called from the explicit, opt-in "remove all
	 * data" action on the settings screen — never on deactivation.
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( array( 'campaign_queue', 'campaigns', 'subscriber_pivot', 'subscriber_meta', 'segments', 'subscribers', 'lists' ) as $table ) {
			$name = self::table( $table );
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		delete_option( self::DB_VERSION_OPTION );
	}
}
