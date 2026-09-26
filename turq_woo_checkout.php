<?php
/*******************************************************************************
 * Checkout customisation
 * 
 * turq_woo_checkout.php
 * 
*******************************************************************************/
//do_action( 'qm/debug', );

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/* Example arrays....
---------------------

	ti_dates = {"p1":[{value:"d1",desc:"12/01/25"},{value:"d2",desc:"19/01/25"},{value:"d3",desc:"26/01/25"}], "p2":[{value:"d1",desc:"13/01/25"},{value:"d2",desc:"20/01/25"},{value:"d3",desc:"27/01/25"}], "p3":[{value:"d1",desc:"14/01/25"},{value:"d2",desc:"21/01/25"},{value:"d3",desc:"28/01/25"}], };
	NOTE / signifies American so mm/dd, whereas - is UK so dd-mm !!!!!!!!

	Array ([wpsl_address] => Array ( [0] => Hannover ) [wpsl_address2] => Array ( [0] => Tower Road ) [wpsl_city] => Array ( [0] => Brighton ) [wpsl_zip] => Array ( [0] => BN2 0FZ ) [wpsl_country] => Array ( [0] => United Kingdom ) [wpsl_country_iso] => Array ( [0] => GB ) [wpsl_lat] => Array ( [0] => 50.826148 ) [wpsl_lng] => Array ( [0] => -0.124809 ) [wpsl_hours] => Array ( [0] => a:7:{s:6:"monday";a:0:{}s:7:"tuesday";a:0:{}s:9:"wednesday";a:0:{}s:8:"thursday";a:1:{i:0;s:15:"9:00 AM,2:00 PM";}s:6:"friday";a:0:{}s:8:"saturday";a:1:{i:0;s:15:"9:00 AM,3:00 PM";}s:6:"sunday";a:0:{}} ) [wpsl_recurring-thursday] => Array ( [0] => rec ) [wpsl_cut_off-thursday] => Array ( [0] => wednesday ) [wpsl_skip-thursday] => Array ( [0] => skip ) )

*/


add_shortcode('turq_woo_mini_cart', function() {
    // The Mini Cart block content
    $block_content = '<!-- wp:woocommerce/mini-cart /-->';

    // Render the block
    echo do_blocks( $block_content );
});


////////////////////////////////////////////////////////////////////////////////
// HELPER FUNCTIONS
////////////////////////////////////////////////////////////////////////////////

function is_checkout_only() { return (is_checkout() && !is_wc_endpoint_url()) ? "true" : "false";}


/**
 * Generic evaluator for array membership relationships.
 *
 * Evaluates whether a relationship between two sets (arrays) is satisfied.
 *
 * @param array  $needle     The array of target or "required" items.
 * @param array  $haystack   The array of items to test against.
 * @param string $condition  One of:
 *    - 'in'              → true if any needle is in haystack
 *    - 'not_in'          → true if no needle is in haystack
 *    - 'all_in'          → true if all haystack items are in needle
 *    - 'not_all_in'      → true if at least one haystack item is not in needle
 *    - 'subset'          → true if all needle items are in haystack
 *    - 'superset'        → true if haystack contains all needle items
 *
 * @return bool True if condition satisfied, false otherwise.
 */
function evaluate_array_relation( $needle, $haystack, $condition ) {
    // Normalize to unique values for set logic
    $needle   = array_unique( $needle );
    $haystack = array_unique( $haystack );

    $has_intersection = count( array_intersect( $needle, $haystack ) ) > 0;
    $all_haystack_in_needle = empty( array_diff( $haystack, $needle ) );
    $all_needle_in_haystack = empty( array_diff( $needle, $haystack ) );

    switch ( $condition ) {
        case 'in':
            // At least one item in needle exists in haystack
            return $has_intersection;

        case 'not_in':
            // No items in needle exist in haystack
            return ! $has_intersection;

        case 'all_in':
            // All items in haystack exist in needle
            return $all_haystack_in_needle;

        case 'not_all_in':
            // At least one haystack item not found in needle
            return ! $all_haystack_in_needle;

        case 'subset':
            // Needle is a subset of haystack
            return $all_needle_in_haystack;

        case 'superset':
            // Needle contains all haystack items
            return $all_haystack_in_needle;

        default:
            return false;
    }
}



// ============================
// ADD TO CART FLOW
// ============================

// 1) Validate product
//--------------------
// If "package_group" is set check its a valid option

add_filter('woocommerce_add_to_cart_validation', 'woocommerce_add_to_cart_validation_turq', 10, 2 );
function woocommerce_add_to_cart_validation_turq( $passed, $product_id ){
	//turq_debug("woocommerce_add_to_cart_validation >> ", $product_id);

	$product				= wc_get_product($product_id);	
	$block_direct_url 		= $product->is_type('variable') && isset($_GET['add-to-cart']);
	$product				= $product->is_type('variation') ? wc_get_product($product->get_parent_id()) : $product;	
	$check					= check_product_options($product);
	$group					= sanitize_text_field($_POST['package_group'] ?? '' );
	$invalid_package_group	= !empty($group) && !in_array($group, $check['valid']);

	//turq_debug_vars(compact('product','block_direct_url','check','group','invalid_package_group'));

	if (!$product || $block_direct_url || $invalid_package_group || !$check['valid']) {		
        wc_add_notice("At the moment we cannot add <a href='".esc_url( get_permalink( $product_id ))."'>".esc_html($product->get_title())."</a> to your cart -  please try again...", 'error' );
        return false;
    }
	
   return $passed;
}


// 2) Add group directly onto product
//-----------------------------------
// Can be user selected group from single product page via $_POST, or if not set, add first valid group(tag)
// Also called by WC()->cart->add_to_cart which is in bulk move so item data already set
// This makes splitting cart into groups fast and deterministic
// also makes cart_item unique so can have same product in different groups, i.e. twice in cart
// NOTE : moving into same group will combine similar products
add_filter('woocommerce_add_cart_item_data', 'woocommerce_add_cart_item_data_turq', 10, 2 );
function woocommerce_add_cart_item_data_turq( $cart_item_data, $product_id ) {
	//turq_debug("woocommerce_add_cart_item_data >> ", $product_id);	
	//turq_debug("cart_item_data >> ", $cart_item_data);	
	
	if (isset($cart_item_data['group_key'])) return $cart_item_data; 
	
    if (!empty($_POST['package_group'])) $cart_item_data['group_key'] = sanitize_text_field( $_POST['package_group'] );
	else {
		$product = check_product_options(wc_get_product($product_id));
		$cart_item_data['group_key'] = $product['group_key'];	
	}

	return $cart_item_data;
}




// Helper - split cart items into groups
// -------------------------------------
function split_into_groups($items) {
	//turq_debug("split_into_groups >> ", $items));	
	$groups				 = turq_Market_Setup()->get_multi_shipping_groups();
	$active_groups		 = array_flip(turq_Market_Setup()->get_active_shipping_groups());

	$split_groups		 = [];
	$items_with_no_group = [];

    foreach ($items as $item_key => $item) {
		//turq_debug("split item >> ", $item));
			
		$group_key = $item['group_key'] ?? (is_object($item) ? $item->get_meta('_group_key') : null);

		if (empty($group_key) || !isset($active_groups[$group_key])) {
			$items_with_no_group[$item_key] = $item;
			continue;
		}

		if (!isset($split_groups[$group_key])) {
			$split_groups[$group_key] = ['count' => 0, 'items' => [], 'all_virtual' => 0, 'label' => $groups[$group_key]['label']];							// build smaller array with only critical elements
		}

		$split_groups[$group_key]['count']++;
		$split_groups[$group_key]['items'][$item_key] = $item;

		// flag if group contains ONLY virtual products
		$product_id	= $item['product_id'];
		$product	= wc_get_product($product_id);
		if ($product->is_downloadable())	$split_groups[$group_key]['all_virtual']++;
		else								$split_groups[$group_key]['all_virtual']--;

		
    }
	//turq_debug_vars("SPLIT groups >> ", compact('split_groups', 'groups', 'items_with_no_group'));

	if (empty($items_with_no_group)) return $split_groups;

    // ---------------------------------------------------------------------
    // Deal with items in cart but group no longer active
    // ---------------------------------------------------------------------
	foreach ($items_with_no_group as $item_key => $item) {
		$product_id	= $item['product_id'];
		$product	= wc_get_product($product_id);	
		$check		= check_product_options($product);
		if (!empty($check['valid'])) {
			// move to another group
			$group_key = current($check['valid']);
			$split_groups[$group_key]['count']++;
			$split_groups[$group_key]['items'][$item_key] = $item;

			if ($product->is_downloadable())	$split_groups[$group_key]['all_virtual']++;
			else								$split_groups[$group_key]['all_virtual']--;
		}
		else {
			// delete from cart
			$done = WC()->cart->remove_cart_item( $item_key );
			if ($done) wc_add_notice("<a href='".esc_url( get_permalink( $product_id ))."'>".esc_html($product->get_title())."</a> is no longer available.", 'notice' );
		}
	}

	//turq_debug_vars("items_with_no_group >> ", compact('split_groups'));
	
    return $split_groups;
}

