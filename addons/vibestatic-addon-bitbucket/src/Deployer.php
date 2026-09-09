<?php
/**
 * Publish the generated site into a Bitbucket repository.
 *
 * Bitbucket's `src` endpoint takes a multipart form: each part whose name is a
 * path becomes that file's new contents, and a repeated `files` field names
 * paths to delete. Both happen in one commit, and it upserts — so, unlike
 * GitLab, there is no need to know first which paths the branch already has.
 *
 * The add-on this replaces is from 2019 and drove the `SitePublisher` base
 * class that WP2Static 7 removed; none of it runs against the current core.
 *
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket;

use WP2Static\Vendor\GuzzleHttp\Client;
use WP2Static\Vendor\GuzzleHttp\Exception\GuzzleException;
use WP2Static\WsLog;

class Deployer extends CommitDeployer {

    const API = 'https://api.bitbucket.org/2.0/';

    /**
     * @var Options
     */
    private $options;

    /**
     * @var Client|null
     */
    private $client = null;

    /**
     * @var string workspace/repository
     */
    private $repository = '';

    /**
     * @var string
     */
    private $branch = '';

    public function __construct( Options $options, ?Client $client = null ) {
        $this->options = $options;
        $this->client = $client;
    }

    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-bitbucket';
    }

    protected function label() : string {
        return 'Bitbucket deployment';
    }

    protected function root() : string {
        $prefix = trim( $this->options->get( 'bitbucketPath' ), '/' );

        return '' === $prefix ? '' : $prefix . '/';
    }

    protected function message( int $batch, int $of ) : string {
        $template = trim( $this->options->get( 'bitbucketCommitMessage' ) );

        if ( '' === $template ) {
            $template = 'Published by VibeStatic';
        }

        return 1 === $of ? $template : sprintf( '%s (%d/%d)', $template, $batch, $of );
    }

    protected function connect() : bool {
        $this->repository = trim( $this->options->get( 'bitbucketRepository' ), '/ ' );

        if ( ! preg_match( '#^[A-Za-z0-9._~-]+/[A-Za-z0-9._-]+$#', $this->repository ) ) {
            WsLog::l( 'Bitbucket: the repository must be given as workspace/name.' );

            return false;
        }

        $username = trim( $this->options->get( 'bitbucketUsername' ) );
        $password = $this->options->plain( 'bitbucketAppPassword' );

        if ( '' === $username || '' === $password ) {
            WsLog::l( 'Bitbucket: both the username and the app password are needed.' );

            return false;
        }

        $this->branch = trim( $this->options->get( 'bitbucketBranch' ) );

        if ( '' === $this->branch ) {
            $this->branch = 'main';
        }

        if ( ! $this->client ) {
            $this->client = new Client(
                [
                    'base_uri' => self::API,
                    'http_errors' => false,
                    'timeout' => 300,
                    // Basic auth with an app password, which is what Bitbucket
                    // Cloud accepts: the account password itself has not worked
                    // on the API since 2022.
                    'auth' => [ $username, $password ],
                ]
            );
        }

        return true;
    }

    /**
     * @param array<string, string> $upserts Repository path => local file path.
     * @param string[]              $deletes Repository paths to remove.
     * @param string                $message The commit message.
     */
    protected function commit( array $upserts, array $deletes, string $message ) : bool {
        if ( ! $this->client ) {
            return false;
        }

        $parts = [
            [
                'name' => 'message',
                'contents' => $message,
            ],
            [
                'name' => 'branch',
                'contents' => $this->branch,
            ],
        ];

        $handles = [];

        foreach ( $upserts as $remote => $local ) {
            $handle = fopen( $local, 'rb' );

            if ( false === $handle ) {
                WsLog::l( 'Bitbucket could not open for upload: ' . $local );

                $this->closeAll( $handles );

                return false;
            }

            $handles[] = $handle;

            /*
             * `filename` as well as `name`: without it Guzzle sends the part
             * without a filename and Bitbucket treats it as a plain form field
             * rather than as file contents.
             */
            $parts[] = [
                'name' => $remote,
                'contents' => $handle,
                'filename' => basename( $remote ),
            ];
        }

        foreach ( $deletes as $remote ) {
            $parts[] = [
                'name' => 'files',
                'contents' => $remote,
            ];
        }

        try {
            $response = $this->client->request(
                'POST',
                'repositories/' . $this->repository . '/src',
                [ 'multipart' => $parts ]
            );
        } catch ( GuzzleException $exception ) {
            WsLog::l( 'Bitbucket request failed: ' . $exception->getMessage() );

            $this->closeAll( $handles );

            return false;
        }

        $this->closeAll( $handles );

        $status = $response->getStatusCode();

        if ( $status >= 200 && $status < 300 ) {
            return true;
        }

        WsLog::l(
            sprintf(
                'Bitbucket answered %d. Check the app password has Repositories: Write and that branch %s exists.',
                $status,
                $this->branch
            )
        );

        return false;
    }

    /**
     * Close the file handles the request opened.
     *
     * Guzzle closes what it consumed, but a batch abandoned before the request
     * was made has handles nobody owns — and a deploy is thousands of files, so
     * "the request will tidy up" is how a run reaches the open-files limit.
     *
     * @param array<int, resource> $handles Open handles.
     */
    private function closeAll( array $handles ) : void {
        foreach ( $handles as $handle ) {
            if ( is_resource( $handle ) ) {
                fclose( $handle );
            }
        }
    }
}
