<?php
/**
 * Remove this add-on's table when the plugin is deleted.
 *
 * `uninstall.php` runs without the plugin loaded, so there is no autoloader and
 * no Options object to ask: the table name is written out here, which is the
 * one place in the add-on that repeats it.
 *
 * @package WP2StaticAzure
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/** @var \wpdb $wpdb */
global $wpdb;

// prepare() with %i, not interpolation. Upstream wrote
// "DROP TABLE IF EXISTS $table_name" straight into query().
//
// And the result is checked: prepare() answers null when the placeholders and
// the arguments do not line up, and query( null ) is a TypeError on PHP 8.
$sql = $wpdb->prepare(
    'DROP TABLE IF EXISTS %i',
    $wpdb->prefix . 'wp2static_addon_azure_options'
);

if ( is_string( $sql ) ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the return of $wpdb->prepare() just above; the sniff cannot follow it through a variable, and the null check is why it has to be one.
    $wpdb->query( $sql );
}