// 3) Create new packages based on cart_item meta
//-----------------------------------------------
// This is also called by checkout
add_filter('woocommerce_cart_shipping_packages', 'woocommerce_cart_shipping_packages_turq' );
function woocommerce_cart_shipping_packages_turq($packages) {
	turq_log("woocommerce_cart_shipping_packages------------------");
	turq_debug("packages>", $packages);
    $cart			= WC()->cart;
    $groups			= split_into_groups($cart->get_cart());
    $new_packages	= [];

	$i=0;
    foreach ($groups as $group_key => $group) {
		
		if ($group['count']==0) continue;

		$items = $group['items'];
        $new_packages[] = [
			'count'			  => $group['count'],
            'contents'        => $items,
            'contents_cost'   => array_sum(wp_list_pluck($items, 'line_total')),
            'applied_coupons' => $cart->get_applied_coupons(),
			'user'			  => $packages['user'] ?? [],
            'destination'     => [
                'country'  => $cart->get_customer()->get_shipping_country(),
                'state'    => $cart->get_customer()->get_shipping_state(),
                'postcode' => $cart->get_customer()->get_shipping_postcode(),
                'city'     => $cart->get_customer()->get_shipping_city(),
                'address'  => $cart->get_customer()->get_shipping_address(),
                'address_1'=> $cart->get_customer()->get_shipping_address_1(),				
                'address_2'=> $cart->get_customer()->get_shipping_address_2(),
            ],
			'group_index'     => $i++,
			'group_key'       => $group_key,
			'label'           => $group['label'],	
			'all_virtual'	  => $group['all_virtual'],	
        ];
				
    }

	turq_debug("cart>", $cart);
	turq_debug("existing packages>", print_r(array_map(fn($v) => is_array($v) ? (array_map(fn($v1) => is_array($v1) ? 'array' : $v1, $v)) : $v, $packages), true));
	turq_debug("new packages>", print_r(array_map(fn($v) => is_array($v) ? (array_map(fn($v1) => is_array($v1) ? 'array' : $v1, $v)) : $v, $new_packages), true));
    return $new_packages;
}

add_action( 'wp_enqueue_scripts', 'turq_enqueue_scripts');
function turq_enqueue_scripts() {
	if ( is_checkout_only() ) {

		wp_enqueue_style(
			'turq-checkout-css',
			plugins_url( '/css/turq_checkout.css', __FILE__ ),
			false,
			'1.0',
			'all'
		);

		wp_enqueue_script(
			'turq-multi-shipping-checkout',
			plugins_url( 'js/multi-shipping-checkout.js', __FILE__ ),
			[ 'jquery', 'wc-checkout' ],
			'1.0',
			true
		);
		
		wp_localize_script(
			'turq-multi-shipping-checkout',
			'multiShippingVars',
			[
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'turq_checkout' ),
			]
		);		
	}
}



add_action('wp_ajax_multi_bulk_move','multi_bulk_move_handler');
add_action('wp_ajax_nopriv_multi_bulk_move','multi_bulk_move_handler');
function multi_bulk_move_handler(){
    check_ajax_referer('turq_checkout','security');

    $from_group = sanitize_text_field($_POST['from_group'] ?? '');
    $target_group = sanitize_text_field($_POST['target_group'] ?? '');
    $items = $_POST['items'] ?? [];

    if(empty($items) || !is_array($items)){
        wp_send_json_error(['message'=>'No items']);
    }
    if($target_group === ''){
        wp_send_json_error(['message'=>'No target specified']);
    }
	// NOTE : $cart_item_key = md5( product_id + variation_id + cart_item_data ) so need to copy, remove, re-add

	$failed = 0;
    foreach($items as $cart_item_key){

		$cart_item = WC()->cart->get_cart()[$cart_item_key];

		$product_id		= $cart_item['product_id'];
		$variation_id	= $cart_item['variation_id'];
		$quantity		= $cart_item['quantity'];
		$old_gk			= $cart_item['group_key'] ?? '';
		
		if ($old_gk != $from_group) {
			$failed++;
			continue;
		}

		// Add with new package
		$success = WC()->cart->add_to_cart(
			$product_id,
			$quantity,
			$variation_id,
			[],
			['group_key'=> $target_group]
		);
		if ($success === false) $failed++;
		else					WC()->cart->remove_cart_item( $cart_item_key ); 			// Remove old cart line

	}
	if ($failed)	wp_send_json_error(['message'=>$failed." items cannot be moved"]);
	else			wp_send_json_success(['message'=>count($items)." items moved"]);
}



add_action( 'wp_ajax_woocommerce_update_cart_item', 'my_ajax_update_cart_item' );
add_action( 'wp_ajax_nopriv_woocommerce_update_cart_item', 'my_ajax_update_cart_item' );
function my_ajax_update_cart_item() {
	check_ajax_referer( 'turq_checkout', 'security' );

	$cart_item_key = wc_clean( $_POST['cart_item_key'] ?? '' );
	$qty = intval( $_POST['quantity'] ?? 0 );

	if ( ! $cart_item_key ) {
		wp_send_json_error( [ 'message' => "Missing cart item key" ] );
	}

	WC()->cart->set_quantity( $cart_item_key, $qty, true );
	wp_send_json_success();
}


// DEV ADD ITEMS............

add_action('wp_ajax_turq_search_products', 'turq_search_products');
add_action('wp_ajax_nopriv_turq_search_products', 'turq_search_products');
function turq_search_products() {
	check_ajax_referer( 'turq_checkout', 'security' );

    $term			= sanitize_text_field($_GET['term'] ?? '');
	$group_key		= sanitize_text_field($_GET['group'] ?? '');

	$groups 		= turq_Market_Setup()->get_multi_shipping_groups();
	$tag_id_filter	= intval($groups[$group_key]['tag_id']);

	global $wpdb;

	$query = $wpdb->prepare("
		SELECT DISTINCT p.ID, p.post_title, GROUP_CONCAT(DISTINCT tt_tag.term_id) AS tag_ids
		FROM {$wpdb->posts} p
		INNER JOIN {$wpdb->prefix}wc_product_meta_lookup pm
			ON p.ID = pm.product_id
		-- tags: include parent tags for variations
		LEFT JOIN {$wpdb->term_relationships} tr_tag
			ON (p.ID = tr_tag.object_id OR p.post_parent = tr_tag.object_id)
		LEFT JOIN {$wpdb->term_taxonomy} tt_tag
			ON tr_tag.term_taxonomy_id = tt_tag.term_taxonomy_id
			AND tt_tag.taxonomy = 'product_tag'
		-- visibility join (optional)
		LEFT JOIN {$wpdb->term_relationships} tr_vis
			ON p.ID = tr_vis.object_id
		LEFT JOIN {$wpdb->term_taxonomy} tt_vis
			ON tr_vis.term_taxonomy_id = tt_vis.term_taxonomy_id
			AND tt_vis.taxonomy = 'product_visibility'
		LEFT JOIN {$wpdb->terms} t_vis
			ON tt_vis.term_id = t_vis.term_id
		WHERE 
		  (
			(p.post_type = 'product' AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->posts} c 
				WHERE c.post_type = 'product_variation' AND c.post_parent = p.ID
			))
			OR p.post_type = 'product_variation'
		  )
		  AND p.post_status = 'publish'
		  AND (pm.stock_quantity > 0 OR pm.stock_quantity IS NULL)
		  AND (t_vis.slug IN ('catalog','search') OR t_vis.slug IS NULL)
		  AND p.post_title LIKE CONCAT('%%', %s, '%%')
		GROUP BY p.ID
		ORDER BY p.post_title ASC
		LIMIT 20
	", $term);

	$products = $wpdb->get_results($query);

	$results = [];
	foreach ($products as $p) {
		$product_tag_ids = explode(',', $p->tag_ids ?: '');
		if (in_array($tag_id_filter, $product_tag_ids)) {
			$results[] = [
				'id' => $p->ID,
				'text' => $p->post_title
			];
		}
	}

	wp_send_json($results);
}

