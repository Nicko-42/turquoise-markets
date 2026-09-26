<?php
/*******************************************************************************
 * Order customisation and order thank you
 * 
 * turq_woo_orders.php
 * 
*******************************************************************************/
//do_action( 'qm/debug', );
//error_log("product : ".print_r($product,true));


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


//
// Display options after shipping
// ------------------------------
// Wherever this line is - everywhere e.g. orders (thankyou, customer account) and emails
add_filter( 'woocommerce_get_order_item_totals', 'woocommerce_get_order_item_totals_turq', 100000, 3 );
function woocommerce_get_order_item_totals_turq($total_rows, $order, $tax_display){

	$new_total_row = [];

	foreach ($order->get_shipping_methods() as $shipping_method) {

		if(!empty($shipping_method)) {

			$pkgmeta			= $shipping_method->get_meta('_turq_package_data');

			$shipping_option	= $pkgmeta['_turq_local_option'] ?? $order->get_meta('_turq_local_option') ?? 'mone';
			$method_name		= $pkgmeta['_turq_local_option_method_name'] ?? ($order->get_meta('_turq_local_option_method_name') ?? '');

			if (!empty($method_name) && $method_name != 'cp' && $method_name != 'v') {
				$method_setup	= turq_Market_Setup()->get_method_setup($method_name);

				$new_total_row[] = [
					'label' => $method_setup['method_description']." :",
					'value' => $shipping_option,
				];
			}
		}
	}

	$pos = array_search( 'shipping', array_keys( $total_rows ), true ) + 1;
	return array_slice( $total_rows, 0, $pos, true ) + $new_total_row + array_slice( $total_rows, $pos, null, true );
}

//
// Control display of shipping address
// -----------------------------------
// Wherever this is checked - everywhere e.g. orders (thankyou, customer account) and emails
add_filter( 'woocommerce_order_needs_shipping_address', 'woocommerce_order_needs_shipping_address_turq', 10, 3);
function woocommerce_order_needs_shipping_address_turq($needs, $hide, $order) {
	if ( !$order instanceof WC_Order )					return $needs;
    if ( !$order->meta_exists( '_turq_ticket_codes' ) )	return $needs;

	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();
	$has_shipping		= 0;

	foreach ( $order->get_shipping_methods() as $shipping_method ) {
		$pkgmeta = $shipping_method->get_meta('_turq_package_data');
	
		if ($pkgmeta)	$method_name = $pkgmeta['_turq_local_option_method_name'];
		else			$method_name = $targeted_methods[$shipping_method->get_method_id().":".$shipping_method->get_instance_id()]; // legacy
		
		if ( $method_name === 's' || $method_name === 'cp') $has_shipping++;
	}

	if ( $has_shipping ) 								return $needs;

    $codes = $order->get_meta( '_turq_ticket_codes' );

    if ( empty( $codes ) || ! is_array( $codes ) )		return $needs;

    return false;

}



// ============================
//       Admin features
// ============================


////////////////////////////////////////////////////////////////////////////////
// All Orders table
////////////////////////////////////////////////////////////////////////////////

//
// add columns to Orders admin list table
// --------------------------------------
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'manage_woocommerce_page_wc_orders_columns_turq', 11 );
function manage_woocommerce_page_wc_orders_columns_turq($columns) {
	if(empty($columns)) return($columns);

    //insert new after...
    foreach ( $columns as $column_name => $column_info ) {
        if ( 'shipping_address' === $column_name ){ 
				$new_columns['item_details'] = 'Line/Item Count';
				$new_columns['order_type_name'] = 'Name / Type';    
				$new_columns['local_shipping_option'] = 'Collection / Delivery';
				$new_columns['shipping_details'] = 'Delivery Address';
                $new_columns['order_notes'] = 'Notes';    
        } else $new_columns[ $column_name ] = $column_info;
    }

    return $new_columns;
}

