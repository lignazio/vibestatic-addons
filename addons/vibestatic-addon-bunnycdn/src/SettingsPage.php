<?php
/**
 * An add-on's settings form.
 *
 * **This file is identical in every VibeStatic add-on**, its namespace line
 * apart. It exists because the upstream views were ten hand-written copies of
 * the same table in which *nothing at all was escaped* — `<?php echo
 * $view['options']['x']->value; ?>` straight into a `value` attribute, one of
 * them the decrypted API token. A token holding an apostrophe closed the
 * attribute early; a label holding a `<` broke the page. Rendering from a
 * declaration means the escaping is written once and cannot be forgotten in
 * the eleventh copy.
 *
 * @package WP2StaticBunnyCDN
 */

namespace WP2StaticBunnyCDN;

class SettingsPage {

    /**
     * Render the whole page.
     *
     * @param string                                     $title        Page heading. The add-on's own name, so not
     *                                                                 translated here — see below.
     * @param string                                     $nonce_action Nonce action, matching the save handler.
     * @param string                                     $form_action  The `admin_post_` action name.
     * @param string                                     $page_slug    Where the save handler redirects back to.
     * @param Options                                    $options      The declared options.
     * @param array<string, array{0: string, 1: string}> $fields Name => [ label, hint ].
     * @param string                                     $intro        Optional paragraph above the table.
     */
    public static function render(
        string $title,
        string $nonce_action,
        string $form_action,
        string $page_slug,
        Options $options,
        array $fields,
        string $intro = ''
    ) : void {
        $values = $options->all();

        ?>
<div class="wrap">

    <h2><?php echo esc_html( $title ); ?></h2>

        <?php if ( '' !== $intro ) : ?>
    <p><?php echo esc_html( $intro ); ?></p>
        <?php endif; ?>

    <form
        name="<?php echo esc_attr( $page_slug ); ?>-save-options"
        method="POST"
        action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">

        <?php wp_nonce_field( $nonce_action ); ?>
        <input name="action" type="hidden" value="<?php echo esc_attr( $form_action ); ?>" />

        <table class="widefat striped">
            <tbody>
                <?php
                foreach ( $fields as $name => $field ) {
                    self::row(
                        $name,
                        $field[0],
                        $field[1],
                        $options->type( $name ),
                        $values[ $name ] ?? ''
                    );
                }
                ?>
            </tbody>
        </table>

        <br>

        <button class="button button-primary"><?php esc_html_e( 'Save Options', 'vibestatic' ); ?></button>
    </form>

</div>
        <?php
    }

    /**
     * One labelled field.
     *
     * @param string $name  Option name, which is also the field's name and id.
     * @param string $label Already translated.
     * @param string $hint  Already translated; may be empty.
     * @param string $type  One of Options::TYPES.
     * @param string $value The stored value, secrets still encrypted.
     */
    private static function row(
        string $name,
        string $label,
        string $hint,
        string $type,
        string $value
    ) : void {
        ?>
                <tr>
                    <td style="width:50%;">
                        <label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
                        <?php if ( '' !== $hint ) : ?>
                        <p class="description"><?php echo esc_html( $hint ); ?></p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php self::field( $name, $type, $value ); ?>
                    </td>
                </tr>
        <?php
    }

    /**
     * The control itself.
     *
     * A secret is decrypted here and nowhere else: it goes into the one field
     * whose whole purpose is to hold it, through esc_attr(), and never into a
     * log line, a diagnostics page or a `wp ... options list` table.
     *
     * @param string $name  Option name.
     * @param string $type  One of Options::TYPES.
     * @param string $value The stored value, secrets still encrypted.
     */
    private static function field( string $name, string $type, string $value ) : void {
        switch ( $type ) {
            case 'bool':
                printf(
                    '<input id="%1$s" name="%1$s" type="checkbox" value="1"%2$s />',
                    esc_attr( $name ),
                    checked( '1', $value, false ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a fixed literal.
                );
                break;

            case 'int':
                printf(
                    '<input id="%1$s" name="%1$s" class="widefat" type="number" step="1" value="%2$s" />',
                    esc_attr( $name ),
                    esc_attr( $value )
                );
                break;

            case 'text':
                printf(
                    '<textarea id="%1$s" name="%1$s" class="widefat" rows="6">%2$s</textarea>',
                    esc_attr( $name ),
                    esc_textarea( $value )
                );
                break;

            case 'password':
                $plain = '' === $value
                    ? ''
                    : (string) \WP2Static\CoreOptions::encrypt_decrypt( 'decrypt', $value );

                printf(
                    '<input id="%1$s" name="%1$s" class="widefat" type="password" autocomplete="new-password" value="%2$s" />',
                    esc_attr( $name ),
                    esc_attr( $plain )
                );
                break;

            default:
                printf(
                    '<input id="%1$s" name="%1$s" class="widefat" type="text" value="%2$s" />',
                    esc_attr( $name ),
                    esc_attr( $value )
                );
        }
    }
}