add_action('wp_footer', 'wp_footer_turq');
function wp_footer_turq(){

    if(!is_checkout_only()) return;
?>

<div id="turq-product-modal" class="turq_delivery_modal">
    <div class="modal-content">
		<button class="turq-product-close modal-close" type="button"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" strokewidth="2" strokelinecap="round" strokelinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>	
        <h3 style="margin-top:0">Add Product for <span></span></h3>
		<div id="turq-product-modal-select">
			<select id="turq-product-search" style="width:100%"></select>
		</div>
		<div style="margin-top: 10px;  display: flex;  justify-content: space-between;">
			<input style="width:70px" type="number" id="turq-qty" value="1" min="1" />
			<button id="turq-product-add-to-package" type="button">Add Item</button>
		</div>
    </div>
</div>

<?php
}


////////////////////////////////////////////////////////////////////////////////
// Main
////////////////////////////////////////////////////////////////////////////////


/**
 * Generates and optionally renders shipping selection fields (HTML) for a package.
 * 
 * This function processes shipping data to build dropdown menus for different 
 * delivery methods ('s' for Shipping/Delivery, 'p' for Pickup/Market). It handles:
 * 1. Data Transformation: Converts raw timestamps/slots into human-readable labels.
 * 2. State Management: Pre-loads values from the WC Session (Frontend) or 
 *    passed variables (Admin Order Edit).
 * 3. UI Rendering: Uses `woocommerce_form_field` to output accessible select inputs.
 * 4. Validation: Returns the original validation array for downstream form checking.
 *
 * @param string      $method_name  The method type code (e.g., 's', 'p', 'cp').
 * @param array|string $package     The WC shipping package array or a group_key string.
 * @param bool        $render       Whether to echo the HTML fields directly.
 * @param mixed       $sv1          Optional: Saved value 1 (used for Admin override/preloads).
 * @param mixed       $sv2          Optional: Saved value 2 (used for Admin override/preloads).
 * 
 * @return array The validation data array associated with the method and package.
 */
function render_options($method_name, $package=[], $sv1=NULL, $sv2=NULL){
	turq_log("render_options------------------");
	turq_debug_vars("", compact('method_name', 'package'));	
	//turq_log("GenOptions: 1:$sv1 2:$sv2");
	
	ob_start();
	
	$group_key			= $package['group_key'] ?? $package;
	$group_key_attr 	= $group_key ? "_{$group_key}" : '';

	$option_validation	= turq_Market_Setup()->get_option_validation($group_key, $method_name);
	$method_setup		= turq_Market_Setup()->get_method_setup($method_name);
	
	if ($method_name == 's' && !empty($option_validation)) {				
		$delivery_options  = [];				
		foreach($option_validation as $key => $delivery_data)
			$delivery_options[$key] = date(DATEFORMAT, $delivery_data['d1']['date'])." ".$delivery_data['d1']['slot'];

		//error_log("BUILD OPTIONS---------- S");
		//error_log("[$group_key]");				
		//error_log(dpv(compact('delivery_options', 'options', 'option_validation')));

		//determine preloads
		if (is_admin()) {
			$value = array_key_exists($sv1, $delivery_options) ? $sv1 : '';
		}
		else if (isset(WC()->session)) {
			$values = WC()->session->get("turq_delivery_group{$group_key_attr}", array());
			$value = $values[$method_setup['field_id']."{$group_key_attr}"] ?? '';
		}
		else $value='';
		//echo "value:$value:".print_r($values,true);		

		// create fields
		$field = $method_setup['field_id'];
		woocommerce_form_field( $field."{$group_key_attr}", array(
			'type'     			=> 'select',
			'class'    			=> ['form-row-wide', $field],
			'required' 			=> true,
			'options'  			=> [''=>'Choose a date'] + $delivery_options,
			'placeholder'		=> 'Choose a date',
			'custom_attributes' => ['data-local-type' => 's0'],
		), $value );
	}
	
	
	if ($method_name == 'p' && !empty($option_validation)) {
		$market_options_location	= [];
		$market_options_date		= [];
		$js_options					= [];
		
		foreach ($option_validation as $market_id => $location) {
			$market_options_location[$market_id] = $location['location'];

			$date_options = array_filter($location, function($key) { return preg_match('/^d\d+$/', $key); }, ARRAY_FILTER_USE_KEY);

			foreach($date_options as $id => $option) {
				$date									= date(DATEFORMAT, $option['date']);
				$market_options_date[$market_id][$id]	= $date;
				$js_options[$market_id][]				= ['v' => $id,'o' => $date];
			}
		}

		//error_log("BUILD OPTIONS---------- P");
		//error_log("[$group_key]");	
		//error_log(dpv(compact('market_options_location', 'market_options_date', 'js_options', 'option_validation')));		

		//determine preloads
		$market_value = '';
		$date_value = '';			
		if (is_admin()) {
			if (isset($sv1) && !empty($sv1)) {
				$market_value = array_key_exists($sv1, $market_options_location) ? $sv1 : '';
				// 2 can only be set if 1 is also
				if (isset($sv2) && !empty($sv2) && !empty($market_value)) {
					$key = array_search( $sv2, $market_options_date[$market_value] );
					if ($key) $date_value = $key ;
				}
			}
		}
		else if (isset(WC()->session)) {
			$values = WC()->session->get("turq_delivery_group{$group_key_attr}", array());
			$market_value = $values[$method_setup['field_id'][0]."{$group_key_attr}"] ?? '';
			$date_value = $values[$method_setup['field_id'][1]."{$group_key_attr}"] ?? '';			
		}
		//error_log("[$group_key]>>market value:$market_value:date value:$date_value:array".print_r($values,true).":ALL=".print_r($market_options_date[$market_value],true));

		// create fields
		$field = $method_setup['field_id'][0];
		woocommerce_form_field( $field."{$group_key_attr}", array(
			'type'     			=> 'select',
			'class'    			=> ['form-row-wide', $field],
			'required' 			=> true,
			'options'  			=> [''=>"Choose a market"] + $market_options_location,
			'placeholder'		=> "Choose a market",
			'custom_attributes' => ['data-local-type' => 'p0'],
		), $market_value);

		$field = $method_setup['field_id'][1];		
		woocommerce_form_field( $field."{$group_key_attr}", array(
			'type'     			=> 'select',
			'class'    			=> ['form-row-wide', $field, $market_value ? '' : 'ti_select_hidden'],
			'required' 			=> true,
			'options'  			=> ['' => "Choose a day"] + ($market_value ? ($market_options_date[$market_value] ?? array()) : array()),
			'placeholder'		=> "Choose a day",			
			'custom_attributes' => ['data-local-type' => 'p1', 'data-ti_dates_data' => esc_attr(json_encode($js_options))],
		), $date_value);

	}
	
	if ($method_name == 'cp') {		
	}


	return ob_get_clean();
}