//
// Render column data
// ------------------
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'manage_woocommerce_page_wc_orders_custom_column_turq', 99999999, 2 );
function manage_woocommerce_page_wc_orders_custom_column_turq($column, $order) {

	$output = "";

    if ( $column == 'item_details' ) {
		$sm = [];
		foreach ($order->get_shipping_methods() as $shipping_method) {
			$pkgmeta = $shipping_method->get_meta('_turq_package_data');
			$sm[] = $pkgmeta['_turq_package_total_items'] ?? (count($pkgmeta['_turq_package_items'] ?? []) ?: $order->get_item_count());
		}
		$output = implode("<br>",$sm);
	}

	if ($column == 'shipping_details') {
		$sm = [];
		foreach ($order->get_shipping_methods() as $shipping_method) {
			$is_delivery = !( strpos( $shipping_method->get_method_id(), 'local_pickup' ) !== false || strpos( $shipping_method->get_method_id(), 'virtual_delivery' ) !== false);
			if ($is_delivery && !array_key_exists('delivery', $sm ?? [])) {
				$sm['delivery'] = wp_kses_post(str_replace(array( '<br/>', '<br>', '<br />' ), ', ', $order->get_formatted_shipping_address() ));
			} else
				$sm[] = "&nbsp;";
		}
		$output = implode("<br>",$sm);
	}

    if ( $column == 'order_type_name' ) {
		foreach ($order->get_shipping_methods() as $shipping_method) {
			$title = !empty($shipping_method) ? ($shipping_method->get_method_title() ?? '') : 'n/a';

			$pkgmeta	= $shipping_method->get_meta('_turq_package_data');
			$name	= $pkgmeta['_turq_local_option_name'] ?? $order->get_meta('_turq_local_option_name');;
			$colors = turq_Market_Setup()->get_group_lookup($name);
			$style	= $colors ? 'background:'.$colors['background'].'; color:'.$colors['color'].'; border-radius:100px; padding:0 10px 3px 10px; width:fit-content' : '';

			if ($name)													$output .= "<div style='$style; font-size:105%'><strong>".wp_kses_post($name)."</strong></div>";
			else														$output .= "<div style='font-size:95%'>$title</div>";		
		}

	}		
	
    if ( $column == 'local_shipping_option' ) {
		foreach ($order->get_shipping_methods() as $shipping_method) {		

			$pkgmeta		= $shipping_method->get_meta('_turq_package_data');
			$method_name	= $pkgmeta['_turq_local_option_method_name'] ?? $order->get_meta('_turq_local_option_method_name');
			switch ($method_name) {
				case 's' : { $icon_class = 'shipping'; break; }
				case 'p' : { $icon_class = 'pickup'; break; }
				case 'cp': { $icon_class = 'postage'; break; }
				case 'v' : { $icon_class = 'download'; break; }
				default: $icon_class = ( strpos( $shipping_method->get_method_id(), 'local_pickup' ) !== false ) ? 'pickup' : 'shipping';
			}

			if (!empty($shipping_method))	$poss_postage = ($method_name == 'cp') || (stripos( ($shipping_method->get_method_title() ?? ''), 'postage' ) != false);
			else							$poss_postage = false; 
			
			$legacy = $order->get_meta('_turq_local_option', true);
			if (is_array($legacy)) $legacy = current($legacy);
			
			if ($poss_postage)											$output .= "<div class='_turq_order_item'><span class='shipping-icon icon-".esc_attr($icon_class)."'></span></div>";
			else if ($note = $pkgmeta['_turq_local_option'] ?? $legacy) $output .= "<div class='_turq_order_item'><span class='shipping-icon icon-".esc_attr($icon_class)."'></span><strong>".wp_kses_post($note)."</strong></div>";
			else														$output .= "<div style='color:red; font-size:105%'><strong>ERROR - CHECK OPTIONS</strong></div>";		
		}
    }

    if ( $column == 'order_notes' ) {
        $output = '<div class="turq_order_admin">';		
		if ($note = $order->get_customer_note())        				$output = "<div style='color:green'><strong>Order Notes :</strong> ".implode(' ', array_slice(explode(' ', esc_html($note)), 0, 5))."...</div>";
		$output .="<div>";
	}

    echo $output ?? '';
	return;

}

//
// Remove "via..."
// ---------------
//  This would normally be under address in "Ship to" colunm of order list table if local pickup
//add_filter( 'woocommerce_order_shipping_method', 'woocommerce_order_shipping_method_turq',10,2);
function woocommerce_order_shipping_method_turq($names, $order){
	if (!$order->meta_exists( '_turq_local_option' )) return $names;
	elseif (is_admin()) {
		$shipping_method = current($order->get_shipping_methods());	
		if (empty($shipping_method) || $shipping_method->get_method_id()=='flat_rate' || $shipping_method->get_method_id()=='free_shipping' ) return($names); else return "";
	}
	else return $names;
}

//
// Remove address
// --------------
//  This would normally be in "Ship to" colunm of order list table if local pickup
//add_filter( 'woocommerce_order_get_formatted_shipping_address', 'woocommerce_order_get_formatted_shipping_address_turq',10,3);
function woocommerce_order_get_formatted_shipping_address_turq($address , $raw_address, $order ){
	if (!$order->meta_exists( '_turq_local_option' )) return $address;
	elseif (is_admin()) {
		$shipping_method = current($order->get_shipping_methods());	
		if (empty($shipping_method) || $shipping_method->get_method_id()=='flat_rate' || $shipping_method->get_method_id()=='free_shipping') return($address); else return "Local pickup";
	}
	else return $address;
}



// filter on admin all orders page
/*
add_action( 'woocommerce_order_list_table_restrict_manage_orders', function() {
	
	if (isset($_GET['local_shipping_option'])) $s=sanitize_text_field(wp_unslash($_GET['local_shipping_option'])); else $s='';
	echo '<select name="local_shipping_option">';
		echo '<option value="0">Local Ship/Deliver</option>';
		extract( carrier_settings() );
		$last_key="";
		foreach ($field_options as $key => $option_value)	{
			if ($last_key != $key) echo '<option disabled>---- '.($key=="p" ? "Pickup" : "Delivery").'</option>';	
			$last_key = $key;
			foreach ($option_value as $key =>$value) {if ($key==0) continue;echo '<option value="'.$key.'"'.($s==$key ? " selected" : "").'>'.$value.'</option>';}
		}
    echo '</select>';

});

add_filter('woocommerce_order_list_table_prepare_items_query_args', function($query) {
    
    $my_query = array();

    if (isset($_GET['local_shipping_option']) && ($opt=sanitize_text_field(wp_unslash($_GET['local_shipping_option'])))!="0")
            array_push($my_query, array('so' =>array('key'=>'_turq_local_option', 'compare'=>'LIKE', 'value'=>str_replace("-", " ",$opt))));


    if (!empty($my_query)) {
        $query['meta_query'] = array('relation' => 'AND', $my_query);
        //$query['orderby'] ='so';
		$query['order'] = 'DSC';
        
        //error_log(">>".print_r($query,true));
    }

    return $query;
}, 10, 2);
*/

////////////////////////////////////////////////////////////////////////////////
// edit/view single order
////////////////////////////////////////////////////////////////////////////////

