<?php
namespace WordPressdotorg\Plugin_Directory\Shortcodes;

use WordPressdotorg\Plugin_Directory\CLI\Block_Plugin_Checker;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;
use WordPressdotorg\Plugin_Directory\Tools;
use WordPressdotorg\Plugin_Directory\Jobs\Plugin_Import;

class Block_Validator {

	/**
	 * Displays a form to validate block plugins.
	 */
	public static function display() {
		ob_start();
		$plugin_url = $_REQUEST['plugin_url'] ?? '';

		if ( is_user_logged_in() ) :
			?>

		<div class="wrap block-validator">
			<form method="post" action="." class="block-validator__plugin-form">
				<label for="plugin_url"><?php _e( 'Plugin repo URL', 'wporg-plugins' ); ?></label>
				<div class="block-validator__plugin-input-container">
					<input type="text" class="block-validator__plugin-input" id="plugin_url" name="plugin_url" placeholder="https://plugins.svn.wordpress.org/" value="<?php echo esc_attr( $plugin_url ); ?>" />
					<input type="submit" class="button button-secondary block-validator__plugin-submit" value="<?php esc_attr_e( 'Check Plugin!', 'wporg-plugins' ); ?>" />
					<?php wp_nonce_field( 'validate-block-plugin', 'block-nonce' ); ?>
				</div>
			</form>

			<?php
			if ( $_POST && ! empty( $_POST['plugin_url'] ) && wp_verify_nonce( $_POST['block-nonce'], 'validate-block-plugin' ) ) {
				self::validate_block( $_POST['plugin_url'] );
			} elseif ( $_POST && ! empty( $_POST['block-directory-edit'] ) ) {
				$post = get_post( intval( $_POST['plugin-id'] ) );
				if ( $post && wp_verify_nonce( $_POST['block-directory-nonce'], 'block-directory-edit-' . $post->ID ) ) {
					if ( current_user_can( 'edit_post', $post->ID ) || current_user_can( 'plugin_admin_edit', $post->ID ) ) {
						$terms = wp_list_pluck( get_the_terms( $post->ID, 'plugin_section' ), 'slug' );
						if ( 'add' === $_POST['block-directory-edit'] ) {
							$terms[] = 'block';
						} elseif ( 'remove' === $_POST['block-directory-edit'] ) {
							$terms = array_diff( $terms, array( 'block' ) );
						}
						$result = wp_set_object_terms( $post->ID, $terms, 'plugin_section' );
						if ( !is_wp_error( $result ) && !empty( $result ) ) {
							if ( 'add' === $_POST['block-directory-edit'] ) {
								Tools::audit_log( 'Plugin added to block directory.', $post->ID );
								self::maybe_send_email_plugin_added( $post );
								Plugin_Import::queue( $post->post_name, array( 'tags_touched' => array( $post->stable_tag ) ) );
							} elseif ( 'remove' === $_POST['block-directory-edit'] ) {
								Tools::audit_log( 'Plugin removed from block directory.', $post->ID );
							}
						}
					}

					self::validate_block( $post->post_name );
				}
			}
			?>
		</div>
		<?php else : ?>
		<div class="wrap block-validator">
			<p><?php _e( 'Please log in to use the validator.', 'wporg-plugins' ); ?></p>
		</div>
		<?php endif;
		return ob_get_clean();
	}

	protected static function plugin_is_in_block_directory( $slug ) {
		$plugin = Plugin_Directory::get_plugin_post( $slug );

		return (
			$plugin &&
			$plugin->post_name === $slug &&
			has_term( 'block', 'plugin_section', $plugin )
		);
	}

