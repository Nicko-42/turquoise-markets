<?php
/*******************************************************************************
 * Coupons handling
 * 
 * turq_woo_coupons.php
 * 
*******************************************************************************/


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

//
// Show coupon description next to coupon code in checkout (optionally cart)
// -------------------------------------------------------------------------
add_filter( 'woocommerce_cart_totals_coupon_label', 'add_coupon_description_to_label', 10, 2 );
function add_coupon_description_to_label( $coupon_label, $coupon ) {
	
	if (!is_cart()) {
		$the_coupon = new WC_Coupon( $coupon->get_code() );
		$description = $the_coupon->get_description();

		if ( $description ) {
			$coupon_label .= '<br><small class="coupon-description">' . esc_html( $description ) . '</small>';
		}
	}
	
	return $coupon_label;
}

//
// remove coupon from basket
// -------------------------
add_filter( 'woocommerce_coupons_enabled', function() {if ( is_cart() ) return false;return true;});

// Massage data attribute for coupon description as HTML in cart
// -------------------------------------------------------------
/*
add_action('wp_footer', function() {
	if (is_cart()) {?>
		<script id="turq_cart_coupon_fix" type="text/javascript">			
			document.addEventListener('DOMContentLoaded', function() {
			  document.querySelectorAll('tr.cart-discount').forEach(row => {
				const td = row.querySelector('td[data-title]');
				if (!td) return;

				let title = td.getAttribute('data-title');
				if (!title) return;

				// Decode HTML entities
				const txt = document.createElement('textarea');
				txt.innerHTML = title;
				let decoded = txt.value;

				// Replace <br> and <br/> tags with a space
				decoded = decoded.replace(/<br\s*\/?>/gi, ' — ');

				// Remove any remaining HTML tags (e.g. <small>, etc.)
				decoded = decoded.replace(/<[^>]+>/g, '');

				// Clean up extra whitespace and trim
				decoded = decoded.replace(/\s+/g, ' ').trim();

				// Rewrite the clean version back to data-title
				td.setAttribute('data-title', decoded);
			  });
			});		
		</script> <?php
}}, 9999);
*/


////////////////////////////////////////////////////////////////////////////////
//Admin customisation
////////////////////////////////////////////////////////////////////////////////{

//
// Register submenu under Marketing
// --------------------------------
add_action('admin_menu', 'wc_register_coupons_import_export_menu');
function wc_register_coupons_import_export_menu() {
    add_submenu_page(
        'woocommerce-marketing',                // Parent: Marketing
        'Coupons Import/Export',                // Page title
        'Tools',				                // Menu title
        'manage_woocommerce',                   // Capability
        'coupons-import-export',                // Slug
        'wc_render_coupons_import_export_page'  // Callback
    );
}

