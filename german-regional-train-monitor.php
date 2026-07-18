<?php
/**
 * Plugin Name:       German Regional Train Monitor
 * Plugin URI:        https://github.com/strYchni0x/train-monitor
 * Description:       Shows the next regional train departures at a station you choose and records punctuality statistics per direction, using the Deutsche Bahn Timetables API.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Florian Willnat
 * Author URI:        https://willnat.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       german-regional-train-monitor
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TRAINMON_VERSION', '1.0.0');
define('TRAINMON_FILE', __FILE__);
define('TRAINMON_DIR', plugin_dir_path(__FILE__));
define('TRAINMON_URL', plugin_dir_url(__FILE__));

require_once TRAINMON_DIR . 'includes/class-trainmon-helpers.php';
require_once TRAINMON_DIR . 'includes/class-trainmon-storage.php';
require_once TRAINMON_DIR . 'includes/class-trainmon-api.php';
require_once TRAINMON_DIR . 'includes/class-trainmon-stats.php';
require_once TRAINMON_DIR . 'includes/class-trainmon-renderer.php';
require_once TRAINMON_DIR . 'includes/class-trainmon-admin.php';
require_once TRAINMON_DIR . 'includes/class-trainmon-plugin.php';

TrainMon_Plugin::init();
