<?php
/**
 * Plugin Name:       Booking Engine Connector
 * Plugin URI:        https://bec-docs.apps.robb.cx
 * Description:       Connects WordPress to external booking engines (Kross Booking and others) with sync, search context, and checkout links.
 * Version:           0.2.10
 * Update URI:        https://github.com/robbdeveloper/booking-engine-connector/
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            RobbDev
 * Author URI:        https://robbdev.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       booking-engine-connector
 * Domain Path:       /languages
 *
 * @package BookingEngineConnector
 */

declare(strict_types=1);

namespace BookingEngineConnector;

use BookingEngineConnector\Core\Plugin;

if (! defined('ABSPATH')) {
	exit;
}

define('BEC_VERSION', '0.2.10');
define('BEC_PLUGIN_FILE', __FILE__);
define('BEC_PLUGIN_DIR', \plugin_dir_path(__FILE__));
define('BEC_PLUGIN_URL', \plugin_dir_url(__FILE__));
define('BEC_PLUGIN_BASENAME', \plugin_basename(__FILE__));

require_once BEC_PLUGIN_DIR . 'includes/Autoload.php';

Autoload::register();

require_once BEC_PLUGIN_DIR . 'includes/bootstrap.php';

Plugin::instance()->init();