//
// Ajax PHP receiver - Store choices of drop downs
// -----------------------------------------------
// See external JS file
add_action( 'wp_ajax_turq_delivery', 'set_delivery' );
add_action( 'wp_ajax_nopriv_turq_delivery', 'set_delivery' );
function set_delivery() {
	check_ajax_referer('turq_checkout', 'security');	

	$values = array_map('sanitize_text_field', $_POST['values'] ?? []);
	$group  = $values['group'] ?? '';
	
	turq_log("set_delivery AJAX------------------");
	turq_debug("save to session>", print_r($_POST['values'],true));
	
	if ( $group ) {
		// Store group-specific data
		$session_key = "turq_delivery_group_{$group}";
		$current = (array) WC()->session->get( $session_key, array() );
		turq_debug("session $group >", print_r(array_merge($current, $values),true));
		WC()->session->set( $session_key, array_merge($current, $values) );
		wp_send_json_success([ 'group' => $group, 'data' => $values ]);
	} else {
		wp_send_json_error('Missing group');
	}

}

//
// check object, e.g.cart, for pickup only products
// ------------------------------------------------
function pickup_products_in($haystack = NULL) {

	$pickup_only_ids = turq_Market_Setup()->get_pickup_only_products();

	if ($haystack === NULL || $haystack === 'cart')
		$haystack = WC()->cart->get_cart();
	else if (array_key_exists('product_id', $haystack) || array_key_exists('variation_id', $haystack))
		$haystack = [$haystack]; 																								// single item, turn into one element array

    foreach ($haystack as $cart_item)
		if ((array_key_exists('product_id', $cart_item) && in_array($cart_item['product_id'], $pickup_only_ids)) || (array_key_exists('variation_id', $cart_item) && in_array($cart_item['variation_id'], $pickup_only_ids))) return true;

	return false;
}

//
// Add note under item name if product in cart/checkout
// ----------------------------------------------------
// see below to remove shipping method
add_filter( 'woocommerce_get_item_data', 'woocommerce_get_item_data_turq', 10, 2);
function woocommerce_get_item_data_turq($item_data, $cart_item ) {
	if (pickup_products_in($cart_item)) $item_data[] = ['name' => 'NOTE', 'value' => "This item makes the order collection only"];
	return $item_data;
}


//
// Add id to all shipping rates as WooC builds them
// ------------------------------------------------
// This typically happens before anything is rendered to the output
// rates e.g. Array( [local_pickup:6] => WC_Shipping_Rate Object, [flat_rate:1] => WC_Shipping_Rate Object )
add_filter( 'woocommerce_package_rates', 'woocommerce_package_rates_turq' , 1 , 2 );
function woocommerce_package_rates_turq( $rates, $package ) {
	//turq_log("woocommerce_package_rates------------------");	
	
	foreach ( $rates as $rate_id => $rate ) {
		/*if ( empty( $rate->get_meta_data('group') ) )*/
			$rate->add_meta_data('group', ['key' => $package['group_key'] ?? '', 'label' => $package['label'] ?? '' ]);
	}

	//turq_debug_vars("", (dpv(compact('rates', 'package'))));
    return $rates;

    $threshold = 50; // Amount above which handling is not charged
    foreach ($rates as $rate_key => $rate) {
        if ('local_pickup' === $rate->method_id) {
            if ($package['contents_cost'] > $threshold) $rates[$rate_key]->cost = 0;
        }
    }
	
    return $rates;
}



/**
 * Filters and validates shipping packages before they are rendered in the checkout context.
 * This happens immediately before output is rendered, last possible so Woo can't alter it !!
 * no data is saved back to WOO for any structures e.g. packages, they only live to control the render
 * local_pickup object behaves differently to flat_rate so only remove those here, not anywhere else e.g. woocommerce_package_rates
 * This is CUSTOM FILTER in CUSTOM TEMPLATE
 * 
 * This function performs final-stage filtering on shipping rates based on:
 * 1. Product-specific restrictions (e.g., "Collection Point only" items).
 * 2. Method configuration settings (enabled/disabled status).
 * 3. Availability of valid delivery/pickup options (validation checks).
 * 
 * It also manages the session state to ensure the 'chosen_method' remains synchronised with the available rates after filtering.
 *
 * @filter turq_checkout_package_context
 * 
 * @param array  $package        The WooCommerce shipping package array containing rates and contents.
 * @param string $chosen_method  The ID of the shipping method currently selected by the user/system.
 * 
 * @return array The filtered package array with updated rates and validated 'chosen_method'.
 */
add_filter( 'turq_checkout_package_context', 'checkout_package_context', 1 , 2 );
function checkout_package_context( $package, $chosen_method ) {
	turq_log("	------------------");
	turq_debug_vars("", compact('chosen_method', 'package'));
	turq_debug("BEFORE >", $package['rates']);	
	
    $targeted_methods = turq_Market_Setup()->get_targeted_methods();
    $group_key        = $package['group_key'] ?? '';
    $session_key      = "turq_delivery_group_{$group_key}";
    $group_data       = (array) WC()->session->get( $session_key, [] );

    // 1. Pre-calculate product constraints
    $package_items      = wp_list_pluck( $package['contents'], 'product_id' );
    $cp_only_products   = turq_Market_Setup()->get_cp_only_products();
    $only_allow_cp		= evaluate_array_relation( $cp_only_products, $package_items, 'all_in' );
    $has_pickup_only    = pickup_products_in( $package['contents'] );
	$virtual_delivery	= $package['all_virtual'] > 0;
    $has_free_shipping	= [];
    foreach ( $package['rates'] as $rate_id => $rate ) {
		$method_type   								= $targeted_methods[$rate_id];				
		$has_free_shipping[$method_type]		  ??= false;
		if ($rate->method_id === 'free_shipping')	$has_free_shipping[$method_type] = true;
	}
	turq_debug("FREE RATES >", $has_free_shipping);	

    // 2. Filter Rates
    foreach ( $package['rates'] as $rate_id => $rate ) {
        if ( !isset( $targeted_methods[$rate_id] ) ) continue;

        $method_type   = $targeted_methods[$rate_id];
        $method_config = turq_Market_Setup()->get_method_config( $group_key, $method_type );

		// Remove other options if FREE method exists for method
		if ( $has_free_shipping[$method_type] && $rate->method_id != 'free_shipping') {
			unset( $package['rates'][$rate_id] );
			continue;
		}

        // Cart contains "ONLY" items, eg. postage
        if ( $only_allow_cp ) {
            if ( $method_type !== 'cp' ) {
                unset( $package['rates'][$rate_id] );
            }
            continue;
        }

        if ( $virtual_delivery ) {
            if ( $method_type !== 'v' ) {
                unset( $package['rates'][$rate_id] );
            }
            continue;
        }

        // Standard cart - check individual method availability
        if ( $method_type === 'cp' && !$method_config['enabled'] ) {
            unset( $package['rates'][$rate_id] );
            continue;
        }

        if ( $method_type === 's' && $has_pickup_only ) {
            unset( $package['rates'][$rate_id] );
            continue;
        }

        // Validation check (e.g., date/slot availability)
        $option_validation = turq_Market_Setup()->get_option_validation( $group_key, $method_type );
        if ( empty( $option_validation ) ) {
            unset( $package['rates'][$rate_id] );
        }
    }

    // 3. Re-validate chosen method after filtering
    $shippingMethod = $group_data['shippingMethod'] ?? '';
    $prev_index     = $group_data['prev_index'] ?? '';
    
    // Reset logic: If method is gone or index changed, fallback to first available rate
    $is_invalid = !isset( $package['rates'][$chosen_method] ) || 
                  ( $package['group_index'] !== $prev_index && !empty( $prev_index ) );

    if ( $is_invalid ) {
        $chosen_method = !empty( $shippingMethod ) && isset( $package['rates'][$shippingMethod] ) 
                         ? $shippingMethod 
                         : array_key_first( $package['rates'] );
    }

    // 4. Finalise Package and Session
    $package['chosen_method'] = $chosen_method;
    $package['method_name']   = $targeted_methods[$chosen_method] ?? '';

    WC()->session->set( $session_key, array_merge( $group_data, [
        'prev_index' => $package['group_index'],
        'no_options' => count( $package['rates'] )
    ] ) );

	turq_debug("group_data", $group_data);	
	turq_debug("chosen_method", $chosen_method);
	turq_debug("AFTER >",$package['rates']);
    return $package;
}