add_action( 'wp_ajax_wcse_validate_postcode', 'validate_postcode_ajax' );
function validate_postcode_ajax() {
    // Security: check nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wcse_postcode_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
    }

    if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient capability' ), 403 );
    }

    // Accept postcode, country, state, city, order_id optionally
    $postcode = isset( $_POST['postcode'] ) ? wc_clean( wp_unslash( $_POST['postcode'] ) ) : '';
    $country  = isset( $_POST['country'] ) ? wc_clean( wp_unslash( $_POST['country'] ) ) : '';
    $state    = isset( $_POST['state'] ) ? wc_clean( wp_unslash( $_POST['state'] ) ) : '';
    $city     = isset( $_POST['city'] ) ? wc_clean( wp_unslash( $_POST['city'] ) ) : '';
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;

    // If order_id given and fields missing, try to use order data
    if ( $order_id && ( empty( $postcode ) || empty( $country ) ) ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            if ( empty( $postcode ) ) {
                $postcode = $order->get_shipping_postcode() ?: $order->get_billing_postcode();
            }
            if ( empty( $country ) ) {
                $country = $order->get_shipping_country() ?: $order->get_billing_country();
            }
            if ( empty( $state ) ) {
                $state = $order->get_shipping_state() ?: $order->get_billing_state();
            }
            if ( empty( $city ) ) {
                $city = $order->get_shipping_city() ?: $order->get_billing_city();
            }
        }
    }

    // Normalize postcode (Woo helper) — optional but good
    $postcode = wc_normalize_postcode( $postcode );

    // Provide defaults if still empty (optional)
    if ( empty( $country ) ) {
        $country = WC()->countries->get_base_country();
    }

    // Build package like checkout does (we keep contents empty for postcode-only lookup)
    $package = array(
        'destination' => array(
            'country'   => $country,
            'state'     => $state,
            'postcode'  => $postcode,
            'city'      => $city,
            'address'   => '',
            'address_2' => '',
        ),
        'contents'     => array(),
        'contents_cost'=> 0,
        'applied_coupons' => array(),
    );

    // Determine the zone
    try {
        $zone = WC_Shipping_Zones::get_zone_matching_package( $package );
    } catch ( Exception $e ) {
        wp_send_json_error( array( 'message' => 'Zone lookup failed: ' . $e->getMessage() ) );
    }

    if ( ! $zone ) {
        wp_send_json_success( array( 'methods' => array(), 'zone' => null ) );
    }

    // Get enabled methods for the zone
    $methods = $zone->get_shipping_methods( true );

    $out = array();

    foreach ( $methods as $method ) {
        $instance_id = method_exists( $method, 'get_instance_id' ) ?  : 0;
        $out[] = array(
            'id'           => isset( $method->id ) ? $method->id : '', // e.g. 'flat_rate'
            'instance_id'  => $method->get_instance_id(),              // e.g. 15
            'method_title' => $method->get_instance_option('title'),
			'cost'		   => $method->get_instance_option('cost', 0),
        );
    }

	$result = (array(
        'zone'    => array(
            'id'   => method_exists( $zone, 'get_id' ) ? $zone->get_id() : ( isset( $zone->zone_id ) ? $zone->zone_id : 0 ),
            'name' => method_exists( $zone, 'get_zone_name' ) ? $zone->get_zone_name() : ( isset( $zone->zone_name ) ? $zone->zone_name : '' ),
        ),
        'methods' => $out,
    ) );

	//error_log("ADMIN >".print_r($result, true));	
    wp_send_json_success(['options' => $result	]);


    $options = [];
    foreach ( $methods as $method ) {
        $value = $method->id . ':' . $method->get_instance_id();
        $label = $method->get_title();
        if ( empty( $label ) ) {
            $label = $method->get_method_title();
        }
        $options[ $value ] = $label;
    }

    wp_send_json_success( [
        'options' => $options,
    ] );	
	
}



add_action( 'wp_ajax_wcse_get_shipping_fields', 'get_shipping_fields' );
function get_shipping_fields() {
    // e.g. "flat_rate:15"
	
    // Security: check nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wcse_postcode_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
    }

    if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient capability' ), 403 );
    }

    $shipping_method = sanitize_text_field( $_POST['selected_method'] ?? '' );
	$preselect1		 = sanitize_text_field( $_POST['preselect1'] ?? '' );
	$preselect2		 = date(DATEFORMAT, absint( $_POST['preselect2'] ));	

    if ( ! $shipping_method ) {
        wp_send_json_error( [ 'message' => 'Missing method.' ] );
    }

	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();
	$method_name		= $targeted_methods[$shipping_method];
	
	//turq_debug_vars("get_shipping_fields AJAX-------------", compact('shipping_method', 'preselect1', 'preselect2', 'targeted_methods', 'method_name'));

	set_transient('turq_options_validation_'.get_current_user_id(), turq_Market_Setup()->get_option_validation($group_key, $method_name), 15*MINUTE_IN_SECONDS);
	$html = render_options($method_name, $PACKAGE, $preselect1, $preselect2);

    wp_send_json_success([
        'html' => $html,
        'method_name' => $targeted_methods[$shipping_method],
    ]);
}

