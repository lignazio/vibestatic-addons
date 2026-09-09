<?php
/**
 * Two WordPress functions the add-on bootstrap reaches through the core.
 *
 * `WP2Static\Addon\Updater::register()` asks `get_file_data()` for the plugin's
 * `Update URI` header and `plugin_basename()` for the name WordPress knows the
 * plugin by. Both are global, so the namespaced stubs a test declares for its
 * own bootstrap do not cover them.
 *
 * A file of its own, required only by CoreGuardTest — which runs in a process
 * of its own — rather than added to `functions.php`, where it would be declared
 * for every test and would collide with the WP_Mock expectations
 * AddonUpdaterTest sets on the same two functions.
 *
 * @package VibeStaticAddons
 */

/*
 * WordPress's time constants. Addon\Updater writes `12 * HOUR_IN_SECONDS` for
 * its transient's lifetime, and without these that is an undefined constant
 * inside the core rather than inside the test.
 */
foreach (
    [
		'MINUTE_IN_SECONDS' => 60,
		'HOUR_IN_SECONDS' => 3600,
		'DAY_IN_SECONDS' => 86400,
		'WEEK_IN_SECONDS' => 604800,
	] as $constant => $seconds
) {
    if ( ! defined( $constant ) ) {
        define( $constant, $seconds );
    }
}

if ( ! function_exists( 'get_file_data' ) ) {

    /**
     * @param string                $file    Ignored.
     * @param array<string, string> $headers Header name by key.
     * @param string                $context Ignored.
     * @return array<string, string>
     */
    function get_file_data( string $file, array $headers, string $context = '' ) : array {
        unset( $file, $context );

        return array_map(
            static function () : string {
                return 'https://github.com/lignazio/vibestatic-addons';
            },
            $headers
        );
    }
}

if ( ! function_exists( 'plugin_basename' ) ) {

    /**
     * @param string $file Absolute path of a plugin file.
     */
    function plugin_basename( string $file ) : string {
        return basename( dirname( $file ) ) . '/' . basename( $file );
    }
}

if ( ! function_exists( 'add_filter' ) ) {

    /**
     * @param string   $hook     Filter name.
     * @param callable $callback What to call.
     * @param int      $priority Ignored.
     * @param int      $args     Ignored.
     */
    function add_filter( string $hook, $callback, int $priority = 10, int $args = 1 ) : bool {
        unset( $hook, $callback, $priority, $args );

        return true;
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {

    /**
     * @param string $url       The URL.
     * @param int    $component Which part, or -1 for all.
     * @return mixed
     */
    function wp_parse_url( string $url, int $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( 'untrailingslashit' ) ) {

    /**
     * @param string $value A path or URL.
     */
    function untrailingslashit( string $value ) : string {
        return rtrim( $value, '/\\' );
    }
}
