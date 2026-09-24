<?php
/**
 * Plugin Name: Simple LMS
 * Plugin URI:  https://ugp.edu.mm/
 * Description: University-first LMS for academic operations, teaching delivery, assessments, attendance, gradebooks, people workflows, and long-term records oversight.
 * Version:     3.5.16
 * Author:      Ven Ratthasara
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: simple-lms
 * Requires at least: 6.4
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'SLMS_VERSION', '3.5.16' );
define( 'SLMS_DB_VERSION', '16' );
define( 'SLMS_FILE', __FILE__ );
define( 'SLMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'SLMS_URL', plugin_dir_url( __FILE__ ) );

require_once SLMS_PATH . 'src/Autoloader.php';

\SimpleLMS\Autoloader::register();

register_activation_hook( SLMS_FILE, array( '\SimpleLMS\Infrastructure\Installer', 'activate' ) );
register_deactivation_hook( SLMS_FILE, array( '\SimpleLMS\Infrastructure\Installer', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\SimpleLMS\Plugin::instance()->boot();
	}
);
