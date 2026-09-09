<?php
/**
 * The shared Options class, which every add-on carries a copy of.
 *
 * These are the four defects the upstream boilerplate had, each with a test:
 * duplicate seed rows, a save that did nothing when the row was absent, table
 * names interpolated into SQL, and secrets written in the clear.
 *
 * @package WP2StaticBunnyCDN
 */

namespace WP2StaticBunnyCDN\Tests;

use PHPUnit\Framework\TestCase;
use WP2StaticBunnyCDN\Options;

class OptionsTest extends TestCase {

    protected function setUp() : void {
        parent::setUp();

        \WP_Mock::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    protected function tearDown() : void {
        \WP_Mock::tearDown();

        parent::tearDown();
    }

    private function options() : Options {
        return new Options(
            'wp2static_addon_test_options',
            [
                'plain' => [ 'string', 'default' ],
                'secret' => [ 'password', '' ],
                'flag' => [ 'bool', '0' ],
                'count' => [ 'int', '5' ],
                'body' => [ 'text', '' ],
            ]
        );
    }

    public function testSeedIsIdempotent() : void {
        $options = $this->options();

        $options->seed();
        $options->seed();

        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            $this->assertStringContainsString(
                'INSERT IGNORE',
                $query,
                'Seeding twice must not be able to add a second row per option.'
            );
        }
    }

    public function testSaveIsAnUpsert() : void {
        $this->options()->save( 'plain', 'value' );

        $this->assertStringContainsString(
            'ON DUPLICATE KEY UPDATE',
            $GLOBALS['wpdb']->queries[0],
            'save() must create the row when it is missing, not silently do nothing.'
        );
    }

    public function testEveryQueryIsPrepared() : void {
        $options = $this->options();

        $options->install();
        $options->all();
        $options->get( 'plain' );
        $options->save( 'plain', "O'Brien" );
        $options->drop();

        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            $this->assertStringNotContainsString(
                '%i',
                $query,
                'A placeholder left unbound means the table name was never passed through prepare().'
            );
        }
    }

    public function testAnUnsetOptionFallsBackToItsDeclaredDefault() : void {
        $this->assertSame( 'default', $this->options()->get( 'plain' ) );
        $this->assertSame( 5, $this->options()->int( 'count' ) );
        $this->assertFalse( $this->options()->bool( 'flag' ) );
    }

    public function testSecretsAreEncryptedOnTheWayIn() : void {
        $this->options()->save( 'secret', 'hunter2' );

        $written = implode( ' ', $GLOBALS['wpdb']->queries );

        $this->assertStringNotContainsString(
            "'hunter2'",
            $written,
            'A password must never reach the database as its own plain value.'
        );
        $this->assertStringContainsString( "'ENCRYPTED:hunter2'", $written );
    }

    public function testANonSecretIsStoredAsItIs() : void {
        $this->options()->save( 'plain', 'visible' );

        $this->assertStringContainsString( 'visible', implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testABlankPasswordKeepsTheStoredOne() : void {
        $_POST = [
            'plain' => 'x',
            'secret' => '',
            'body' => '',
            'count' => '1',
        ];

        $this->options()->savePosted();

        foreach ( $GLOBALS['wpdb']->queries as $query ) {
            $this->assertStringNotContainsString(
                "'secret'",
                $query,
                'An empty password field must not wipe the saved credentials.'
            );
        }
    }

    public function testAnUncheckedBoxIsSavedAsZero() : void {
        $_POST = [ 'plain' => 'x' ];

        $this->options()->savePosted();

        $this->assertStringContainsString( "'flag', '0'", implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testAnArrayPostedForAScalarOptionIsRejected() : void {
        $_POST = [ 'plain' => [ 'not', 'a', 'string' ] ];

        $this->options()->savePosted();

        $this->assertStringContainsString( "'plain', ''", implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testAnUndeclaredPostedFieldIsIgnored() : void {
        $_POST = [
            'plain' => 'x',
            'somethingElse' => 'y',
        ];

        $this->options()->savePosted();

        $this->assertStringNotContainsString( 'somethingElse', implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testATextareaKeepsItsNewlines() : void {
        $_POST = [ 'body' => "one\ntwo" ];

        $this->options()->savePosted();

        $this->assertStringContainsString( "one\ntwo", implode( ' ', $GLOBALS['wpdb']->queries ) );
    }

    public function testAnUndeclaredTypeFallsBackToString() : void {
        $options = new Options( 'x', [ 'weird' => [ 'nonsense', '' ] ] );

        $this->assertSame( 'string', $options->type( 'weird' ) );
        $this->assertFalse( $options->isSecret( 'weird' ) );
    }
}
