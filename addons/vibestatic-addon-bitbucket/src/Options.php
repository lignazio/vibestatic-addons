<?php
/**
 * An add-on's options table.
 *
 * **This file is identical in every VibeStatic add-on**, its namespace line
 * apart, and that is deliberate. The upstream boilerplate was copied into ten
 * repositories as two hundred and fifty lines of Controller, and every copy
 * carried the same four defects: table names interpolated into SQL, a seed that
 * inserted a duplicate row on each call, a save that silently did nothing when
 * the row was missing, and labels frozen into the database in whichever
 * language happened to be active on first use. Copying one small class that is
 * right beats copying a large one that is wrong.
 *
 * Writing an add-on of your own: take this file as it is, change only the
 * namespace, and declare your options in the constructor.
 *
 * @package WP2StaticBitbucket
 */

namespace WP2StaticBitbucket;

class Options {

    /**
     * The kinds of option this understands.
     *
     * `password` is stored encrypted with the core's key and never echoed
     * anywhere but its own field. `bool` is a checkbox, which browsers do not
     * post at all when unticked — hence the explicit 0 rather than a missing
     * value. `text` is multi-line and keeps its newlines; `string` does not.
     */
    const TYPES = [ 'string', 'text', 'password', 'bool', 'int' ];

    /**
     * @var string Table name, site prefix included.
     */
    private $table;

    /**
     * @var array<string, array{type: string, default: string}> Declared options.
     */
    private $declared = [];

    /**
     * @param string                                     $table   Unprefixed table name.
     * @param array<string, array{0: string, 1: string}> $options Name => [ type, default ].
     */
    public function __construct( string $table, array $options ) {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $this->table = $wpdb->prefix . $table;

        foreach ( $options as $name => $declaration ) {
            $type = $declaration[0];

            $this->declared[ $name ] = [
                'type' => in_array( $type, self::TYPES, true ) ? $type : 'string',
                'default' => $declaration[1],
            ];
        }
    }

    /**
     * The option names this add-on knows about, in declaration order.
     *
     * @return string[]
     */
    public function names() : array {
        return array_keys( $this->declared );
    }

    /**
     * An option's declared type, or `string` for one never declared.
     *
     * @param string $name Option name.
     */
    public function type( string $name ) : string {
        return $this->declared[ $name ]['type'] ?? 'string';
    }

    /**
     * Run a statement that has already been prepared.
     *
     * The `prepare()` call stays at each call site rather than moving in here,
     * deliberately: WordPress.DB.PreparedSQL is a blocking sniff and it checks
     * that the *template* is a literal string, which is the thing worth
     * checking — SQL assembled from data is what it exists to catch. Passing
     * the template through a `string $sql` parameter would hide exactly that
     * from it.
     *
     * What is shared here is the null check. `wpdb::prepare()` answers null
     * when the placeholders and the arguments do not line up, and
     * `wpdb::query( null )` is a TypeError on PHP 8: passing the one straight
     * into the other — the idiom everywhere in this project's ancestry — turns
     * a mistake in a query into a fatal error in the admin.
     *
     * @param string|null $prepared The return of `$wpdb->prepare()`.
     */
    private static function execute( ?string $prepared ) : void {
        if ( null === $prepared ) {
            return;
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every caller passes the return of $wpdb->prepare() with a literal template, which is what the sniff checks there; it cannot follow a prepared statement through a parameter.
        $wpdb->query( $prepared );
    }

    /**
     * Whether this option holds a secret, and is therefore stored encrypted.
     *
     * @param string $name Option name.
     */
    public function isSecret( string $name ) : bool {
        return 'password' === $this->type( $name );
    }

    /**
     * Create the table and seed the options that are missing.
     *
     * VARCHAR(191) on `name` because that is the longest a utf8mb4 column can
     * be and still be indexed, and the unique key on it because without one
     * `save()` could not be an upsert nor `seed()` idempotent.
     *
     * `label` and `description` are not columns. Upstream they were, written on
     * first use, which froze the active language into the database: a site
     * later switched to Italian kept reading English labels forever. They live
     * in the view now, translated on every load.
     */
    public function install() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // TEXT rather than VARCHAR(255): a service account key, a commit
        // message template or a list of hosts to rewrite all outgrow 255
        // characters, and upstream they were silently truncated on the way in.
        $sql = "CREATE TABLE {$this->table} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            value TEXT NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY name (name)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        $this->seed();
    }

