<?php
/**
 * Removes the plugin's settings, including encrypted API keys, when the plugin is deleted.
 *
 * Template revisions are kept: they are the history of files in the theme.
 *
 * @package Imajiner_Editor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'imajiner_editor_ai' );
