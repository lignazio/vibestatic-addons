<?php
/**
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\DeployCache;
use WP2Static\DeployPlan;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2StaticBitbucket\Deployer;
use WP2StaticBitbucket\Options;

class DeployerTest extends TestCase {

    /**
     * @var string
     */
    private $site;

    protected function setUp() : void {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();

        DeployCache::reset();

        $this->site = sys_get_temp_dir() . '/vs-bb-' . uniqid();

        mkdir( $this->site, 0777, true );
        file_put_contents( $this->site . '/index.html', '<h1>home</h1>' );
    }

    protected function tearDown() : void {
        foreach ( glob( $this->site . '/*' ) as $file ) {
            unlink( $file );
        }

        @rmdir( $this->site );

        parent::tearDown();
    }

    private function options( string $repository = 'acme/site', string $user = 'deploybot' ) : Options {
        $GLOBALS['wpdb']->values = [
            'bitbucketRepository' => $repository,
            'bitbucketUsername' => $user,
            'bitbucketAppPassword' => 'ENCRYPTED:app-pw',
            'bitbucketBranch' => 'main',
            'bitbucketCommitMessage' => 'Published by VibeStatic',
        ];

        return new Options(
            'wp2static_addon_bitbucket_options',
            [
                'bitbucketRepository' => [ 'string', '' ],
                'bitbucketUsername' => [ 'string', '' ],
                'bitbucketAppPassword' => [ 'password', '' ],
                'bitbucketBranch' => [ 'string', 'main' ],
                'bitbucketPath' => [ 'string', '' ],
                'bitbucketCommitMessage' => [ 'string', 'Published by VibeStatic' ],
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $parts The multipart body.
     * @return array<string, mixed> Keyed by part name; repeated names collected.
     */
    private function byName( array $parts ) : array {
        $named = [];

        foreach ( $parts as $part ) {
            if ( isset( $named[ $part['name'] ] ) ) {
                $named[ $part['name'] ] = (array) $named[ $part['name'] ];
                $named[ $part['name'] ][] = $part['contents'];

                continue;
            }

            $named[ $part['name'] ] = $part['contents'];
        }

        return $named;
    }

    public function testARepositoryWithoutASlashIsRefused() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options( 'site' ), $client ) )->deploy( $this->site );

        $this->assertSame( [], $client->calls );
    }

    public function testNothingIsSentWithoutAUsername() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options( 'acme/site', '' ), $client ) )->deploy( $this->site );

        $this->assertSame( [], $client->calls );
    }

    /**
     * The whole batch is one multipart POST: a part per file, plus the branch
     * and the message.
     */
    public function testABatchIsOneMultipartCommit() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertCount( 1, $client->calls );
        $this->assertSame( 'POST', $client->calls[0][0] );
        $this->assertSame( 'repositories/acme/site/src', $client->calls[0][1] );

        $parts = $this->byName( $client->calls[0][2]['multipart'] );

        $this->assertSame( 'main', $parts['branch'] );
        $this->assertSame( 'Published by VibeStatic', $parts['message'] );
        $this->assertArrayHasKey( 'index.html', $parts );
    }

    /**
     * A part without a filename is read as a plain form field rather than as
     * file contents.
     */
    public function testFilePartsCarryAFilename() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        foreach ( $client->calls[0][2]['multipart'] as $part ) {
            if ( 'index.html' === $part['name'] ) {
                $this->assertSame( 'index.html', $part['filename'] );

                return;
            }
        }

        $this->fail( 'The file part was not sent.' );
    }

    /**
     * Deletions ride in the same commit as the uploads, so a rename lands once
     * and the repository never goes through a state where the old path is gone
     * and the new one has not arrived.
     */
    public function testDeletionsTravelWithTheUploads() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [ '/gone.html' ], 0 );

        $client = new Client();

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertCount( 1, $client->calls );

        $parts = $this->byName( $client->calls[0][2]['multipart'] );

        $this->assertSame( 'gone.html', $parts['files'] );
        $this->assertArrayHasKey( 'index.html', $parts );
    }

    public function testAFailedCommitIsNotRecordedAsSent() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [], 0 );

        $client = new Client();
        $client->responses = [ new Response( 403 ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [], DeployCache::$added );
    }

    public function testASuccessfulCommitIsRecorded() : void {
        DeployCache::$plan = new DeployPlan( [ '/index.html' ], [ '/gone.html' ], 0 );

        $client = new Client();
        $client->responses = [ new Response( 201 ) ];

        ( new Deployer( $this->options(), $client ) )->deploy( $this->site );

        $this->assertSame( [ [ '/index.html', 'wp2static-addon-bitbucket' ] ], DeployCache::$added );
        $this->assertSame( [ [ '/gone.html', 'wp2static-addon-bitbucket' ] ], DeployCache::$removed );
    }
}