    /**
     * Insert the options that are not there yet, leaving the rest alone.
     *
     * INSERT IGNORE against the unique key. Upstream this was a plain INSERT
     * into a table with no unique key, so every activation added a second row
     * per option — and then `all()` kept the last row per name while `get()`
     * took `LIMIT 1` with no ORDER BY, so the settings page and the command
     * line could disagree about the same option.
     */
    public function seed() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        foreach ( $this->declared as $name => $declaration ) {
            self::execute(
                $wpdb->prepare(
                    'INSERT IGNORE INTO %i (name, value) VALUES (%s, %s)',
                    $this->table,
                    $name,
                    $declaration['default']
                )
            );
        }
    }

    /**
     * Every option, keyed by name, secrets still encrypted.
     *
     * An option the add-on declares but the table has not got yet comes back at
     * its default, so a view can render before a seed has run.
     *
     * @return array<string, string>
     */
    public function all() : array {
        /** @var \wpdb $wpdb */
        global $wpdb;

        /** @var list<object{name: string, value: string}>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare( 'SELECT name, value FROM %i', $this->table )
        );

        $options = [];

        foreach ( $this->declared as $name => $declaration ) {
            $options[ $name ] = $declaration['default'];
        }

        foreach ( $rows ?? [] as $row ) {
            if ( isset( $this->declared[ $row->name ] ) ) {
                $options[ $row->name ] = $row->value;
            }
        }

        return $options;
    }

    /**
     * One option's stored value — still encrypted, for those that are.
     *
     * @param string $name Option name.
     */
    public function get( string $name ) : string {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $value = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT value FROM %i WHERE name = %s LIMIT 1',
                $this->table,
                $name
            )
        );

        if ( is_string( $value ) ) {
            return $value;
        }

        return $this->declared[ $name ]['default'] ?? '';
    }

    /**
     * One option's usable value: decrypted when it is a secret.
     *
     * @param string $name Option name.
     */
    public function plain( string $name ) : string {
        $value = $this->get( $name );

        if ( '' === $value || ! $this->isSecret( $name ) ) {
            return $value;
        }

        return (string) \WP2Static\CoreOptions::encrypt_decrypt( 'decrypt', $value );
    }

    /**
     * An option read as a checkbox.
     *
     * @param string $name Option name.
     */
    public function bool( string $name ) : bool {
        return '1' === $this->get( $name );
    }

    /**
     * An option read as a whole number.
     *
     * @param string $name Option name.
     */
    public function int( string $name ) : int {
        return (int) $this->get( $name );
    }

    /**
     * Store an option, encrypting it when it is a secret.
     *
     * One upsert. Upstream this was `$wpdb->update()`, which updates nothing
     * when the row is absent and reports success either way: an option added by
     * a later version of an add-on could never be saved on an installation
     * whose table had been seeded before that option existed.
     *
     * @param string $name  Option name.
     * @param string $value What to store, before any encryption.
     */
    public function save( string $name, string $value ) : void {
        if ( $this->isSecret( $name ) && '' !== $value ) {
            $value = (string) \WP2Static\CoreOptions::encrypt_decrypt( 'encrypt', $value );
        }

        /** @var \wpdb $wpdb */
        global $wpdb;

        self::execute(
            $wpdb->prepare(
                'INSERT INTO %i (name, value) VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE value = %s',
                $this->table,
                $name,
                $value,
                $value
            )
        );
    }

    /**
     * Take the declared options out of $_POST and store them.
     *
     * The caller has already checked capability and nonce — see
     * `\WP2Static\Controller::authorize()`. Only declared names are read, so a
     * request carrying extra fields cannot write anything the add-on never
     * asked for.
     *
     * **A password left blank keeps the stored value** rather than clearing it.
     * Secrets are shown in a password field; a browser that declines to refill
     * one must not be able to wipe the credentials of the account the whole
     * site is published to just because somebody saved the form.
     */
    public function savePosted() : void {
        foreach ( $this->declared as $name => $declaration ) {
            /*
             * Sanitised on the same expression that reads $_POST, per type, and
             * not through a variable in between. That is what makes it visible
             * to the WordPress.Security.ValidatedSanitizedInput sniff — the
             * blocking one in CI — and the sniff is right to want it there:
             * "it gets sanitised further down" is exactly the shape that stops
             * being true when somebody adds a branch.
             *
             * `sanitize_text_field()` and `sanitize_textarea_field()` both
             * answer '' for an array or an object, so a request that posts
             * `name[]=x` cannot reach the database either.
             */
            switch ( $declaration['type'] ) {
                case 'bool':
                    // Absent means unticked: browsers do not post an unchecked
                    // box at all, so presence is the whole of the value.
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the caller verified nonce and capability through \WP2Static\Controller::authorize(); WPCS cannot see a guard reached through ::.
                    $value = isset( $_POST[ $name ] ) ? '1' : '0';
                    break;

                case 'int':
                    // is_scalar() first: a request posting `name[]=x` would hand
                    // an array to a function whose parameter is a string.
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                    $value = isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] )
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                        ? (string) (int) sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) )
                        : '0';
                    break;

                case 'text':
                    // Newlines are the point of a textarea, and
                    // sanitize_text_field() eats them.
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                    $value = isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] )
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                        ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $name ] ) )
                        : '';
                    break;

                default:
                    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                    $value = isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] )
                        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
                        ? sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) )
                        : '';
            }

            if ( '' === $value && $this->isSecret( $name ) ) {
                continue;
            }

            $this->save( $name, $value );
        }
    }

    /**
     * Drop the table. Called from uninstall.php.
     */
    public function drop() : void {
        /** @var \wpdb $wpdb */
        global $wpdb;

        self::execute( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $this->table ) );
    }
}