//
// Min order value check & message
// -------------------------------
function get_min_order_text($package=null, $linkToGroup=false) {
	$out = "";
	$minimum = turq_Market_Setup()->get_order_setting('min_order_value');
	
	// CAUTION: line_subtotal = true cost, line_total = has coupon applied
	$subTotal = $package ? array_sum(wp_list_pluck($package['contents'], 'line_subtotal')) : WC()->cart->subtotal;				// was just $package['contents_cost']
	
	if ($subTotal < $minimum) {
		$out = "For delivery, you must have a minimum order amount of ".wc_price($minimum)." - your current order total is ".wc_price($subTotal).".";
		if ($linkToGroup) $out .=" Please edit your <a href='#package-{$package['group_key']}'>{$package['label']} items</a>";
	}
	return $out;
}


/**
 * Renders the UI for chosen shipping methods in the WooCommerce checkout.
 * 
 * This function hooks into 'turq_woocommerce_after_shipping_rate' to display 
 * a selection trigger (button) and a modal popup when a "targeted" shipping 
 * method is selected. It handles minimum order value checks and generates 
 * the interactive selection options.
 *
 * @hooked turq_woocommerce_after_shipping_rate - 20
 * 
 * @param WC_Shipping_Rate $method         The current shipping rate being rendered.
 * @param string           $index          The index of the shipping rate.
 * @param string           $chosen_method  The ID of the currently selected shipping method.
 * @param array            $package        The shipping package data.
 * 
 * @return void Renders HTML directly to the page.
 */
add_action( 'turq_woocommerce_after_shipping_rate', 'woocommerce_after_shipping_rate_turq' , 20, 4 );
function woocommerce_after_shipping_rate_turq( $method, $index, $chosen_method, $package ){
	//static $hcount = 0; 	error_log("woocommerce_after_shipping_rate>".$hcount++." ajax[".json_encode(wp_doing_ajax())."]");
	
	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();

	$group_key			= esc_attr($package['group_key']??'');
	//$chosen_method	= WC()->session->get( 'chosen_shipping_methods' )[ $index ] ?? '';

	turq_log("after_shipping_rate -------- $group_key");
	turq_debug ("chosen_shipping_methods [$index] -", $chosen_method);
	turq_debug ("method>", $method);
	turq_debug ("package>", $package);

    if( !empty($chosen_method) && ($method->id == $chosen_method) && array_key_exists($method->id, $targeted_methods) && ($targeted_methods[$method->id] != 'cp') && ($targeted_methods[$method->id] != 'v')) {

		$free_shipping = $package['rates'][$chosen_method]->get_method_id() == 'free_shipping';

		if ( ( $free_shipping && !turq_Market_Setup()->get_order_setting('free_min_order_value')) ||
			 (!$free_shipping && in_array($chosen_method, array_keys($targeted_methods, 's'), true)) )	$has_min_order_value = get_min_order_text($package);
		
		$method_name		= $targeted_methods[$chosen_method] ?? '';		
		$method_setup		= turq_Market_Setup()->get_method_setup($method_name);		
		$method_description = $method_setup['method_description'] ?? '';

		?>	
		<div class="turq_delivery_trigger" data-group="<?= $group_key ?>">
			<span class="turq_delivery_summary" data-default="No <?= esc_html($method_description) ?> chosen"></span>
			<button class="turq_delivery_open" type="button"><?= esc_html( str_replace(['Collection', 'Delivery'], "Pick", $method_description) ) ?></button>
		</div>
		<div id="turq_delivery_modal_<?= $group_key ?>" class="turq_delivery_modal" data-group="<?= $group_key ?>">
			<div class="modal-content">
				<button class="closeDeliveryModal modal-close" type="button"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" strokewidth="2" strokelinecap="round" strokelinejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>				
				<h3 style="margin-top:0"><?= esc_html($method_description) ?></h3> <?php
				echo "<div class='turq_delivery' data-label='".esc_attr($method_description)."'>";
					if (empty($has_min_order_value)) echo render_options($method_name, $package);
					else							 echo $has_min_order_value;
					WC()->session->set("ti_market_validation_$group_key", turq_Market_Setup()->get_option_validation($group_key, $method_name));
				?>
					<button class="turq_delivery_confirm modal-confirm" type="button"  style="margin: 10px 0 0 auto"> <?=  (empty($has_min_order_value)) ? "Confirm" : "OK" ?></button>		
				</div>
			</div>
		</div>
<?php		
    }
}


//
// Include our custom fields in normal woo data
// --------------------------------------------
// Makes retrieving data easier later on in checkout flow
add_filter( 'woocommerce_checkout_posted_data', 'woocommerce_checkout_posted_data_turq');
function woocommerce_checkout_posted_data_turq( $data ) {
	turq_log("woocommerce_checkout_posted_data ----------------------");
	turq_debug("_POST>", $_POST);
	turq_debug("data>", $data);	

    $field_ids	= turq_Market_Setup()->get_method_setup('', 'field_id');
	$events		= turq_Market_Setup()->get_active_shipping_groups();

    foreach ( $field_ids as $f_id ) {
		foreach ( $events as $group_key) {
			$field_id = $f_id.'_'.$group_key;
			if ( isset( $_POST[$field_id] ) ) {
				$data[$field_id] = wc_clean( $_POST[$field_id] );
			}
		}
    }

	turq_debug("new data>", $data);
    return $data;
}

//
// Bind group(key) from cart item to order line item
// --------------------------------------------------
// Ensure it's hidden
add_action('woocommerce_checkout_create_order_line_item', 'woocommerce_checkout_create_order_line_item_turq',10,4);
function woocommerce_checkout_create_order_line_item_turq ( $item, $cart_item_key, $values, $order ) { $item->add_meta_data('_group_key', $values['group_key'], true ); }

add_filter('woocommerce_hidden_order_itemmeta', 'woocommerce_hidden_order_itemmeta_turq');
function woocommerce_hidden_order_itemmeta_turq( $hidden_meta ) { $hidden_meta[] = '_group_key'; return $hidden_meta;}


//
// Helper to get all keys from multidimensional array
// --------------------------------------------------
function all_keys($array){
    $keys = [];
	foreach ($array ?? [] as $key => $value) {
        $keys[$key] = true;
        if (is_array($value)) $keys += all_keys($value);
    }
    return $keys;
}

/**
 * Validates custom shipping selection fields during the WooCommerce checkout process.
 * 
 * This function triggers on 'woocommerce_checkout_process' to verify that:
 * 1. Targeted shipping methods have valid options available.
 * 2. Required custom fields (from the delivery modal) are populated.
 * 3. The submitted values match the current session-validated keys (preventing spoofing).
 * 4. Minimum order requirements for specific shipping groups are met.
 * 
 * If any validation fails, it issues a `wc_add_notice` error to halt the checkout.
 *
 * @hooked woocommerce_checkout_process - 9999999999999
 * 
 * @return void Issues notices via wc_add_notice on failure.
 */
//add_action('woocommerce_checkout_process', 'woocommerce_checkout_process_turq', 9999999999999);
//function woocommerce_checkout_process_turq() {
   
