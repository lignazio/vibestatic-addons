<?php
/**
 * Publish the generated site into a GitLab repository.
 *
 * GitLab's commits API takes a list of actions — create, update, delete — and
 * applies them as one commit, so a whole batch is a single request. The one
 * thing it will not do is upsert: `create` fails on a path that exists and
 * `update` fails on one that does not. So the branch's file list is read once
 * at the start, and each path is given the action that fits.
 *
 * The add-on this replaces predates all of it: it drove the 2019 `SitePublisher`
 * base class, dispatched on `$_POST['ajax_action']`, and kept its own idea of
 * what had changed in a crc32 hash file under uploads.
 *
 * @package WP2StaticGitLab
 */

namespace WP2StaticGitLab;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends CommitDeployer {

    const API = 'https://gitlab.com/api/v4/';

    /**
     * How many tree entries GitLab returns per page. 100 is its maximum.
     */
    const TREE_PAGE = 100;

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null
     */
    private $client = null;

    /**
     * @var string The URL-encoded project path or numeric ID.
     */
    private $project = '';

    /**
     * @var string
     */
    private $branch = '';

    /**
     * @var array<string, true> Paths the branch already has.
     */
    private $existing = [];

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-gitlab';
    }

    protected function label() : string {
        return 'GitLab deployment';
    }

    protected function root() : string {
        $prefix = trim( $this->options->get( 'gitlabPath' ), '/' );

        return '' === $prefix ? '' : $prefix . '/';
    }

    protected function message( int $batch, int $of ) : string {
        $template = trim( $this->options->get( 'gitlabCommitMessage' ) );

        if ( '' === $template ) {
            $template = 'Published by VibeStatic';
        }

        return 1 === $of ? $template : sprintf( '%s (%d/%d)', $template, $batch, $of );
    }

    protected function connect() : bool {
        $project = trim( $this->options->get( 'gitlabProject' ), '/ ' );

        if ( '' === $project ) {
            WsLog::l( 'GitLab: the project is not set.' );

            return false;
        }

        // A project is addressed either by numeric ID or by its full path,
        // URL-encoded — `group/subgroup/project` becomes
        // `group%2Fsubgroup%2Fproject`.
        $this->project = rawurlencode( $project );

        $token = $this->options->plain( 'gitlabToken' );

        if ( '' === $token ) {
            WsLog::l( 'GitLab: the access token is not set.' );

            return false;
        }

        $this->branch = trim( $this->options->get( 'gitlabBranch' ) );

        if ( '' === $this->branch ) {
            $this->branch = 'main';
        }

        $base = trim( $this->options->get( 'gitlabApiUrl' ) );

        if ( '' === $base ) {
            $base = self::API;
        }

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => rtrim( $base, '/' ) . '/',
                    'http_errors' => false,
                    'timeout' => 300,
                    'headers' => [
                        'PRIVATE-TOKEN' => $token,
                        'Accept' => 'application/json',
                    ],
                ]
            );
        }

        return $this->readTree();
    }

    /**
     * Which paths the branch already holds.
     *
     * One pass, kept in memory, rather than a HEAD per file: the alternative is
     * a request per path to find out whether to say `create` or `update`, which
     * is the cost the whole batch design exists to avoid.
     *
     * Offset pagination, following `X-Next-Page`, with a hard stop at a
     * thousand pages so a header that never empties cannot loop forever.
     */
    private function readTree() : bool {
        $page = 1;

        do {
            $response = $this->request(
                'GET',
                'projects/' . $this->project . '/repository/tree',
                [
                    'ref' => $this->branch,
                    'recursive' => 'true',
                    'per_page' => (string) self::TREE_PAGE,
                    'page' => (string) $page,
                ]
            );

            if ( null === $response ) {
                WsLog::l(
                    sprintf(
                        'GitLab: could not read branch %s. Check the token, the project and that the branch exists.',
                        $this->branch
                    )
                );

                return false;
            }

            /** @var mixed $entries */
            $entries = json_decode( (string) $response->getBody(), true );

            if ( ! is_array( $entries ) ) {
                return false;
            }

            foreach ( $entries as $entry ) {
                if ( ! is_array( $entry ) || 'blob' !== ( $entry['type'] ?? '' ) ) {
                    continue;
                }

                // Checked, not cast: a path that came back as something other
                // than a string is the API having said something unexpected,
                // and turning it into the word "Array" would put a key nothing
                // matches into the map.
                if ( isset( $entry['path'] ) && is_string( $entry['path'] ) ) {
                    $this->existing[ $entry['path'] ] = true;
                }
            }

            $next = $response->getHeaderLine( 'X-Next-Page' );
            ++$page;
        } while ( '' !== $next && $page < 1000 );

        return true;
    }

    /**
     * @param array<string, string> $upserts Repository path => local file path.
     * @param string[]              $deletes Repository paths to remove.
     * @param string                $message The commit message.
     */
    protected function commit( array $upserts, array $deletes, string $message ) : bool {
        $actions = [];

        foreach ( $upserts as $remote => $local ) {
            $contents = file_get_contents( $local );

            if ( false === $contents ) {
                WsLog::l( 'GitLab could not read for upload: ' . $local );

                return false;
            }

            $actions[] = [
                'action' => isset( $this->existing[ $remote ] ) ? 'update' : 'create',
                'file_path' => $remote,
                // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the API's own encoding, not obfuscation.
                'content' => base64_encode( $contents ),
                'encoding' => 'base64',
            ];

            $this->existing[ $remote ] = true;
        }

        foreach ( $deletes as $remote ) {
            /*
             * A delete for a path GitLab does not have fails the whole commit,
             * and takes the uploads in the same batch down with it. The deploy
             * cache and the repository can disagree — somebody edited the
             * repository by hand — so the path is skipped rather than trusted.
             */
            if ( ! isset( $this->existing[ $remote ] ) ) {
                continue;
            }

            $actions[] = [
                'action' => 'delete',
                'file_path' => $remote,
            ];

            unset( $this->existing[ $remote ] );
        }

        if ( ! $actions ) {
            return true;
        }

        $response = $this->request(
            'POST',
            'projects/' . $this->project . '/repository/commits',
            [],
            [
                'branch' => $this->branch,
                'commit_message' => $message,
                'actions' => $actions,
            ]
        );

        return null !== $response;
    }

    /**
     * One request, with the response checked.
     *
     * The Guzzle option arrays are written out at each call rather than passed
     * through as `array<string, mixed>`, which matches none of the shape Guzzle
     * declares. The return type names the *prefixed* PSR-7 interface: the core
     * ships Guzzle under `WP2Static\Vendor\`, so plain `Psr\Http\Message\...`
     * is a class that does not exist here.
     *
     * @param string                $method HTTP verb.
     * @param string                $uri    Path under the API root.
     * @param array<string, string> $query  Query parameters, or none.
     * @param mixed[]|null          $json   JSON body, or null for none.
     * @return \WP2Static\Vendor\Psr\Http\Message\ResponseInterface|null
     */
    private function request( string $method, string $uri, array $query = [], ?array $json = null ) {
        if ( ! $this->client ) {
            return null;
        }

        try {
            if ( null !== $json ) {
                $response = $this->client->request( $method, $uri, [ 'json' => $json ] );
            } elseif ( $query ) {
                $response = $this->client->request( $method, $uri, [ 'query' => $query ] );
            } else {
                $response = $this->client->request( $method, $uri );
            }
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'GitLab request failed: ' . $exception->getMessage() );

            return null;
        }

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            return $response;
        }

        /*
         * GitLab puts a readable reason in the body — "A file with this name
         * already exists", "You are not allowed to push into this branch" — and
         * without it the log says only 400.
         */
        $reason = '';

        /** @var mixed $body */
        $body = json_decode( (string) $response->getBody(), true );

        if ( is_array( $body ) && isset( $body['message'] ) && is_string( $body['message'] ) ) {
            $reason = ': ' . $body['message'];
        }

        WsLog::l( sprintf( 'GitLab answered %d for %s %s%s', $status, $method, $uri, $reason ) );

        return null;
    }
}
