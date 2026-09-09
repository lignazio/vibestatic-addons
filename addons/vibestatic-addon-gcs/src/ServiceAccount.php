<?php
/**
 * A Google service account, and the access token it can be exchanged for.
 *
 * **Why there is no SDK here.** Upstream required `google/cloud-storage ^1.23`,
 * which pulls in some ninety packages including its own Guzzle, its own PSR-7
 * and its own gRPC glue. Two WordPress plugins each shipping a different major
 * of that stack under the same class names break each other, and the version
 * pinned in 2021 no longer installs on PHP 8.2. The core made the same call for
 * S3 and signs its own requests; this does the same thing with the smaller of
 * the two problems, because Google's is a plain OAuth2 exchange rather than a
 * per-request signature.
 *
 * The whole of it: build a JWT asserting who we are and what we want, sign it
 * with the service account's private key, POST it to Google's token endpoint,
 * get an access token back, put it in a transient until shortly before it
 * expires.
 *
 * @package WP2StaticGCS
 */

namespace WP2StaticGCS;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class ServiceAccount {

    const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    const SCOPE = 'https://www.googleapis.com/auth/devstorage.read_write';

    /**
     * How long a token is asked for, and how long it is kept.
     *
     * Google issues one-hour tokens. The transient expires five minutes early,
     * so a deploy that starts just before the hour does not lose the token
     * halfway through.
     */
    const LIFETIME = 3600;

    const CACHE_MARGIN = 300;

    /**
     * @var string The `client_email` from the key file.
     */
    private $email;

    /**
     * @var string The PEM private key.
     */
    private $key;

    /**
     * @var Client|null
     */
    private $client;

    private function __construct( string $email, string $key, ?Client $client = null ) {
        $this->email = $email;
        $this->key = $key;
        $this->client = $client;
    }

    /**
     * Read a service account out of the JSON Google hands out.
     *
     * The JSON is pasted into the settings field rather than left on disk as a
     * path. Upstream took `keyFilePath` — a filename, typed into a text box,
     * opened by the plugin — which is a file read whose target the operator
     * controls and whose contents nobody validates, and which breaks whenever
     * the site moves or the deploy runs as a different user.
     *
     * @param string      $json   The service account key file's contents.
     * @param Client|null $client Injectable for tests.
     */
    public static function fromJSON( string $json, ?Client $client = null ) : ?self {
        /** @var mixed $decoded */
        $decoded = json_decode( $json, true );

        if ( ! is_array( $decoded ) ) {
            WsLog::l( 'GCS: the service account key is not valid JSON.' );

            return null;
        }

        $email = $decoded['client_email'] ?? '';
        $key = $decoded['private_key'] ?? '';

        if ( ! is_string( $email ) || '' === $email ) {
            WsLog::l( 'GCS: the service account key has no client_email.' );

            return null;
        }

        if ( ! is_string( $key ) || '' === $key ) {
            WsLog::l( 'GCS: the service account key has no private_key.' );

            return null;
        }

        return new self( $email, $key, $client );
    }

    /**
     * Which account this is. Used for the cache key and for the log.
     */
    public function email() : string {
        return $this->email;
    }

    /**
     * An access token, from cache or freshly minted.
     */
    public function accessToken() : ?string {
        $cache_key = 'vibestatic_gcs_token_' . md5( $this->email );

        /** @var mixed $cached */
        $cached = get_transient( $cache_key );

        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $token = $this->mint();

        if ( null === $token ) {
            return null;
        }

        set_transient( $cache_key, $token, self::LIFETIME - self::CACHE_MARGIN );

        return $token;
    }

    /**
     * Sign an assertion and exchange it for a token.
     */
    private function mint() : ?string {
        $assertion = $this->assertion();

        if ( null === $assertion ) {
            return null;
        }

        $client = $this->client ?? new Client(
            [
                'http_errors' => false,
                'timeout' => 30,
            ]
        );

        try {
            $response = $client->request(
                'POST',
                self::TOKEN_URI,
                [
                    'form_params' => [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $assertion,
                    ],
                ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'GCS: could not reach the Google token endpoint: ' . $exception->getMessage() );

            return null;
        }

        if ( 200 !== $response->getStatusCode() ) {
            /*
             * The status, not the body. The body of a failed token exchange
             * echoes parts of the assertion, and this line goes into a log the
             * user is asked to paste into bug reports.
             */
            WsLog::l(
                sprintf(
                    'GCS: Google refused the service account (HTTP %d). Check the key and that the Storage API is enabled.',
                    $response->getStatusCode()
                )
            );

            return null;
        }

        /** @var mixed $body */
        $body = json_decode( (string) $response->getBody(), true );

        if ( ! is_array( $body ) || ! isset( $body['access_token'] ) || ! is_string( $body['access_token'] ) ) {
            WsLog::l( 'GCS: the token response had no access_token in it.' );

            return null;
        }

        return $body['access_token'];
    }

    /**
     * The signed JWT.
     *
     * RS256, which is the only algorithm Google accepts here, so the private
     * key has to be an RSA one — `openssl_sign()` says so rather than failing
     * later with an opaque 400.
     */
    private function assertion() : ?string {
        $now = time();

        $header = self::base64url(
            (string) wp_json_encode(
                [
                    'alg' => 'RS256',
                    'typ' => 'JWT',
                ]
            )
        );

        $claims = self::base64url(
            (string) wp_json_encode(
                [
                    'iss' => $this->email,
                    'scope' => self::SCOPE,
                    'aud' => self::TOKEN_URI,
                    'iat' => $now,
                    'exp' => $now + self::LIFETIME,
                ]
            )
        );

        $signature = '';

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed key makes openssl_sign() emit a warning as well as returning false; the message below is the useful one.
        $signed = @openssl_sign( "$header.$claims", $signature, $this->key, OPENSSL_ALGO_SHA256 );

        if ( ! $signed ) {
            WsLog::l(
                'GCS: could not sign with the service account private key. Paste the key file whole, newlines included.'
            );

            return null;
        }

        // $signature is filled in by reference, so its declared type stays
        // mixed until it is checked.
        if ( ! is_string( $signature ) ) {
            return null;
        }

        return $header . '.' . $claims . '.' . self::base64url( $signature );
    }

    /**
     * base64url, which is base64 with two characters swapped and no padding.
     *
     * @param string $value What to encode.
     */
    private static function base64url( string $value ) : string {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT encoding, not obfuscation.
        return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
    }
}
