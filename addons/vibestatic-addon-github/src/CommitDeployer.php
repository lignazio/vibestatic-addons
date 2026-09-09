<?php
/**
 * The part of a deploy to a git host that is the same for all three of them.
 *
 * **Why this is not `WP2Static\PlanDrivenDeployer`.** That class sends one file
 * per call, which is right for a filesystem, for FTP and for an object store.
 * A git host is not any of those: every write is a commit, and a commit per
 * file means four thousand commits and four thousand API calls for one deploy.
 * All three services here take a batch — GitHub as a tree, GitLab as an actions
 * array, Bitbucket as a multipart form — so the unit of work is a batch, and
 * that is what this class drives.
 *
 * It still gets its answer from the same place: `DeployCache::plan()` says what
 * changed, what is new and what has left the site. The add-ons this replaces
 * kept their own idea of that in a `WP2STATIC-GITHUB-PREVIOUS-HASHES.txt` file
 * in the uploads directory, compared with `crc32` — a checksum with collisions
 * you can find by hand — and rebuilt it from nothing whenever the file went
 * missing, which meant re-uploading the site.
 *
 * **This file is identical in every VibeStatic git add-on**, its namespace line
 * apart.
 *
 * @package WP2StaticGitHub
 */

namespace WP2StaticGitHub;

use WP2Static\DeployCache;
use WP2Static\WsLog;

abstract class CommitDeployer {

    /**
     * How many files go in one commit.
     *
     * A cap on count and a cap on bytes, because either one alone lets the
     * other run away: five thousand favicons are a large request by count, and
     * three videos are a large request by size.
     */
    const FILES_PER_COMMIT = 100;

    const BYTES_PER_COMMIT = 20971520;

    /**
     * The DeployCache namespace: the add-on's slug, never `default`.
     */
    abstract protected function deployCacheNamespace() : string;

    /**
     * What this deployer calls itself in the log.
     */
    abstract protected function label() : string;

    /**
     * Read the settings and get ready. False abandons the deploy without an
     * error of its own: whatever failed has already said so.
     */
    abstract protected function connect() : bool;

    /**
     * Write one batch to the repository.
     *
     * @param array<string, string> $upserts Repository path => local file path.
     * @param string[]              $deletes Repository paths to remove.
     * @param string                $message The commit message.
     * @return bool Whether the commit landed.
     */
    abstract protected function commit( array $upserts, array $deletes, string $message ) : bool;

    /**
     * Where the site goes inside the repository. Empty for its root.
     */
    protected function root() : string {
        return '';
    }

    /**
     * The commit message for one batch.
     *
     * @param int $batch Which batch this is, counting from one.
     * @param int $of    How many there are in total.
     */
    protected function message( int $batch, int $of ) : string {
        if ( 1 === $of ) {
            return 'Published by VibeStatic';
        }

        return sprintf( 'Published by VibeStatic (%d/%d)', $batch, $of );
    }

    /**
     * Run the deploy.
     *
     * @param string $processed_site_path The processed site's directory.
     */
    final public function deploy( string $processed_site_path ) : void {
        if ( ! is_dir( $processed_site_path ) ) {
            WsLog::l( 'Processed folder does not exist: ' . $processed_site_path );

            return;
        }

        if ( ! $this->connect() ) {
            return;
        }

        $namespace = $this->deployCacheNamespace();
        $plan = DeployCache::plan( $namespace );

        WsLog::l( $plan->summary() );

        $to_deploy = $plan->toDeploy();
        $to_delete = $plan->toDelete();

        if ( ! $to_deploy && ! $to_delete ) {
            WsLog::l( $this->label() . ': nothing changed, no commit made.' );

            return;
        }

        $batches = $this->batch( $processed_site_path, $to_deploy, $to_delete );
        $total = count( $batches );

        $sent = 0;
        $removed = 0;
        $failed = 0;

        foreach ( $batches as $index => $batch ) {
            if ( ! $this->commit( $batch['upserts'], $batch['deletes'], $this->message( $index + 1, $total ) ) ) {
                $failed += count( $batch['upserts'] ) + count( $batch['deletes'] );

                /*
                 * Stop at the first failed batch rather than carrying on.
                 * A git host that has just refused a commit — a bad token, a
                 * protected branch, a repository over quota — will refuse the
                 * next one too, and going on turns one clear error in the log
                 * into forty identical ones.
                 */
                WsLog::l( $this->label() . ': stopping after a failed commit.' );

                break;
            }

            /*
             * The cache is written after the commit landed, and per batch. An
             * interrupted deploy therefore leaves the batches it did not reach
             * still pending, instead of believing they are done.
             */
            foreach ( $batch['paths'] as $path ) {
                DeployCache::addFile( $path, $namespace );

                ++$sent;
            }

            if ( $batch['deleted_paths'] ) {
                DeployCache::rmPaths( $batch['deleted_paths'], $namespace );

                $removed += count( $batch['deleted_paths'] );
            }
        }

        WsLog::l(
            sprintf(
                '%s complete: %d sent, %d removed, %d failed, in %d commit(s).',
                $this->label(),
                $sent,
                $removed,
                $failed,
                $total
            )
        );
    }

