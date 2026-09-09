<?php
/**
 * What every VibeStatic add-on does the same way.
 *
 * **This file is identical in every VibeStatic add-on**, its namespace line
 * apart. It is the answer to the thing ADDONS.md called the root cause: a
 * boilerplate copied into ten repositories, so that one defect in it became ten
 * defects, and fixing one of them fixed one.
 *
 * A concrete add-on says what it is called, which options it has and what it
 * does with them; announcing itself to the core, registering its settings page,
 * authorising the save and dispatching the deploy happen here.
 *
 * **On the API this speaks.** Everything below is the 7.x/8.x add-on API, which
 * the fork has committed to keeping: `wp2static_register_addon` to announce
 * itself, `wp2static_add_menu_items` to get a settings page,
 * `wp2static_deploy` to be handed the processed site, and
 * `wp2static_post_deploy_trigger` afterwards. Nothing here is private to
 * VibeStatic — a third-party add-on can be written exactly like this one.
 *
 * @package WP2StaticAlgolia
 */

namespace WP2StaticAlgolia;

abstract class AddonController {

    /**
     * @var array<class-string, static> One instance per concrete add-on.
     */
    private static $instances = [];

    /**
     * The slug the core knows this add-on by.
     *
     * **It is not ours to prettify.** It keys the row in `wp2static_addons`,
     * the name of the options table and the DeployCache namespace, so it stays
     * `wp2static-addon-*` even though the plugin is now called VibeStatic:
     * renaming it would disconnect an existing installation from its own deploy
     * cache, and the next deploy would re-upload the entire site.
     */
    abstract public function slug() : string;

    /**
     * What the add-on is called in the menu and on its own page.
     */
    abstract public function name() : string;

    /**
     * One line for the Add-ons table.
     */
    abstract public function description() : string;

    /**
     * Where its documentation lives.
     */
    abstract public function docsUrl() : string;

    /**
     * What kind of add-on this is: `deploy`, `crawl`, `post_deploy`, `other`.
     */
    public function type() : string {
        return 'deploy';
    }

    /**
     * The options it stores.
     */
    abstract public function options() : Options;

    /**
     * Its settings fields: option name => [ label, hint ], both translated.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    abstract protected function fields() : array;

    /**
     * Anything above the settings table. Translated; may be empty.
     */
    protected function intro() : string {
        return '';
    }

    /**
     * Do the deploy. Only called when this add-on is the enabled deployer.
     *
     * A `crawl`- or `post_deploy`-type add-on leaves this alone.
     *
     * @param string $processed_site_path The processed site's directory.
     */
    protected function runDeploy( string $processed_site_path ) : void {
    }

    /**
     * Hooks that are this add-on's own. Called at the end of run().
     */
    protected function registerHooks() : void {
    }

    /**
     * Add-ons are configured by overriding methods, never through constructor
     * arguments.
     *
     * `final` so that `new static()` in instance() is safe: without it a
     * subclass could declare a constructor taking arguments, and the base class
     * would be calling it with none.
     */
    final public function __construct() {
    }

    /**
     * Create the add-on and hook it up. Called from the plugin's main file.
     *
     * @return static
     */
    final public static function boot() : self {
        $addon = self::instance();

        $addon->run();

        return $addon;
    }

    /**
     * The one instance of this add-on.
     *
     * @return static
     */
    final public static function instance() : self {
        if ( ! isset( self::$instances[ static::class ] ) ) {
            self::$instances[ static::class ] = new static();
        }

        /** @var static $instance */
        $instance = self::$instances[ static::class ];

        return $instance;
    }

    /**
     * The short name used in page slugs, hook names and the menu key.
     *
     * `wp2static-addon-bunnycdn` becomes `bunnycdn`.
     */
    final public function key() : string {
        return (string) preg_replace( '/^wp2static-addon-/', '', $this->slug() );
    }

    /**
     * The nonce action guarding this add-on's settings form.
     */
    final public function nonceAction() : string {
        return 'wp2static-' . $this->key() . '-options';
    }

