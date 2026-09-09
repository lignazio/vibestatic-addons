<?php
/**
 * Plugin Name:       VibeStatic Add-on: Bitbucket Deployment
 * Plugin URI:        https://github.com/lignazio/vibestatic-addon-bitbucket
 * Description:       Commits the generated site to a Bitbucket repository.
 * Version:           1.0.0
 * Requires PHP:      8.2
 * Requires at least: 6.5
 * Author:            Ignazio Lucenti
 * Author URI:        https://lucenti.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibestatic
 *
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket;

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

require_once __DIR__ . '/autoload.php';

/*
 * On `plugins_loaded`, not at file scope.
 *
 * WordPress includes plugin files in alphabetical order, so an add-on can load
 * before the core it extends. Upstream constructed its Controller immediately,
 * which on a site where the core sorted later meant a fatal error on
 * `WP2Static\...` — and, because that happens during activation, a white
 * screen with no way back except editing the database.
 *
 * Priority 15 leaves room for the core's own boot at the default 10.
 */
add_action(
    'plugins_loaded',
    function () : void {
        if ( ! class_exists( '\\WP2Static\\Controller' ) ) {
            add_action( 'admin_notices', __NAMESPACE__ . '\\renderMissingCoreNotice' );

            return;
        }

        Controller::boot();
    },
    15
);

/**
 * Say why the add-on is doing nothing.
 *
 * Upstream said nothing at all: without the core the add-on either crashed or
 * sat there inert, and the Add-ons page — which lives in the core — was not
 * there to be looked at either.
 */
function renderMissingCoreNotice() : void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html__(
            'The VibeStatic Bitbucket add-on needs the VibeStatic plugin, which is not active.',
            'vibestatic'
        )
    );
}

register_activation_hook( __FILE__, [ Controller::class, 'activate' ] );
