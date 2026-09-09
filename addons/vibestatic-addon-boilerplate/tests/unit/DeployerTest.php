<?php
/**
 * The reference deployer, which is a dry run.
 *
 * These tests are as much documentation as verification: the one thing the
 * upstream boilerplate taught by example — record a file as sent whether or not
 * it arrived — is the thing pinned here as *not* happening.
 *
 * @package WP2StaticBoilerplate
 */

namespace WP2StaticBoilerplate\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\WsLog;
use WP2StaticBoilerplate\Deployer;
use WP2StaticBoilerplate\Options;

class DeployerTest extends TestCase {

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();

        DeployCache::reset();

        WsLog::$lines = [];
    }

    private function options( string $list_every_path = '0' ) : Options {
        $GLOBALS['wpdb']->values = [ 'listEveryPath' => $list_every_path ];

        return new Options(
            'wp2static_addon_boilerplate_options',
            [
                'aRegularOption' => [ 'string', '' ],
                'anEncryptedOption' => [ 'password', '' ],
                'listEveryPath' => [ 'bool', '0' ],
            ]
        );
    }

    /**
     * **The point of the whole add-on.** Upstream decided success with
     * `rand( 0, 1 )` and wrote to the DeployCache either way, teaching by
     * example that a deployer records a file as sent without knowing whether it
     * arrived. A real add-on copying that shape reports a deploy that lost half
     * the site as a success, and never retries the lost half.
     */
    public function testADryRunRecordsNothingAsDeployed() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html', '/about.html' ], [ '/gone.html' ], 5 );

        ( new Deployer( $this->options() ) )->deploy( sys_get_temp_dir() );

        $this->assertSame( [], DeployCache::$added );
    }

    public function testItReportsWhatARealDeployWouldHaveDone() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html', '/about.html' ], [ '/gone.html' ], 5 );

        ( new Deployer( $this->options() ) )->deploy( sys_get_temp_dir() );

        $this->assertStringContainsString(
            '2 file(s) would be sent, 1 would be removed',
            implode( "\n", WsLog::$lines )
        );
    }

    public function testItSaysUpFrontThatItSendsNothing() : void {
        DeployCache::$plan = new DeployPlan( [], [], 0 );

        ( new Deployer( $this->options() ) )->deploy( sys_get_temp_dir() );

        $this->assertStringContainsString( 'nothing will be uploaded', WsLog::$lines[0] );
    }

    public function testPerPathLoggingIsOffByDefault() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        ( new Deployer( $this->options() ) )->deploy( sys_get_temp_dir() );

        $this->assertStringNotContainsString( 'would send: ', implode( "\n", WsLog::$lines ) );
    }

    public function testPerPathLoggingCanBeTurnedOn() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [ '/gone.html' ], 0 );

        ( new Deployer( $this->options( '1' ) ) )->deploy( sys_get_temp_dir() );

        $logged = implode( "\n", WsLog::$lines );

        $this->assertStringContainsString( 'would send: /index.html', $logged );
        $this->assertStringContainsString( 'would remove: /gone.html', $logged );
    }
}
