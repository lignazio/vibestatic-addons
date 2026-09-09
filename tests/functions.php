<?php
/**
 * The WordPress functions an add-on's own classes call directly.
 *
 * Deliberately short. A grep over every add-on's src says the whole list is
 * `__()`, `untrailingslashit()`, `wp_json_encode()`, the options API and the
 * transients API — everything else goes through the core, which `stubs.php`
 * stands in for by class, or through WP_Mock in a test that expects the call.
 *
 * **A file of its own, and outside PHPStan's paths.** Declaring these next to
 * the class stubs made PHPStan see two declarations of `__()` — its own, from
 * the WordPress stubs, and this one — and settle on `mixed` for the return of
 * both. Five hundred and ninety-three errors followed, every one of them of
 * the form "should return string but returns mixed", in code that had not
 * changed. The functions have to exist at run time and be invisible at
 * analysis time, so they live here and `phpstan.neon` does not look.
 *
 * @package VibeStaticAddons
 */

namespace {

    /**
     * WordPress's transient API, in memory.
     *
     * The GCS add-on caches its OAuth token in a transient, which is the right
     * thing to do — a service-account token is good for an hour and signing a
     * new JWT for every request would be both slower and noisier. It is also
     * the only WordPress function an add-on calls from a class a test drives
     * directly rather than through WP_Mock, so it needs to exist rather than be
     * expected.
     *
     * `function_exists()` because these are declared, not mocked. The store is
     * `$GLOBALS['transients']`, which is the name ServiceAccountTest already
     * empties in its setUp() — it was written against a stub that was never
     * there, so the caching test passed or failed depending on what had run
     * before it.
     */
    if ( ! function_exists( 'get_transient' ) ) {

        $GLOBALS['transients'] = [];

        /**
         * @param string $key Transient name.
         * @return mixed The stored value, or false.
         */
        function get_transient( string $key ) {
            return $GLOBALS['transients'][ $key ] ?? false;
        }

        /**
         * @param string $key        Transient name.
         * @param mixed  $value      What to store.
         * @param int    $expiration Ignored: nothing here waits an hour.
         */
        function set_transient( string $key, $value, int $expiration = 0 ) : bool {
            unset( $expiration );

            $GLOBALS['transients'][ $key ] = $value;

            return true;
        }

        /**
         * @param string $key Transient name.
         */
        function delete_transient( string $key ) : bool {
            unset( $GLOBALS['transients'][ $key ] );

            return true;
        }
    }

    /**
     * The rest of what an add-on's own classes call directly.
     *
     * Deliberately short. A grep over every add-on's src says the whole list is
     * `__()`, `untrailingslashit()`, `wp_json_encode()` and the transients
     * above — everything else an add-on touches goes through the core, which
     * is stubbed by class further up, or through WP_Mock in a test that
     * expects the call.
     */
    if ( ! function_exists( 'wp_json_encode' ) ) {

        /**
         * @param mixed $data    What to encode.
         * @param int   $options json_encode flags.
         * @return string|false
         */
        function wp_json_encode( $data, int $options = 0 ) {
            return json_encode( $data, $options );
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

    if ( ! function_exists( 'get_option' ) ) {

        $GLOBALS['wp_options'] = [];

        /**
         * WordPress's options API, in memory.
         *
         * The store is `$GLOBALS['wp_options']`, which is the name
         * ControllerTest writes into directly — another test written against a
         * stub that was not there.
         *
         * @param string $name    Option name.
         * @param mixed  $default_value What to answer when it is not set.
         * @return mixed
         */
        function get_option( string $name, $default_value = false ) {
            return $GLOBALS['wp_options'][ $name ] ?? $default_value;
        }

        /**
         * @param string $name  Option name.
         * @param mixed  $value What to store.
         */
        function update_option( string $name, $value ) : bool {
            $GLOBALS['wp_options'][ $name ] = $value;

            return true;
        }
    }

    if ( ! function_exists( '__' ) ) {

        /**
         * @param string $text   The string.
         * @param string $domain Text domain, ignored here.
         */
        function __( string $text, string $domain = 'default' ) : string {
            unset( $domain );

            return $text;
        }
    }
}
