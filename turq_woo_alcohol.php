<?php
/*******************************************************************************
 * Alcohol sales - age declaration
 *
 * turq_woo_alcohol.php
 *
 * Alcohol products are those in the product category with slug 'alcohol'
 * (or any of its sub-categories). The category name may change, the slug
 * must not, and the category must not be deleted - both are guarded below.
 *
 *  - [turq_alcohol_notice] shortcode for the Kadence Shopkit product template
 *  - Checkout notice + required declaration checkbox (server validated)
 *  - Declaration (and time given) saved with the order
 *  - ID CHECK flag on any order containing alcohol, incl. admin created/edited
 *  - ID CHECK shown in admin order list, order edit page, admin New order email
 *  - Reminder in customer Processing / On-hold emails (HTML + plain text)
 *
 * Texts are configurable : WooCommerce > Settings > TSP Settings > Alcohol
 * (includes/settings/class-turq-section-alcohol.php)
 *
 * Order meta
 *  _turq_id_check					'yes' if order contains alcohol (kept in sync on every order save)
 *  _turq_age_declaration			'yes' if customer ticked the declaration at checkout
 *  _turq_age_declaration_time		UTC timestamp the declaration was given
 *  _turq_age_declaration_text		the declaration wording the customer agreed to
 *
*******************************************************************************/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define('TURQ_ALCOHOL_CAT_SLUG',	'alcohol');
define('TURQ_ALCOHOL_FIELD',	'turq_age_declaration');
define('TURQ_ALCOHOL_OPTION',	'turq_alcohol_settings');


////////////////////////////////////////////////////////////////////////////////
// Settings / texts
////////////////////////////////////////////////////////////////////////////////{

//
// Default texts - also used if a setting is saved blank
// -----------------------------------------------------
function turq_alcohol_default_texts() {
	return [
		'product_notice'	=> 'Over 18s only. ID will be requested on collection or delivery (Challenge 25).',
		'checkout_notice'	=> 'It is against the law to sell alcohol to anyone under the age of 18. It is also an offence for anyone to buy, or attempt to buy, alcohol on behalf of someone under 18.',
		'checkbox_label'	=> 'I confirm I am 18 or over and understand ID will be requested on collection or delivery.',
		'checkout_error'	=> 'Your basket contains alcohol. Please confirm you are 18 or over to place your order.',
		'email_reminder'	=> 'Your order contains alcohol. Please have photo ID ready on collection or delivery.',
	];
}

function turq_alcohol_text($key) {
	static $texts = null;
	if ($texts === null) {
		$saved = get_option(TURQ_ALCOHOL_OPTION, []);
		$texts = turq_alcohol_default_texts();
		foreach ($texts as $k => $default) {
			if (!empty($saved[$k])) $texts[$k] = $saved[$k];
		}
	}
	return $texts[$key] ?? '';
}

//}


////////////////////////////////////////////////////////////////////////////////
// Identifying alcohol products
////////////////////////////////////////////////////////////////////////////////{

//
// Term IDs for the alcohol category and all its sub-categories
// ------------------------------------------------------------
function turq_alcohol_term_ids() {
	static $ids = null;
	if ($ids !== null) return $ids;

	$term = get_term_by('slug', TURQ_ALCOHOL_CAT_SLUG, 'product_cat');
	if (!$term) {
		turq_log("ALCOHOL : product category '".TURQ_ALCOHOL_CAT_SLUG."' not found");
		return $ids = [];
	}

	$children = get_term_children($term->term_id, 'product_cat');
	return $ids = array_merge([(int)$term->term_id], is_wp_error($children) ? [] : array_map('intval', $children));
}

//
// Is product (or a variation's parent) in the alcohol category?
// -------------------------------------------------------------
function turq_alcohol_is_alcohol_product($product_id) {
	static $cache = [];

	$product_id = absint($product_id);
	if (!$product_id) return false;
	if (isset($cache[$product_id])) return $cache[$product_id];

	$term_ids = turq_alcohol_term_ids();
	if (empty($term_ids)) return $cache[$product_id] = false;

	// categories are held on the parent product, not the variation
	if (get_post_type($product_id) === 'product_variation') {
		$parent_id = wp_get_post_parent_id($product_id);
		if ($parent_id) $product_id = $parent_id;
	}

	return $cache[$product_id] = has_term($term_ids, 'product_cat', $product_id);
}

function turq_alcohol_cart_has_alcohol() {
	if (!WC()->cart) return false;

	foreach (WC()->cart->get_cart() as $cart_item) {
		if (turq_alcohol_is_alcohol_product($cart_item['product_id'])) return true;
	}
	return false;
}