	/**
	 * Validates readme.txt contents and adds feedback.
	 *
	 * @param string $plugin_url The URL of a Subversion or GitHub repository.
	 */
	protected static function validate_block( $plugin_url ) {

		$checker = new Block_Plugin_Checker();
		$results = $checker->run_check_plugin_repo( $plugin_url );

		echo '<h2>' . __( 'Results', 'wporg-plugins' ) . '</h2>';

		if ( $checker->repo_url && $checker->repo_revision ) {
			echo '<p>';
			printf(
				'Results for %1$s revision %2$s',
				'<code>' . esc_url( $checker->repo_url ) . '</code>',
				esc_html( $checker->repo_revision )
			);
			echo '</p>';
		}

		$results_by_type = array();
		$block_json_issues = array();
		foreach ( $results as $item ) {
			if ( 'info' !== $item->type && 'check_block_json_is_valid' === $item->check_name ) {
				$block_json_issues[] = $item;
			} else {
				$results_by_type[ $item->type ][] = $item;
			}
		}

		if ( $checker->slug ) {
			$plugin = Plugin_Directory::get_plugin_post( $checker->slug );
			if ( current_user_can( 'edit_post', $plugin->ID ) ) {
				// Plugin reviewers etc
				echo '<form method="post">';
				echo '<h3>' . __( 'Plugin Review Tools', 'wporg-plugins' ) . '</h3>';
				echo '<ul>';
				echo '<li><a href="' . get_edit_post_link( $plugin->ID ) . '">' . __( 'Edit plugin', 'wporg-plugins' ) . '</a></li>';
				echo '<li><a href="' . esc_url( 'https://plugins.trac.wordpress.org/browser/' . $checker->slug . '/trunk' ) . '">' . __( 'Trac browser', 'wporg-plugins' ) . '</a></li>';
				echo '</ul>';

				echo wp_nonce_field( 'block-directory-edit-' . $plugin->ID, 'block-directory-nonce' );
				echo '<input type="hidden" name="plugin-id" value="' . esc_attr( $plugin->ID ) . '" />';
				echo '<p>';
				if ( ! empty( $results_by_type['error'] ) ) {
					// translators: %s plugin title.
					printf( __( "%s can't be added to the block directory, due to errors in validation.", 'wporg-plugins' ), $plugin->post_title );
				} else if ( self::plugin_is_in_block_directory( $checker->slug ) ) {
					// translators: %s plugin title.
					echo '<button class="button button-secondary button-large" type="submit" name="block-directory-edit" value="remove">' . sprintf( __( 'Remove %s from Block Directory', 'wporg-plugins' ), $plugin->post_title ) . '</button>';
				} else {
					// translators: %s plugin title.
					echo '<button class="button button-primary button-large" type="submit" name="block-directory-edit" value="add">' . sprintf( __( 'Add %s to Block Directory', 'wporg-plugins' ), $plugin->post_title ) . '</button>';
				}
				echo '</p>';
				echo '</form>';
			} elseif ( current_user_can( 'plugin_admin_edit', $plugin->ID ) ) {
				// Plugin committers
				echo '<form method="post">';
				echo '<h3>' . __( 'Committer Tools', 'wporg-plugins' ) . '</h3>';
				echo '<ul>';
				echo '<li><a href="' . esc_url( 'https://plugins.trac.wordpress.org/browser/' . $checker->slug . '/trunk' ) . '">' . __( 'Browse code on trac', 'wporg-plugins' ) . '</a></li>';
				echo '</ul>';

				echo wp_nonce_field( 'block-directory-edit-' . $plugin->ID, 'block-directory-nonce' );
				echo '<input type="hidden" name="plugin-id" value="' . esc_attr( $plugin->ID ) . '" />';
				echo '<p>';
				if ( ! empty( $results_by_type['error'] ) ) {
					// translators: %s plugin title.
					printf( __( "%s can't be added to the block directory, due to errors in validation.", 'wporg-plugins' ), $plugin->post_title );
				} else if ( self::plugin_is_in_block_directory( $checker->slug ) ) {
					// translators: %s plugin title.
					echo '<button class="button button-secondary button-large" type="submit" name="block-directory-edit" value="remove">' . sprintf( __( 'Remove %s from Block Directory', 'wporg-plugins' ), $plugin->post_title ) . '</button>';
				} else {
					// translators: %s plugin title.
					echo '<button class="button button-primary button-large" type="submit" name="block-directory-edit" value="add">' . sprintf( __( 'Add %s to Block Directory', 'wporg-plugins' ), $plugin->post_title ) . '</button>';
				}
				echo '</p>';
			}
		}

		$output = '';

		if ( empty( $results_by_type['error'] ) ) {
			$output .= '<h3>' . __( 'Success', 'wporg-plugins' ) . '</h3>';
			$output .= "<div class='notice notice-success notice-alt'>\n";
			if ( $checker->slug && self::plugin_is_in_block_directory( $checker->slug ) ) {
				$output .= '<p>' . __( 'No problems were found. This plugin is already in the Block Directory.', 'wporg-plugins' ) . '</p>';
			} else {
				$output .= '<p>' . __( 'No problems were found. Your plugin has passed the first step towards being included in the Block Directory.', 'wporg-plugins' ) . '</p>';
			}
			$output .= "</div>\n";
		} else {
			$output .= '<h3>' . __( 'Problems were encountered', 'wporg-plugins' ) . '</h3>';
			$output .= "<div class='notice notice-error notice-alt'>\n";
			$output .= '<p>' . __( 'Some problems were found. They need to be addressed before your plugin will work in the Block Directory.', 'wporg-plugins' ) . '</p>';
			$output .= "</div>\n";
		}

		$error_types = array(
			'error'   => __( 'Fatal Errors:', 'wporg-plugins' ),
			'warning' => __( 'Warnings:', 'wporg-plugins' ),
			'info'    => __( 'Notes:', 'wporg-plugins' ),
		);
		foreach ( $error_types as $type => $warning_label ) {
			if ( empty( $results_by_type[ $type ] ) ) {
				continue;
			}

			$output .= "<h3>{$warning_label}</h3>\n";
			$output .= "<div class='notice notice-{$type} notice-alt'>\n";
			foreach ( $results_by_type[ $type ] as $item ) {
				// Only get details if this is a warning or error.
				$details = ( 'info' === $type ) ? false : self::get_detailed_help( $item->check_name, $item );
				if ( $details ) {
					$details = '<p>' . implode( '</p><p>', (array) $details ) . '</p>';
					$output .= "<details class='{$item->check_name}'><summary>{$item->message}</summary>{$details}</details>";
				} else {
					$output .= "<p>{$item->message}</p>";
				}
			}
			// Collapse block.json warnings into one details at the end of warnings list.
			if ( 'warning' === $type && ! empty( $block_json_issues ) ) {
				$messages = wp_list_pluck( $block_json_issues, 'message' );
				$details = '<p>' . implode( '</p><p>', (array) $messages ) . '</p>';
				$output .= sprintf(
					'<details class="check_block_json_is_valid"><summary>%1$s</summary>%2$s</details>',
					__( 'Issues found in block.json file.', 'wporg-plugins' ),
					$details
				);
			}
			$output .= "</div>\n";
		}

		if ( empty( $output ) ) {
			$output .= '<div class="notice notice-success notice-alt">';
			$output .= '<p>' . __( 'Congratulations! No errors found.', 'wporg-plugins' ) . '</p>';
			$output .= '</div>';
		}

		echo $output;
	}

