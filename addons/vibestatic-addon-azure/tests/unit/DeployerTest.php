<?php
/**
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2StaticAzure\Deployer;
use WP2StaticAzure\MimeTypes;
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

        $this->site = sys_get_temp_dir() . '/vs-azure-' . uniqid();

        mkdir( $this->site, 0777, true );
        file_put_contents( $this->site . '/index.html', '<h1>home</h1>' );
    }

    protected function tearDown() : void {
        if ( is_file( $this->site . '/index.html' ) ) {
            unlink( $this->site . '/index.html' );
        }

        @rmdir( $this->site );

        parent::tearDown();
    }

    private function options( string $container = '' ) : Options {
        $GLOBALS['wpdb']->values = [
            'azureAccountName' => 'acme',
            'azureAccountKey' => 'ENCRYPTED:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA',
            'azureContainer' => $container,
        ];

        return new Options(
            'wp2static_addon_azure_options',
            [
                'azureAccountName' => [ 'string', '' ],
                'azureAccountKey' => [ 'password', '' ],
                'azureContainer' => [ 'string', '$web' ],
                'azureRemotePath' => [ 'string', '' ],
                'azureCacheControl' => [ 'string', '' ],
                'azureEndpointSuffix' => [ 'string', '' ],
            ]
        );
    }

    public function testPathsAreEncodedSegmentBySegment() : void {
        $this->assertSame( '/a%20b/c.html', Deployer::encodePath( 'a b/c.html' ) );
        $this->assertSame( '/a%2Bb.html', Deployer::encodePath( '/a+b.html' ) );
    }

    /**
     * Azure's static-website container is literally named `$web`. Running the
     * container name through rawurlencode() would ask for `%24web`, so it is
     * kept out of the encoder and only the blob name goes through it.
     */
    public function testTheContainerNameIsNotPercentEncoded() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options( '' ), $client ) )->deploy( $this->site );

        $this->assertSame( '/$web/index.html', $client->calls[0][1] );
    }

    public function testAPageIsUploadedAsHtmlSoBrowsersRenderIt() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $headers = $client->calls[0][2]['headers'];

        $this->assertSame( 'text/html; charset=UTF-8', $headers['Content-Type'] );
        $this->assertSame( 'text/html; charset=UTF-8', $headers['x-ms-blob-content-type'] );
        $this->assertSame( 'BlockBlob', $headers['x-ms-blob-type'] );
        $this->assertStringStartsWith( 'SharedKey acme:', $headers['Authorization'] );
    }

    public function testTheContainerDefaultsToTheStaticWebsiteOne() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options( '' ), $client ) )->deploy( $this->site );

        $this->assertStringStartsWith( '/$web/', $client->calls[0][1] );
    }

    public function testAFailedUploadIsNotRecordedAsSent() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 403 ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }

    public function testAnUnknownExtensionIsNotClaimedToBeSomethingElse() : void {
        $this->assertSame( 'application/octet-stream', MimeTypes::forPath( '/x.whatever' ) );
        $this->assertSame( 'text/css; charset=UTF-8', MimeTypes::forPath( '/style.CSS' ) );
    }
}
