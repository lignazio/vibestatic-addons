<?php
/**
 * The add-on's autoloader.
 *
 * Four lines of `spl_autoload_register` rather than a `vendor/` of its own,
 * because **this add-on has no runtime dependencies**. Upstream it declared
 * Guzzle and shipped a copy; VibeStatic already carries one, prefixed into
 * `WP2Static\Vendor\`, and two plugins shipping incompatible copies of the same
 * library under the same class names is how one of them breaks the other. The
 * add-on uses the core's.
 *
 * The practical consequence is that this plugin installs from a plain zip:
 * there is nothing to `composer install`.
 *
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket;

spl_autoload_register(
    function ( string $class_name ) : void {
        $prefix = __NAMESPACE__ . '\\';

        if ( 0 !== strpos( $class_name, $prefix ) ) {
            return;
        }

        $relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
        $file = __DIR__ . '/src/' . $relative . '.php';

        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);