add_action('woocommerce_after_checkout_validation', 'woocommerce_after_checkout_validation_turq', 9999999999999, 2);
function woocommerce_after_checkout_validation_turq($data, $errors) {   
	// $errors is object so passed by reference
   
	$packages = WC()->shipping()->get_packages();
   
    // Ensure shipping packages exist and are calculated
    if ( empty( $packages ) ) { 
        WC()->cart->calculate_shipping(); 
        $packages = WC()->shipping()->get_packages();
    }

	turq_log("woocommerce_after_checkout_validation ------------------- ");
	turq_debug("packages ", $packages);
	turq_debug("chosen ", WC()->session->get('chosen_shipping_methods', []));
	
	$no_shipping		= true;
	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();

    foreach ( $packages as $index => $package ) {
        $chosen_method		= WC()->session->get( 'chosen_shipping_methods' )[ $index ] ?? '';

        if ( !isset( $targeted_methods[$chosen_method] ) ) continue;

		$group_key			= $package['group_key'];
		$valid_keys			= all_keys(WC()->session->get("ti_market_validation_$group_key"));
		$delivery_data		= WC()->session->get("turq_delivery_group_$group_key");

		$method_name		= $targeted_methods[$chosen_method];
		$method_setup		= turq_Market_Setup()->get_method_setup($method_name);		
		$method_description = $method_setup['method_description'] ?? '';

		$no_shipping		= false;
		
        // ---------------------------------------------------------
		// Check if all products in group are virtual - if so, there is by definition NO shipping, but do not throw error
		// ---------------------------------------------------------
		if ($package['all_virtual'] > 0) continue;

        // ---------------------------------------------------------
		// Check if there are any valid options for this group
		// ---------------------------------------------------------
		if (($delivery_data['no_options'] ?? 0) === 0) {
			$item_label = $package['count'] > 1 ? 'items' : 'item';
			$errors->add( 
				'turq_required_shipping_method', 
				"We cannot process the {$item_label} for <a href='#package-{$package['group_key']}'>{$package['label']}</a> as there are no {$method_description}s available. Please either move or delete items."
			);
            continue;
        }

        // ---------------------------------------------------------
		// Minimum Order Value / Free shipping Validation
		// ---------------------------------------------------------
		$free_shipping = $package['rates'][$chosen_method]->get_method_id() == 'free_shipping';

		if ( ( $free_shipping && !turq_Market_Setup()->get_order_setting('free_min_order_value')) ||
			 (!$free_shipping && in_array($chosen_method, array_keys($targeted_methods, 's'), true)) )	{
				$min_order_text = get_min_order_text($package, true);
				if ( $min_order_text )
					$errors->add( 
						'turq_min_order_value', 
						$min_order_text
					);

			 }

        // ---------------------------------------------------------
		// Field Presence and Value Validation
		// ---------------------------------------------------------
		$all_fields = array_combine((array)$method_setup['field_label'], (array)$method_setup['field_id']);

		foreach ($all_fields as $label=>$f_id) {
			$field_id	= $f_id.'_'.$group_key;
		
			// check if fields exist and are not empty
			if(empty($_POST[$field_id] ?? ''))
				$errors->add( 
					'turq_required_custom_field_'.$f_id, 
					"<div class='turq_deliver_error'>Please select a <strong><a href='#turq_delivery_modal_{$group_key}'>{$label}</a></strong> as it is a required field for your <a href='#package-{$package['group_key']}'>{$package['label']}</a> items.</div>"
				);
			
			else {
				// validate actual field value against expected
				$val = get_post_value($_POST, $field_id);
				if (!isset($valid_keys[$val]))
					$errors->add( 
						'turq_required_custom_field_'.$f_id, 
						"<div class='turq_deliver_error'>Invalid selection ".esc_html($val)." for <strong><a href='#turq_delivery_modal_{$group_key}'>{$label}</a></strong></div>"
					);
			}
		}

    }

	if ($no_shipping) $errors->add( 
						'turq_no_shipping', 
						"If there are no shipping methods available then our online shop is temporarily CLOSED."
					);

	
	turq_debug("errors>", $errors);
	return;
}


// will be deprecated.....!! 
function set_order_turq_options($group_key, $chosen_method, $post, &$order) {
	
	// group_key is only used in checkout session. Admin order edit is not group based.
	if (!empty($group_key)) {
		$group_key_attr	= "_{$group_key}";
		$valid			= WC()->session->get('ti_market_validation'.$group_key_attr);
	} else {
		$group_key_attr	= '';		
		$valid			= get_transient('turq_options_validation_'.get_current_user_id());
	}
	
	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();
	$method_name		= $targeted_methods[$chosen_method] ?? null;
	
	if ($method_name) {

		if (!empty($group_key)) $order->update_meta_data('_turq_local_option_group',   $group_key);

		$order->update_meta_data( '_turq_local_option_method_name', $method_name);


		if ($method_name == 'cp') {
			$tlo = 'Postage';
			$order->update_meta_data('_turq_local_option', ['cp' => $tlo]);
			$order->update_meta_data('_turq_local_option_name', '');
			$order->update_meta_data('_turq_local_option_tag_id', turq_Market_Setup()->get_multi_shipping_groups()[$group_key]['tag_id']);
		}				
		else {
			$values		=[];

			// get posted fields for choosen method
			$field_ids	= (array)turq_Market_Setup()->get_method_setup($method_name, 'field_id');
			foreach ($field_ids as $field_id) {
				$val = get_post_value($post, $field_id.$group_key_attr);
				if ($val)	$values[] = esc_attr($val);
				else		return;
			}
			
			$primary = $values[0];
			$row = $valid[$primary];
			$opt = $row[$values[1] ?? 'd1'];

			if ($method_name == 'p') $tlo = $row['location'].' - '.date(DATEFORMAT, $opt['date']);
			if ($method_name == 's') $tlo = date(DATEFORMAT, $opt['date'])." ".$opt['slot'];

			$order->update_meta_data('_turq_local_option', [ $primary => $tlo ]);
			$order->update_meta_data('_turq_local_option_tag_id', $row['tag_id']);
			
			foreach ($opt as $key => $value) $order->update_meta_data('_turq_local_option_'.$key, $value);
			
		}
		//WC()->session->__unset("turq_delivery_group{$group_key_attr}");
	}
	return $tlo ?? '';
}


// manual trigger function to correct bad order shipping data
// ONLY WORKS WITH ONE METHOD ON ORDER
add_filter( 'woocommerce_order_actions', function( $actions ) { $actions['change_to_virtual_delivery'] = 'Change to Virtual Delivery'; return $actions;});
add_action( 'woocommerce_order_action_change_to_virtual_delivery', function( $order ) {

    $shipping_methods = $order->get_shipping_methods();

    if ( empty($shipping_methods) ) return;

    $current_shipping_item		= current( $shipping_methods );
    $current_shipping_item_id	= key( $shipping_methods );

    $shipping = new WC_Order_Item_Shipping();
	$shipping->set_name( 'Virtual Delivery' );
	$shipping->set_method_title( 'Virtual Delivery' );
	$shipping->set_method_id( 'virtual_delivery' );
	$shipping->set_instance_id( 14 );
	$shipping->set_total( 0 );

    // copy existing shipping meta
    foreach ( $current_shipping_item->get_meta_data() as $meta ) {
        $shipping->add_meta_data(
            $meta->key,
            $meta->value,
            true
        );
    }

    // remove old shipping item
    $order->remove_item( $current_shipping_item_id );

    // add replacement
    $order->add_item( $shipping );
	$order->add_order_note("Shipping method manually changed to Virtual Delivery.");
	woocommerce_checkout_order_processed_turq($order->get_id(), 'ADMIN DELIVERY FIX', $order);
	
    $order->save();

});


// manual trigger function to check ticket codes if none already exist, or order qty changed. etc...
add_filter( 'woocommerce_order_actions', function( $actions ) { $actions['check_ticket_codes'] = 'Check ticket codes'; return $actions;});
add_action( 'woocommerce_order_action_check_ticket_codes', function( $order ) {

	$shipping_methods	= $order->get_shipping_methods();
	if (count($shipping_methods)>1) return;
	
	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();	
	$shipping_method	= current($shipping_methods);
	$chosen_method		= $shipping_method->get_method_id() . ':' . $shipping_method->get_instance_id();
	$method_name		= $targeted_methods[$chosen_method] ?? null;
	if ($method_name != 'v') return;

	woocommerce_checkout_order_processed_turq($order->get_id(), 'ADMIN TICKET CHECK', $order);
	return;
});



