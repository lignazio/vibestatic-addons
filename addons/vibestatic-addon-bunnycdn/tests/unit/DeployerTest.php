<?php
/**
 * @package WP2StaticBunnyCDN
 */

namespace WP2StaticBunnyCDN\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2StaticBunnyCDN\Deployer;
use WP2StaticBunnyCDN\Options;

class DeployerTest extends TestCase {

    /**
     * @var string
     */
    private $site;

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();

        DeployCache::reset();

        $this->site = sys_get_temp_dir() . '/vs-bunny-' . uniqid();

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

    private function options( string $zone = 'my-zone', string $region = '', string $prefix = '' ) : Options {
        $GLOBALS['wpdb']->values = [
            'bunnycdnStorageZoneName' => $zone,
            'bunnycdnStorageZonePassword' => 'ENCRYPTED:s3cret',
            'bunnycdnStorageRegion' => $region,
            'bunnycdnRemotePath' => $prefix,
        ];

        return new Options(
            'wp2static_addon_bunnycdn_options',
            [
                'bunnycdnStorageZoneName' => [ 'string', '' ],
                'bunnycdnStorageZonePassword' => [ 'password', '' ],
                'bunnycdnStorageRegion' => [ 'string', '' ],
                'bunnycdnRemotePath' => [ 'string', '' ],
            ]
        );
    }

    public function testTheDefaultRegionIsTheBareHost() : void {
        $this->assertSame( 'storage.bunnycdn.com', Deployer::host( '' ) );
    }

    public function testARegionCodeIsPrefixed() : void {
        $this->assertSame( 'ny.storage.bunnycdn.com', Deployer::host( 'ny' ) );
        $this->assertSame( 'syd.storage.bunnycdn.com', Deployer::host( ' SYD ' ) );
    }

    /**
     * A region code reaches a hostname, so anything that is not one is refused
     * rather than concatenated.
     */
    public function testAnImplausibleRegionFallsBackToTheDefault() : void {
        $this->assertSame( 'storage.bunnycdn.com', Deployer::host( 'evil.example.com/' ) );
        $this->assertSame( 'storage.bunnycdn.com', Deployer::host( '../..' ) );
    }

    /**
     * urlencode() on the whole path turns every separator into %2F, which asks
     * the API for one very long filename. Upstream did exactly that.
     */
    public function testPathsAreEncodedSegmentBySegment() : void {
        $this->assertSame( 'about/index.html', Deployer::encodePath( '/about/index.html' ) );
        $this->assertSame( 'a%20b/c%2Bd.html', Deployer::encodePath( '/a b/c+d.html' ) );
    }

    public function testFilesInThePlanAreUploadedAndRecorded() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html', '/about/index.html' ], [], 3 );

        $client = new Client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertCount( 2, $client->calls );
        $this->assertSame( 'PUT', $client->calls[0][0] );
        $this->assertSame( 'my-zone/index.html', $client->calls[0][1] );
        $this->assertSame( 'my-zone/about/index.html', $client->calls[1][1] );

        $this->assertSame(
            [ [ '/index.html', 'wp2static-addon-bunnycdn' ], [ '/about/index.html', 'wp2static-addon-bunnycdn' ] ],
            DeployCache::$added
        );
    }

    /**
     * The defect that made every BunnyCDN deploy a full one: upstream never
     * called DeployCache::addFile() at all — the line was commented out with a
     * `TODO: Look for 201 status from Bunny` beside it.
     */
    public function testAFailedUploadIsNotRecordedAsSent() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 401, '{"Message":"Unauthorized"}' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }

    public function testRemovedPathsAreDeletedAtTheDestination() : void {
        DeployCache::$plan = new DeployPlan( [], [ '/gone.html' ], 0 );

        $client = new Client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( 'DELETE', $client->calls[0][0] );
        $this->assertSame( 'my-zone/gone.html', $client->calls[0][1] );
    }

    /**
     * Already gone is what the plan wanted. Treating it as a failure would keep
     * the path in the cache and retry it on every future deploy.
     */
    public function testDeletingSomethingAlreadyAbsentCounts() : void {
        DeployCache::$plan = new DeployPlan( [], [ '/gone.html' ], 0 );

        $client = new Client();
        $client->responses = [ new Response( 404 ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [ [ '/gone.html', 'wp2static-addon-bunnycdn' ] ], DeployCache::$removed );
    }

    public function testAPrefixIsAppliedToEveryPath() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options( 'my-zone', '', 'site' ), $client ) )->deploy( $this->site );

        $this->assertSame( 'my-zone/site/index.html', $client->calls[0][1] );
    }

    public function testNothingIsSentWithoutAZoneName() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options( '' ), $client ) )->deploy( $this->site );

        $this->assertSame( [], $client->calls );
    }
}
