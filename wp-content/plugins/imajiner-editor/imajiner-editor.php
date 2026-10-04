<?php
/**
 * Plugin Name: Imajiner Editor
 * Description: Visual editor for Imajiner PHP page templates. Requires the Imajiner theme.
 * Version: 0.7.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Author: Imajiner
 * License: GPL-2.0-or-later
 * Text Domain: imajiner-editor
 *
 * @package Imajiner_Editor
 */

defined( 'ABSPATH' ) || exit;

define( 'IMAJINER_EDITOR_VERSION', '0.7.0' );
define( 'IMAJINER_EDITOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'IMAJINER_EDITOR_URL', plugin_dir_url( __FILE__ ) );

require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-template-scanner.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-css-editor.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-filesystem.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-editor-locks.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-section-library.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-template-store.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-rest.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-preview.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-editor.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-template-manager.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-builder.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-secrets.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-ai.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-prompts.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-generation.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-settings.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-filesystem-credentials.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-ai-jobs.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-section-ai.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-site-setup.php';
require_once IMAJINER_EDITOR_DIR . 'includes/class-imajiner-design-system.php';

add_action( 'init', function () {
	load_plugin_textdomain( 'imajiner-editor', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );
Imajiner_Editor_Locks::init();
Imajiner_Section_Library::init();
Imajiner_Filesystem_Credentials::init();
Imajiner_AI::init();
Imajiner_AI_Jobs::init();
Imajiner_Generation::init();
Imajiner_Section_AI::init();
Imajiner_Site_Setup::init();
add_action( 'init', array( 'Imajiner_Design_System', 'init' ) );

add_action( 'init', array( 'Imajiner_Template_Store', 'init' ) );

// AI settings work whichever theme is active.
Imajiner_Settings::init();

// The active theme is only known once themes have loaded.
add_action( 'after_setup_theme', array( 'Imajiner_Editor', 'init' ) );
