<?php
/**
 * A deployer that deploys nothing, and says exactly what it would have done.
 *
 * **Read this before writing a real one.** It shows the two things upstream's
 * boilerplate got wrong and that every add-on copied from it inherited:
 *
 * 1. *Ask the plan, do not walk the directory.* The core works out what
 *    changed, what is new and what has left the site — see
 *    `WP2Static\DeployCache::plan()`. Upstream iterated the whole processed
 *    site and asked `fileisCached()` per file, which finds what to upload but
 *    can never find what to **delete**: a page removed in WordPress stayed
 *    online forever. Extending `WP2Static\PlanDrivenDeployer` gets the upload
 *    loop, the deletion pass, the empty-directory tidy-up and the summary line
 *    for free.
 *
 * 2. *Record a file as sent only once it has arrived.* Upstream's example
 *    decided success with `rand( 0, 1 )` and wrote to the DeployCache either
 *    way. A real add-on that copies that shape reports a deploy that lost half
 *    the site as a success, and — worse — never retries the lost half, because
 *    the cache now says it is there.
 *
 * This class does neither: `put()` and `delete()` return false, so nothing is
 * ever recorded as sent, and running it twice reports the same plan twice.
 *
 * @package WP2StaticBoilerplate
 */

namespace WP2StaticBoilerplate;

use WP2Static\WsLog;

class Deployer extends \WP2Static\PlanDrivenDeployer {

    /**
     * @var Options
     */
    private $options;

    /**
     * @var int Files the plan would have sent.
     */
    private $would_send = 0;

    /**
     * @var int Files the plan would have removed.
     */
    private $would_remove = 0;

    public function __construct( Options $options ) {
        $this->options = $options;
    }

    /**
     * The DeployCache namespace: the add-on's slug, never `default`.
     *
     * The cache is shared between deployers. Two of them writing to the same
     * namespace would each read the other's uploads as its own and skip files
     * it had never sent.
     */
    protected function deployCacheNamespace() : string {
        return 'wp2static-addon-boilerplate';
    }

    protected function label() : string {
        return 'Boilerplate (dry run)';
    }

    protected function connect() : bool {
        WsLog::l(
            'Boilerplate: nothing will be uploaded. This add-on reports the plan and stops.'
        );

        return true;
    }

    protected function put( string $local, string $destination ) : bool {
        ++$this->would_send;

        if ( $this->options->bool( 'listEveryPath' ) ) {
            WsLog::l( 'Boilerplate would send: ' . $destination );
        }

        /*
         * False, deliberately. Returning true here would put the path in the
         * DeployCache, and the next deploy — including a real one, by a real
         * add-on, in this same namespace — would skip a file that was never
         * anywhere.
         */
        return false;
    }

    protected function delete( string $destination ) : bool {
        ++$this->would_remove;

        if ( $this->options->bool( 'listEveryPath' ) ) {
            WsLog::l( 'Boilerplate would remove: ' . $destination );
        }

        return false;
    }

    protected function removeDirectory( string $destination ) : bool {
        return false;
    }

    protected function disconnect() : void {
        WsLog::l(
            sprintf(
                'Boilerplate dry run: %d file(s) would be sent, %d would be removed.',
                $this->would_send,
                $this->would_remove
            )
        );
    }
}