//
// Render submenu page
// -------------------
function wc_render_coupons_import_export_page() {
    ?>
    <div class="wrap woocommerce">
        <h1 class="wp-heading-inline" style="border-bottom: 1px solid #bbb">Coupons Import / Export</h1>
		<p style="margin-top:0"> ©<?php echo date('Y'); ?> Nick Oakes, Turquoise Internet Ltd</p>
        <hr class="wp-header-end">

        <h2>Export Coupons</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="export_coupons_csv">
            <?php submit_button('Download Coupons CSV', 'primary', 'download_coupons'); ?>
        </form>

        <hr>

        <h2>Import Coupons</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field('import_coupons_csv', 'import_coupons_csv_nonce'); ?>
            <input type="hidden" name="action" value="import_coupons_csv">
            <input type="file" name="coupons_csv" accept=".csv" required>
            <?php submit_button('Upload and Import', 'primary'); ?>
        </form>

        <?php
		// Show detailed import log if available
        $log = get_transient('coupons_import_detailed_log');
        if ($log) {
            echo '<h2>Import Log</h2>';
            echo '<table class="widefat striped">';
            echo '<thead><tr><th>Coupon Code</th><th>Status</th><th>Fields Updated</th></tr></thead><tbody>';
            foreach ($log as $entry) {
                echo '<tr>';
                echo '<td>' . esc_html($entry['code']) . '</td>';
                echo '<td>' . esc_html(ucfirst($entry['status'])) . '</td>';
                echo '<td>' . (!empty($entry['changes']) ? esc_html(implode(', ', $entry['changes'])) : '-') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            delete_transient('coupons_import_detailed_log');
        }
        ?>
    </div>
    <?php
}

//
// Export Handler
// --------------
add_action('admin_post_export_coupons_csv', 'wc_export_coupons_csv');
function wc_export_coupons_csv() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Not allowed');
    }

    // Get all coupon IDs via WP_Query
    $coupon_ids = get_posts([
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'numberposts'    => -1,
        'fields'         => 'ids',
    ]);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="coupons-export-' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // Header row
    fputcsv($output, [
        'code', 'discount_type', 'amount', 'expiry_date',
        'usage_limit', 'usage_limit_per_user', 'individual_use',
        'free_shipping', 'min_amount', 'max_amount',
        'product_ids', 'excluded_product_ids',
        'product_categories', 'excluded_product_categories',
        'emails', 'description'
    ]);

    foreach ($coupon_ids as $id) {
        $coupon = new WC_Coupon($id);
        $expiry = $coupon->get_date_expires();

        fputcsv($output, [
            $coupon->get_code(),
            $coupon->get_discount_type(),
            $coupon->get_amount(),
            $expiry ? $expiry->date('d-m-Y') : '',
            $coupon->get_usage_limit(),
            $coupon->get_usage_limit_per_user(),
            $coupon->get_individual_use(),
            $coupon->get_free_shipping(),
            $coupon->get_minimum_amount(),
            $coupon->get_maximum_amount(),
            implode('|', $coupon->get_product_ids()),
            implode('|', $coupon->get_excluded_product_ids()),
            implode('|', $coupon->get_product_categories()),
            implode('|', $coupon->get_excluded_product_categories()),
            implode('|', $coupon->get_email_restrictions()),
            $coupon->get_description(),
        ]);
    }

    fclose($output);
    exit;
}

