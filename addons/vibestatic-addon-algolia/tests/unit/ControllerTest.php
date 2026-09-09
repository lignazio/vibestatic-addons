<?php
/**
 * @package WP2StaticAlgolia
 */

namespace WP2StaticAlgolia\Tests;

use PHPUnit\Framework\TestCase;
use WP2StaticAlgolia\Controller;
use VibeStatic\Tests\FakeWpdb;

class ControllerTest extends TestCase {

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['site_url'] = 'https://wp.example.com/';
        $GLOBALS['wp_options'] = [];
    }

    private function controller() : Controller {
        $GLOBALS['wpdb']->values = [
            'algoliaSearchPath' => '/search/',
            'algoliaRewriteFormActions' => '1',
        ];

        return Controller::instance();
    }

    public function testPermalinksAreStoredRelative() : void {
        $records = $this->controller()->relativisePermalinks(
            [ [ 'permalink' => 'https://wp.example.com/hello/' ] ]
        );

        $this->assertSame( '/hello/', $records[0]['permalink'] );
    }

    public function testTheSiteRootBecomesASlashRatherThanNothing() : void {
        $records = $this->controller()->relativisePermalinks(
            [ [ 'permalink' => 'https://wp.example.com' ] ]
        );

        $this->assertSame( '/', $records[0]['permalink'] );
    }

    public function testBothSchemesAreStripped() : void {
        $records = $this->controller()->relativisePermalinks(
            [ [ 'permalink' => 'http://wp.example.com/hello/' ] ]
        );

        $this->assertSame( '/hello/', $records[0]['permalink'] );
    }

    /**
     * Upstream read the port from `$site_path`, a variable that does not exist
     * in that function: on PHP 8 it passed null to parse_url(), took the
     * deprecation, and never found a port. A development site on :8080
     * therefore kept absolute permalinks, and only there.
     */
    public function testAPortInTheSiteURLIsHandled() : void {
        $GLOBALS['site_url'] = 'https://wp.example.com:8080/';

        $records = $this->controller()->relativisePermalinks(
            [ [ 'permalink' => 'https://wp.example.com:8080/hello/' ] ]
        );

        $this->assertSame( '/hello/', $records[0]['permalink'] );
    }

    public function testTheAuthorURLIsRelativisedToo() : void {
        $records = $this->controller()->relativisePermalinks(
            [
                [
                    'permalink' => 'https://wp.example.com/hello/',
                    'post_author' => [ 'user_url' => 'https://wp.example.com/author/x/' ],
                ],
            ]
        );

        $this->assertSame( '/author/x/', $records[0]['post_author']['user_url'] );
    }

    /**
     * A record indexed by a plugin version that did not store the author must
     * not take the whole batch down. Upstream read
     * `$hit['post_author']['user_url']` unguarded.
     */
    public function testARecordWithoutAnAuthorIsLeftAlone() : void {
        $records = $this->controller()->relativisePermalinks( [ [ 'post_title' => 'x' ] ] );

        $this->assertSame( [ [ 'post_title' => 'x' ] ], $records );
    }

    public function testTheSearchPageGoesIntoTheCrawlQueue() : void {
        $this->assertContains( '/search/', $this->controller()->addSearchPage( [ '/' ] ) );
    }

    public function testTheSearchPageIsNotQueuedTwice() : void {
        $queue = $this->controller()->addSearchPage( [ '/search/' ] );

        $this->assertSame( [ '/search/' ], $queue );
    }

    public function testTheSearchPathIsNormalised() : void {
        $GLOBALS['wpdb']->values = [ 'algoliaSearchPath' => 'find' ];

        $this->assertSame( '/find/', Controller::instance()->searchPath() );
    }

    /**
     * The form-action rewriting happens in the generated HTML. Upstream echoed
     * a `<script>` into `wp_footer` that rewrote every form on every page a
     * real visitor loaded, with the site URL interpolated into the JavaScript
     * unescaped.
     */
    public function testOnlySearchFormsArePointedAtTheSearchPage() : void {
        $file = tempnam( sys_get_temp_dir(), 'vs' );

        file_put_contents(
            $file,
            '<form role="search" action="https://wp.example.com/"><input name="s"></form>'
            . '<form action="/wp-comments-post.php"><input name="comment"></form>'
        );

        $this->controller()->rewriteFormActions( $file );

        $html = (string) file_get_contents( $file );

        unlink( $file );

        $this->assertStringContainsString( '<form role="search" action="/search/">', $html );
        $this->assertStringContainsString( 'action="/wp-comments-post.php"', $html );
    }

    public function testASearchFormWithNoActionGetsOne() : void {
        $file = tempnam( sys_get_temp_dir(), 'vs' );

        file_put_contents( $file, '<form class="search-form"><input name="s"></form>' );

        $this->controller()->rewriteFormActions( $file );

        $html = (string) file_get_contents( $file );

        unlink( $file );

        $this->assertStringContainsString( 'action="/search/"', $html );
    }

    public function testTheRewriteCanBeTurnedOff() : void {
        $file = tempnam( sys_get_temp_dir(), 'vs' );

        $original = '<form role="search" action="/"><input name="s"></form>';

        file_put_contents( $file, $original );

        $GLOBALS['wpdb']->values = [
            'algoliaSearchPath' => '/search/',
            'algoliaRewriteFormActions' => '0',
        ];

        Controller::instance()->rewriteFormActions( $file );

        $html = (string) file_get_contents( $file );

        unlink( $file );

        $this->assertSame( $original, $html );
    }

    public function testItKnowsWhetherTheAlgoliaPluginIsThere() : void {
        $this->assertFalse( Controller::algoliaPluginActive() );

        $GLOBALS['wp_options']['algolia_application_id'] = 'APPID';

        $this->assertTrue( Controller::algoliaPluginActive() );
    }
}
