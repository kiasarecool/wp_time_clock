<?php
/**
 * Plugin Name: PF Employee Time Clock
 * Description: Simple employee kiosk time clock, exception review, reporting, audit logs, and CSV/XLSX exports.
 * Version: 1.1.10
 * Author: The Plant Factory
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) exit;

define('PF_TC_VERSION','1.1.10');
define('PF_TC_FILE',__FILE__);
define('PF_TC_DIR',plugin_dir_path(__FILE__));
define('PF_TC_URL',plugin_dir_url(__FILE__));
require_once PF_TC_DIR.'includes/class-pf-time-clock.php';
register_activation_hook(__FILE__, ['PF_Time_Clock','activate']);
PF_Time_Clock::instance();