/**
 * Processes and stores Turq package delivery metadata after a WooCommerce order is created.
 * 
 * This function triggers on 'woocommerce_checkout_order_processed' to:
 * 1. Prevent duplicate processing of previously handled orders.
 * 2. Associate selected delivery options with individual shipping packages.
 * 3. Persist validated delivery data from the checkout session into shipping item metadata.
 * 4. Generate package-level and order-level summary metadata for reporting and searching.
 * 5. Store package item relationships, delivery dates, collection slots, and market identifiers.
 * 6. Handle special delivery methods such as Virtual Delivery and Postage.
 * 7. Generate unique ticket codes for qualifying products and attach them to the order.
 * 
 * If required delivery data is missing during processing, the order is marked as
 * incomplete by resetting the package count and processing is aborted.
 *
 * @hooked woocommerce_checkout_order_processed
 * 
 * @param int      $order_id    The WooCommerce order ID.
 * @param array    $posted_data Checkout form data submitted by the customer.
 * @param WC_Order $order       The WooCommerce order object being processed.
 *
 * @return void Persists package metadata, ticket codes, and order summary data.
 */
add_action('woocommerce_checkout_order_processed', 'woocommerce_checkout_order_processed_turq', 20, 3);
function woocommerce_checkout_order_processed_turq($order_id, $posted_data, $order) {

		turq_log("woocommerce_checkout_order_processed ----------------------");
		turq_debug_vars("1>", compact('order_id','posted_data','order'));

        if (!$order instanceof WC_Order) return;

		$shipping_methods	= $order->get_shipping_methods();
		$targeted_methods	= turq_Market_Setup()->get_targeted_methods();

		$order_items		= $order->get_items();
		$groups				= split_into_groups($order_items);

		$order_meta			= [];
		$order_meta['_turq_package_count'][] = count($shipping_methods);

		turq_debug_vars("2>", compact('shipping_methods', 'order_items', 'groups'));

		foreach ( $shipping_methods as $id => $shipping_method ) {
			$pkgmeta		= [];

			$group			= $shipping_method->get_meta( 'group' );
			$group_key		= $group['key'] ?? '';

			$chosen_method	= $shipping_method->get_method_id() . ':' . $shipping_method->get_instance_id();
			$method_name	= $targeted_methods[$chosen_method] ?? null;
			
			turq_debug_vars("2.1>", compact('group_key','group', 'chosen_method', 'method_name'));
			
			if ($method_name) {
				// helper... store to shipping meta and order summary meta
				$push_meta = function($key, $value) use (&$pkgmeta, &$order_meta) {
					$pkgmeta[$key]  	= $value;
					$order_meta[$key] ??= [];
					$order_meta[$key][] = $value; 
				};
				
				if (!empty($group_key)) {
					 // only store to package not summary order meta
					$pkgmeta['_turq_package_items'] = array_keys($groups[$group_key]['items']) ?? [];
					$pkgmeta['_turq_local_option_group'] = $group_key;
				}

				$push_meta('_turq_local_option_method_name', $method_name);

				// analyse package items
				$total_items	= 0;
				$items_summary	= [];
				foreach ($groups[$group_key]['items'] as $key => $item) {
					$qty			= $item->get_quantity();
					$total_items	+= $qty;
					$items_summary[] = sprintf(
						'%s × %d',
						$item->get_name(),
						$qty
					);
				}
				$pkgmeta['_turq_package_total_items'] = $total_items;

				if ($method_name == 'v') {
					$tlo = 'Virtual Delivery';
					$push_meta('_turq_local_option', $tlo);
				
					$push_meta('_turq_local_option_name', $groups[$group_key]['label']);
					$push_meta('_turq_local_option_tag_id', turq_Market_Setup()->get_multi_shipping_groups()[$group_key]['tag_id']);

					// copy woo normal format
					$shipping_method->update_meta_data('Items', implode(", ", $items_summary));
				}
				else if ($method_name == 'cp') {
					$tlo = 'Postage';
					$push_meta('_turq_local_option', $tlo);
					
					$push_meta('_turq_local_option_name', '');
					$push_meta('_turq_local_option_tag_id', turq_Market_Setup()->get_multi_shipping_groups()[$group_key]['tag_id']);
				}				
				else {
					$values			=[];					
					$group_key_attr	= "_{$group_key}";
					$valid			= WC()->session->get('ti_market_validation'.$group_key_attr);

					turq_debug("2.1.1> valid", $valid);

					// get posted fields for choosen method
					$field_ids	= (array)turq_Market_Setup()->get_method_setup($method_name, 'field_id');
					foreach ($field_ids as $field_id) {
						$val = get_post_value($posted_data, $field_id.$group_key_attr);
						if ($val)	$values[] = esc_attr($val);
						else {
							$order->update_meta_data( '_turq_package_count', 0 );  // if order has incomplete data do not flag as processed, ie count = 0
							$order->save();
							return;
						}
					}
					
					$primary = $values[0];
					$row = $valid[$primary];
					$opt = $row[$values[1] ?? 'd1'];

					if ($method_name == 'p') $tlo = $row['location'].' - '.date(DATEFORMAT, $opt['date']);
					if ($method_name == 's') $tlo = date(DATEFORMAT, $opt['date'])." ".$opt['slot'];

					$push_meta('_turq_local_option', $tlo);
					$push_meta('_turq_local_option_tag_id', $row['tag_id']);
					$pkgmeta['_turq_local_option_market_id'] = $primary;					
					
					foreach ($opt as $key => $value) $push_meta('_turq_local_option_'.$key, $value);
					
				}
				
				$shipping_method->update_meta_data('_turq_package_data', $pkgmeta);
			
				turq_debug_vars("2.2>", compact('items_summary','order_meta','pkgmeta'));				
			}
		}


		// Clean _turq meta first
		foreach ( $order->get_meta_data() as $meta ) {
			if ( str_starts_with( $meta->key, '_turq' ) && $meta->key !== '_turq_ticket_codes' ) $order->delete_meta_data( $meta->key );
		}		
		
		// then add package summary data to order (no duplicates) for quick searching
		foreach ( $order_meta as $key => $data ) {
			foreach ( array_unique($data) as $d ) $order->add_meta_data( $key, $d );
		}
		
		// check if ticket exists on order, then generate codes
		$ticket_product_id	= 4973; // <-- CHANGE THIS
		$ticket_qty			= 0;

		foreach ( $order_items as $item ) {
			if ( $item->get_product_id() == $ticket_product_id ) $ticket_qty += (int) $item->get_quantity();
		}

		if ( $ticket_qty === 0 ) {
			$order->delete_meta_data( '_turq_ticket_codes' );
		} else {
			$codes		 = $order->get_meta( '_turq_ticket_codes', true );
			$codes		 = is_array( $codes ) ? $codes : [];
			$current_qty = count( $codes );

			if ( $current_qty > $ticket_qty ) {
				$codes = array_slice( $codes, 0, $ticket_qty );
			}
			elseif ( $current_qty < $ticket_qty ) {
				$characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ2346789';
				$max_chars		= strlen( $characters ) - 1;

				for ( $i = $current_qty + 1; $i <= $ticket_qty; $i++ ) {
					$code = '-';
					for ( $c = 0; $c < 6; $c++ ) $code .= $characters[ wp_rand( 0, $max_chars ) ];
					$codes[] = '#' . $order_id . '.' . sprintf('%02d', $i) . $code;
				}
			}

			$order->update_meta_data( '_turq_ticket_codes', $codes );
		}

		turq_log("3> DONE");
		$order->save();
		return;
	
}