add_action('wp_ajax_wcse_add_shipping_to_order', 'add_shipping_to_order');
function add_shipping_to_order() {
	//error_log("AJAX post>:".print_r($_POST,true));

    // Security: check nonce
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'wcse_postcode_nonce' ) ) {
        wp_send_json_error( array( 'message' => 'Invalid nonce' ), 403 );
    }

    if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => 'Insufficient capability' ), 403 );
    }

    $selected_method = sanitize_text_field($_POST['selected_method'] ?? '');
    $method_title	 = sanitize_text_field($_POST['method_title'] ?? '');	
    $cost			 = floatval($_POST['cost'] ?? 0);		
    $order_id		 = absint($_POST['order_id'] ?? 0);


    if (!$selected_method || !$order_id) {
        wp_send_json_error(['message' => 'Missing order ID or method.']);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['message' => 'Invalid order.']);
    }

    // ---------------------------------------------------------------------
    // Parse shipping method ("flat_rate:15" => id=flat_rate, instance=15)
    // ---------------------------------------------------------------------
    if (strpos($selected_method, ':') === false) {
        wp_send_json_error(['message' => 'Invalid shipping method format.']);
    }
	[$method_id, $instance_id] = explode(':', $selected_method);
	$instance_id = absint($instance_id);
	
    // ---------------------------------------------------------------------
    // Set shipping options
	// see turq_woo_checkout.php for reference - "set_order_turq_options"
    // ---------------------------------------------------------------------
	$targeted_methods = turq_Market_Setup()->get_targeted_methods();
	try {
		
		if (in_array($selected_method, array_keys($targeted_methods))) {
			$current_option			= $order->get_meta('_turq_local_option', true); 
			$current_label			= current($current_option) ?? '';			
			$current_value			= array_key_first( is_array( $current_option ) ? $current_option : [] ) ?? '';
			$current_method_name	= $order->get_meta('_turq_local_option_method_name', true);
			$method_setup			= turq_Market_Setup()->get_method_setup($current_method_name);
			$current_method_desc	= $method_setup['method_description'] ?? '';

			$method_name			= $targeted_methods[$selected_method];
			$method_setup			= turq_Market_Setup()->get_method_setup($method_name);
			$method_desc			= $method_setup['method_description'] ?? '';

			$newship				= set_order_turq_options('', $selected_method, $_POST, $order);
		}

	} catch ( Exception $e ) {
		wp_send_json_error(['message' => 'Unable to add shipping meta - ' . $e->getMessage() ]);
    }		


    // ---------------------------------------------------------------------
    // Remove any existing shipping lines first, typically only one
    // ---------------------------------------------------------------------
    foreach ($order->get_items('shipping') as $item_id => $item) {
		$current_method_title = $item->get_method_title();

		//error_log(">>".print_r($item->get_meta_data(),true));

		// Only preserve the original checkout 'Items' snapshot
		if ($item->meta_exists('original_Items'))	$use_key = 'original_Items';
		else if ($item->meta_exists('Items'))		$use_key = 'Items';
		else										$use_key = '';

		$original_items_meta =[];
		foreach ($item->get_meta_data() as $meta) {
			if ($meta->key === $use_key) $original_items_meta[] = ['key' => 'original_Items', 'value' => $meta->value];
			if ($meta->key === 'group')	 $original_items_meta[] = ['key' => 'group', 'value' => $meta->value];
		}

        $order->remove_item($item_id);
    }

    // ---------------------------------------------------------------------
    // Create a new shipping item
    // ---------------------------------------------------------------------
    $shipping_item = new WC_Order_Item_Shipping();
    $shipping_item->set_method_id($method_id);
    $shipping_item->set_instance_id($instance_id);
    $shipping_item->set_name($method_title);
	$shipping_item->set_total($cost);

	//Reapply preserved checkout meta only once
	foreach ($original_items_meta as $meta) $shipping_item->update_meta_data($meta['key'], $meta['value']);

	//Add/update new meta with current order line items
	$items = [];
	foreach ($order->get_items('line_item') as $item) $items[] = sprintf('%s × %d', $item->get_name(), $item->get_quantity());
	$shipping_item->add_meta_data('Items',implode(', ', $items));
    $order->add_item($shipping_item);
    $order->save();

    // ---------------------------------------------------------------------
    // Update order notes
    // ---------------------------------------------------------------------
	if (($current_method_title ?? '') != $method_title) {
		if(empty($current_method_title))	$order->add_order_note("Shipping added $method_title");
		else								$order->add_order_note("Shipping change from $current_method_title to $method_title");
		$recalc = 1;
	}
	else $recalc = 0;
	
	if ($newship){
		if ($current_option)	$order->add_order_note("Option changed from [$current_method_desc] $current_label to [$method_desc] $newship");
		else					$order->add_order_note("Option added [$method_desc] $newship");
		$order->save_meta_data();
	}	
	
	$order->save_meta_data();

    // ---------------------------------------------------------------------
    // Recalculate totals
    // ---------------------------------------------------------------------
    $order->calculate_totals();
    $order->save();
	
    wp_send_json_success(['message' => 'Shipping added.', 'recalc' => $recalc]);
}

add_filter('woocommerce_order_item_display_meta_key', 'woocommerce_order_item_display_meta_key_turq' , 10, 2);
function woocommerce_order_item_display_meta_key_turq($display_key, $meta) {
    if ($meta->key === 'original_Items') return 'Original Items';
    return $display_key;
}





add_action( 'woocommerce_admin_order_item_headers', 'add_admin_order_item_header' );
function add_admin_order_item_header() {
    echo "<th style='width:10%'>Type</th>";
}

