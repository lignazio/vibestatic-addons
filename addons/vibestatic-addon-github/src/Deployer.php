<?php
/**
 * Publish the generated site into a GitHub repository.
 *
 * **One commit per batch, through the Git Data API.** The add-on this replaces
 * used the Contents API, which writes one file per request and needs the file's
 * current blob SHA to overwrite it — so publishing a site cost a GET and a PUT
 * per file. On the 4418-URL site the fork was tested against that is close to
 * nine thousand requests against a limit of five thousand an hour: the deploy
 * could not finish, and the half of it that had run was already committed.
 *
 * Here a batch becomes: a blob per changed file, one tree on top of the branch's
 * current tree, one commit, one ref update. Deletions are tree entries with a
 * null SHA, which is how git removes a path.
 *
 * @package WP2StaticGitHub
 */

namespace WP2StaticGitHub;

use WP2Static\Addon\Options;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends CommitDeployer {

    const API = 'https://api.github.com/';

    /**
     * The file mode git records for a plain, non-executable file.
     */
    const BLOB_MODE = '100644';

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null Injectable, so the commit logic can be exercised without
     *                  a GitHub account on the other end.
     */
    private $client = null;

    /**
     * @var string owner/repo
     */
    private $repository = '';

    /**
     * @var string
     */
    private $branch = '';

    /**
     * @var string The commit the branch currently points at.
     */
    private $head = '';

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-github';
    }

    protected function label() : string {
        return 'GitHub deployment';
    }

    protected function root() : string {
        $prefix = trim( $this->options->get( 'githubPath' ), '/' );

        return '' === $prefix ? '' : $prefix . '/';
    }

    protected function message( int $batch, int $of ) : string {
        $template = trim( $this->options->get( 'githubCommitMessage' ) );

        if ( '' === $template ) {
            $template = 'Published by VibeStatic';
        }

        return 1 === $of ? $template : sprintf( '%s (%d/%d)', $template, $batch, $of );
    }

    protected function connect() : bool {
        $this->repository = trim( $this->options->get( 'githubRepository' ), '/ ' );

        /*
         * Checked, and said plainly. Upstream did
         * `list($user, $repo) = explode('/', $settings['ghRepo'])`, so a value
         * without a slash in it — which is what somebody types when the field
         * says "repository" — was an undefined-offset notice followed by a
         * request to a URL with the word "null" in it.
         */
        if ( ! preg_match( '#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $this->repository ) ) {
            WsLog::l( 'GitHub: the repository must be given as owner/name.' );

            return false;
        }

        $token = $this->options->plain( 'githubToken' );

        if ( '' === $token ) {
            WsLog::l( 'GitHub: the access token is not set.' );

            return false;
        }

        $this->branch = trim( $this->options->get( 'githubBranch' ) );

        if ( '' === $this->branch ) {
            $this->branch = 'main';
        }

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => self::API,
                    'http_errors' => false,
                    'timeout' => 300,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                        // GitHub rejects requests without one.
                        'User-Agent' => 'VibeStatic',
                    ],
                ]
            );
        }

        $head = $this->head();

        if ( null === $head ) {
            return false;
        }

        $this->head = $head;

        return true;
    }

    /**
     * The commit the branch points at now.
     */
    private function head() : ?string {
        $ref = $this->get( 'repos/' . $this->repository . '/git/ref/heads/' . rawurlencode( $this->branch ) );

        if ( null === $ref ) {
            WsLog::l(
                sprintf(
                    'GitHub: could not read branch %s of %s. Check the token, the repository and that the branch exists.',
                    $this->branch,
                    $this->repository
                )
            );

            return null;
        }

        $object = $ref['object'] ?? null;
        $sha = is_array( $object ) ? ( $object['sha'] ?? null ) : null;

        if ( ! is_string( $sha ) ) {
            WsLog::l( 'GitHub: the branch reference had no commit SHA in it.' );

            return null;
        }

        return $sha;
    }

    /**
     * Write one batch as a single commit.
     *
     * @param array<string, string> $upserts Repository path => local file path.
     * @param string[]              $deletes Repository paths to remove.
     * @param string                $message The commit message.
     */
    protected function commit( array $upserts, array $deletes, string $message ) : bool {
        $tree = [];

        foreach ( $upserts as $remote => $local ) {
            $blob = $this->blob( $local );

            if ( null === $blob ) {
                return false;
            }

            $tree[] = [
                'path' => $remote,
                'mode' => self::BLOB_MODE,
                'type' => 'blob',
                'sha' => $blob,
            ];
        }

        foreach ( $deletes as $remote ) {
            /*
             * A null SHA is how the Git Data API says "this path is not in the
             * new tree". `wp_json_encode` keeps it as JSON null, which is what
             * GitHub reads; an empty string would create an entry pointing at
             * nothing.
             */
            $tree[] = [
                'path' => $remote,
                'mode' => self::BLOB_MODE,
                'type' => 'blob',
                'sha' => null,
            ];
        }

        if ( ! $tree ) {
            return true;
        }

        $created = $this->post(
            'repos/' . $this->repository . '/git/trees',
            [
                'base_tree' => $this->head,
                'tree' => $tree,
            ]
        );

        if ( null === $created || ! isset( $created['sha'] ) || ! is_string( $created['sha'] ) ) {
            WsLog::l( 'GitHub: could not create the tree.' );

            return false;
        }

        $commit = $this->post(
            'repos/' . $this->repository . '/git/commits',
            [
                'message' => $message,
                'tree' => $created['sha'],
                'parents' => [ $this->head ],
            ]
        );

        if ( null === $commit || ! isset( $commit['sha'] ) || ! is_string( $commit['sha'] ) ) {
            WsLog::l( 'GitHub: could not create the commit.' );

            return false;
        }

        $updated = $this->request(
            'PATCH',
            'repos/' . $this->repository . '/git/refs/heads/' . rawurlencode( $this->branch ),
            [ 'sha' => $commit['sha'] ]
        );

        if ( null === $updated ) {
            WsLog::l(
                sprintf(
                    'GitHub: the commit was made but branch %s could not be moved to it. A protected branch or a push rule would do that.',
                    $this->branch
                )
            );

            return false;
        }

        /*
         * The next batch builds on this commit. Without this line every batch
         * would branch off the same starting point and the last one to land
         * would be the only one that survived.
         */
        $this->head = $commit['sha'];

        return true;
    }

    /**
     * Upload one file's contents and get back its blob SHA.
     *
     * @param string $local Absolute path of the file.
     */
    private function blob( string $local ) : ?string {
        $contents = file_get_contents( $local );

        if ( false === $contents ) {
            WsLog::l( 'GitHub could not read for upload: ' . $local );

            return null;
        }

        $blob = $this->post(
            'repos/' . $this->repository . '/git/blobs',
            [
                // Base64 for everything, text included: the API's other encoding
                // is `utf-8`, and a file that is not valid UTF-8 sent that way
                // is rejected or silently mangled.
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API's own encoding, not obfuscation.
                'content' => base64_encode( $contents ),
                'encoding' => 'base64',
            ]
        );

        if ( null === $blob || ! isset( $blob['sha'] ) || ! is_string( $blob['sha'] ) ) {
            WsLog::l( 'GitHub: could not create a blob for ' . $local );

            return null;
        }

        return $blob['sha'];
    }

    /**
     * @param string $uri Path under the API root.
     * @return mixed[]|null
     */
    private function get( string $uri ) : ?array {
        return $this->request( 'GET', $uri, null );
    }

    /**
     * @param string               $uri  Path under the API root.
     * @param array<string, mixed> $body JSON body.
     * @return mixed[]|null
     */
    private function post( string $uri, array $body ) : ?array {
        return $this->request( 'POST', $uri, $body );
    }

    /**
     * One request, with the response checked and the reason logged.
     *
     * @param string                    $method HTTP verb.
     * @param string                    $uri    Path under the API root.
     * @param array<string, mixed>|null $body   JSON body, or null for none.
     * @return mixed[]|null The decoded body, or null on failure.
     */
    private function request( string $method, string $uri, ?array $body ) : ?array {
        if ( ! $this->client ) {
            return null;
        }

        $options = [];

        if ( null !== $body ) {
            $options['json'] = $body;
        }

        try {
            $response = $this->client->request( $method, $uri, $options );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'GitHub request failed: ' . $exception->getMessage() );

            return null;
        }

        $status = $response->getStatusCode();

        if ( $status < 200 || $status >= 300 ) {
            /*
             * The rate limit gets its own line, because it is the failure this
             * add-on is most likely to hit and the one whose remedy — wait, or
             * deploy less often — is not obvious from "GitHub answered 403".
             */
            if ( 403 === $status && '0' === $response->getHeaderLine( 'X-RateLimit-Remaining' ) ) {
                WsLog::l(
                    'GitHub: the API rate limit is used up. The deploy stops here and can be resumed later; nothing already committed is lost.'
                );

                return null;
            }

            WsLog::l( sprintf( 'GitHub answered %d for %s %s', $status, $method, $uri ) );

            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode( (string) $response->getBody(), true );

        return is_array( $decoded ) ? $decoded : [];
    }
}
