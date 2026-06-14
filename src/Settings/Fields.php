<?php
/**
 * Reusable field renderers for the settings page.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless helpers that echo escaped form controls whose name attributes
 * map into the ums_settings[section][key] option array.
 */
class Fields {

	const OPTION = SettingsRepository::OPTION_KEY;

	/**
	 * Build a name attribute for ums_settings[section][key].
	 *
	 * @param string $section Section.
	 * @param string $key     Key.
	 * @return string
	 */
	public static function name( $section, $key ) {
		return self::OPTION . '[' . $section . '][' . $key . ']';
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string $section Section.
	 * @param string $key     Key.
	 * @param bool   $value   Current value.
	 * @param string $label   Label text.
	 * @param string $help    Optional help text.
	 * @return void
	 */
	public static function checkbox( $section, $key, $value, $label, $help = '' ) {
		$name = self::name( $section, $key );
		?>
		<label>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?> />
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
		if ( '' !== $help ) {
			echo ' <p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Render a text input row.
	 *
	 * @param string $section     Section.
	 * @param string $key         Key.
	 * @param string $value       Current value.
	 * @param string $placeholder Placeholder.
	 * @param string $type        Input type.
	 * @return void
	 */
	public static function text( $section, $key, $value, $placeholder = '', $type = 'text' ) {
		printf(
			'<input type="%1$s" class="regular-text" name="%2$s" value="%3$s" placeholder="%4$s" />',
			esc_attr( $type ),
			esc_attr( self::name( $section, $key ) ),
			esc_attr( (string) $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Render a textarea whose value is one item per line.
	 *
	 * @param string $section Section.
	 * @param string $key     Key.
	 * @param string $value   Current value (string).
	 * @param string $help    Optional help text.
	 * @return void
	 */
	public static function textarea( $section, $key, $value, $help = '' ) {
		printf(
			'<textarea class="large-text code" rows="5" name="%1$s">%2$s</textarea>',
			esc_attr( self::name( $section, $key ) ),
			esc_textarea( (string) $value )
		);
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
	}

	/**
	 * Render a multi-checkbox list of roles.
	 *
	 * @param string   $section  Section.
	 * @param string   $key      Key (stored as an array).
	 * @param string[] $selected Selected role slugs.
	 * @return void
	 */
	public static function roles_checklist( $section, $key, array $selected ) {
		$roles = wp_roles()->get_names();
		$name  = self::name( $section, $key ) . '[]';

		echo '<fieldset>';
		foreach ( $roles as $slug => $label ) {
			printf(
				'<label style="display:inline-block;min-width:180px;"><input type="checkbox" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( $name ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Render a dropdown of published pages.
	 *
	 * @param string $section Section.
	 * @param string $key     Key.
	 * @param int    $value   Selected page id.
	 * @return void
	 */
	public static function page_select( $section, $key, $value ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes all array values internally.
		wp_dropdown_pages(
			array(
				'name'              => self::name( $section, $key ),
				'selected'          => (int) $value,
				'show_option_none'  => __( '— None —', 'user-management-suite' ),
				'option_none_value' => '0',
			)
		);
		// phpcs:enable
	}

	/**
	 * Render a labelled select.
	 *
	 * @param string               $section Section.
	 * @param string               $key     Key.
	 * @param string               $value   Current value.
	 * @param array<string,string> $choices value => label.
	 * @return void
	 */
	public static function select( $section, $key, $value, array $choices ) {
		printf( '<select name="%s">', esc_attr( self::name( $section, $key ) ) );
		foreach ( $choices as $val => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $val ),
				selected( (string) $value, (string) $val, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Open a settings table row.
	 *
	 * @param string $label Row label.
	 * @return void
	 */
	public static function row_start( $label ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
	}

	/**
	 * Close a settings table row.
	 *
	 * @return void
	 */
	public static function row_end() {
		echo '</td></tr>';
	}
}
