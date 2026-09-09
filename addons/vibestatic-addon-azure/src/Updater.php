<?php
/**
 * Updates for this add-on, installed from a zip. GENERATED FILE — DO NOT EDIT.
 *
 * `tools/sync_updater.php` writes this from `tools/updater-template.php`, one
 * copy per add-on with only the namespace differing, and `verify.php` fails if
 * any copy has drifted from the template. Edit the template.
 *
 * It used to be `WP2Static\Addon\Updater`, in the core. That could not last:
 * a core installed from the wordpress.org directory does not have it, because
 * guideline 8 forbids a plugin hosted there from "serving updates or otherwise
 * installing plugins, themes, or add-ons from servers other than
 * WordPress.org's". The core's package for the directory ships an inert shim at
 * that class name so an add-on at 1.0.0 does not fatal — and from 1.1.0 an
 * add-on does not ask the core at all. Its updates are its own business, and
 * this repository is not in the directory.
 *
 * Ten copies of one file, and that is deliberate. Each add-on is a separate
 * plugin and its zip has to stand alone: there is nowhere shared to put this
 * that ships with all ten. What made the ancestral duplication a defect was
 * that it was copied by hand and then diverged; this is generated, and the
 * divergence is what the check exists to prevent.
 *
 * One request serves every add-on installed all the same: the release list is
 * cached in a transient keyed by the repository, so the first of the ten to ask
 * fetches it and the other nine read it.
 *
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


class Updater {

    /**
     * @var int How long a good answer is kept.
     */
    const TTL_OK = 12 * HOUR_IN_SECONDS;

    /**
     * @var int How long a miss is kept.
     *
     * Failure is remembered too, and that is half the work: a repository that
     * is not there, or GitHub's rate limit, would otherwise restart the call on
     * every update check.
     */
    const TTL_FAIL = HOUR_IN_SECONDS;

    /**
     * @var array<string, array{prefix: string, uri: string, name: string, slug: string}>
     *      Plugin basename => what is needed to answer for it.
     */
    private static $addons = [];

    /**
     * @var bool Whether the filters are on.
     */
    private static $hooked = false;

    /**
     * An add-on asks to be kept up to date.
     *
     * Called from the add-on's main file, where `__FILE__` is its own:
     *
     *     Updater::register( __FILE__, 'bunnycdn', 'BunnyCDN' );
     *
     * @param string $plugin_file The add-on's main file, i.e. `__FILE__`.
     * @param string $prefix      Tag prefix, so `bunnycdn` for `bunnycdn-v1.0.0`.
     * @param string $name        What to call it in the update details panel.
     */
    public static function register( string $plugin_file, string $prefix, string $name ) : void {
        $data = get_file_data( $plugin_file, [ 'UpdateURI' => 'Update URI' ] );
        $uri = untrailingslashit( $data['UpdateURI'] );

        if ( '' === $uri ) {
            return;
        }

        $basename = plugin_basename( $plugin_file );

        self::$addons[ $basename ] = [
            'prefix' => $prefix,
            'uri' => $uri,
            'name' => $name,
            // The slug WordPress knows the plugin by: its directory.
            'slug' => dirname( $basename ),
        ];

        if ( self::$hooked ) {
            return;
        }

        self::$hooked = true;

        $host = wp_parse_url( $uri, PHP_URL_HOST );

        if ( ! is_string( $host ) || '' === $host ) {
            return;
        }

        add_filter( "update_plugins_$host", [ self::class, 'checkForUpdate' ], 10, 3 );
        add_filter( 'plugins_api', [ self::class, 'pluginInformation' ], 10, 3 );
    }

    /**
     * Answer WordPress for one of ours and for nobody else's.
     *
     * The filter name carries only the host, so `update_plugins_github.com` is
     * shared with every installed plugin whose `Update URI` is on GitHub. A
     * callback answering for all of them would redirect other people's updates
     * to our releases — hence the lookup by plugin file, and `$update` returned
     * untouched when it is none of our business.
     *
     * @param array<string, mixed>|false $update      Whatever came before us.
     * @param array<string, string>      $plugin_data Headers of the plugin being asked about.
     * @param string                     $plugin_file Its main file, as a basename.
     * @return array<string, mixed>|false
     */
    public static function checkForUpdate( $update, array $plugin_data, string $plugin_file ) {
        if ( ! isset( self::$addons[ $plugin_file ] ) ) {
            return $update;
        }

        $addon = self::$addons[ $plugin_file ];

        if ( ! isset( $plugin_data['UpdateURI'] )
            || untrailingslashit( $plugin_data['UpdateURI'] ) !== $addon['uri']
        ) {
            return $update;
        }

        $release = self::latestRelease( $addon['uri'], $addon['prefix'] );

        if ( ! $release ) {
            return $update;
        }

        return [
            'id' => $addon['uri'],
            'slug' => $addon['slug'],
            'plugin' => $plugin_file,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => '8.2',
            'tested' => '',
        ];
    }

    /**
     * Fill in the "View version details" panel.
     *
     * Without it that link opens a modal which asks wordpress.org about a slug
     * that does not exist there, and shows an error: the update would work, but
     * the one thing a user can click before installing it would not.
     *
     * @param object|array<string,mixed>|false $result Whatever came before us.
     * @param string                           $action What WordPress is asking for.
     * @param object                           $args   Arguments, including the slug.
     * @return object|array<string,mixed>|false
     */
    public static function pluginInformation( $result, string $action, $args ) {
        if ( 'plugin_information' !== $action || ! isset( $args->slug ) ) {
            return $result;
        }

        foreach ( self::$addons as $addon ) {
            if ( $addon['slug'] !== $args->slug ) {
                continue;
            }

            $release = self::latestRelease( $addon['uri'], $addon['prefix'] );

            if ( ! $release ) {
                return $result;
            }

            return (object) [
                'name' => $addon['name'],
                'slug' => $addon['slug'],
                'version' => $release['version'],
                'author' => '<a href="https://lucenti.studio">Ignazio Lucenti</a>',
                'homepage' => $addon['uri'],
                'requires' => '6.5',
                'requires_php' => '8.2',
                'download_link' => $release['package'],
                'sections' => [
                    'changelog' => $release['notes'],
                ],
            ];
        }

        return $result;
    }

    /**
     * The newest release for one add-on, or null.
     *
     * @param  string $uri    The repository, as declared in `Update URI`.
     * @param  string $prefix Tag prefix, so `bunnycdn` for `bunnycdn-v1.0.0`.
     * @return array{version: string, url: string, package: string, notes: string}|null
     */
    private static function latestRelease( string $uri, string $prefix ) : ?array {
        foreach ( self::releases( $uri ) as $release ) {
            if ( ! is_array( $release ) ) {
                continue;
            }

            $found = self::asUpdate( $release, $prefix, $uri );

            if ( $found ) {
                return $found;
            }
        }

        return null;
    }

    /**
     * One release, if it is this add-on's and installable.
     *
     * @param  array<mixed> $release One entry from GitHub's list. Decoded JSON,
     *                               so every key on it is checked before use.
     * @param  string       $prefix  Tag prefix this add-on's releases carry.
     * @param  string       $uri     The repository, for the fallback link.
     * @return array{version: string, url: string, package: string, notes: string}|null
     */
    private static function asUpdate( array $release, string $prefix, string $uri ) : ?array {
        if ( ! isset( $release['tag_name'] ) || ! is_string( $release['tag_name'] ) ) {
            return null;
        }

        $tag = $release['tag_name'];

        /*
         * `bunnycdn-v`, anchored. Without the anchor and the `v`,
         * `advanced-crawling` would match a tag belonging to an add-on whose
         * name merely ends the same way, and a draft `bunnycdn-notes` tag would
         * count as a release.
         */
        if ( 0 !== strpos( $tag, $prefix . '-v' ) ) {
            return null;
        }

        // A prerelease is not offered to a production site: that is what an
        // -alpha, -beta or -rc is for.
        if ( ! empty( $release['prerelease'] ) || ! empty( $release['draft'] ) ) {
            return null;
        }

        $package = self::zipAssetUrl( $release );

        /*
         * No asset, no update — and no falling back on the zipball GitHub
         * generates itself. That one unpacks into `lignazio-vibestatic-addons-<sha>`,
         * so WordPress would install the whole monorepo as one plugin.
         */
        if ( ! $package ) {
            return null;
        }

        return [
            'version' => substr( $tag, strlen( $prefix ) + 2 ),
            'url' => isset( $release['html_url'] ) && is_string( $release['html_url'] )
                ? $release['html_url']
                : $uri,
            'package' => $package,
            'notes' => isset( $release['body'] ) && is_string( $release['body'] )
                ? wp_kses_post( nl2br( esc_html( $release['body'] ) ) )
                : '',
        ];
    }

    /**
     * The repository's releases, newest first, remembered between checks.
     *
     * One fetch per repository, not one per add-on: the transient is keyed by
     * the repository so ten installed add-ons share a single request. That is
     * why it survived each add-on getting a class of its own — the cache is
     * shared even though the code is not.
     *
     * @param  string $uri The repository, as declared in `Update URI`.
     * @return list<mixed>
     */
    private static function releases( string $uri ) : array {
        $key = 'vibestatic_addon_releases_' . md5( $uri );

        $cached = get_transient( $key );

        if ( is_array( $cached ) ) {
            // array_values() and not a cast: what comes back from a transient
            // is whatever was serialised into it, and the return type here says
            // list.
            return array_values( $cached );
        }

        if ( 'none' === $cached ) {
            return [];
        }

        $releases = self::fetchReleases( $uri );

        if ( ! $releases ) {
            set_transient( $key, 'none', self::TTL_FAIL );

            return [];
        }

        set_transient( $key, $releases, self::TTL_OK );

        return $releases;
    }

    /**
     * @param  string $uri The repository, as declared in `Update URI`.
     * @return list<mixed>
     */
    private static function fetchReleases( string $uri ) : array {
        $path = (string) wp_parse_url( $uri, PHP_URL_PATH );

        $response = wp_remote_get(
            'https://api.github.com/repos' . untrailingslashit( $path ) . '/releases?per_page=100',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ],
            ]
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return [];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        return is_array( $body ) ? array_values( $body ) : [];
    }

    /**
     * @param array<mixed> $release One release.
     */
    private static function zipAssetUrl( array $release ) : ?string {
        if ( ! isset( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
            return null;
        }

        foreach ( $release['assets'] as $asset ) {
            if ( ! is_array( $asset )
                || ! isset( $asset['browser_download_url'] )
                || ! is_string( $asset['browser_download_url'] )
            ) {
                continue;
            }

            if ( str_ends_with( $asset['browser_download_url'], '.zip' ) ) {
                return $asset['browser_download_url'];
            }
        }

        return null;
    }

    /**
     * Forget everything registered. For tests.
     */
    public static function reset() : void {
        self::$addons = [];
        self::$hooked = false;
    }
}