	/**
	 * Get a more detailed help message for a given check.
	 *
	 * @param string $method The name of the check method.
	 * @param array  $result The full result data.
	 *
	 * @return string|array More details for a given block issue. Array of strings if there should be a linebreak.
	 */
	public static function get_detailed_help( $method, $result ) {
		switch ( $method ) {
			// These don't need more details.
			case 'check_readme_exists':
			case 'check_license':
			case 'check_plugin_headers':
				return false;
			// This is a special case, since multiple values may be collapsed.
			case 'check_block_json_is_valid':
				return false;
			case 'check_block_tag':
				return __( 'The readme.txt file must contain the tag "block" (singular) for this to be added to the block directory.', 'wporg-plugins' );
			case 'check_for_duplicate_block_name':
				$details = [
					__( "Block names must be unique, otherwise it can cause problems when using the block. It is recommended to use your plugin's name as the namespace.", 'wporg-plugins' ),
				];
				if ( 'warning' === $result->type ) {
					$details[] = '<em>' . __( 'If this is a different version of your own plugin, you can ignore this warning.', 'wporg-plugins' ) . '</em>';
				}
				return $details;
			case 'check_for_blocks':
				return [
					__( 'In order to work in the Block Directory, a plugin must register a block. Generally one per plugin (multiple blocks may be permitted if those blocks are interdependent, such as a list block that contains list item blocks).', 'wporg-plugins' ),
					__( 'If your plugin doesn’t register a block, it probably belongs in the main Plugin Directory rather than the Block Directory.', 'wporg-plugins' ),
					sprintf( '<a href="TUTORIAL">%s</a>', __( 'Learn how to create a block.' ) ),
				];
			case 'check_for_block_json':
				return __( 'Your plugin should contain at least one <code>block.json</code> file. This file contains metadata describing the block and its JavaScript and CSS assets. Make sure you include at least one <code>script</code> or <code>editorScript</code> item.', 'wporg-plugins' );
			case 'check_for_block_script_files':
				return [
					__( 'The value of <code>script</code>, <code>style</code>, <code>editorScript</code>, <code>editorStyle</code> must be a valid file path. This value was detected, but there is no file at this location in your plugin.', 'wporg-plugins' ),
					__( 'Unlike regular blocks, plugins in the block directory cannot use a script handle for these values.', 'wporg-plugins' ),
				];
			case 'check_for_register_block_type':
				return __( 'At least one of your JavaScript files must explicitly call registerBlockType(). Without that call, your block will not work in the editor.', 'wporg-plugins' );
			case 'check_block_json_is_valid_json':
				return __( 'This block.json file is invalid. The Block Directory needs to be able to read this file.', 'wporg-plugins' );
			case 'check_php_size':
				return __( 'Block plugins should keep the PHP code to a mimmum. If you need a lot of PHP code, your plugin probably belongs in the main Plugin Directory rather than the Block Directory.', 'wporg-plugins' );
			case 'check_for_standard_block_name':
				return [
					__( 'Block names must contain a namespace prefix, include only lowercase alphanumeric characters or dashes, and start with a letter. The namespace should be unique to your block plugin, make sure to change any defaults from block templates like "create-block/" or "cgb/".', 'wporg-plugins' ),
					__( 'Example: <code>my-plugin/my-custom-block</code>', 'wporg-plugins' ),
				];
		}
	}

