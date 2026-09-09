<?php
/**
 * Additional host rewriting.
 *
 * **What became of the add-on this replaces.** `wp2static-addon-advanced-crawling`
 * carried its own Crawler, CrawlQueue, Detection and Rewriter: in 2021 it did
 * not extend the core, it substituted it, because the core's crawler was
 * sequential and did not follow links. VibeStatic 8 crawls in parallel, follows
 * links, keeps a crawl cache and prunes what it has published, so substituting
 * it would be a downgrade.
 *
 * Of its thirteen options, nine are core options now — addURLsWhileCrawling,
 * crawlChunkSize, crawlProgressReportInterval, detectRedirectionPluginURLs,
 * fileExtensionsToIgnore, filenamesToIgnore, additionalPathsToCrawl,
 * crawlConcurrency, useCrawlCaching — and its Redirection-plugin detection is
 * `WP2Static\DetectRedirectionPluginURLs`. What is left, and is not anywhere in
 * the core, is rewriting hosts other than the WordPress site's own.
 *
 * The slug stays `wp2static-addon-advanced-crawling`: it keys the row in the
 * add-ons table and the name of the options table on installations that already
 * have it. The name it shows says what it now does.
 *
 * @package WP2StaticAdvancedCrawling
 */

namespace WP2StaticAdvancedCrawling;

use WP2Static\Addon\Options;

class Controller extends \WP2Static\Addon\Controller {

    /**
     * @var Options|null
     */
    private $options = null;

    public function slug() : string {
        return 'wp2static-addon-advanced-crawling';
    }

    public function type() : string {
        return 'post_process';
    }

    public function name() : string {
        return __( 'Additional Host Rewriting', 'vibestatic' );
    }

    public function description() : string {
        return __(
            'Rewrites hosts other than the WordPress site — a CDN, a second domain — to the deployment URL.',
            'vibestatic'
        );
    }

    public function docsUrl() : string {
        return 'https://github.com/lignazio/vibestatic-addons/tree/main/addons/vibestatic-addon-advanced-crawling';
    }

    public function options() : Options {
        if ( null === $this->options ) {
            $this->options = new Options(
                'wp2static_addon_advanced_crawling_options',
                [
                    'additionalHostsToRewrite' => [ 'text', '' ],
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
            'additionalHostsToRewrite' => [
                __( 'Additional hosts to rewrite', 'vibestatic' ),
                __(
                    'One host per line, without a scheme — cdn.example.com. Each is replaced with the deployment URL, over both http and https and protocol-relative.',
                    'vibestatic'
                ),
            ],
        ];
    }

    protected function intro() : string {
        return __(
            'The core already rewrites the WordPress site\'s own URL. This is for the other hosts a page can point at.',
            'vibestatic'
        );
    }

    protected function registerHooks() : void {
        Rewriter::setOptions( $this->options() );

        /*
         * Priority 100, after the core's own rewriting at the default. The
         * order matters: the core turns the WordPress site URL into the
         * deployment URL, and this then does the same for the extra hosts.
         */
        foreach ( [ 'html', 'css', 'js', 'xml', 'robots_txt' ] as $kind ) {
            add_action( 'wp2static_process_' . $kind, [ Rewriter::class, 'rewrite' ], 100, 1 );
        }

        if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) ) {
            \WP_CLI::add_command( 'vibestatic advanced-crawling', [ CLI::class, 'command' ] );
        }
    }
}