    /**
     * Where one root-relative path lands in the repository.
     *
     * Both halves are trimmed before they are joined. `ltrim( $root . $path )`
     * — which is what this was — only strips the front of the result, so a
     * configured prefix of `docs` and a path of `/index.html` produced
     * `docs//index.html`: a repository with a directory whose name is the empty
     * string, and a published site that 404s.
     *
     * @param string $root Prefix inside the repository, or empty for its root.
     * @param string $path The root-relative path.
     */
    private static function remotePath( string $root, string $path ) : string {
        $prefix = trim( $root, '/' );
        $relative = ltrim( $path, '/' );

        return '' === $prefix ? $relative : $prefix . '/' . $relative;
    }

    /**
     * A batch with nothing in it yet.
     *
     * @return array{upserts: array<string, string>, deletes: string[], paths: string[], deleted_paths: string[]}
     */
    private static function emptyBatch() : array {
        return [
            'upserts' => [],
            'deletes' => [],
            'paths' => [],
            'deleted_paths' => [],
        ];
    }

    /**
     * Split the plan into commit-sized pieces.
     *
     * Deletions ride along with the uploads rather than forming a batch of
     * their own, so a rename lands as one commit instead of two — and the
     * repository never goes through a state where the old path is gone and the
     * new one has not arrived.
     *
     * @param string   $processed_site_path Local root.
     * @param string[] $to_deploy           Root-relative paths to send.
     * @param string[] $to_delete           Root-relative paths that have gone.
     * @return list<array{upserts: array<string, string>, deletes: string[], paths: string[], deleted_paths: string[]}>
     */
    private function batch( string $processed_site_path, array $to_deploy, array $to_delete ) : array {
        $root = $this->root();

        $batches = [];
        $current = self::emptyBatch();
        $bytes = 0;

        foreach ( $to_deploy as $path ) {
            $local = $processed_site_path . $path;

            if ( ! is_file( $local ) ) {
                // The plan was made from the processed site; a path that is not
                // there any more means something wrote to it mid-deploy.
                WsLog::l( $this->label() . ' skipped a file that vanished: ' . $path );

                continue;
            }

            $size = (int) filesize( $local );

            if ( $current['upserts']
                && ( count( $current['upserts'] ) >= self::FILES_PER_COMMIT
                    || $bytes + $size > self::BYTES_PER_COMMIT )
            ) {
                $batches[] = $current;
                $current = self::emptyBatch();
                $bytes = 0;
            }

            $current['upserts'][ self::remotePath( $root, $path ) ] = $local;
            $current['paths'][] = $path;
            $bytes += $size;
        }

        foreach ( $to_delete as $path ) {
            if ( count( $current['deletes'] ) >= self::FILES_PER_COMMIT ) {
                $batches[] = $current;
                $current = self::emptyBatch();
                $bytes = 0;
            }

            $current['deletes'][] = self::remotePath( $root, $path );
            $current['deleted_paths'][] = $path;
        }

        if ( $current['upserts'] || $current['deletes'] ) {
            $batches[] = $current;
        }

        return $batches;
    }
}
