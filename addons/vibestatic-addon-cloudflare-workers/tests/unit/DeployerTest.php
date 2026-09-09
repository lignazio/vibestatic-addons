<?php
/**
 * @package WP2StaticCloudflareWorkers
 */

namespace WP2StaticCloudflareWorkers\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2StaticCloudflareWorkers\Deployer;
use WP2Static\Addon\Options;
use VibeStatic\Tests\FakeWpdb;

class DeployerTest extends TestCase {

    /**
     * @var string
     */
    private $site;

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();

        DeployCache::reset();

        $this->site = sys_get_temp_dir() . '/vs-cf-' . uniqid();

        mkdir( $this->site . '/about', 0777, true );
        file_put_contents( $this->site . '/index.html', '<h1>home</h1>' );
        file_put_contents( $this->site . '/about/index.html', '<h1>about</h1>' );
    }

    protected function tearDown() : void {
        foreach ( [ '/about/index.html', '/index.html' ] as $path ) {
            if ( is_file( $this->site . $path ) ) {
                unlink( $this->site . $path );
            }
        }

        @rmdir( $this->site . '/about' );
        @rmdir( $this->site );

        parent::tearDown();
    }

    private function options( string $index_as_directory = '1' ) : Options {
        $GLOBALS['wpdb']->values = [
            'cloudflareAccountID' => 'acct',
            'cloudflareNamespaceID' => 'ns',
            'cloudflareAPIToken' => 'ENCRYPTED:tok',
            'cloudflareIndexAsDirectory' => $index_as_directory,
        ];

        return new Options(
            'wp2static_addon_cloudflare_workers_options',
            [
                'cloudflareAccountID' => [ 'string', '' ],
                'cloudflareNamespaceID' => [ 'string', '' ],
                'cloudflareAPIToken' => [ 'password', '' ],
                'cloudflareIndexAsDirectory' => [ 'bool', '1' ],
            ]
        );
    }

    /**
     * `/about/index.html` becomes `/about/`, which is what a worker asks KV for
     * when a browser requests a directory URL. Upstream did this in its bulk
     * path and not in its singular one, so the two modes wrote different keys
     * for the same file.
     */
    public function testIndexFilesBecomeDirectoryKeys() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html', '/about/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 200, '{"success":true}' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $keys = array_column( $client->calls[0][2]['json'], 'key' );

        $this->assertContains( '/', $keys );
        $this->assertContains( '/about/', $keys );
    }

    public function testTheMappingCanBeTurnedOff() : void {
        DeployCache::$plan = new DeployPlan( [ '/about/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 200, '{"success":true}' ) ];

        ( new Deployer( $this->options( '0' ), $client ) )->deploy( $this->site );

        $keys = array_column( $client->calls[0][2]['json'], 'key' );

        $this->assertContains( '/about/index.html', $keys );
    }

    /**
     * A content-type sidecar key per file, which is the layout a Workers Sites
     * script reads.
     */
    public function testEachFileGetsAContentTypeKey() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 200, '{"success":true}' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $pairs = [];

        foreach ( $client->calls[0][2]['json'] as $entry ) {
            $pairs[ $entry['key'] ] = $entry['value'];
        }

        $this->assertSame( 'text/html; charset=UTF-8', $pairs['/_ct'] );
        $this->assertSame( '<h1>home</h1>', base64_decode( $pairs['/'], true ) );
    }

    /**
     * Cloudflare answers 200 with `{"success": false}` for some failures.
     * Upstream added every file to the deploy cache regardless of the answer,
     * so a rejected batch was remembered as deployed and never retried.
     */
    public function testASuccessFalseAnswerIsNotRecordedAsSent() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [
            new Response( 200, '{"success":false,"errors":[{"message":"invalid namespace"}]}' ),
        ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }

    /**
     * A gateway error returns HTML, not JSON. Upstream read `$result->success`
     * off the decoded body without checking it had decoded at all.
     */
    public function testANonJsonAnswerDoesNotFatal() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 502, '<html>Bad gateway</html>' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }

    /**
     * Upstream had no deletion at all: the namespace kept every page the site
     * had ever had, and a worker serving from it went on serving them.
     */
    public function testRemovedPathsAndTheirContentTypeKeysAreDeleted() : void {
        DeployCache::$plan = new DeployPlan( [], [ '/gone/index.html' ], 0 );

        $client = new Client();
        $client->responses = [ new Response( 200, '{"success":true}' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( 'DELETE', $client->calls[0][0] );
        $this->assertSame( [ '/gone/', '/gone/_ct' ], $client->calls[0][2]['json'] );
        $this->assertSame( [ [ '/gone/index.html', 'wp2static-addon-cloudflare-workers' ] ], DeployCache::$removed );
    }

    public function testNothingIsSentWithoutANamespace() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $options = $this->options();

        $GLOBALS['wpdb']->values['cloudflareNamespaceID'] = '';

        $client = new Client();

        ( new Deployer( $options, $client ) )->deploy( $this->site );

        $this->assertSame( [], $client->calls );
    }
}
