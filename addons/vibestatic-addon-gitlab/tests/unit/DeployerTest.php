<?php
/**
 * @package WP2StaticGitLab
 */

namespace WP2StaticGitLab\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2StaticGitLab\Deployer;
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

        $this->site = sys_get_temp_dir() . '/vs-gl-' . uniqid();

        mkdir( $this->site, 0777, true );
        file_put_contents( $this->site . '/index.html', '<h1>home</h1>' );
        file_put_contents( $this->site . '/new.html', '<h1>new</h1>' );
    }

    protected function tearDown() : void {
        foreach ( glob( $this->site . '/*' ) as $file ) {
            unlink( $file );
        }

        @rmdir( $this->site );

        parent::tearDown();
    }

    private function options( string $project = 'group/site' ) : Options {
        $GLOBALS['wpdb']->values = [
            'gitlabProject' => $project,
            'gitlabToken' => 'ENCRYPTED:glpat-x',
            'gitlabBranch' => 'main',
            'gitlabCommitMessage' => 'Published by VibeStatic',
        ];

        return new Options(
            'wp2static_addon_gitlab_options',
            [
                'gitlabProject' => [ 'string', '' ],
                'gitlabToken' => [ 'password', '' ],
                'gitlabBranch' => [ 'string', 'main' ],
                'gitlabPath' => [ 'string', '' ],
                'gitlabCommitMessage' => [ 'string', 'Published by VibeStatic' ],
                'gitlabApiUrl' => [ 'string', '' ],
            ]
        );
    }

    /**
     * @param string $tree The branch's file list, as the API returns it.
     */
    private function client( string $tree = '[{"type":"blob","path":"index.html"}]' ) : Client {
        $client = new Client();

        $client->responses = [
            new Response( 200, $tree ),
            new Response( 200, '{"id":"abc"}' ),
        ];

        return $client;
    }

    /**
     * A project path is URL-encoded whole — `group/site` becomes `group%2Fsite`
     * — because that is how GitLab addresses a project by path rather than ID.
     */
    public function testTheProjectPathIsEncodedAsOneSegment() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options( 'group/sub/site' ), $client ) )->deploy( $this->site );

        $this->assertStringContainsString( 'projects/group%2Fsub%2Fsite/', $client->calls[0][1] );
    }

    /**
     * GitLab has no upsert: `create` fails on a path that exists and `update`
     * on one that does not. The branch's file list decides which to send.
     */
    public function testAKnownPathIsUpdatedAndANewOneIsCreated() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html', '/new.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $actions = $client->calls[1][2]['json']['actions'];

        $by_path = [];

        foreach ( $actions as $action ) {
            $by_path[ $action['file_path'] ] = $action['action'];
        }

        $this->assertSame( 'update', $by_path['index.html'] );
        $this->assertSame( 'create', $by_path['new.html'] );
    }

    /**
     * A delete for a path GitLab does not have fails the whole commit and takes
     * the uploads in the same batch down with it, so it is skipped instead.
     */
    public function testADeleteForAPathTheBranchDoesNotHaveIsSkipped() : void {
        DeployCache::$plan = new DeployPlan( [], [ '/never-there.html' ], 0 );

        $client = $this->client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        // Only the tree read: there was nothing left to commit.
        $this->assertCount( 1, $client->calls );
    }

    public function testAKnownPathIsDeleted() : void {
        DeployCache::$plan = new DeployPlan( [], [ '/index.html' ], 0 );

        $client = $this->client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $actions = $client->calls[1][2]['json']['actions'];

        $this->assertSame( 'delete', $actions[0]['action'] );
        $this->assertSame( 'index.html', $actions[0]['file_path'] );
    }

    public function testContentGoesUpBase64EncodedSoBinaryFilesSurvive() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $action = $client->calls[1][2]['json']['actions'][0];

        $this->assertSame( 'base64', $action['encoding'] );
        $this->assertSame( '<h1>home</h1>', base64_decode( $action['content'], true ) );
    }

    public function testAnUnreadableBranchStopsBeforeAnythingIsRecorded() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 404, '{"message":"404 Project Not Found"}' ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }

    public function testNothingIsSentWithoutAProject() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = $this->client();

        ( new Deployer( $this->options( '' ), $client ) )->deploy( $this->site );

        $this->assertSame( [], $client->calls );
    }
}