add_action( 'woocommerce_admin_order_item_values', 'add_admin_order_item_values', 10, 3 );
function add_admin_order_item_values( $_product, $item, $item_id ) {
	if ( $item instanceof WC_Order_Item_Product ) {
		static $shipping_methods_cache = null;

		$shipping_methods = $shipping_methods_cache ?? wc_get_order($item->get_order_id())->get_shipping_methods();
		$shipping_methods_cache = $shipping_methods;
		
		$group_key = $item->get_meta('_group_key', true);

		if (empty($group_key)) {echo "<td></td>"; return;}
		
		foreach ($shipping_methods as $shipping_method) {		
			$group	= $shipping_method->get_meta('group', true);
			if (!$group || $group['key'] == $group_key ) break;
		}
		
		$colors = turq_Market_Setup()->get_group_lookup($group_key);
		$style	= $colors ? 'background:'.$colors['background'].'; color:'.$colors['color'].'; border-radius:100px; padding:0 10px 3px 10px; width:fit-content' : '';

		//echo "<div style='$style; font-size:105%'><strong>".esc_html($group['label'])."</strong></div>";
		echo "<td><div style='$style; font-size:105%'><strong>".esc_html($group['label'])."</strong></div></td>";
		
	}
	else if ( $item instanceof WC_Order_Item_Shipping ) {
		$pkgmeta	= $item->get_meta('_turq_package_data');
		if ($pkgmeta) {
			$colors = turq_Market_Setup()->get_group_lookup($pkgmeta['_turq_local_option_name']);
			$style	= $colors ? 'background:'.$colors['background'].'; color:'.$colors['color'].'; border-radius:100px; padding:0 10px 3px 10px; width:fit-content' : '';
			echo "<td><div style='$style; font-size:105%'><strong>".esc_html($pkgmeta['_turq_local_option_name'])."</strong></div></td>";
			}
	}
	else echo "<td></td>";


}
	
add_action ('woocommerce_before_order_itemmeta', function($item_id, $item){
	if ( $item instanceof WC_Order_Item_Shipping ) {
		$pkgmeta	= $item->get_meta('_turq_package_data');
		if ($pkgmeta) {
			$colors = turq_Market_Setup()->get_group_lookup($pkgmeta['_turq_local_option_name']);
			$style	= $colors ? 'background:'.$colors['background'].'; color:'.$colors['color'].'; padding:0 10px 3px 10px; width:fit-content' : '';

			//echo "<div style='$style; font-size:105%'><strong>".esc_html($pkgmeta['_turq_local_option_name'])." :</strong> ".esc_html($pkgmeta['_turq_local_option'])."</div>";
			echo "<strong>".esc_html($pkgmeta['_turq_local_option'])."</strong>";
			}
	}
	
}, 10,2);