function turq_alcohol_order_has_alcohol($order) {
	if (!$order instanceof WC_Order) return false;

	foreach ($order->get_items() as $item) {
		if (turq_alcohol_is_alcohol_product($item->get_product_id())) return true;
	}
	return false;
}

//
// Does order need an ID check?
// ----------------------------
// Checked live against the items so it is always correct, even for
// orders placed before this feature existed. Use this in the picking list.
function turq_alcohol_order_requires_id_check($order) {
	return turq_alcohol_order_has_alcohol($order);
}

//
// Declaration details for display
// -------------------------------
// Returns null if no declaration (e.g. admin created order)
function turq_alcohol_order_declaration($order) {
	if (!$order instanceof WC_Order || $order->get_meta('_turq_age_declaration') !== 'yes') return null;

	$time = absint($order->get_meta('_turq_age_declaration_time'));
	return [
		'time'		=> $time,
		'time_str'	=> $time ? wp_date('D d-m-y H:i', $time) : 'unknown',
		'text'		=> $order->get_meta('_turq_age_declaration_text'),
	];
}

//
// Protect the alcohol category
// ----------------------------
// Category must not be deleted and the slug must not change.
add_action('pre_delete_term', function($term_id, $taxonomy) {
	if ($taxonomy !== 'product_cat') return;

	$term = get_term($term_id, 'product_cat');
	if ($term && !is_wp_error($term) && $term->slug === TURQ_ALCOHOL_CAT_SLUG)
		wp_die('The "'.esc_html($term->name).'" category is used to identify alcohol products for age verification and cannot be deleted.', 'Category protected', ['back_link' => true]);
}, 10, 2);

add_filter('wp_update_term_data', function($data, $term_id, $taxonomy, $args) {
	if ($taxonomy !== 'product_cat') return $data;

	$term = get_term($term_id, 'product_cat');
	if ($term && !is_wp_error($term) && $term->slug === TURQ_ALCOHOL_CAT_SLUG) $data['slug'] = TURQ_ALCOHOL_CAT_SLUG;

	return $data;
}, 10, 4);

//}


////////////////////////////////////////////////////////////////////////////////
// Shop - single product notice
////////////////////////////////////////////////////////////////////////////////{

//
// [turq_alcohol_notice]
// ---------------------
// For the Kadence Shopkit single product template.
// get_queried_object_id() is used rather than the global $product - a Shopkit
// template can render outside the normal product loop, and the queried object
// is always the product page being viewed.
add_shortcode('turq_alcohol_notice', function() {
	$product_id = get_queried_object_id();

	if (!$product_id || get_post_type($product_id) !== 'product')	return '';
	if (!turq_alcohol_is_alcohol_product($product_id))				return '';

	return "<div class='turq-alcohol-notice' role='note'>".wp_kses_post(turq_alcohol_text('product_notice'))."</div>";
});

//}


////////////////////////////////////////////////////////////////////////////////
// Checkout
////////////////////////////////////////////////////////////////////////////////{

//
// Notice + declaration checkbox
// -----------------------------
// Rendered just above Place order. This area is refreshed by Woo on every
// update_checkout (e.g. switching collection/delivery, qty change, item moved)
// so it appears / disappears as alcohol is added to / removed from the basket.
// The tick is kept across refreshes by reading it back from the posted form
// data, it is never stored, so each order needs a fresh declaration.
add_action('woocommerce_review_order_before_submit', 'turq_alcohol_checkout_declaration', 5);
function turq_alcohol_checkout_declaration() {
	if (!turq_alcohol_cart_has_alcohol()) return;

	$checked = false;
	if (isset($_POST['post_data'])) {
		// AJAX checkout refresh - whole form is posted serialised
		parse_str(wp_unslash($_POST['post_data']), $post_data);
		$checked = !empty($post_data[TURQ_ALCOHOL_FIELD]);
	}
	elseif (isset($_POST[TURQ_ALCOHOL_FIELD])) {
		$checked = true;
	}

	?>
	<div class="turq-alcohol-declaration">
		<div class="turq-alcohol-declaration__icon" aria-hidden="true">
			<svg viewBox="0 0 48 48" focusable="false"><circle cx="24" cy="24" r="24" fill="#d63638"/><rect x="21" y="9" width="6" height="20" rx="3" fill="#fff"/><circle cx="24" cy="36.5" r="3.5" fill="#fff"/></svg>
		</div>
		<div class="turq-alcohol-declaration__body">
			<p class="turq-alcohol-declaration__notice"><?= wp_kses_post(turq_alcohol_text('checkout_notice')) ?></p>
			<p class="form-row validate-required" id="<?= TURQ_ALCOHOL_FIELD ?>_field">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
					<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="<?= TURQ_ALCOHOL_FIELD ?>" id="<?= TURQ_ALCOHOL_FIELD ?>" value="1" <?php checked($checked) ?> />
					<span><?= wp_kses_post(turq_alcohol_text('checkbox_label')) ?>&nbsp;<abbr class="required" title="required">*</abbr></span>
				</label>
				<span class="turq-alcohol-declaration__error" role="alert"><?= wp_kses_post(turq_alcohol_text('checkout_error')) ?></span>
			</p>
		</div>
	</div>
	<?php
}

