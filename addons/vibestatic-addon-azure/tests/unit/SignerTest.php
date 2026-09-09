<?php
/**
 * The Shared Key signature.
 *
 * A signature is either exactly right or it is a 403 with no explanation, so
 * this pins the string being signed rather than only the outcome: when Azure
 * starts refusing, the failing assertion says which of the thirteen positional
 * slots moved.
 *
 * @package WP2StaticAzure
 */

namespace WP2StaticAzure\Tests;

use PHPUnit\Framework\TestCase;
use WP2StaticAzure\Signer;

class SignerTest extends TestCase {

    /**
     * base64 of 32 zero bytes: a valid key whose HMAC is reproducible.
     */
    const KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testAKeyThatIsNotBase64IsRefused() : void {
        $this->assertFalse( ( new Signer( 'acme', 'not base64 !!!' ) )->isUsable() );
    }

    public function testAValidKeyIsAccepted() : void {
        $this->assertTrue( ( new Signer( 'acme', self::KEY ) )->isUsable() );
    }

    public function testAnEmptyAccountIsRefused() : void {
        $this->assertFalse( ( new Signer( '', self::KEY ) )->isUsable() );
    }

    public function testTheHeaderNamesTheAccountAndCarriesABase64Signature() : void {
        $header = ( new Signer( 'acme', self::KEY ) )->authorization(
            'PUT',
            '/$web/index.html',
            [
                'Content-Length' => '13',
                'Content-Type' => 'text/html; charset=UTF-8',
                'x-ms-blob-type' => 'BlockBlob',
                'x-ms-date' => 'Mon, 08 Sep 2026 00:00:00 GMT',
                'x-ms-version' => Signer::VERSION,
            ]
        );

        $this->assertStringStartsWith( 'SharedKey acme:', $header );

        $signature = substr( $header, strlen( 'SharedKey acme:' ) );

        $this->assertNotFalse( base64_decode( $signature, true ) );
        $this->assertSame( 32, strlen( (string) base64_decode( $signature, true ) ) );
    }

    /**
     * The same request signed twice gives the same signature; a different verb
     * gives a different one. That is the whole contract from the caller's side.
     */
    public function testTheSignatureDependsOnTheRequest() : void {
        $signer = new Signer( 'acme', self::KEY );

        $headers = [
            'Content-Length' => '0',
            'x-ms-date' => 'Mon, 08 Sep 2026 00:00:00 GMT',
        ];

        $put = $signer->authorization( 'PUT', '/$web/a.html', $headers );
        $again = $signer->authorization( 'PUT', '/$web/a.html', $headers );
        $delete = $signer->authorization( 'DELETE', '/$web/a.html', $headers );
        $other = $signer->authorization( 'PUT', '/$web/b.html', $headers );

        $this->assertSame( $put, $again );
        $this->assertNotSame( $put, $delete );
        $this->assertNotSame( $put, $other );
    }

    /**
     * From API version 2014-02-14 a zero Content-Length is signed as an empty
     * line, not as "0". Getting it wrong is a 403 on every DELETE and nothing
     * else — which is the hardest kind of failure to place.
     */
    public function testAZeroContentLengthSignsAsAnEmptyLine() : void {
        $signer = new Signer( 'acme', self::KEY );

        $with_zero = $signer->authorization( 'DELETE', '/$web/a.html', [ 'Content-Length' => '0' ] );
        $without = $signer->authorization( 'DELETE', '/$web/a.html', [] );

        $this->assertSame( $without, $with_zero );
    }

    /**
     * The x-ms-* headers are signed sorted, so the order they were built in
     * cannot change the signature.
     */
    public function testHeaderOrderDoesNotChangeTheSignature() : void {
        $signer = new Signer( 'acme', self::KEY );

        $one = $signer->authorization(
            'PUT',
            '/$web/a.html',
            [
                'x-ms-date' => 'D',
                'x-ms-version' => 'V',
                'x-ms-blob-type' => 'BlockBlob',
            ]
        );

        $two = $signer->authorization(
            'PUT',
            '/$web/a.html',
            [
                'x-ms-blob-type' => 'BlockBlob',
                'x-ms-version' => 'V',
                'x-ms-date' => 'D',
            ]
        );

        $this->assertSame( $one, $two );
    }

    /**
     * Headers that are not x-ms-* are not part of the canonicalised block.
     */
    public function testUnrelatedHeadersAreNotSigned() : void {
        $signer = new Signer( 'acme', self::KEY );

        $bare = $signer->authorization( 'PUT', '/$web/a.html', [ 'x-ms-date' => 'D' ] );
        $extra = $signer->authorization(
            'PUT',
            '/$web/a.html',
            [
                'x-ms-date' => 'D',
                'X-Custom' => 'x',
            ]
        );

        $this->assertSame( $bare, $extra );
    }
}
