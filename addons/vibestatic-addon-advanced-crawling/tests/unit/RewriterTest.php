<?php
/**
 * @package WP2StaticAdvancedCrawling
 */

namespace WP2StaticAdvancedCrawling\Tests;

use PHPUnit\Framework\TestCase;
use WP2StaticAdvancedCrawling\Options;
use WP2StaticAdvancedCrawling\Rewriter;

class RewriterTest extends TestCase {

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['core_options'] = [
            'deploymentURL' => 'https://static.example.com',
            'skipURLRewrite' => '0',
        ];
    }

    private function withHosts( string $hosts ) : void {
        $GLOBALS['wpdb']->values = [ 'additionalHostsToRewrite' => $hosts ];

        Rewriter::setOptions(
            new Options(
                'wp2static_addon_advanced_crawling_options',
                [ 'additionalHostsToRewrite' => [ 'text', '' ] ]
            )
        );
    }

    /**
     * **The bug this add-on shipped with.** Upstream built its https pattern as
     * `'https:// ' . $host` — a space between the scheme and the host — so of
     * the four patterns it produced, the one for https matched nothing. On any
     * site served over TLS the feature did not work at all, and did so quietly.
     */
    public function testHttpsUrlsAreRewritten() : void {
        $this->withHosts( 'cdn.example.com' );

        $this->assertSame(
            '<img src="https://static.example.com/a.jpg">',
            Rewriter::rewriteFileContents( '<img src="https://cdn.example.com/a.jpg">' )
        );
    }

    public function testHttpUrlsAreRewritten() : void {
        $this->withHosts( 'cdn.example.com' );

        $this->assertSame(
            '<img src="https://static.example.com/a.jpg">',
            Rewriter::rewriteFileContents( '<img src="http://cdn.example.com/a.jpg">' )
        );
    }

    public function testProtocolRelativeUrlsAreRewritten() : void {
        $this->withHosts( 'cdn.example.com' );

        $this->assertSame(
            '<img src="//static.example.com/a.jpg">',
            Rewriter::rewriteFileContents( '<img src="//cdn.example.com/a.jpg">' )
        );
    }

    /**
     * Escaped slashes, which is how a URL appears inside inline JSON.
     */
    public function testEscapedUrlsInsideJsonAreRewritten() : void {
        $this->withHosts( 'cdn.example.com' );

        $this->assertSame(
            '{"src":"\\/\\/static.example.com\\/a.jpg"}',
            Rewriter::rewriteFileContents( '{"src":"\\/\\/cdn.example.com\\/a.jpg"}' )
        );
    }

    /**
     * Longest first: rewriting `example.com` before `cdn.example.com` would
     * leave `cdn.` stuck to the front of the destination URL.
     */
    public function testTheMoreSpecificHostWins() : void {
        $this->withHosts( "example.com\ncdn.example.com" );

        $this->assertSame(
            '<img src="https://static.example.com/a.jpg">',
            Rewriter::rewriteFileContents( '<img src="https://cdn.example.com/a.jpg">' )
        );
    }

    public function testAHostTypedAsAFullUrlIsReducedToItsHost() : void {
        $this->withHosts( 'https://cdn.example.com/' );

        $this->assertSame(
            '<img src="https://static.example.com/a.jpg">',
            Rewriter::rewriteFileContents( '<img src="https://cdn.example.com/a.jpg">' )
        );
    }

    public function testBlankLinesAreIgnored() : void {
        $this->withHosts( "\n\n  \ncdn.example.com\n\n" );

        $this->assertSame( [ 'cdn.example.com' ], Rewriter::hosts() );
    }

    public function testDuplicatesAreCollapsed() : void {
        $this->withHosts( "cdn.example.com\ncdn.example.com" );

        $this->assertSame( [ 'cdn.example.com' ], Rewriter::hosts() );
    }

    public function testNothingHappensWithNoHostsConfigured() : void {
        $this->withHosts( '' );

        $html = '<img src="https://cdn.example.com/a.jpg">';

        $this->assertSame( $html, Rewriter::rewriteFileContents( $html ) );
    }

    /**
     * The core's own switch is honoured: somebody who turned URL rewriting off
     * does not want this add-on rewriting either.
     */
    public function testSkipURLRewriteIsHonoured() : void {
        $this->withHosts( 'cdn.example.com' );

        $GLOBALS['core_options']['skipURLRewrite'] = '1';

        $html = '<img src="https://cdn.example.com/a.jpg">';

        $this->assertSame( $html, Rewriter::rewriteFileContents( $html ) );
    }

    public function testNothingHappensWithoutADeploymentURL() : void {
        $this->withHosts( 'cdn.example.com' );

        $GLOBALS['core_options']['deploymentURL'] = '';

        $html = '<img src="https://cdn.example.com/a.jpg">';

        $this->assertSame( $html, Rewriter::rewriteFileContents( $html ) );
    }
}