//
// Display custom field
// --------------------
add_action( 'woocommerce_admin_order_data_after_order_details', 'woocommerce_admin_order_data_after_order_details_turq', 30, 1 );
function woocommerce_admin_order_data_after_order_details_turq($order) { ?>
	<style>.turq_market_details p.form-field {display:flex;width: 100% !important;} #turq_local_option_field span.optional{display:none}; </style>
	<br class='clear' />
	<div class='turq_market_details'> <?php

		$already_selected		= 'none';
		$action					= isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
		$shipping_method		= current( $order->get_shipping_methods() ) ?: null;
		$choosen_method_id		= '';
		$choosen_instance_id	= '';
		$value_selected			= '';
		$option_date			= '';

		//echo "shipping_method >".print_r($shipping_method,true);

		if (!empty($shipping_method)) {
			$choosen_method_id	 = $shipping_method->get_method_id();
			$choosen_instance_id = $shipping_method->get_instance_id();

			$option_date	 	 = absint($order->get_meta('_turq_local_option_date', true));
			$option_date_str	 = date(DATEFORMAT, $option_date);
			
			$shipping_option 	= (array) $order->get_meta('_turq_local_option', true);
			$label			 	= current($shipping_option) ?? '';
			$value_selected	 	= array_key_first( is_array( $shipping_option ) ? $shipping_option : [] ) ?? '';
			$method_name	 	= $order->get_meta('_turq_local_option_method_name', true);
			$method_setup	 	= turq_Market_Setup()->get_method_setup($method_name);
			$method_desc	 	= $method_setup['method_description'] ?? '';

			$already_selected = "$method_desc : $label";
			//echo "CHECK ALL - shipping_option >".print_r($shipping_option,true)."<br> Value[$value] Name[$method_name] Sel[$value_selected] OptDate[$option_date_str]";
		}

		?>
		<details style="margin-top:1.33em">
			<summary><span style="font-size:14px"><b>Shipping Details</b> <span style="color: lightgray;"><?= $already_selected ?></span></span><a style="float:right;display: flex;align-items: center;text-decoration: none;" href="<?= esc_url($order->get_checkout_order_received_url()) ?>" target="_blank">check order <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M19.5 4.5h-7V6h4.44l-5.97 5.97 1.06 1.06L18 7.06v4.44h1.5v-7Zm-13 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3H17v3a.5.5 0 0 1-.5.5h-10a.5.5 0 0 1-.5-.5v-10a.5.5 0 0 1 .5-.5h3V5.5h-3Z"></path></svg></a></summary>
			<div>
				<style>pre {margin:0}</style>
				<?= wpv(compact('choosen_method_id', 'choosen_instance_id')) ?>
				<?= wpv(['Order' => $order]) ?>
				<?= wpv(['quick - Order Meta' => $order->get_meta_data()]) ?>
				<?= wpv(['quick - Shipping Items' => $order->get_items( 'shipping' )]) ?>
			</div>
		</details>

		<?= ($already_selected !='none' ? '<div style="color:red">NOTE - changing the shipping method will not alter the payment already taken.</div>' : '') ?>
		
		<p>Event</p>
		<?php
		
		$valid_tag_id	= false;
		$tag_id			= $order->get_meta('_turq_local_option_tag_id', true);
		$active_groups	= turq_Market_Setup()->get_active_shipping_groups();
		foreach ($active_groups as $tagID => $grp) {
			$options[$tagID] = turq_Market_Setup()->get_group_lookup($tagID)['label'];
			if ($tag_id == $tagID) $valid_tag_id = true;
		}
		
		if (!$valid_tag_id) echo "NOTE - Order event does not match any currently active.";
		
		woocommerce_form_field( "turq_group", array(
			'type'     			=> 'select',
			'class'    			=> ['form-row-wide', $field],
			'required' 			=> true,
			'options'  			=> [''=>'Choose an event'] + $options,
			'placeholder'		=> 'Choose an event',
		), $tag_id );		
		
		?>
		<div style="display:flex;margin-top:9px"><img id="turq_se_spinner" style="padding-right:5px" src="<?= admin_url('images/loading.gif') ?>"/><div id="turq_se_status">Checking order details...</div></div>
		
		<p class="form-row form-row-wide">
			<span class="woocommerce-input-wrapper">
				<select id="turq_shipping_select" class="select" style="display:none"></select>
			</span>
		</p>		

		<div id="turq_shipping_container"></div>
		
		<button id="turq_add_shipping" type="button" class="button button-primary" style="display:none" disabled> <?= ($already_selected =='none' ? '+ Add Shipping To Order' : 'Update Shipping') ?></button>		
		

		<script>
			jQuery( function( $ ) {

				// Utility: attempt to find postcode and country inputs used on admin order screens
				function findFields() {
					// Common selectors used in Woo admin order UI — fallbacks included
					var selectors = {
						billing_postcode: $('#_billing_postcode, input[name="billing_postcode"], #order_billing_postcode'),
						shipping_postcode: $('#_shipping_postcode, input[name="shipping_postcode"], #order_shipping_postcode'),
						billing_country: $('#_billing_country, select[name="billing_country"], #order_billing_country'),
						shipping_country: $('#_shipping_country, select[name="shipping_country"], #order_shipping_country')
					};
					return selectors;
				}

				function writeDebug( txt, object ) {
					combined_txt = txt + "\r\n"+ (typeof object === 'object' ? JSON.stringify(object, null, 2) : String(object) );
					$('#turq_se_status').text(txt);
					console.log( 'TURQ postcode debug:', (typeof object!='undefined' ? combined_txt : txt));
				}

				function callPostcodeAjax( postcode, country, state, city, order_id ) {
					// Basic validation
					if ( ! postcode ) {
						writeDebug( 'No postcode provided' );
						return;
					}

					writeDebug( 'Looking up shipping methods for: ' + postcode.toUpperCase().replace(/\s+/g, '').replace(/(.{3})$/, ' $1'));

					$.ajax({
						url: '<?= admin_url( 'admin-ajax.php' ) ?>',
						method: 'POST',
						dataType: 'json',
						data: {
							action: 'wcse_validate_postcode',
							nonce: '<?= wp_create_nonce( 'wcse_postcode_nonce' ) ?>',
							postcode: postcode,
							country: country,
							state: state,
							city: city,
							order_id: order_id || 0
						}
					}).done( function( resp ) {
						$('#turq_se_spinner').hide();						
						if ( resp && resp.success ) {
							const select = $('#turq_shipping_select');
							select.empty(); // clear current items

							const options = resp.data.options;

							// Add placeholder
							select.append('<option id="select_placeholder" value="">Select Shipping Method</option>');

							// Build new options
							preselectedAdded = false;
							$.each(options.methods, function(i, method) {
								const isSelected = method.id == <?= json_encode($choosen_method_id) ?> && method.instance_id == <?= json_encode($choosen_instance_id) ?>;
								if (isSelected) preselectedAdded = true;								
								select.append(`
									<option value="${method.id}:${method.instance_id}" 
											data-cost="${method.cost}" 
											data-method_title="${method.method_title}" 
											${isSelected ? 'selected' : ''}>
										${method.method_title}
									</option>
								`);
							});

							writeDebug("Loaded shipping methods", options);
							//writeDebug( resp.data );
							
							if (preselectedAdded) select.trigger('change');
						
							select.show();
							$('#turq_add_shipping').show()
						
						} else {
							// if WP returns success=false, try to print the message
							writeDebug( resp && resp.data ? resp.data : 'No data returned' );
						}
					} ).fail( function( jqXHR, textStatus, errorThrown ) {
						$('#turq_se_spinner').hide();
						writeDebug( 'AJAX failed: ' + textStatus + ' ' + (errorThrown || '') );
						console.error( jqXHR );
					} );
				}





				// Wire up events
				function initWatchers() {
					var fields = findFields();

					// Choose which postcode & country to use: shipping if present (admin might set shipping), else billing
					function getCurrentInputs() {
						var postcode = '';
						var country = '';
						var state = '';
						var city = '';

						if ( fields.shipping_postcode && fields.shipping_postcode.length && fields.shipping_postcode.val() ) {
							postcode = fields.shipping_postcode.val();
							if ( fields.shipping_country && fields.shipping_country.length ) {
								country = fields.shipping_country.val();
							}
						} else if ( fields.billing_postcode && fields.billing_postcode.length && fields.billing_postcode.val() ) {
							postcode = fields.billing_postcode.val();
							if ( fields.billing_country && fields.billing_country.length ) {
								country = fields.billing_country.val();
							}
						} else {
							// fallback: try any inputs matching billing_postcode or shipping_postcode
							var any = $('input[name="billing_postcode"], input[name="shipping_postcode"], #_billing_postcode, #_shipping_postcode');
							if ( any.length ) {
								postcode = any.first().val();
							}
						}

						// state & city fallback attempts — optional
						var stateIn = $('input[name="shipping_state"], input[name="billing_state"], #_shipping_state, #_billing_state');
						if ( stateIn.length ) {
							state = stateIn.first().val();
						}
						var cityIn = $('input[name="shipping_city"], input[name="billing_city"], #_shipping_city, #_billing_city');
						if ( cityIn.length ) {
							city = cityIn.first().val();
						}

						// order id if present on the page
						var order_id = $('#post_ID').length ? $('#post_ID').val() : 0;

						return {
							postcode: postcode,
							country: country,
							state: state,
							city: city,
							order_id: order_id
						};
					}

					function triggerPostcodeLookupIfValid() {
						var current = getCurrentInputs();
						// Small trim
						var pc = $.trim( current.postcode || '' );
						var ct = $.trim( current.country || '' );
						var st = $.trim( current.state || '' );
						var ci = $.trim( current.city || '' );
						var oid = current.order_id || 0;

						if ( ! pc ) {
							writeDebug( 'Postcode empty — no lookup available. Please enter valid postcode for either billing or shipping address.' );
							$('#turq_se_spinner').hide();
							return;
						}

						// Fire the ajax lookup
						$('#turq_se_spinner').show();
						callPostcodeAjax( pc, ct, st, ci, oid );
					}

					// Attach change/blur handlers to postcode & country fields
					var watchSelectors = 'input[name="billing_postcode"], input[name="shipping_postcode"], #_billing_postcode, #_shipping_postcode, select[name="billing_country"], select[name="shipping_country"], #_billing_country, #_shipping_country';

					$( document ).on( 'change blur', watchSelectors, function( e ) {
						triggerPostcodeLookupIfValid();
					} );
					
					// auto-trigger on load
					setTimeout(function () {
						triggerPostcodeLookupIfValid();
					}, 100);					
				}

				// Init after a short delay to ensure inputs exist	
				setTimeout( initWatchers, 400 );
		
				//First select option -
				$('#turq_shipping_select').on('change', function() {
					var selected = $(this).val();
					if (!selected) return;

					$('#select_placeholder').prop('disabled', true);
					$('#turq_shipping_container').html('');
					$('#turq_add_shipping').prop('disabled', true);

					$('#turq_se_spinner').show();
					writeDebug( 'Loading shipping rates...' );

					$.ajax({
						url: '<?= admin_url( 'admin-ajax.php' ) ?>',
						type: 'POST',
						data: {
							action: 'wcse_get_shipping_fields',
							nonce: '<?= wp_create_nonce( 'wcse_postcode_nonce' ) ?>',
							selected_method: selected,
							preselect1: <?= json_encode($value_selected) ?>,
							preselect2: <?= json_encode($option_date) ?>
						}
					}).done( function( resp ) {
							$('#turq_se_spinner').hide();						
							if ( resp && resp.success ) {
								$('#turq_shipping_container').html(resp.data.html);
								controlSubmitButton();
								writeDebug( 'Shipping rates loaded' );
								
							} else {
								// if WP returns success=false, try to print the message
								writeDebug( resp && resp.data ? resp.data : 'No data returned' );
							}
						} ).fail( function( jqXHR, textStatus, errorThrown ) {
							$('#turq_se_spinner').hide();							
							writeDebug( 'AJAX failed: ' + textStatus + ' ' + (errorThrown || '') );
							console.error( jqXHR );
						} );
				});

				
				// Add shipping to order
				$('#turq_add_shipping').on('click', function(e) {
					e.preventDefault();

					const shipSelect = $('#turq_shipping_select');
					const shipSelectOption = shipSelect.find(':selected');
					let data = {
						action: 'wcse_add_shipping_to_order',
						nonce: '<?= wp_create_nonce( 'wcse_postcode_nonce' ) ?>',
						selected_method: shipSelect.val(),
						method_title: shipSelectOption.data('method_title') || '',
						cost: shipSelectOption.data('cost') || 0,
						order_id: $('#post_ID').length ? $('#post_ID').val() : 0,
					};

					$('#turq_shipping_container').find('input, select, textarea').each(function(){
						const id = $(this).attr('id');
						if (!id) return;
						data[id] = $(this).val();
					});
					$.ajax({
						url: '<?= admin_url( 'admin-ajax.php' ) ?>',
						method: 'POST',
						data: data,
					}).done( function( resp ) {
						$('#turq_se_spinner').hide();						
						if ( resp && resp.success ) {
							writeDebug("Shipping added successfully", resp.data);
							if (resp.data.recalc) $('button.calculate-action').trigger('click');
							//$('button.calculate-action').click();
						} else {
							writeDebug( resp && resp.data ? resp.data : 'Failed to add shipping' );
						}
					} ).fail( function( jqXHR, textStatus, errorThrown ) {
						$('#turq_se_spinner').hide();
						writeDebug( 'AJAX failed: ' + textStatus + ' ' + (errorThrown || '') );
						console.error( jqXHR );
					} );
				});
				

				
			} );
		</script>

	</div> <?php
	
}



