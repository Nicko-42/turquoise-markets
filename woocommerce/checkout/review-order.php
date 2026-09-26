<?php
/**
 * Multi-Shipping Grouped Checkout Table
 *
 * Fully fragment-safe checkout override.
 *
 * CUSTOM TURQUOISE  <<<<<<<<<<<
 * 
 */

defined( 'ABSPATH' ) || exit;

$cart = WC()->cart;
if ( ! $cart ) return;

// Get shipping groups & settings
$out_of_area 	= 0;

$groups			= turq_Market_Setup()->get_multi_shipping_groups();
$used_groups	= turq_Market_Setup()->get_active_shipping_groups();

$packages		= WC()->shipping()->get_packages();
$chosen_methods = WC()->session->get('chosen_shipping_methods', [] );
$new_chosen		= [];

$order_subtotal = 0;
$total_shipping = 0;

$ship_type = ['Postage' => 0, 'Delivery' => 0];

//error_log("packages >".print_r($packages,true));
//error_log("groups >".print_r($groups,true));

?>

<table id="turq_checkout" class="shop_table woocommerce-checkout-review-order-table">
	<tbody style="border:none">
	
	<?php 
	foreach ( $packages as $i => $package ) {
		$group_key = $package['group_key'];
		if ( $package['count']==0 ) continue;
		
		$package_key = $package['label'];
	
		$package_subtotal[$package_key] = 0;
		$package_colour[$package_key] = esc_attr($groups[$group_key]['background']);

		$movable_count = 0;
	?>

		<tr class="multi-shipping-group">
			<td colspan="2">
				<div id="package-<?= esc_html($group_key) ?>" class="multi-shipping-group-title toggle-header">
					<span class="toggle-title"><span class="group_dot" style="background-color: <?=  $package_colour[$package_key] ?>"></span><?= esc_html( $package_key.' items' ) ?></span>
					<span class="toggle-arrow" aria-expanded="true" >▲</span>
				</div>
				<div class="toggle-content">
					<table class="group-table cart shop_table shop_table_responsive cart" data-group="<?= esc_attr($group_key) ?>">
						<thead>
							<tr>
								<th class="product-select"></th>						
								<th class="product-remove"></th>
								<th class="product-thumbnail">Product</th>
								<th class="product-name"></th>
								<th class="product-price">Price</th>
								<th class="product-quantity">Qty</th>
								<th class="product-subtotal"><span>Total</span></th>
								<?php $colcount = 7; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $package['contents'] as $cart_item_key => $cart_item ) :
								$product = wc_get_product($cart_item['product_id']);
								$check	 = check_product_options($product);
								
								$product = $cart_item['data'];
								if ( !$product || !$product->exists() ) continue;

								$all_possible_groups = [];
								foreach($check['valid'] as $tag_id => $gk) {
									$allowed_groups		  = $groups[$gk]['allowed_groups'];
									$all_possible_groups += array_intersect_key($used_groups, array_flip($allowed_groups));
								}
								
								$valid_targets = [];
								foreach($all_possible_groups as $tag_id => $gk) {
									if ($gk == $group_key) continue;
									$valid_targets[$gk] = $groups[$gk]['label'];
								}

								?>
								<tr class="cart_item" data-cart_item_key="<?= esc_attr($cart_item_key) ?>"  data-group="<?= esc_attr($group_key) ?>" >
									<td class="product-select" >
										<?php if (!empty($valid_targets)) : 
											$movable_count++; ?>
											<input type="checkbox" class="bulk-select-item" data-cart_item_key="<?= esc_attr($cart_item_key) ?>" data-move-targets="<?= esc_attr(wp_json_encode($valid_targets)) ?>" >
										<?php endif; ?>
									</td>
									<td class="product-remove" >
										<a href="<?= esc_url( wc_get_cart_remove_url( $cart_item_key ) ) ?>" class="remove" aria-label="Remove this item" data-product_id="<?= $product->get_id() ?>" data-cart_item_key="<?= esc_attr( $cart_item_key ) ?>">×</a>
									</td>
									<td class="product-thumbnail">
										<?php
										$product_permalink = $product->get_permalink($cart_item);										
										$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $product->get_image(), $cart_item, $cart_item_key );
										if ( ! $product_permalink ) echo $thumbnail;
										else						printf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $thumbnail );
										?>
									</td>								
									<td class="product-name" data-title="Product">
										<?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key ) ) . '&nbsp;'; ?>
										<?php if ($valid_targets)  {
											echo "</br>Can move to: ";
											foreach($valid_targets as $gk=>$label){
												echo "<span class='group_pill' style='background-color:".esc_attr($groups[$gk]['background'])."; color: ".esc_attr($groups[$gk]['color'])."'>".esc_html($label)."</span>";
											}
										} ?>
										<?php echo wc_get_formatted_cart_item_data( $cart_item );?>
									</td>
									<td class="product-price" data-title="Price">
										<?php echo wc_price($product->get_price());?>
									</td>
									<td class="product-quantity" data-title="Qty">
										<div class="qty-btn-wrap">
										<button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">−</button>
										<input type="text" class="input-text qty text" inputmode="numeric" pattern="[0-9]*" name="cart[<?= esc_attr( $cart_item_key ) ?>][qty]" value="<?= esc_attr($cart_item['quantity']) ?>" title="Qty" data-last_valid_qty="<?= esc_attr($cart_item['quantity']) ?>" >
										<button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">+</button>									
										</div>
									</td>
									<td class="product-subtotal" data-title="Item Subtotal" data-unitprice="<?php echo esc_attr( $product->get_price() ); ?>">
										<?php
										$package_subtotal[$package_key] += $cart_item['line_subtotal']; // CAUTION: line_subtotal = true cost, line_total = has coupon applied
										echo WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] );
										?>
									</td>						
								</tr>
							<?php endforeach;?>
								<?php /* <tr class="product-add-item"><td colspan="3"></td><td><button type="button" class="turq-add-item" data-group-label="<?= esc_html($package_key) ?>">+ Add Item</button></td><td colspan="3"></td></tr> */ ?>
						</tbody>
						<tfoot>
						
							<tr class="cart-subtotal">
								<th colspan="<?= $colcount-1 ?>"><span>Subtotal</span></th>
								<td data-title="Subtotal"><?= wc_price( $package_subtotal[$package_key] /*$package['contents_cost']*/ ) ?></td>
							</tr>
							<?php if ($movable_count) : ?>
								<tr class="group-bulk-actions" data-group="<?= esc_attr($group_key) ?>">
									<td colspan="<?= $colcount ?>">
										<label>
											<input type="checkbox" class="select-all-in-group" data-group="<?= esc_attr($group_key) ?>">
											Select all in group
										</label>
										<span style="margin-left:1rem;">
											<label for="bulk-move-target-<?= esc_attr($group_key) ?>">Move to:</label>
											<select class="bulk-move-target" id="bulk-move-target-<?= esc_attr($group_key) ?>" placeholder="Select an item">
											<option value="" disabled selected>Select an item first</option>
											</select>
											<button class="bulk-move-button" data-group="<?= esc_attr($group_key) ?>" type="button">Move...</button>
										</span>
										<span class="bulk-move-status" style="margin-left:1rem;"></span>
									</td>
								</tr>
							<?php endif; 
							
							// Render shipping options for this group
							// --------------------------------------

							$zone = WC_Shipping_Zones::get_zone_matching_package( $package );
							if ( $zone && $zone->get_id() != 1 && $package['virtual_delivery'] <0 ) { // Package shipping is out of local delivery area (id=1) and is not virtual
								$out_of_area++;
							}

							$chosen_method = $chosen_methods[ $i ] ?? '';
							$package = apply_filters('turq_checkout_package_context', $package, $chosen_method);
							$chosen_method = $package['chosen_method'] ?? $chosen_method;
							if ($package['method_name']=='cp')	$ship_type['Postage']++;
							if ($package['method_name']=='s')	$ship_type['Delivery']++;

							//error_log("RENDERING------- $group_key chosen[$chosen_method] : available = ".print_r($package, true));
							wc_get_template(
								'cart/cart-shipping.php',
								array(
									'package'					=> $package,
									'available_methods'			=> $package['rates'],
									'show_package_details'		=> false,
									'show_shipping_calculator'	=> false,
									'package_details'			=> '',
									'package_name'				=> $package_key ?? 'Shipping',
									'index'						=> $i,
									'chosen_method'				=> $chosen_method,
									'formatted_destination'		=> WC()->countries->get_formatted_address( $package['destination'], ', ' ),
									'has_calculated_shipping'	=> WC()->customer->has_calculated_shipping(),
									'col_1'						=> 4,
									'col_2'						=> $colcount - 4,
								)
							);
							$new_chosen[$i] = $chosen_method;						
							
							?>
						</tfoot>			
					</table>
				</div>
				<div class="toggle-subtotal">Subtotal: <?= wc_price( $package_subtotal[$package_key]) ?></div>

			</td>
		</tr>

		<?php
	
		if ($package['rates']) $total_shipping += $package['rates'][$chosen_method]->get_cost();
		//if ($package['rates']) $total_shipping += $package['rates'][$package['chosen_method']]->get_cost();
	
		$order_subtotal += $package_subtotal[$package_key];

		echo "<tr style='background-color:transparent'><td colspan=2>&nbsp;</td></tr>";
	};

	//echo "<tr><td>".print_r($new_chosen,true)."</td></tr>";
	
	// build new chosen to reflect actual as set/rendered
	WC()->session->set('chosen_shipping_methods', $new_chosen );
		
	if (count($package_subtotal)>1) { 
		echo "<tr style='background-color:#f2ddb0'><th style='border-top:none;text-align:center;padding:5px' colspan=2><h3>Order Summary</h3></th></tr>";
		
		foreach($package_subtotal as $label => $total) { ?>
			<tr class="multi-shipping-sr">
				<th style="font-weight:normal"><span class="group_dot" style="background-color: <?= $package_colour[$label] ?>"></span><?= $label ?></th>
				<td><?= wc_price( $total ) ?></td>
			</tr> <?php 
		} ?>
		
			<tr class="multi-shipping-subtotal multi-shipping-sr total-row">
				<th><span>Subtotal</span></th>
				<td><span>Subtotal:</span><?= wc_price( $order_subtotal ) ?></td>
			</tr> <?php 
		
		if ($total_shipping) { 
			// build shipping message
			arsort($ship_type);
			$ship_type_string = '';
			if (current($ship_type)) $ship_type_string = key($ship_type);
			next ($ship_type);
			if (current($ship_type)) $ship_type_string .= "/".key($ship_type); ?>
		
			<tr class="multi-shipping-total-delivery multi-shipping-sr">
				<th>Order <?= $ship_type_string ?> Total</th>
				<td><?= wc_price( $total_shipping ) ?></td>
			</tr> <?php
		};
	};

	if ($out_of_area) { ?> 
		<tr class="multi-shipping-sr">
			<th>PLEASE NOTE - Delivery is only available to local postcodes, <a href="/privacy-policy/#tandcs-shipping">see more details here</a></th>
			<td style="color:red">No local delivery</td>
		</tr>
	<?php }; 

	foreach ( WC()->cart->get_coupons() as $code => $coupon ) { ?>
		<tr class="cart-discount coupon-<?= esc_attr( sanitize_title( $code ) ) ?> multi-shipping-sr">
			<th><?= wc_cart_totals_coupon_label( $coupon ) ?></th>
			<td><?= wc_cart_totals_coupon_html( $coupon )  ?></td>
		</tr>
	<?php }; ?>					

	<tr class="multi-shipping-grand-total multi-shipping-sr total-row">
		<th><span>Order Total</span></th>
		<td><span>Order Total:</span><?= wc_cart_totals_order_total_html() ?></td>
	</tr>

	</tbody>
</table>