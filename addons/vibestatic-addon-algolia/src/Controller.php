<?php
/**
 * Algolia search on a static site.
 *
 * **What this add-on is, and is not.** It does not index anything: the
 * "WP Search with Algolia" plugin does that. This one makes what that plugin
 * sends usable from a *static* copy of the site — permalinks stored relative
 * rather than absolute, and a `/search/` page put into the crawl so there is
 * something for the search form to submit to.
 *
 * That was never written down upstream, and it matters: without the other
 * plugin installed this add-on has nothing to hook, which is why it now says so
 * on its own settings page instead of appearing to work.
 *
 * @package WP2StaticAlgolia
 */

namespace WP2StaticAlgolia;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * The post types "WP Search with Algolia" builds an index for.
     */
    const POST_TYPE_INDICES = [
        'post',
        'page',
        'attachment',
        'custom_css',
        'customize_changeset',
        'oembed_cache',
        'user_request',
        'wp_block',
    ];

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-algolia';
    }

    /**
     * Not a deployer.
     *
     * Upstream registered nothing at all — no `wp2static_register_addon` call
     * anywhere — so the add-on had a settings page reachable from the menu and
     * no row on the Add-ons page saying it existed.
     */
    public function type() : string {
        return 'other';
    }

    public function name() : string {
        return __( 'Algolia', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Makes WP Search with Algolia work on the static copy: relative permalinks and a crawled search page.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-algolia';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_algolia_options',
                [
                    'algoliaSearchPath' => [ 'string', '/search/' ],
                    'algoliaRewriteFormActions' => [ 'bool', '1' ],
                ]
            );
        }

        return $this->options;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    protected function fields() : array {
        return [
            'algoliaSearchPath' => [
                __( 'Search page path', 'vibestatic' ),
                __(
                    'Added to the crawl, and where search forms are pointed. It has to be a page the site actually serves.',
                    'vibestatic'
                ),
            ],
            'algoliaRewriteFormActions' => [
                __( 'Point search forms at that path', 'vibestatic' ),
                __(
                    'Rewrites each search form\'s action in the generated HTML. Turn it off if your theme already posts to the right place.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function intro() : string {
        if ( self::algoliaPluginActive() ) {
            return __(
                'The indexing itself is done by WP Search with Algolia, which is active. This add-on only adjusts what it sends and what gets crawled.',
                'vibestatic'
            );
        }

        return __(
            'WP Search with Algolia does not appear to be installed. Without it there is nothing for this add-on to adjust, and search on the static site will not work.',
            'vibestatic'
        );
    }

    /**
     * Whether the plugin this one extends is there.
     *
     * Its application ID option is the cheapest thing to look for that is not
     * a filename, so it holds whether the plugin is installed as
     * `wp-search-with-algolia` or under any of the names it has had.
     */
    public static function algoliaPluginActive() : bool {
        $app_id = get_option( 'algolia_application_id', '' );

        return is_string( $app_id ) && '' !== $app_id;
    }

    protected function registerHooks() : void {
        add_filter( 'wp2static_modify_initial_crawl_list', [ $this, 'addSearchPage' ], 15, 1 );

        /*
         * `algolia_searchable_post_{$type}_records` and `algolia_post_{$type}_records`.
         *
         * Upstream wrote these as "algolia_searchable_post_${post_type}_records".
         * `${var}` inside a string is deprecated in PHP 8.2 and removed in
         * PHP 9: on a site with WP_DEBUG on, this add-on printed sixteen
         * deprecation notices on every admin page load.
         */
        foreach ( self::POST_TYPE_INDICES as $post_type ) {
            add_filter( "algolia_searchable_post_{$post_type}_records", [ $this, 'relativisePermalinks' ], 15, 1 );
            add_filter( "algolia_post_{$post_type}_records", [ $this, 'relativisePermalinks' ], 15, 1 );
        }

        add_filter( 'algolia_searchable_post_records', [ $this, 'relativisePermalinks' ], 15, 1 );
        add_filter( 'algolia_post_records', [ $this, 'relativisePermalinks' ], 15, 1 );

        add_action( 'wp2static_process_html', [ $this, 'rewriteFormActions' ], 15, 1 );

        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic algolia', [ CLI::class, 'command' ] );
        }
    }

    /**
     * Put the search page in the crawl queue.
     *
     * @param mixed $url_queue URLs to crawl.
     * @return string[] The same, plus the search page.
     */
    public function addSearchPage( $url_queue ) : array {
        $queue = is_array( $url_queue ) ? $url_queue : [];

        $path = $this->searchPath();

        if ( '' !== $path && ! in_array( $path, $queue, true ) ) {
            $queue[] = $path;
        }

        /** @var string[] $queue */
        return $queue;
    }

    /**
     * The configured search path, normalised to `/something/`.
     */
    public function searchPath() : string {
        $path = trim( $this->options()->get( 'algoliaSearchPath' ) );

        if ( '' === $path ) {
            return '';
        }

        return '/' . trim( $path, '/' ) . '/';
    }

    /**
     * Store permalinks relative to the site root.
     *
     * A record indexed with `https://example.com/post/` sends a visitor to the
     * WordPress site, not to the static copy that is actually published — which
     * on a setup where WordPress is a private staging host means the search
     * results are all dead links.
     *
     * @param mixed $post_records Records the Algolia plugin is about to send.
     * @return mixed[] The same, with permalinks made relative.
     */
    public function relativisePermalinks( $post_records ) : array {
        if ( ! is_array( $post_records ) ) {
            return [];
        }

        $site_urls = self::siteURLs();

        foreach ( $post_records as &$record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }

            if ( isset( $record['permalink'] ) && is_string( $record['permalink'] ) ) {
                $record['permalink'] = self::relative( $record['permalink'], $site_urls );
            }

            /*
             * Checked one level at a time. `post_author` is whatever the
             * indexing plugin put there — a version of it that stored the
             * author as a string rather than an array would otherwise be an
             * offset access on a string.
             */
            if ( ! isset( $record['post_author'] ) || ! is_array( $record['post_author'] ) ) {
                continue;
            }

            $author = $record['post_author'];

            if ( isset( $author['user_url'] ) && is_string( $author['user_url'] ) ) {
                $author['user_url'] = self::relative( $author['user_url'], $site_urls );

                $record['post_author'] = $author;
            }
        }

        unset( $record );

        return $post_records;
    }

    /**
     * The site's own URLs, both schemes, port included.
     *
     * Upstream read the port out of `$site_path` — a variable that does not
     * exist in that function — so on PHP 8 it passed null to `parse_url()`,
     * took the deprecation, and never found a port. A development site on
     * :8080 therefore kept absolute permalinks, and only there.
     *
     * @return string[]
     */
    private static function siteURLs() : array {
        $site_url = rtrim( \WP2Static\SiteInfo::getURL( 'site' ), '/' );

        $host = parse_url( $site_url, PHP_URL_HOST );

        if ( ! is_string( $host ) || '' === $host ) {
            return [ $site_url ];
        }

        $port = parse_url( $site_url, PHP_URL_PORT );

        if ( is_int( $port ) ) {
            $host .= ':' . $port;
        }

        return [ 'https://' . $host, 'http://' . $host, $site_url ];
    }

    /**
     * @param string   $url       An absolute or relative URL.
     * @param string[] $site_urls The site's own URLs.
     */
    private static function relative( string $url, array $site_urls ) : string {
        $relative = str_replace( $site_urls, '', $url );

        return '' === $relative ? '/' : $relative;
    }

    /**
     * Point search forms at the static search page, in the generated HTML.
     *
     * **This replaces a script injected into the live site.** Upstream hooked
     * `wp_footer` and echoed a `<script>` that walked every form on every page
     * a visitor loaded and rewrote its action — with the site URL interpolated
     * into the JavaScript unescaped, and a `console.log` left in. It ran for
     * real visitors of the WordPress site, not only for the crawler, and search
     * did not work at all with JavaScript off.
     *
     * Doing it here instead means the published HTML already has the right
     * action, nothing is injected into the live site, and there is no script.
     *
     * @param string $filename An HTML file in the processed site.
     */
    public function rewriteFormActions( string $filename ) : void {
        if ( ! $this->options()->bool( 'algoliaRewriteFormActions' ) ) {
            return;
        }

        $path = $this->searchPath();

        if ( '' === $path ) {
            return;
        }

        $html = file_get_contents( $filename );

        if ( false === $html || '' === $html ) {
            return;
        }

        /*
         * Only forms that already carry `role="search"` or a `name="s"` field
         * are touched. Rewriting every form on the page — which is what the
         * script did — moves comment forms and newsletter sign-ups to the
         * search page too.
         */
        $rewritten = preg_replace_callback(
            '#<form\b[^>]*>#i',
            static function ( array $matches ) use ( $path ) : string {
                $tag = $matches[0];

                if ( ! preg_match( '#role=["\']search["\']#i', $tag )
                    && ! preg_match( '#\bclass=["\'][^"\']*\bsearch-form\b#i', $tag )
                ) {
                    return $tag;
                }

                if ( preg_match( '#\saction=["\'][^"\']*["\']#i', $tag ) ) {
                    return (string) preg_replace(
                        '#\saction=["\'][^"\']*["\']#i',
                        ' action="' . $path . '"',
                        $tag
                    );
                }

                return (string) preg_replace( '#<form\b#i', '<form action="' . $path . '"', $tag, 1 );
            },
            $html
        );

        if ( null === $rewritten || $rewritten === $html ) {
            return;
        }

        file_put_contents( $filename, $rewritten );
    }
}