    /**
     * The `admin_post_` action its form posts to.
     */
    final public function formAction() : string {
        return 'wp2static_' . str_replace( '-', '_', $this->key() ) . '_save_options';
    }

    final public function run() : void {
        add_action( 'init', [ $this, 'registerAddon' ] );

        add_filter( 'wp2static_add_menu_items', [ $this, 'addSubmenuPage' ] );

        add_action( 'admin_post_' . $this->formAction(), [ $this, 'saveOptionsFromUI' ] );

        if ( 'deploy' === $this->type() ) {
            add_action( 'wp2static_deploy', [ $this, 'deploy' ], 15, 2 );
        }

        $this->registerHooks();
    }

    /**
     * Tell the core this add-on exists.
     *
     * On `init`, and on every admin request, rather than on activation only.
     * Upstream did it in `register_activation_hook`, which fires once ever: an
     * add-on updated to a new name or documentation URL kept the old ones
     * forever, and one whose row was lost — a restored database, a
     * `wp2static addons truncate` — never came back at all without being
     * deactivated and reactivated by hand.
     *
     * `wp2static_register_addon` does not touch `enabled`, so re-announcing
     * cannot switch a deployer back on that the user switched off.
     */
    final public function registerAddon() : void {
        do_action(
            'wp2static_register_addon',
            $this->slug(),
            $this->type(),
            $this->name(),
            $this->docsUrl(),
            $this->description()
        );
    }

    /**
     * Ask the core for a settings page.
     *
     * `wp2static_add_menu_items` rather than a bare `add_submenu_page()` with
     * `'null'` as its parent, which is what upstream did: the literal string
     * `'null'`, giving an orphaned page that WordPress does not know the title
     * of and that no menu links to.
     *
     * @param mixed $submenu_pages Pages registered so far.
     * @return mixed[] The same, plus this one.
     */
    final public function addSubmenuPage( $submenu_pages ) : array {
        $pages = is_array( $submenu_pages ) ? $submenu_pages : [];

        $pages[ $this->key() ] = [ $this, 'renderSettingsPage' ];

        return $pages;
    }

    final public function renderSettingsPage() : void {
        SettingsPage::render(
            $this->name(),
            $this->nonceAction(),
            $this->formAction(),
            'wp2static-' . $this->key(),
            $this->options(),
            $this->fields(),
            $this->intro()
        );
    }

    /**
     * Save the settings form.
     *
     * **Capability first, then nonce, then anything else.** Upstream checked
     * only `check_admin_referer()` — which says where a request came from, not
     * who sent it — on forms that store the credentials of the account the
     * whole site is published to. That is the same class of defect the core had
     * on all twenty-four of its `admin_post_*` handlers.
     */
    final public function saveOptionsFromUI() : void {
        \WP2Static\Controller::authorize( $this->nonceAction() );

        $this->options()->savePosted();

        wp_safe_redirect( admin_url( 'admin.php?page=wp2static-' . $this->key() ) );
        exit;
    }

    /**
     * The core is deploying. Act only if we are the chosen deployer.
     *
     * @param string $processed_site_path The processed site's directory.
     * @param string $enabled_deployer    Slug of the deployer the user picked.
     */
    final public function deploy( string $processed_site_path, string $enabled_deployer = '' ) : void {
        if ( $this->slug() !== $enabled_deployer ) {
            return;
        }

        $this->runDeploy( $processed_site_path );
    }

    /**
     * Create this add-on's table. Hooked to activation by the main file.
     *
     * Multisite is handled by looping the network's sites here rather than in
     * ten copies of an activation routine — each of which built its blog-id
     * query with `sprintf()` instead of `prepare()`.
     *
     * @param bool|null $network_wide Whether the plugin was activated network-wide.
     */
    final public static function activate( ?bool $network_wide = null ) : void {
        if ( ! $network_wide || ! is_multisite() ) {
            static::instance()->options()->install();

            return;
        }

        foreach ( get_sites(
            [
				'fields' => 'ids',
				'number' => 0,
			]
        ) as $site_id ) {
            switch_to_blog( (int) $site_id );

            static::instance()->options()->install();

            restore_current_blog();
        }
    }
}
