<?php
/**
 * Plugin Name:       VibeStatic Add-on: GitHub Deployment
 * Plugin URI:        https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-github
 * Description:       Commits the generated site to a GitHub repository.
 * Update URI:        https://github.com/lignazio/vibestatic-addons
 * Version:           1.0.0
 * Requires PHP:      8.2
 * Requires at least: 6.5
 * Author:            Ignazio Lucenti
 * Author URI:        https://lucenti.studio
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vibestatic
 *
 * @package WP2StaticGitHub
 */

namespace WP2StaticGitHub;

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

require_once __DIR__ . '/autoload.php';

/**
 * The tag that carries this add-on's releases.
 *
 * The repository holds ten plugins, so a release is `bunnycdn-v1.0.1` and not
 * `v1.0.1`, and `WP2Static\Addon\Updater` looks for this prefix. It is the
 * add-on's directory name with `vibestatic-addon-` taken off — the same shape
 * as everything else that identifies it.
 */
const TAG_PREFIX = 'github';

/**
 * The name this add-on goes by when it has to explain itself.
 *
 * A constant because renderCoreNotice() below runs when the add-on's own
 * classes cannot be touched, so it cannot ask the Controller for its name.
 */
const ADDON_NAME = 'VibeStatic GitHub';

/**
 * The core this add-on is built against.
 *
 * The series, `9.0`, and not a patch level: an add-on needs an API, and the
 * fork's promise is that WP2Static\Addon\ does not change under it without a
 * major version. Written as `9.0` so a `9.0.0-rc1` or a `9.0.0-dev` core
 * satisfies it — version_compare() ranks a prerelease below the release it
 * leads to, so requiring `9.0.0` would refuse to run against the very builds
 * this add-on is developed on.
 */
const REQUIRES_CORE = '9.0';

/**
 * Whether the core is there and new enough.
 *
 * **This has to be plain PHP, and it has to run before anything else in this
 * add-on is touched.** The Controller below `extends
 * \WP2Static\Addon\Controller`, so merely autoloading it on a core that does
 * not have that class is a fatal error — and it happens during
 * `plugins_loaded`, which means a white screen with no way back except editing
 * the database. Nothing here may reference the add-on's own classes, and
 * nothing here may be moved into a shared file, because a shared file would
 * have to be loaded from the thing whose absence this exists to survive.
 */
function coreIsUsable() : bool {
    return class_exists( '\\WP2Static\\Addon\\Controller' )
        && '' !== coreVersion()
        && version_compare( coreVersion(), REQUIRES_CORE, '>=' );
}

/**
 * The installed core's version, or the empty string when there is not one.
 *
 * `constant()` answers mixed, and casting it would be asserting something
 * about a constant this add-on does not own: a site with
 * `define( 'VIBESTATIC_VERSION', 9 )` in wp-config.php is not impossible, and
 * `(string) 9` would then be compared as a version. Asking whether it is a
 * string is both the honest check and the one that survives analysis.
 */
function coreVersion() : string {
    if ( ! defined( 'VIBESTATIC_VERSION' ) ) {
        return '';
    }

    $version = constant( 'VIBESTATIC_VERSION' );

    return is_string( $version ) ? $version : '';
}

add_action(
    'plugins_loaded',
    function () : void {
        if ( ! coreIsUsable() ) {
            add_action( 'admin_notices', __NAMESPACE__ . '\\renderCoreNotice' );

            return;
        }

        Controller::boot();

        /*
         * Without this the add-on never updates. `Update URI` in the header
         * tells WordPress NOT to look on wordpress.org — rightly, the slug is
         * not ours over there — and points it here instead; this is what
         * answers. The core's own Updater cannot: it asks for the repository's
         * latest release, which in a repository of ten plugins is somebody
         * else's.
         *
         * This add-on's own class, not `\WP2Static\Addon\Updater`, which is
         * where it lived until 1.1.0. A core installed from the wordpress.org
         * directory ships an inert shim at that name and nothing more — the
         * directory forbids a plugin hosted there from serving updates for
         * anything, this repository included — so an add-on that asked the core
         * would simply stop updating the day its user installed the core from
         * WordPress rather than from GitHub. `src/Updater.php` is generated from
         * one template for all ten; see tools/sync_updater.php.
         */
        Updater::register( __FILE__, TAG_PREFIX, ADDON_NAME );
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
function renderCoreNotice() : void {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    $installed = coreVersion();

    $message = '' === $installed
        ? sprintf(
            /* translators: %s: this add-on's name. */
            __( 'The %s add-on needs the VibeStatic plugin, which is not active.', 'vibestatic' ),
            ADDON_NAME
        )
        : sprintf(
            /* translators: 1: this add-on's name. 2: required VibeStatic version. 3: installed VibeStatic version. */
            __(
                'The %1$s add-on needs VibeStatic %2$s or later. This site has %3$s.',
                'vibestatic'
            ),
            ADDON_NAME,
            REQUIRES_CORE,
            $installed
        );

    printf(
        '<div class="notice notice-warning"><p>%s</p><p>%s</p></div>',
        esc_html( $message ),
        esc_html__(
            'Once VibeStatic is in place, deactivate and reactivate this add-on so it can create its options table.',
            'vibestatic'
        )
    );
}

/*
 * Guarded, because the callback autoloads the Controller — and the Controller
 * extends a core class. Without the check, activating this add-on on a site
 * without VibeStatic is a fatal error during activation rather than a notice.
 */
register_activation_hook(
    __FILE__,
    function ( ?bool $network_wide = null ) : void {
        if ( ! coreIsUsable() ) {
            return;
        }

        Controller::activate( $network_wide );
    }
);
