<?php
// class-turq-section-general.php

if (!defined('ABSPATH')) exit;

class turq_Section_General extends turq_Settings_Section_Base {
	
    private $turq_Market_Setup;

    public function __construct($turq_Market_Setup) {
        $this->turq_Market_Setup = $turq_Market_Setup;
    }	
	

    public function get_id() {
        return 'turq_general';
    }

    public function get_label() {
        return 'General';
    }

    public function register_hooks() {
        //add_action('wp_ajax_turq_test_set_today', [$this, 'ajax_test_today']);
    }

    public function render() {
		
		$saved_targeted_methods	= get_option('targeted_methods', array());
		$all_shipping_rates		= $this->turq_Market_Setup->get_all_shipping_rates();
        
		?>
		
		<div class="turq_woo_settings">
			<h3>Shipping Target Settings</h3>
				<table class="form-table">
				<?php foreach(turq_Market_Setup::TARGETED_METHOD_LABELS as $key => $label) : ?>
					<tr>
						<th scope="row"><?= $label ?> Rates</th>
						<td>
							<select name="targeted_methods[<?= $key ?>][]" multiple="multiple" class="wc-enhanced-select" style="width: 50%;">
								<?php
								$selected = $saved_targeted_methods[$key] ?? [];
								foreach ($all_shipping_rates as $rate_id => $rate) {
									printf(	"<option value='%s' %s>%s</option>",
											esc_attr($rate_id),
											in_array($rate_id, $selected) ? 'selected' : '',
											esc_html($rate)
									);
								}
								?>
							</select>
							<p class="description">Choose shipping rates for <?= $label ?></p>
						</td>
					</tr>
				<?php endforeach; ?>
				</table>
			

			<?php
			$pickup_only_ids = get_option('pickup_only_products', '');
			$prefiled_options_p = "<option value='' selected='selected'></option>";
			if (!empty($pickup_only_ids)) {
				foreach ($pickup_only_ids as $product_id) {
					$product = wc_get_product($product_id);
					if ($product) $prefiled_options_p .= "<option value='".esc_attr($product_id)."' selected='selected'>".esc_html($product->get_name())."</option>";
				}
			}

			$cp_only_ids = get_option('cp_only_products', '');
			$prefiled_options_cp = "<option value='' selected='selected'></option>";
			if (!empty($cp_only_ids)) {
				foreach ($cp_only_ids as $product_id) {
					$product = wc_get_product($product_id);
					if ($product) $prefiled_options_cp .= "<option value='".esc_attr($product_id)."' selected='selected'>".esc_html($product->get_name())."</option>";
				}
			} ?>
			
			<h3>Product Shipping Control</h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="pickup_only_products">Collection Only Products</label>
					</th>
					<td class="forminp forminp-select">
						<select 
							id="pickup_only_products" 
							name="pickup_only_products[]" 
							multiple="multiple" 
							class="wc-product-search" 
							style="width: 50%;" 
							data-placeholder="Search for a product"
							data-action="woocommerce_json_search_products"
							data-multiple="true"
							<?= $prefiled_options_p ?>
						</select>
						<p class="description">Select products that make the package collection only.</p>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="cp_only_products">Courier/Postage Products</label>
					</th>
					<td class="forminp forminp-select">
						<select 
							id="cp_only_products" 
							name="cp_only_products[]" 
							multiple="multiple" 
							class="wc-product-search" 
							style="width: 50%;" 
							data-placeholder="Search for a product"
							data-action="woocommerce_json_search_products"
							data-multiple="true"
							<?= $prefiled_options_cp ?>
						</select>
						<p class="description">Select products that are for courier/post delivery if they are the only ones in the package.</p>
					</td>
				</tr>				
			</table>

			<h3>Debug</h3>
			<table class="form-table">
				<tr valign="top">
					<th scope="row" class="titledesc">
						<label for="debug">Enable debug logging</label>
					</th>
					<td class="forminp forminp-select">
						<input name="turq_debug" type="checkbox" value="1" <?= (get_option('turq_debug') ?? 0 ) ? "checked" : "" ?>/>
					</td>
				</tr>
			</table>			
		</div>

		<?php
    }

    public function save() {
	
	
		// Validate : targeted_methods
		//--------------------------------------
		if ( isset($_POST['targeted_methods']) ) {
			// sanitize ?
			update_option('targeted_methods', $_POST['targeted_methods']);
		}		

		
		// Validate : pickup_only_products
		//--------------------------------------
		if (isset($_POST['pickup_only_products'])) {
			$filtered = [];
			foreach (array_map('absint', $_POST['pickup_only_products']) as $id) {
				$product = wc_get_product($id);
				if (!$product) continue;
				$filtered[] = $id;
			}
			update_option('pickup_only_products', $filtered);
		} else {
			update_option('pickup_only_products', array());
		}
		
		// Validate : cp_only_products
		//--------------------------------------
		if (isset($_POST['cp_only_products'])) {
			$filtered = [];			
			foreach (array_map('absint', $_POST['cp_only_products']) as $id) {
				$product = wc_get_product($id);
				if (!$product) continue;
				$filtered[] = $id;
			}
			update_option('cp_only_products', $filtered);
		} else {
			update_option('cp_only_products', array());
		}	

		// Validate : turq_debug
		//--------------------------------------
		if (isset($_POST['turq_debug']))	update_option('turq_debug', 1);
		else								update_option('turq_debug', 0);


	}

    //public function ajax_test_today() {

}