//
// simple snippet from woo_checkout
// --------------------------------
add_action('admin_footer', 'admin_footer_turq');
function admin_footer_turq() {
	?>
	<style>
	#local_shipping_option {width:20ch}
	#item_details {width:4ch}
	
	.shipping-icon {
		display: inline-block;
		width: 24px;
		height: 24px;
		background-repeat: no-repeat;
		background-size: contain;
		vertical-align: middle;
	}

	/* Shipping Icon (Truck) */
	.icon-shipping {
		background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="gray" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polyline points="16 8 20 8 23 11 23 16 16 16"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>');
	}


	/* Pickup Icon (Map Pin) */
	.icon-pickup {
		background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="gray" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>');
	}

	/* Postage Icon (Letter) */
	.icon-postage {
		background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="gray" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="m2 7 10 7 10-7"/></svg>');
	}	
	
	/* Virtual Delivery Icon (Download) */
	.icon-download {
		background-image: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="gray" stroke-width="1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><polyline points="7 10 12 15 17 10"/><path d="M5 21h14"/></svg>');
	}	
	
	
	._turq_order_item {color:#283583; font-size:105%; display:flex;gap:5px; align-items: center;}
	
	</style>
	<?php 
	
	
	
    $screen = get_current_screen();
	//error_log("scrren>".print_r($screen, true));
	
    //if ( $screen && $screen->id === 'woocommerce_page_wc-orders' && isset($_GET['action']) && $_GET['action'] === 'edit' ) {
	if ( $screen && ($screen->id === 'woocommerce_page_wc-orders')) {		
?>
		<script>
			window.controlSubmitButton = function() {
				const selects = document.querySelectorAll('#turq_shipping_container select');
				let allValid = true;

				selects.forEach(select => {
					const value = (select.value || '').trim();
					const text = select.options[select.selectedIndex]?.text || '';
					const isInvalid = !value || /choose/i.test(text);

					select.classList.toggle('ti-invalid', isInvalid);
					if (isInvalid) allValid = false;
				});

				const btn = document.getElementById('turq_add_shipping');
				if (btn) btn.disabled = !allValid;
			};
		
		
		
			jQuery( function( $ ) {

				$('#turq_shipping_container').on('change', function(e) {

					controlSubmitButton();

					if (e.target.id === 'ti_market') {

						function getTiDates() {
							var $el = $('.ti_dates_data, #ti_dates_data').first(); //fallback for legacy if still using ID
							var val = ($el.length && $el.val()) || '{}';
							try {
								return JSON.parse(val);
							} catch (e) {
								console.error('Failed to parse ti_dates JSON', e, val);
								return {};
							}
						}

						var ti_dates = getTiDates();

						var $ti_datef = $('#ti_date_field');
						var $ti_date  = $('#ti_date');
						var marketVal = $(e.target).val();

						if (!$ti_date.length || !$ti_datef.length) return;

						if (marketVal === '') {
							$ti_date.html('<option value="" selected="selected">Choose a day</option>');
						} else {
							$ti_datef.removeClass('ti_select_hidden');

							const optionsArray = ti_dates[marketVal] || [];

							// Build options
							var html = $.map(optionsArray, function(opt) {
								return `<option value="${opt.v}">${opt.o}</option>`;
							}).join('');
							$ti_date.html(html);

							// Auto-select if only one valid entry
							const valid = optionsArray.filter(o => $.trim(o.v) !== "");
							if (valid.length === 1) {
								$ti_date.val(valid[0].v);
								controlSubmitButton();
							}
						}
					}
				});

			});
		</script>
		
		<style>
		.turq_order_split {font-size:90%; color:green}

		#order_shipping_line_items .wc-order-edit-line-item-actions {visibility: hidden;}
		.button.add-order-shipping {display: none;}
		
		</style>
<?php 
    }
}
 
 
 
add_action( 'woocommerce_admin_order_data_after_shipping_address', function($order) {

	if ( !$order instanceof WC_Order )					return;

	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();
	$has_shipping		= 0;

	foreach ( $order->get_shipping_methods() as $shipping_method ) {
		$pkgmeta = $shipping_method->get_meta('_turq_package_data');
	
		if ($pkgmeta)	$method_name = $pkgmeta['_turq_local_option_method_name'];
		else			$method_name = $targeted_methods[$shipping_method->get_method_id().":".$shipping_method->get_instance_id()]; // legacy
		
		if ( $method_name === 's' || $method_name === 'cp') $has_shipping++;
	}

	if ( $has_shipping ) 								return;

	?> 
	
	<style>
		.order_data_column:has([class*="_shipping"]) .address>p:not(.order_note), .order_data_column:has([class*="_shipping"]) .edit_address p[class*="_shipping"], .order_data_column:has([class*="_shipping"]) h3 span {display: none !important}
	</style> 
	 <?php
	 
 }, 99999, 1);
 
 

//
// Show Ticket Codes in sidebar
// ----------------------------

add_action( 'add_meta_boxes', 'turq_ticket_codes_metabox' );
function turq_ticket_codes_metabox() {

    $screen = wc_get_page_screen_id( 'shop-order' );

    add_meta_box(
        'turq_ticket_codes',
        'Ticket Codes',
        'turq_ticket_codes_metabox_callback',
        $screen,
        'side',
        'default'
    );
}

function turq_ticket_codes_metabox_callback( $post ) {

    $order = wc_get_order( $post->ID );

    $codes = $order->get_meta( '_turq_ticket_codes' );

    if ( empty( $codes ) ) {
        echo '<p>No ticket codes found.</p>';
        return;
    }

    foreach ( $codes as $code ) {
        echo '<div style="font-family:monospace;margin-bottom:6px;">'
            . esc_html( $code )
            . '</div>';
    }
}


?>