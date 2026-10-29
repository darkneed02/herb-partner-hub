<?php

defined( 'ABSPATH' ) || exit;

final class HPH_Install {
	public const ROLE        = 'herb_dropshipper';
	public const DB_OPTION   = 'hph_db_version';
	public const ADMIN_CAPS  = array( 'hph_manage', 'hph_view_reports' );
	public const TABLES      = array( 'applications', 'end_customers', 'log' );

	public static function activate(): void {
		self::install();
	}

	/**
	 * Roles, tables and data stay in place, so reactivating carries on where it left off.
	 * Removing them is uninstall.php's job, and only when the setting asks for it.
	 */
	public static function deactivate(): void {
	}

	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_OPTION ) !== HPH_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Safe to run again: add_role skips an existing role, dbDelta only adds what is missing.
	 */
	public static function install(): void {
		self::create_roles();
		self::create_tables();

		if ( false === get_option( HPH_Settings::OPTION_KEY, false ) ) {
			add_option( HPH_Settings::OPTION_KEY, HPH_Settings::defaults() );
		}

		update_option( self::DB_OPTION, HPH_DB_VERSION );
	}

	/**
	 * The role name stays untranslated here because this can run at plugins_loaded,
	 * before translations load. WordPress translates role names on display.
	 */
	public static function create_roles(): void {
		add_role( self::ROLE, 'Dropshipper', array( 'read' => true, 'hph_dropshipper' => true ) );

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::ADMIN_CAPS as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Reports only until the team decides who else may manage dropshippers (dq7-c).
		$shop_manager = get_role( 'shop_manager' );
		if ( $shop_manager ) {
			$shop_manager->add_cap( 'hph_view_reports' );
		}
	}

	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . 'hph_';

		// dbDelta is picky: two spaces before the PRIMARY KEY column list, one field per line, no backticks.
		dbDelta(
			"CREATE TABLE {$p}applications (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  email varchar(190) NOT NULL,
  name varchar(190) NOT NULL DEFAULT '',
  business_name varchar(190) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  phone varchar(50) NOT NULL DEFAULT '',
  message text NULL,
  fields_json longtext NULL,
  terms_version varchar(20) NOT NULL DEFAULT '',
  ip_hash char(32) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NULL,
  reviewer_id bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  internal_note text NULL,
  PRIMARY KEY  (id),
  KEY status (status),
  KEY email (email)
) $collate;
CREATE TABLE {$p}end_customers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  owner_user_id bigint(20) unsigned NOT NULL,
  first_name varchar(100) NOT NULL DEFAULT '',
  last_name varchar(100) NOT NULL DEFAULT '',
  company varchar(190) NOT NULL DEFAULT '',
  address_1 varchar(255) NOT NULL DEFAULT '',
  address_2 varchar(255) NOT NULL DEFAULT '',
  city varchar(100) NOT NULL DEFAULT '',
  state varchar(100) NOT NULL DEFAULT '',
  postcode varchar(20) NOT NULL DEFAULT '',
  country char(2) NOT NULL DEFAULT '',
  phone varchar(50) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  note text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  deleted_at datetime NULL,
  PRIMARY KEY  (id),
  KEY owner_user_id (owner_user_id)
) $collate;
CREATE TABLE {$p}log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  action varchar(50) NOT NULL,
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  before_json longtext NULL,
  after_json longtext NULL,
  ip_hash char(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY object (object_type,object_id),
  KEY actor_id (actor_id),
  KEY action (action)
) $collate;"
		);
	}

	public static function missing_tables(): array {
		global $wpdb;
		$missing = array();
		foreach ( self::TABLES as $name ) {
			$table = hph_table( $name );
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				$missing[] = $name;
			}
		}
		return $missing;
	}
}
