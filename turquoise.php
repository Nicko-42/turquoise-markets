<?php
/*******************************************************************************
 * Plugin for Wordpress & Woocommerce
 *
 * @author    Nick Oakes
 * @copyright 2023 Turquoise Internet Ltd
 * @license   single site, single user, perpetual license
 *
 * Plugin Name:  Turquoise Wordpress Utilities
 * Description:  A plugin to add useful utilities and customisations to Wordpress and Woocommerce  - &copy;Nick Oakes 2026
 * Date:         23/06/2026
 * Version:      17.1
 * Plugin URI:   https://turquoise-internet.co.uk/
 * Author:       Turquoise Internet
 * Author URI:   https://turquoise-internet.co.uk/
 *
 * This program is not free software; you cannot redistribute it and/or modify
 * it in any way without prior consent from the author.
 *
 *******************************************************************************/

// $time_start = microtime(true); error_log('Total execution: ' . (microtime(true) - $time_start));
// do_action( 'qm/debug', $xxx );
//error_log (print_r($xxx,true));
//define( 'WP_DEBUG', true );
//define( 'WP_DEBUG_LOG', true );
//define( 'WP_DEBUG_DISPLAY', false );


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Global defs
//------------
// ALL DATES are UTC values until point of display, unless required by settings, e.g. woo admin setup tab date picker.
// NOTE / signifies American so mm/dd, whereas - is UK so dd-mm !!!!!!!!
define('DATEFORMAT','D d-m-y');

// Returns the current Unix timestamp adjusted to your WP City setting
define('TODAY', current_time('timestamp'));
//define('TODAY',strtotime("28-11-2026 23:59")); //DEBUG
//define('TODAY',strtotime("30-03-2026 13:00")); //DEBUG


// Our includes
//-------------
include_once ('turq_admin.php');
//include_once ('turq_user.php');

include_once ('turq_market_controller.php');
include_once ('turq_woo_settings.php');

include_once ('turq_wpsl_setup.php');

include_once ('turq_dashboard_widget.php');

include_once ('turq_content.php');
include_once ('turq_woo_product.php');
include_once ('turq_woo_checkout.php');
include_once ('turq_woo_orders.php');
//include_once ('turq_woo_xero.php');
include_once ('turq_woo_email.php');
include_once ('turq_woo_coupons.php');


add_filter('woocommerce_locate_template', 'woocommerce_locate_template_turq', 99999, 3);
function woocommerce_locate_template_turq($template, $template_name, $template_path) {
    $plugin_path = plugin_dir_path(__FILE__) . 'woocommerce/';
    if (file_exists($plugin_path . $template_name)) {
			return $plugin_path . $template_name;
    }
	

    return $template;
}


////////////////////////////////////////////////////////////////////////////////
// General
////////////////////////////////////////////////////////////////////////////////{
function turq_log_prefix()				{ return '['. (WC()->session ? WC()->session->get_customer_id() : 'none') .'] '; }
function turq_log		($mixed)		{ if (get_option('turq_debug') ?? 0) error_log(turq_log_prefix().$mixed);}
function turq_debug		($text, $mixed)	{ turq_log($text." ".print_r($mixed, true)); }
function turq_debug_vars($text, $mixed)	{ turq_log($text." ".dpv($mixed)); }

function wpv(array $vars) {
    $out = '';
    foreach ($vars as $label => $value) {
		if (is_array($value) || is_object($value)) {
				$out .= "<pre><details><summary>".htmlspecialchars($label)."</summary><div>".print_r($value, true) . "</details></pre>";
		}
		else	$out .= "<pre>" . htmlspecialchars($label) . ': ' . print_r($value, true) . "</pre>";
    }
    return $out;
}

function dpv(array $vars) {
    $out = '';
    foreach ($vars as $label => $value) {
        $out .= htmlspecialchars($label) . ': ' . print_r($value, true) . "\r\n";
    }
    return $out;
}

function get_post_value(array $post, string $key) { return isset($post[$key]) && !empty($post[$key]) ? esc_attr($post[$key]) : null; }


// CLOSED
//add_filter( 'woocommerce_is_purchasable', '__return_false' );
//add_action('template_redirect', function (){if(is_shop() || is_product() || is_product_category() || is_product_taxonomy()) wp_redirect(site_url() . '/shop-temporarily-closed', '302');});


//stop T&Cs being displayed as drop down (inline)
add_action( 'wp', function(){remove_action( 'woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30 );} );

// Close comments on the front-end
add_filter('comments_open', '__return_false', 20, 2);
add_filter('pings_open', '__return_false', 20, 2);

//}
?>