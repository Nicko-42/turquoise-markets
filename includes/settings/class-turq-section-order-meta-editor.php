<?php
if (!defined('ABSPATH')) exit;

class turq_Section_Order_Meta_Editor extends turq_Settings_Section_Base {

    public function get_id() {
        return 'turq_some';
    }

    public function get_label() {
        return 'Order Meta Edit';
    }

    public function register_hooks() {
		add_action('wp_ajax_ome_save_hidden_columns', [$this, 'ome_save_hidden_columns']);
		add_action('admin_footer', [$this, 'ome_admin_footer']);
	
    }


	function ome_get_meta_keys() {
	/*
		$keys = get_transient('ome_meta_keys');

		if ($keys !== false) {
			return $keys;
		}
	*/
		global $wpdb;

		$table = $wpdb->prefix . 'wc_orders_meta';

		$keys = $wpdb->get_col("
			SELECT DISTINCT meta_key
			FROM {$table}
			WHERE meta_key LIKE '%turq_local_option%'
			AND order_id > 0
			ORDER BY meta_key
		");

		set_transient('ome_meta_keys', $keys, HOUR_IN_SECONDS);

		return $keys;

	}

	function ome_clean_meta_label($key) {

		$label = str_replace('_turq_local_option_', '', $key);
		$label = str_replace('_', ' ', $label);

		return ucwords($label);

	}

	function ome_get_user_visible_columns($meta_keys){
		$user_id = get_current_user_id();
		$hidden = get_user_meta($user_id,'ome_hidden_columns',true);
		if(!is_array($hidden)) $hidden = [];
		return array_filter($meta_keys, function($key) use ($hidden){
			return !in_array($key,$hidden);
		});
	}


	function ome_get_orders() {

		return wc_get_orders([
			'limit'			=> 50,
			'orderby'		=> 'date',
			'order'			=> 'DESC',
			/*'status'		=> 'processing',*/
			'date_created'	=> '>=' . date( 'Y-01-01' ),			
		]);

	}

	public function render() {

		$orders = $this->ome_get_orders();
		$all_meta_keys = $this->ome_get_meta_keys();
		$meta_keys = array_flip($this->ome_get_user_visible_columns($all_meta_keys));
		
		?>
		<div id="turq_ome" class="turq_woo_settings">

			<h3>Order Meta Editor</h3>
			<div id="options-wrap"></div>
			<table class="widefat striped fixed">
				<thead>
					<tr>
						<th>Order</th>
						<th>Status</th>
						<th>Date</th>
						<th>Customer</th>
						<th>Total</th>

						<?php foreach ($all_meta_keys as $meta_key): 
							$visible = ($meta_keys[$meta_key] ?? null) === null ? "style='display:none'" : "";
						?>
							<th class="col<?= $meta_key ?>" data-meta="<?= esc_attr($meta_key); ?>" <?= $visible ?>><?= esc_html($this->ome_clean_meta_label($meta_key)); ?></th>
						<?php endforeach; ?>

						<th>Save</th>
					</tr>
				</thead>
				<tbody>

					<?php foreach ($orders as $order):

						$order_id = $order->get_id();

					?>

						<tr data-order-id="<?= $order_id; ?>">

							<td>#<?= $order_id; ?></td>
							<td><?= wc_get_order_status_name($order->get_status()); ?></td>
							<td><?= $order->get_date_created()->date('Y-m-d H:i'); ?></td>
							<td><?= esc_html($order->get_formatted_billing_full_name()); ?></td>
							<td><?= wc_price($order->get_total()); ?></td>

							<?php foreach ($all_meta_keys as $meta_key):

								$value	 = $order->get_meta($meta_key);
								$visible = ($meta_keys[$meta_key] ?? null) === null ? "style='display:none'" : "";

							?>

								<td data-meta="<?= esc_attr($meta_key); ?>" <?= $visible ?>>

									<?php

									if (is_array($value)) {

										$key  = array_key_first($value) ?? '';
										$data = $value[$key] ?? '';

										?>

										<div class="ome-array-row">

										<input
										type="text"
										class="ome-array-key"
										value="<?php echo esc_attr($key); ?>"
										>

										<input
										type="text"
										class="ome-array-data"
										value="<?php echo esc_attr($data); ?>"
										>

										</div>

										<?php
									
									} else {

									?>

										<input
										type="text"	
										class="ome-field"
										data-meta="<?php echo esc_attr($meta_key); ?>"
										value="<?php echo esc_textarea($value); ?>"
										>

										<?php
									}
									
									if ( array_reduce(['date', 'cut_off', 'cutoff'], fn($a, $n) => $a || str_contains($meta_key, $n), false) && is_numeric($value)) {
										echo "<div class='utc-display' style='margin-top:2px;font-size:12px;color:#555;'>".date(DATEFORMAT.' H:i', $value)."</div>";
									}
									
									?>

								</td>

							<?php endforeach; ?>

							<td><button class="button button-primary ome-save-row">Save</button></td>

						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		
		<script>
		jQuery(function($){
			// --- Screen Options: dynamically hide/show columns ---
			const allMetaKeys = <?php echo json_encode($all_meta_keys); ?>;
			const hidden = <?php echo json_encode(get_user_meta(get_current_user_id(),'ome_hidden_columns',true) ?: []); ?>;

			// Create a simple dropdown for toggling columns
			const $dropdown = $('<div style="margin:10px 0; padding:5px;display: flex; align-items: center;	gap: 10px;">Layout: </div>');
			allMetaKeys.forEach(function(key,i){
				const label = key.replace('_turq_local_option_','').replace('_',' ');
				const checked = hidden.includes(key) ? '' : 'checked';
				$dropdown.append('<label style="margin-right:5px;"><input type="checkbox" class="column-toggle-checkbox" data-meta-key="'+key+'" '+checked+'> '+label+'</label>');
			});
			$('#options-wrap').html($dropdown);

			// Toggle column visibility
			$('.column-toggle-checkbox').on('change',function(){
				const metaKey = $(this).data('meta-key');
				const visible = $(this).is(':checked');

				// Find column index
				const colIndex = $('th[data-meta="'+metaKey+'"]').index();
				if(colIndex!==-1){
					$('tr').each(function(){
						$(this).children().eq(colIndex).toggle(visible);
					});
				}

				// Save hidden columns via AJAX
				const hiddenCols = [];
				$('.column-toggle-checkbox').each(function(){
					if(!$(this).is(':checked')) hiddenCols.push($(this).data('meta-key'));
				});

				$.post(ajaxurl,{
					action:'ome_save_hidden_columns',
					hidden:hiddenCols,
					_wpnonce:'<?php echo wp_create_nonce('ome-save-hidden'); ?>'
				});
			});	
		});
		</script>
		
		

		<?php
	}

	function ome_save_hidden_columns (){
		check_ajax_referer('ome-save-hidden');
		$hidden = isset($_POST['hidden']) ? array_map('sanitize_text_field',$_POST['hidden']) : [];
		update_user_meta(get_current_user_id(),'ome_hidden_columns',$hidden);
		wp_send_json_success();
	}


	function ome_admin_footer() {

		?>

		<style>
			.col_turq_local_option {width:60ch}
			.col_turq_local_option_tag_id {width:6ch}
			.col_turq_local_option_slot{width:6ch}
			.col_turq_local_option_name{width:35ch}

			#turq_ome .ome-array-row{
			display:flex;
			gap:5px;
			margin-bottom:4px;
			}

			#turq_ome .ome-array-row input:first-of-type{
			width:90px;
			}

