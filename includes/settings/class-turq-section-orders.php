<?php
if (!defined('ABSPATH')) exit;

class turq_Section_Orders extends turq_Settings_Section_Base {

    public function get_id() {
        return 'turq_s4';
    }

    public function get_label() {
        return 'Order Settings';
    }

    public function register_hooks() {
        //add_action('wp_ajax_turq_test_set_today', [$this, 'ajax_test_today']);
    }

    public function render() {
		$saved_order_settings = get_option('order_settings', []); ?>
        <div class="turq_woo_settings">
            <h3>Order Settings</h3>
				<table class="form-table">
						<tr valign="top">
							<th>Number of weeks ahead</th>
							<td><input type="number" min="1" name="order_settings[weeks_ahead]" value="<?= $saved_order_settings['weeks_ahead']?>" placeholder="a number, e.g. 4"></td>
						<tr>
						<tr valign="top">
							<th>Number of weeks ahead in ADMIN</th>
							<td><input type="number" min="1" name="order_settings[weeks_ahead_admin]" value="<?= $saved_order_settings['weeks_ahead_admin']?>" placeholder="a number, e.g. 4"></td>
						<tr>
						<tr valign="top">
							<th>Minimum order value for Delivery</th>
							<td><input type="number" step="0.01" min="0" name="order_settings[min_order_value]" value="<?= $saved_order_settings['min_order_value']?>" placeholder="a cost, e.g. 25.00"></td>
						<tr>					
						<tr valign="top">
							<th>No Minimum order value with Free Shipping</th>
							<td><input type="checkbox" name="order_settings[free_min_order_value]" <?php echo checked($saved_order_settings['free_min_order_value']??0,'1',false);?> value="1" ></td>
						<tr>			

						<?php $value = $saved_order_settings['report_order_status'] ?? [];?>
						<tr>
							<th scope="row"><label for="default_cut_off_day">Report Order Statuses</label></th>
							<td>
								<div style="display:flex;gap:15px;"> <?php
									foreach (wc_get_order_statuses() as $key=>$label) {
										$checked  = in_array($key, $value) ? 'checked' : '';
										echo '
											<label style="display:flex;align-items:center;gap:4px;margin-top:5px">
												<input type="checkbox" name="order_settings[report_order_status][]" value="'.esc_attr($key).'" '.$checked.' >'.esc_html($label).'
											</label>
										';
									} ?>
								</div>
								<p class="description">Only orders with these status will be included in the reports.</p>												
							</td>
						</tr>
						
				</table>
		</div>
	<?php
    }

    public function save() {
		turq_debug("WOO ADMIN SECTION ORDER : SAVED = ",$_POST);		

		if (isset($_POST['order_settings'])) {
			// sanitize ?
			update_option('order_settings', $_POST['order_settings']);
		}
    }

    //public function ajax_test_today() {

}