add_action( 'woocommerce_thankyou', 'woocommerce_thankyou_turq', 999999);
function woocommerce_thankyou_turq( $order_id ) {

	$order				= wc_get_order($order_id);
	$shipping_methods	= $order->get_shipping_methods();
	$groups				= split_into_groups( $order->get_items() );	
	?>

	<section class="woocommerce-customer-details">
			<div class="woocommerce-column woocommerce-column--1 woocommerce-column--package-details col-1">
				<h2 class="woocommerce-column__title">Package summary</h2> <?php

				foreach ( $shipping_methods as $shipping_method ) {
					$group			= $shipping_method->get_meta( 'group' );
					$group_key		= $group['key'] ?? '';

					$item_summary	= $shipping_method->get_meta('Items');

					$pkgmeta 		= $shipping_method->get_meta('_turq_package_data');
					if ($pkgmeta)	$package_name = $pkgmeta['_turq_local_option'];
					else			$package_name = $shipping_method->get_name(); // legacy

					echo "<address>";
					echo "<b>$package_name</b><br>";
					if ($item_summary) echo $item_summary;
					else {
						// legacy if no meta
						$items_summary[$group_key]	??= [];
						foreach ($groups[$group_key]['items'] as $key => $item) {
							$items_summary[$group_key][] = sprintf(
								'%s × %d',
								$item->get_name(),
								$item->get_quantity()
							);
						}
						echo implode(", ", $items_summary[$group_key]);
					}
					echo "</address>";		
				}
			?>
			</div>
	</section>
	<?php

	if ($order->meta_exists('_turq_ticket_codes')) {
		$codes = $order->get_meta( '_turq_ticket_codes' );
		if ( !empty( $codes ) && is_array( $codes ) ) { ?>
			<section class="woocommerce-customer-details">
					<div class="woocommerce-column woocommerce-column--1 woocommerce-column--package-details col-1">
						<h2 class="woocommerce-column__title">Ticket codes</h2>
						<address>
							<?php foreach ( $codes as $code ) echo "<div style='font-family:monospace; letter-spacing: 0.087em'>".esc_html($code)."</div>";	 ?>
						</address>
					</div>
			</section>
	<?php
		}
		
		// temp gen ticket report !!!!
		//////////////////////////////
		$reports_dir = plugin_dir_path( __FILE__ ) . 'reports/';

		$filename = $reports_dir . 'ticket-report.csv';

		$fh = fopen( $filename, 'w' );

		$orders = wc_get_orders( [
			'limit'        => -1,
			'status'	   => 'processing',
			'date_created' => '>2026-06-30',	// add in for next event, then change for the next 
			'meta_query'   => [
				[
					'key'     => '_turq_ticket_codes',
					'compare' => 'EXISTS',
				]
			],

		] );

		$rows = [];
		$total_tickets = 0;

		foreach ( $orders as $order ) {

			$codes = $order->get_meta( '_turq_ticket_codes', true );

			if ( empty( $codes ) || ! is_array( $codes ) ) continue;

			$ticket_qty		= count( $codes );
			$total_tickets += $ticket_qty;

			foreach ( $codes as $code ) {

				$rows[] = [
					'checked_in' => '',
					'surname'    => $order->get_billing_last_name(),
					'forename'   => $order->get_billing_first_name(),
					'ticket'     => $code,
					'email'      => $order->get_billing_email(),
					'order'      => $order->get_order_number(),
					'status'	 => $order->get_status(),
					'date'       => $order->get_date_created()->date( 'd/m/Y' ),
					'qty'        => $ticket_qty,
				];

			}
		}


		usort( $rows, function( $a, $b ) {

			return strcasecmp(
				$a['surname'] . $a['forename'],
				$b['surname'] . $b['forename']
			);

		} );
		
		// header row
		fputcsv( $fh, [
			'Checked In',
			'Surname',
			'Forename',
			'Ticket Code',
			'Email',
			'Order',
			'Status',
			'Date',
			'Ticket Qty',
		] );

		$previous_group = '';

		foreach ( $rows as $row ) {

			$current_group = $row['surname'] . '|' . $row['forename'] . '|' . $row['order'];

			if ( $current_group !== $previous_group && $previous_group !== '' ) {
				fputcsv( $fh, [] ); // spacer row
			}

			if ( $current_group === $previous_group ) {
				// continuing data row
				fputcsv( $fh, [
					'',
					'',
					'',
					$row['ticket'],
					'',
					'',
					'',
					'',
					'',
				] );

			} else {
				// group start
				fputcsv( $fh, [
					$row['checked_in'],
					$row['surname'],
					$row['forename'],
					$row['ticket'],
					$row['email'],
					$row['order'],
					$row['status'],					
					$row['date'],
					$row['qty'],
				] );

				$previous_group = $current_group;
			}
		}			

		//summary row
		fputcsv( $fh, [
			'',
			'',
			'',
			'',
			'',
			'',
			'',
			'Total Tickets',
			$total_tickets
		] );		

		fclose( $fh );

	}	

}



// Checkout form fields
// --------------------
add_filter( 'woocommerce_checkout_fields', 'woocommerce_checkout_fields_turq', 10, 1); 
function woocommerce_checkout_fields_turq( $fields ) {
	$fields['billing']['billing_email']['priority'] = 1;
	$fields['billing']['billing_phone']['priority'] = 90;		
	$fields['billing']['billing_country']['priority'] = 100;	

	return $fields;
}


//
// Payment gateway only visible to admins
// -------------------------------------- 
// used for testing
add_filter( 'woocommerce_available_payment_gateways', 'woocommerce_available_payment_gateways_turq');
function woocommerce_available_payment_gateways_turq( $available_gateways ) {
	
	//return $available_gateways; //show all
	
	if (wc_current_user_has_role('administrator') || is_admin() ) 	return $available_gateways;

	if (isset($available_gateways['cheque'])) unset($available_gateways['cheque']);
	return $available_gateways;
}

add_filter( 'woocommerce_gateway_description', 'woocommerce_gateway_description_turq' , 25, 2 );
function woocommerce_gateway_description_turq( $description, $gateway_id ) {
	if( 'dojo' === $gateway_id ) {
		// you can use HTML tags here
		$description = "Powered by <a href='https://dojo.tech' target='_blank'>Dojo</a> : <img src='https://thesussexpeasant.co.uk/wp-content/uploads/2024/11/Visa_Brandmark_Blue_RGB_2021.png' width='50px'/><img src='https://thesussexpeasant.co.uk/wp-content/uploads/2024/11/ma_symbol_opt_73_1x.png' width='40px'/><img src='https://thesussexpeasant.co.uk/wp-content/uploads/2024/11/AXP_BlueBoxLogo_Alternate_SMALLscale_RGB_DIGITAL_80x80.png' width='30px'/>";
		//$description .=" <br>Payment Gateway is currently offline, you order will not be processed - please choose another payment method.";
	}
	return $description;
}

/*
add_filter('woocommerce_order_button_html', 'remove_order_button_html' );
function remove_order_button_html( $button ) {
	$style = 'style="display: block;text-align: center;cursor: not-allowed !important;"';
	$button_text = 'Payment Gateway is currently offline. Please try placing your order later.';
	$button = '<a class="button" '.$style.'>' . $button_text . '</a>';
	return $button;
}
*/


add_action( 'woocommerce_after_checkout_form', 'woocommerce_after_checkout_form_turq');
function woocommerce_after_checkout_form_turq() { 

	$limit   = 12;
	$columns = 6;
	$orderby = 'rand';
	$order   = 'desc';

	$cross_sells = isset( WC()->cart )
		? array_filter( array_map( 'wc_get_product', WC()->cart->get_cross_sells() ), 'wc_products_array_filter_visible' )
		: array();

	if ( $cross_sells ) {
		wc_set_loop_prop( 'name', 'cross-sells' );
		wc_set_loop_prop( 'columns', apply_filters( 'woocommerce_cross_sells_columns', $columns ) );

		// Order + limit
		$cross_sells = wc_products_array_orderby( $cross_sells, $orderby, $order );
		$limit       = intval( apply_filters( 'woocommerce_cross_sells_total', $limit ) );
		$cross_sells = $limit > 0 ? array_slice( $cross_sells, 0, $limit ) : $cross_sells;

		do_action( 'woocommerce_before_cart_collaterals' );

		echo "<div id='turq_cross_sell' class='cart_collaterals alignfull' style='background-color: #fafafa;padding: 20px 20px 40px 20px;  margin-bottom: -78px;  margin-top: 20px;'><div style='max-width: var( --global-content-width, 1920px );  margin: auto;'>";
		wc_get_template(
			'cart/cross-sells.php',
			array( 'cross_sells' => $cross_sells )
		);
		echo "</div></div>";
	}
}

?>