//
// Import Handler with field-level logging
// ---------------------------------------
add_action('admin_post_import_coupons_csv', 'wc_import_coupons_csv');
function wc_import_coupons_csv() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('Not allowed');
    }

    check_admin_referer('import_coupons_csv', 'import_coupons_csv_nonce');

    if (empty($_FILES['coupons_csv']['tmp_name'])) {
        wp_die('No CSV uploaded.');
    }

    $file = fopen($_FILES['coupons_csv']['tmp_name'], 'r');
    $header = fgetcsv($file); // skip header

    $log = [];

    while (($row = fgetcsv($file)) !== FALSE) {
        list($code, $discount_type, $amount, $expiry_date, $usage_limit, $usage_limit_per_user,
            $individual_use, $free_shipping, $min_amount, $max_amount,
            $product_ids, $excluded_product_ids, $product_cats, $excluded_cats,
            $emails, $description) = $row;

        $coupon_id = wc_get_coupon_id_by_code($code);
        $coupon_log = [
            'code'    => $code,
            'status'  => $coupon_id ? 'updated' : 'created',
            'changes' => []
        ];

        if ($coupon_id) {
            $coupon = new WC_Coupon($coupon_id);
        } else {
            $coupon = new WC_Coupon();
            $coupon->set_code($code);
        }

        // Field map
        $fields = [
            'discount_type' => ['get' => 'get_discount_type', 'set' => 'set_discount_type', 'value' => $discount_type],
            'amount' => ['get' => 'get_amount', 'set' => 'set_amount', 'value' => $amount],
            'date_expires' => ['get' => 'get_date_expires', 'set' => 'set_date_expires', 'value' => $expiry_date ? strtotime($expiry_date) : null],
            'usage_limit' => ['get' => 'get_usage_limit', 'set' => 'set_usage_limit', 'value' => $usage_limit],
            'usage_limit_per_user' => ['get' => 'get_usage_limit_per_user', 'set' => 'set_usage_limit_per_user', 'value' => $usage_limit_per_user],
            'individual_use' => ['get' => 'get_individual_use', 'set' => 'set_individual_use', 'value' => (bool)$individual_use],
            'free_shipping' => ['get' => 'get_free_shipping', 'set' => 'set_free_shipping', 'value' => (bool)$free_shipping],
            'minimum_amount' => ['get' => 'get_minimum_amount', 'set' => 'set_minimum_amount', 'value' => $min_amount],
            'maximum_amount' => ['get' => 'get_maximum_amount', 'set' => 'set_maximum_amount', 'value' => $max_amount],
            'product_ids' => ['get' => 'get_product_ids', 'set' => 'set_product_ids', 'value' => $product_ids ? array_map('intval', explode('|', $product_ids)) : []],
            'excluded_product_ids' => ['get' => 'get_excluded_product_ids', 'set' => 'set_excluded_product_ids', 'value' => $excluded_product_ids ? array_map('intval', explode('|', $excluded_product_ids)) : []],
            'product_categories' => ['get' => 'get_product_categories', 'set' => 'set_product_categories', 'value' => $product_cats ? array_map('intval', explode('|', $product_cats)) : []],
            'excluded_product_categories' => ['get' => 'get_excluded_product_categories', 'set' => 'set_excluded_product_categories', 'value' => $excluded_cats ? array_map('intval', explode('|', $excluded_cats)) : []],
            'email_restrictions' => ['get' => 'get_email_restrictions', 'set' => 'set_email_restrictions', 'value' => $emails ? array_map('sanitize_email', explode('|', $emails)) : []],
            'description' => ['get' => 'get_description', 'set' => 'set_description', 'value' => $description],
        ];

        foreach ($fields as $key => $map) {
            $getter = $map['get'];
            $setter = $map['set'];
            $new_value = $map['value'];

            $current_value = $coupon->$getter();

            // WC_DateTime → timestamp
            if ($current_value instanceof WC_DateTime) {
                $current_value = $current_value->getTimestamp();
            }

            // Normalize for comparison
            $compare_current = is_array($current_value) ? implode('|', $current_value) : $current_value;
            $compare_new     = is_array($new_value) ? implode('|', $new_value) : $new_value;

            if ($compare_current != $compare_new) {
                $coupon->$setter($new_value);
                $coupon_log['changes'][] = $key;
            }
        }

		if (!count($coupon_log['changes'])) $coupon_log['status']="no change";
		
        $coupon->save();
        $log[] = $coupon_log;
    }

    fclose($file);

    // Store log for display
    set_transient('coupons_import_detailed_log', $log, 60);

    wp_safe_redirect(admin_url('admin.php?page=coupons-import-export&import=done'));
    exit;
}


//
// Add a new column to the Coupons table
// -------------------------------------
add_filter('manage_edit-shop_coupon_columns', 'add_coupon_email_restriction_column');
function add_coupon_email_restriction_column($columns) {
    // Insert the column after "Coupon amount" (optional)
    $new_columns = [];

    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;

        if ($key === 'description') {
            $new_columns['email_restriction'] = 'Email Restrictions';
        }
    }

    return $new_columns;
}

//
// Display the email restriction values in the new column
// ------------------------------------------------------
add_action('manage_shop_coupon_posts_custom_column', 'show_coupon_email_restriction_column_content', 10, 2);
function show_coupon_email_restriction_column_content($column, $post_id) {
    if ($column === 'email_restriction') {
        $emails = get_post_meta($post_id, 'customer_email', true);

        if (!empty($emails)) {
            if (is_array($emails)) {
                echo implode(', ', $emails);
            } else {
                echo esc_html($emails);
            }
        } else {
            echo '<span style="color:#999;">—</span>';
        }
    }
}

?>