//
// Server side validation
// ----------------------
// Error id is picked up by the checkout_error JS below to show the inline error.
add_action('woocommerce_after_checkout_validation', 'turq_alcohol_checkout_validation', 10, 2);
function turq_alcohol_checkout_validation($data, $errors) {
	if (!turq_alcohol_cart_has_alcohol()) return;

	if (empty($_POST[TURQ_ALCOHOL_FIELD]))
		$errors->add(
			'turq_age_declaration',
			"<a href='#".TURQ_ALCOHOL_FIELD."_field'>".wp_kses_post(turq_alcohol_text('checkout_error'))."</a>",
			['id' => TURQ_ALCOHOL_FIELD]
		);
}

//
// Save declaration with order
// ---------------------------
// Validation has already passed by this point.
add_action('woocommerce_checkout_create_order', 'turq_alcohol_checkout_create_order', 10, 2);
function turq_alcohol_checkout_create_order($order, $data) {
	if (!turq_alcohol_cart_has_alcohol() || empty($_POST[TURQ_ALCOHOL_FIELD])) return;

	$order->update_meta_data('_turq_age_declaration',		'yes');
	$order->update_meta_data('_turq_age_declaration_time',	time());
	$order->update_meta_data('_turq_age_declaration_text',	wp_strip_all_tags(turq_alcohol_text('checkbox_label')));
}

//}


////////////////////////////////////////////////////////////////////////////////
// Order record - ID CHECK flag
////////////////////////////////////////////////////////////////////////////////{

//
// Keep ID CHECK flag in sync on every order save
// ----------------------------------------------
// Covers checkout, admin created orders and items added/removed in admin.
// Stored so it can be searched / filtered; display uses the live check.
add_action('woocommerce_before_order_object_save', function($order) {
	if (!$order instanceof WC_Order) return;

	if (turq_alcohol_order_has_alcohol($order))	$order->update_meta_data('_turq_id_check', 'yes');
	else										$order->delete_meta_data('_turq_id_check');
}, 10, 1);

//}


////////////////////////////////////////////////////////////////////////////////
// Staff visibility - admin
////////////////////////////////////////////////////////////////////////////////{

function turq_alcohol_id_check_badge() {
	return "<span class='turq-id-check' title='Order contains alcohol - check photo ID on collection or delivery'>ID CHECK</span>";
}

//
// Orders list - badge in Notes column (HPOS)
// ------------------------------------------
// Echoed before the notes output in turq_woo_orders.php (priority 99999999)
add_action('manage_woocommerce_page_wc-orders_custom_column', function($column, $order) {
	if ($column !== 'order_notes') return;
	if (turq_alcohol_order_requires_id_check($order)) echo "<div class='turq-id-check-row'>".turq_alcohol_id_check_badge()."</div>";
}, 10, 2);

//
// Order edit page - side meta box
// -------------------------------
add_action('add_meta_boxes', function() {
	$screen = wc_get_page_screen_id('shop-order');
	add_meta_box('turq_age_declaration', 'Age Verification', 'turq_alcohol_metabox_callback', $screen, 'side', 'high');
});

function turq_alcohol_metabox_callback($post_or_order) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
	if (!$order) return;

	if (!turq_alcohol_order_requires_id_check($order)) {
		echo "<p>No alcohol in this order.</p>";
		return;
	}

	echo "<p>".turq_alcohol_id_check_badge()."</p>";
	echo "<p>Order contains alcohol. Check photo ID on collection or delivery.</p>";

	$declaration = turq_alcohol_order_declaration($order);
	if ($declaration) {
		echo "<p><strong>Customer declaration given</strong><br>".esc_html($declaration['time_str'])."</p>";
		if ($declaration['text']) echo "<p><em>&ldquo;".esc_html($declaration['text'])."&rdquo;</em></p>";
	} else {
		echo "<p><strong>No customer declaration</strong><br>e.g. order created or items added in admin.</p>";
	}
}

