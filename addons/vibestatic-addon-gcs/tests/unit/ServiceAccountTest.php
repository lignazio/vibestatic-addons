<?php
/**
 * @package WP2StaticGCS
 */

namespace WP2StaticGCS\Tests;

use PHPUnit\Framework\TestCase;
use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Response;
use WP2Static\WsLog;
use WP2StaticGCS\ServiceAccount;

class ServiceAccountTest extends TestCase {

    /**
     * @var string A real RSA key, generated for this test run.
     */
    private static $private_key = '';

    /**
     * @var string Its public half, for verifying what the class signed.
     */
    private static $public_key = '';

    public static function setUpBeforeClass() : void {
        $resource = openssl_pkey_new(
            [
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]
        );

        openssl_pkey_export( $resource, self::$private_key );

        $details = openssl_pkey_get_details( $resource );

        self::$public_key = is_array( $details ) ? (string) $details['key'] : '';
    }

    protected function setUp() : void {
        parent::setUp();

        WsLog::$lines = [];
        $GLOBALS['transients'] = [];
    }

    private function keyFile() : string {
        return (string) json_encode(
            [
                'type' => 'service_account',
                'client_email' => 'deploy@acme.iam.gserviceaccount.com',
                'private_key' => self::$private_key,
            ]
        );
    }

    public function testAKeyFileThatIsNotJSONIsRefused() : void {
        $this->assertNull( ServiceAccount::fromJSON( 'not json' ) );
        $this->assertStringContainsString( 'not valid JSON', WsLog::$lines[0] );
    }

    public function testAKeyFileWithoutAnEmailIsRefused() : void {
        $this->assertNull( ServiceAccount::fromJSON( '{"private_key":"x"}' ) );
        $this->assertStringContainsString( 'client_email', WsLog::$lines[0] );
    }

    public function testAKeyFileWithoutAPrivateKeyIsRefused() : void {
        $this->assertNull( ServiceAccount::fromJSON( '{"client_email":"a@b.c"}' ) );
        $this->assertStringContainsString( 'private_key', WsLog::$lines[0] );
    }

    public function testAValidKeyFileIsAccepted() : void {
        $account = ServiceAccount::fromJSON( $this->keyFile() );

        $this->assertNotNull( $account );
        $this->assertSame( 'deploy@acme.iam.gserviceaccount.com', $account->email() );
    }

    /**
     * A signed JWT, exchanged for a token. The assertion sent is checked as
     * well as the token received: it is the part Google rejects silently.
     */
    public function testATokenIsMintedFromASignedAssertion() : void {
        $client = new Client();
        $client->responses = [ new Response( 200, '{"access_token":"ya29.abc","expires_in":3600}' ) ];

        $account = ServiceAccount::fromJSON( $this->keyFile(), $client );

        $this->assertNotNull( $account );
        $this->assertSame( 'ya29.abc', $account->accessToken() );

        $assertion = $client->calls[0][2]['form_params']['assertion'];

        $this->assertSame(
            'urn:ietf:params:oauth:grant-type:jwt-bearer',
            $client->calls[0][2]['form_params']['grant_type']
        );

        [ $header, $claims, $signature ] = explode( '.', $assertion );

        $decoded_header = json_decode( self::base64url_decode( $header ), true );
        $decoded_claims = json_decode( self::base64url_decode( $claims ), true );

        $this->assertSame( 'RS256', $decoded_header['alg'] );
        $this->assertSame( 'deploy@acme.iam.gserviceaccount.com', $decoded_claims['iss'] );
        $this->assertSame( ServiceAccount::TOKEN_URI, $decoded_claims['aud'] );
        $this->assertStringContainsString( 'devstorage', $decoded_claims['scope'] );

        // The signature verifies against the key that made it.
        $this->assertSame(
            1,
            openssl_verify(
                "$header.$claims",
                self::base64url_decode( $signature ),
                self::$public_key,
                OPENSSL_ALGO_SHA256
            )
        );
    }

    public function testTheTokenIsCachedRatherThanMintedPerRequest() : void {
        $client = new Client();
        $client->responses = [ new Response( 200, '{"access_token":"ya29.abc"}' ) ];

        $account = ServiceAccount::fromJSON( $this->keyFile(), $client );

        $account->accessToken();
        $account->accessToken();

        $this->assertCount( 1, $client->calls );
    }

    /**
     * The failed exchange's body echoes parts of the assertion, and this line
     * goes into a log users are asked to paste into bug reports. Only the
     * status is logged.
     */
    public function testARefusedExchangeLogsTheStatusAndNotTheBody() : void {
        $client = new Client();
        $client->responses = [
            new Response( 400, '{"error":"invalid_grant","error_description":"eyJhbGciOi..."}' ),
        ];

        $account = ServiceAccount::fromJSON( $this->keyFile(), $client );

        $this->assertNull( $account->accessToken() );

        $logged = implode( ' ', WsLog::$lines );

        $this->assertStringContainsString( 'HTTP 400', $logged );
        $this->assertStringNotContainsString( 'eyJhbGciOi', $logged );
    }

    public function testAMalformedPrivateKeyIsReportedRatherThanSigningWithNothing() : void {
        $account = ServiceAccount::fromJSON(
            (string) json_encode(
                [
                    'client_email' => 'a@b.c',
                    'private_key' => 'not a key',
                ]
            )
        );

        $this->assertNotNull( $account );
        $this->assertNull( $account->accessToken() );
        $this->assertStringContainsString( 'private key', implode( ' ', WsLog::$lines ) );
    }

    private static function base64url_decode( string $value ) : string {
        return (string) base64_decode( strtr( $value, '-_', '+/' ), true );
    }
}
