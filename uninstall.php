<?php
/**
 * Uninstall handler for Simple LMS.
 *
 * This release keeps academic data intact by default. The uninstall routine
 * only clears scheduled jobs so accidental removal does not wipe university
 * records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'src/Autoloader.php';

\SimpleLMS\Autoloader::register();

if ( class_exists( '\SimpleLMS\Infrastructure\Notifications\NotificationManager' ) ) {
	\SimpleLMS\Infrastructure\Notifications\NotificationManager::clear_scheduled_event();
}

if ( class_exists( '\SimpleLMS\Domain\Academic\TermService' ) ) {
	\SimpleLMS\Domain\Academic\TermService::clear_scheduled_event();
}