	/**
	 * Sends an email confirmation to the plugin's author the first time a plugin is added to the directory.
	 */
	protected static function maybe_send_email_plugin_added( $post ) {

		$plugin_author = get_user_by( 'id', $post->post_author );
		if ( empty( $plugin_author ) )
			return false;

		// Only send the email the first time it's added.
		if ( !add_post_meta( $post->ID, 'added_to_block_directory', time(), true ) )
			return false;


		/* translators: %s: plugin name */
		$email_subject = sprintf(
			__( '[WordPress Plugin Directory] Added to Block Directory - %s', 'wporg-plugins' ),
			$post->post_name
		);

		/*
			Please leave the blank lines in place.
		*/
		$email_content = sprintf(
			// translators: 1: plugin name, 2: plugin slug.
			__(
'This email is to let you know that your plugin %1$s has been added to the Block Directory here: https://wordpress.org/plugins/browse/block/.

We\'re still working on improving Block Directory search and automated detection of blocks, so don\'t be alarmed if your block isn\'t immediately visible there. We\'ve built a new tool to help developers identify problems and potential improvements to block plugins, which you\'ll find here: https://wordpress.org/plugins/developers/block-plugin-validator/.

In case you missed it, the Block Directory is a new feature coming to WordPress 5.5 in August. By having your plugin automatically added here, WordPress users will be able to discover your block plugin and install it directly from the editor when searching for blocks. If you have any feedback (bug, enhancement, etc) about this new feature, please open an issue here: https://github.com/wordpress/gutenberg/.

If you would like your plugin removed from the Block Directory, you can do so here: https://wordpress.org/plugins/developers/block-plugin-validator/?plugin_url=%2$s

Otherwise, you\'re all set!

--
The WordPress Plugin Directory Team
https://make.wordpress.org/plugins', 'wporg-plugins'
			),
			$post->post_title,
			$post->post_name
		);

		$user_email = $plugin_author->user_email;

		return wp_mail( $user_email, $email_subject, $email_content, 'From: plugins@wordpress.org' );
	}
}