			#turq_ome .ome-field, #turq_ome .ome-array-row input{
			width:100%;
			}

			#turq_ome .ome-row-changed{
			background:#fff8d6;
			}

			#turq_ome .ome-row-saved{
			background:#d7ffd9;
			}

		</style>

		<script id="turq_ome">

		jQuery(function($){

			$('.ome-field, .ome-array-key, .ome-array-data').on('change keyup', function(){

			$(this).closest('tr').addClass('ome-row-changed');

			});

			// Function to convert UTC to readable format
			function formatUTC(utcInt){
				if(!utcInt) return '';

				// Convert seconds → milliseconds
				let date = new Date(utcInt * 1000);

				if(isNaN(date.getTime())) return ''; // invalid date

				// Format as e.g., Wed 11 Mar 12:00
				const options = {
					weekday: 'short',
					day: '2-digit',
					month: 'short',
					hour: '2-digit',
					minute: '2-digit',
					hour12: false
				};

				return date.toLocaleString(undefined, options);
			}

			// Function to update UTC displays
			function updateUTCColumn($table, colIndex){
				$table.find('tbody tr').each(function(){
					const $td = $(this).children().eq(colIndex);
					let value = '';

					// Check if array inputs exist in this cell
					const $arrayData = $td.find('.ome-array-data');
					if($arrayData.length){
						// For arrays, just pick the first data field to display as UTC if needed
						value = $arrayData.first().val();
					} else {
						value = $td.find('.ome-field').val();
					}

					const formatted = formatUTC(value);
					$td.find('.utc-display').text(formatted);
				});
			}

			// On typing in any textarea or array data input, update UTC displays
			$('table').on('input', '.ome-field, .ome-array-data', function(){
				const $td = $(this).closest('td');
				const colIndex = $td.index();
				const $table = $td.closest('table');

				const $th = $table.find('thead th').eq(colIndex);
				updateUTCColumn($table, colIndex);
			});

		});
		</script>

		<?php
	}


    public function save() {
    }

    //public function ajax_test_today() {

}