//
// Admin styles
// ------------
add_action('admin_head', function() {
	$screen = get_current_screen();
	if (!$screen || $screen->id !== wc_get_page_screen_id('shop-order')) return; ?>
	<style>
		.turq-id-check {display:inline-block; background:#d63638; color:#fff; font-weight:700; font-size:12px; line-height:1.6; padding:0 8px; border-radius:3px; white-space:nowrap; letter-spacing:.03em}
		.turq-id-check-row {margin:0 0 4px}
	</style> <?php
});

//}


////////////////////////////////////////////////////////////////////////////////
// Emails
////////////////////////////////////////////////////////////////////////////////{

add_action('woocommerce_email_before_order_table', 'turq_alcohol_email_before_order_table', 5, 4);
function turq_alcohol_email_before_order_table($order, $sent_to_admin, $plain_text, $email) {
	if (!$order instanceof WC_Order || !isset($email->id))	return;
	if (!turq_alcohol_order_requires_id_check($order))		return;

	//
	// Admin "New order" - ID CHECK marker
	// -----------------------------------
	if ($email->id === 'new_order') {
		$declaration = turq_alcohol_order_declaration($order);
		$detail		 = $declaration ? "Customer age declaration given ".$declaration['time_str']."." : "No customer age declaration on this order.";

		if ($plain_text) {
			echo "\n*** ID CHECK ***\n";
			echo "Order contains alcohol - check photo ID on collection or delivery.\n";
			echo $detail."\n\n";
		} else {
			echo "<div style='border:3px solid #d63638; padding:12px; margin:0 0 20px; text-align:center'>";
			echo "<div style='color:#d63638; font-size:22px; font-weight:bold; letter-spacing:1px'>ID CHECK</div>";
			echo "<div>Order contains alcohol - check photo ID on collection or delivery.</div>";
			echo "<div style='font-size:90%'>".esc_html($detail)."</div>";
			echo "</div>";
		}
		return;
	}

	//
	// Customer Processing / On-hold - ID reminder
	// -------------------------------------------
	if (in_array($email->id, ['customer_processing_order', 'customer_on_hold_order'], true)) {
		$reminder = turq_alcohol_text('email_reminder');

		if ($plain_text) {
			echo "\n".wp_strip_all_tags($reminder)."\n\n";
		} else {
			echo "<div style='border:2px solid #333; padding:12px; margin:0 0 20px'><strong>".wp_kses_post($reminder)."</strong></div>";
		}
	}
}

//}


////////////////////////////////////////////////////////////////////////////////
// Front end styles
////////////////////////////////////////////////////////////////////////////////{

add_action('wp_head', function() {
	if (!is_product() && !is_checkout()) return; ?>
	<style id="turq-alcohol">
		.turq-alcohol-notice {border-left:4px solid currentColor; padding:8px 12px; margin:1em 0; font-weight:600; overflow-wrap:anywhere;}
		.turq-alcohol-declaration {display:flex; align-items:flex-start; gap:12px; border:1px solid rgba(0,0,0,.15); padding:12px; margin:1em 0; border-radius:4px; background-color: var(--wp--preset--color--kb-palette-4);}
		.turq-alcohol-declaration__icon {flex:0 0 44px; width:44px; height:44px}
		.turq-alcohol-declaration__icon svg {display:block; width:100%; height:100%}
		.turq-alcohol-declaration__body {flex:1 1 auto; min-width:0; overflow-wrap:anywhere}
		#main .turq-alcohol-declaration__notice {font-size:.9em; margin:0}
		.turq-alcohol-declaration .form-row {margin:0; padding:0}
		#main .turq-alcohol-declaration label.checkbox {display:flex; align-items:flex-start; gap:8px; font-weight:600; line-height:1.4; margin:0.75em 0 0}
		.turq-alcohol-declaration input[type="checkbox"] {flex:0 0 auto; margin:.2em 0 0; width:1.2em; height:1.2em}
		.turq-alcohol-declaration__error {display:none; color:#e2401c; font-size:.9em; font-weight:600; margin-top:.5em}
		.turq-alcohol-declaration .woocommerce-invalid label {color:#e2401c}
		.turq-alcohol-declaration .woocommerce-invalid .turq-alcohol-declaration__error {display:block}
		@media (max-width: 480px) {
			.turq-alcohol-declaration {gap:10px; padding:10px}
			.turq-alcohol-declaration__icon {flex-basis:32px; width:32px; height:32px}
		}
	</style> <?php
});

//
// Inline error on failed checkout
// -------------------------------
// Woo adds the error id as data-id on the notice; flag our field so the inline
// error shows. Woo's own field validation clears it again when the box is ticked.
add_action('wp_footer', function() {
	if (!is_checkout() || is_wc_endpoint_url()) return; ?>
	<script id="turq-alcohol-js">
	jQuery(function($){
		$(document.body).on('checkout_error', function(){
			if ($('[data-id="<?= TURQ_ALCOHOL_FIELD ?>"]').length) {
				$('#<?= TURQ_ALCOHOL_FIELD ?>_field').addClass('woocommerce-invalid woocommerce-invalid-required-field');
			}
		});
	});
	</script> <?php
});

//}

?>
