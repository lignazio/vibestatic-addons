<?php
/**
 * @package WP2StaticGitHub
 */

namespace WP2StaticGitHub\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2StaticGitHub\Deployer;
use WP2StaticGitHub\Options;

class DeployerTest extends TestCase {

    /**
     * @var string
     */
    private $site;

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();

        DeployCache::reset();

        $this->site = sys_get_temp_dir() . '/vs-gh-' . uniqid();

        mkdir( $this->site, 0777, true );
        file_put_contents( $this->site . '/index.html', '<h1>home</h1>' );
        file_put_contents( $this->site . '/about.html', '<h1>about</h1>' );
    }

    protected function tearDown() : void {
        foreach ( glob( $this->site . '/*' ) as $file ) {
            unlink( $file );
        }

        @rmdir( $this->site );

        parent::tearDown();
    }

    private function options( string $repository = 'acme/site', string $path = '' ) : Options {
        $GLOBALS['wpdb']->values = [
            'githubRepository' => $repository,
            'githubToken' => 'ENCRYPTED:ghp_x',
            'githubBranch' => 'main',
            'githubPath' => $path,
            'githubCommitMessage' => 'Published by VibeStatic',
        ];

        return new Options(
            'wp2static_addon_github_options',
            [
                'githubRepository' => [ 'string', '' ],
                'githubToken' => [ 'password', '' ],
                'githubBranch' => [ 'string', 'main' ],
                'githubPath' => [ 'string', '' ],
                'githubCommitMessage' => [ 'string', 'Published by VibeStatic' ],
            ]
        );
    }

    /**
     * Enough canned answers for one branch read and any number of
     * blob/tree/commit/ref calls.
     */
    private function client() : Client {
        $client = new Client();

        $client->responses = [ new Response( 200, '{"object":{"sha":"head0"},"sha":"new"}' ) ];

        return $client;
    }

    /**
     * Upstream did `list($user, $repo) = explode('/', $settings['ghRepo'])`, so
     * a value without a slash was an undefined-offset notice and then a request
     * to a URL with the word "null" in it.
     */
    public function testARepositoryWithoutASlashIsRefused() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options( 'site' ), $client ) )->deploy( $this->site );

        $this->assertSame( [], $client->calls );
        $this->assertSame( [], DeployCache::$added );
    }

    /**
     * One commit for the batch: a blob per file, then a tree, a commit and a
     * ref update. Upstream used the Contents API, which is a GET and a PUT per
     * file — nine thousand requests for the site the fork was tested against,
     * against a limit of five thousand an hour.
     */
    public function testABatchIsOneCommitNotOneRequestPerFile() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html', '/about.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $uris = array_column( $client->calls, 1 );

        // read the branch, two blobs, one tree, one commit, one ref update
        $this->assertCount( 6, $client->calls );
        $this->assertSame( 'repos/acme/site/git/ref/heads/main', $uris[0] );
        $this->assertSame( 'repos/acme/site/git/blobs', $uris[1] );
        $this->assertSame( 'repos/acme/site/git/blobs', $uris[2] );
        $this->assertSame( 'repos/acme/site/git/trees', $uris[3] );
        $this->assertSame( 'repos/acme/site/git/commits', $uris[4] );
        $this->assertSame( 'repos/acme/site/git/refs/heads/main', $uris[5] );
        $this->assertSame( 'PATCH', $client->calls[5][0] );
    }

    /**
     * A path that has left the site becomes a tree entry with a null SHA, which
     * is how git removes it. Upstream had no deletion at all.
     */
    public function testARemovedPathBecomesANullTreeEntry() : void {
        DeployCache::$plan = new DeployPlan( [], [ '/gone.html' ], 0 );

        $client = $this->client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $tree = null;

        foreach ( $client->calls as $call ) {
            if ( 'repos/acme/site/git/trees' === $call[1] ) {
                $tree = $call[2]['json']['tree'];
            }
        }

        $this->assertNotNull( $tree );
        $this->assertSame( 'gone.html', $tree[0]['path'] );
        $this->assertNull( $tree[0]['sha'] );

        $this->assertSame( [ [ '/gone.html', 'wp2static-addon-github' ] ], DeployCache::$removed );
    }

    public function testAConfiguredPathPrefixesEveryEntry() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options( 'acme/site', 'docs' ), $client ) )->deploy( $this->site );

        foreach ( $client->calls as $call ) {
            if ( 'repos/acme/site/git/trees' === $call[1] ) {
                $this->assertSame( 'docs/index.html', $call[2]['json']['tree'][0]['path'] );

                return;
            }
        }

        $this->fail( 'No tree was created.' );
    }

    /**
     * Nothing is recorded as sent when the branch could not even be read: the
     * deploy has not started.
     */
    public function testAnUnreadableBranchStopsBeforeAnythingIsRecorded() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 404, '{}' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertCount( 1, $client->calls );
        $this->assertSame( [], DeployCache::$added );
    }

    public function testAFailedCommitIsNotRecordedAsSent() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [
            new Response( 200, '{"object":{"sha":"head0"}}' ),
            new Response( 200, '{"sha":"blob0"}' ),
            new Response( 422, '{"message":"nope"}' ),
        ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }
}
