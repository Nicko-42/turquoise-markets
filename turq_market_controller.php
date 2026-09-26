<?php
/*******************************************************************************
 * Market settings
 * 
 * turq_market_controller.php
 * 
*******************************************************************************/

/*
add_action('wp_head', function(){ //useful debug area !!
	print_r(get_option('wpsl_settings')['editor_hours']['dropdown']);
});
*/

if (!defined('ABSPATH')) exit;

define('TURQ_PLUGIN_PATH', plugin_dir_path(__FILE__));

require_once TURQ_PLUGIN_PATH . 'includes/class-turq-market-setup.php';

// Core always loads
//$turq_Market_Setup = new turq_Market_Setup();
$turq_Market_Setup = turq_Market_Setup();

function turq_Market_Setup() { return turq_Market_Setup::instance(); }

if (is_admin() && !wp_doing_ajax()) {
    require_once TURQ_PLUGIN_PATH . 'includes/class-turq-market-admin.php';
    new turq_Market_Admin($turq_Market_Setup);
}

?>