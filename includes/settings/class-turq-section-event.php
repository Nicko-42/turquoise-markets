<?php
if (!defined('ABSPATH')) exit;

class turq_Section_Event  extends turq_Settings_Section_Base {

    private $turq_Market_Setup;
    private $group_key;
    private $group_data;
	private $key_type;
	private $bg;
	private $color;
	private $label;

    public function __construct($turq_Market_Setup, $group_key, $group_data) {
        $this->turq_Market_Setup	= $turq_Market_Setup;
        $this->group_key			= $group_key;
        $this->group_data			= $group_data;
        $this->label				= esc_html($group_data['label'] ?? 'Event');

		if (str_starts_with($group_key, 'regular'))	$this->key_type = 'regular';
		if (str_starts_with($group_key, 'event'))	$this->key_type = 'event';		
    }

    public function get_id() {
        return 'turq_e_' . $this->group_key;
    }

    public function get_label() {
        $this->bg		= esc_attr($this->group_data['background'] ?? '#ccc');
        $this->color	= esc_attr($this->group_data['color'] ?? '#000');

        return "<span class='group_pill' style='background:{$this->bg};color:{$this->color}'>{$this->label} Setup</span>";
    }

    public function render() {
		$preview_active = get_transient('turq_preview_active') ?? '';		
		
        ?>
		<div class="turq_woo_settings <?= $preview_active ?>">
			
			<h3>Shipping Method Setup</h3>
			<p class="description">Enable which methods are used for the group and schedule an active period if required, otherwise leave blank.</p> <?php

			$group_method_config = get_option('group_method_config', []);

			$style = $this->group_data['enabled'] ? "" : "'style='display:none'"; ?>
			<table class="widefat local-table" id="method_setup-table" <?= $style ?>>
				<thead>
					<tr>
						<th><?= $this->get_label() ?></th>
						<th>Start Date</th>
						<th>End Date</th>							
						<th class="enabled">Enabled</th>
						<th style="width:50px;"></th>
					</tr>
				</thead>
				<tbody>
				<?php
					foreach(turq_Market_Setup::TARGETED_METHOD_LABELS as $key => $label) {
						$value		= $group_method_config[$this->group_key][$key];
						$checked	= ($value['enabled'] ?? 0) ? "checked" : "";
						?>
						<tr>
							<td><?= $label ?></td>
							<td><input type="date" name="group_method_config[<?= $key ?>][start_date]" value="<?= $value['start_date'] ?>"></td>
							<td><input type="date" name="group_method_config[<?= $key ?>][end_date]" value="<?= $value['end_date'] ?>"></td>
							<td><input type="checkbox" name="group_method_config[<?= $key ?>][enabled]" value="1" <?= $checked ?>></td>
							<td></td>							
						</tr>
						<?php
					}
				?>
				</tbody>
			</table>
			
			<div id="group_local_collection_points"> <?php
				if		($this->key_type == 'regular') $message ="This is a regular event, so market day is already defined. Cut off is relative to the market.";
				else if ($this->key_type == 'event')	 $message ="This is a special event, so the market day and cut off can be absolutely defined.";

				$group_local_collection_points = get_option('group_local_collection_points', []);
				$group_local_collection_points = $group_local_collection_points[$this->group_key] ?? [];

				$markets = get_posts([
					'post_type'      => 'wpsl_stores',
					'posts_per_page' => -1,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'meta_query'     => [['key'=>'wpsl_hours','compare'=>'EXISTS']]
				]);

				$weekdays	= ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
				$columns	= array_fill_keys($weekdays, []);

				foreach ( $markets as $market ) {
					$days = maybe_unserialize(get_post_meta($market->ID,'wpsl_hours',true));
					if(!$days) continue;

					foreach($weekdays as $day) {
						if(!empty($days[$day])) {
							$columns[$day][] = $market;
						}
					}
				}

				foreach ($columns as $day => $items) {
					if(empty($items)) continue;
					
					usort($items, function($a,$b) use ($day){

						$a_meta = get_post_meta($a->ID,'wpsl_turq_order',true);
						$b_meta = get_post_meta($b->ID,'wpsl_turq_order',true);

						$a_pos = $a_meta[$day] ?? PHP_INT_MAX;
						$b_pos = $b_meta[$day] ?? PHP_INT_MAX;

						return $a_pos <=> $b_pos;
					});

					$day_key		= '-'.$day;

					if ($this->key_type == 'event') {
						$day_key	= '';
						$day		= '';						
					}
						

					foreach($items as $market) {
						$title				= get_post_field('post_title', $market->ID);
						$title				= wp_specialchars_decode($title, ENT_QUOTES);
						$market_location	= mb_convert_case($title.", ".get_post_meta($market->ID,'wpsl_city',true), MB_CASE_TITLE, 'UTF-8');
				
						$market_options[($market->ID).($day_key)] = ['id' => $market->ID, 'location' => $market_location, 'open_day' => $day];
					}
				}
				
				// debug checks....
				//$temp = $market_options['1127-friday'];
				//unset($market_options['1127-friday']);
				//$market_options['1127-monday'] = $temp;
				//$market_options['1127-monday']['open_day']='monday';
				//$market_options['1127-friday']['open_day']='monday';
				//$market_options['1127-friday']['location']='changed';
				//unset($market_options['1094']);

				?>
				<script>
					window.localCollectionData = <?= wp_json_encode(array_values($group_local_collection_points))  ?>;
					window.marketLookUp = <?= wp_json_encode($market_options)  ?>;
				</script>
	
				<h3>Collection Markets <?= $group_method_config[$this->group_key]['p']['enabled'] ?? 0 ? "" : "- <mark style='color:red;padding:0 5px'>DISABLED</mark>" ?></h3>
				<p class="description"><?= $message ?> Drag to re-order this table.</p>
				<table class="widefat local-table" data-key-type="<?= $this->key_type ?>" id="local-collection-table">
					<thead>
						<tr>
							<th class="drag-handle">☰</th>
							<th>Location</th>
							<th class="open-day">Open Day</th>								
							<th class="special-date">Date</th>
							<th class="cut-off" >Cut-off</th>							
							<th colspan=2>Skips</th>
							<th class="enabled">Enable</th>
							<th style="width:50px;">Delete</th>								
						</tr>
					</thead>
					<tbody class="sortable">
						<template id="local-collection-row-template">
							<tr data-market-id="{{market_id}}">
								<td class="drag-handle">☰</td>
								<td class="location">
									<input type="hidden" name="local_collection_points[{{i}}][id]" value="{{id}}">
									<input type="hidden" name="local_collection_points[{{i}}][market_id]" value="{{market_id}}">
									<input type="hidden" name="local_collection_points[{{i}}][location]" value="{{location}}">
									{{location}}
								</td>
								<td class="open-day"><input type="hidden" name="local_collection_points[{{i}}][open_day]" value="{{open_day}}">{{open_day}}</td>									
								<td class="special-date"><input type="date" name="local_collection_points[{{i}}][date]" value="{{date}}"></td>
								
								<?php if ($this->key_type == 'regular') { ?>
								<td>
									<select name="local_collection_points[{{i}}][cut_off]">
										<option value="8">Default</option>
									<?php
										foreach (turq_Market_Setup::WEEKDAYS as $i=>$label) echo '<option value="'.esc_attr($i+1).'">'.esc_html($label).'</option>';
									?>
									</select>
								</td>
								<?php } 
									  if ($this->key_type == 'event') { ?>
								<td><input type="date" name="local_collection_points[{{i}}][cut_off]" value="{{cut_off}}"></td>
								<?php } ?>

								<td colspan=2><input type="hidden" name="local_collection_points[{{i}}][skips]">[for future expansion]</td>									
								<td><input type="checkbox" name="local_collection_points[{{i}}][enabled]" class="enabled-checkbox" value="1"></td>
								<td><button type="button" class="button remove-row">×</button></td>									
							</tr>
						</template>
					</tbody>
					<tfoot>
						<tr>
						<?php if (!empty($markets)) { ?>
							<td colspan="3"><button type="button" class="button button-primary" id="add-local-collection-row" >+ Add Row</button></td>
							<td><?php if ($this->key_type == 'event') { ?>
								<div style="display:flex"><input type="date" data-target="cut_off" class="apply-all-date" style="margin:0 5px 0 0;padding: 0 8px"><button type="button" class="apply-all-btn button-secondary">Apply</button></div> <?php } ?>
							</td>
							<td colspan="2"></td>
							<td><input type="checkbox" name="enable-all"></td>
							<td><button type="button" class="button remove-row-all">×</button></td>									
						<?php }  else { ?>
							<td colspan="8" style="border-top:none;">To add collection points, first set up some markets.</td>
						<?php }?>
						</tr>
					</tfoot>						
				</table>
					
				<div id="market-modal" class="ti_modal wp-core-ui" style="display:none;">
					<div>
						<h2>Select a Market</h2>
						<div style="display:flex;flex-direction:column;gap:5px">
							<?php 
							foreach ($market_options as $id=>$option) { ?>
								<label>
								<input 
									type="checkbox"
									name="market_option[]"
									value="<?= esc_attr($id) ?>"
									data-location="<?= esc_attr($option['location']) ?>"
									data-open-day="<?= esc_attr($option['open_day']) ?>"
								>
								<?= esc_html($option['location']).($option['open_day'] ? " - ".esc_html(ucwords($option['open_day'])) : '') ?>
								</label>
							<?php 
							}
							?>
							<button type="button" class="button button-secondary toggle-all" style="margin-top:10px">Toggle All</button>
						</div>
						<p style="text-align:right;">
							<button type="button" class="button button-primary confirm-modal">Add Row(s)</button>
							<button type="button" class="button button-secondary cancel-modal">Cancel</button>
						</p>
					</div>
				</div>			
			</div>		

			<div id="group_local_delivery_days"> <?php
				$group_local_delivery_days = get_option('group_local_delivery_days', []);
				$group_local_delivery_days = $group_local_delivery_days[$this->group_key] ?? [];
				?>
				<script>
					window.localDeliveryData = <?= wp_json_encode(array_values($group_local_delivery_days)); ?>;
				</script>
		
				<h3>Delivery Dates <?= $group_method_config[$this->group_key]['s']['enabled'] ?? 0 ? "" : "- <mark style='color:red;padding:0 5px'>DISABLED</mark>" ?></h3>
				<p class="description">Drag to tidy the order in this table.</p>
				<table class="widefat local-table" id="local-delivery-table">
					<thead>
						<tr>
							<th class="drag-handle">☰</th>
							<th>ID</th>
							<th>Date</th>
							<th>Slot</th>							
							<th>Cut-off</th>
							<th style="text-align:center">Enable</th>
							<th style="width:50px;">Delete</th>
						</tr>
					</thead>
					<tbody class="sortable"> 
						<template id="local-delivery-row-template">
							<tr>
								<td class="drag-handle">☰</td>
								<td><input type="hidden" name="local_delivery_days[{{i}}][id]" value="{{id}}">{{id}}</td>
								<td><input type="date" name="local_delivery_days[{{i}}][date]" value="{{date}}"></td>
								<td><input type="text" name="local_delivery_days[{{i}}][slot]" value="{{slot}}" placeholder="e.g. AM, PM"></td>								
								<td><input type="date" name="local_delivery_days[{{i}}][cut_off]" value="{{cut_off}}"></td>
								<td><input type="checkbox" name="local_delivery_days[{{i}}][enabled]" class="enabled-checkbox" value="1"></td>
								<td><button type="button" class="button remove-row">×</button></td>
							</tr>
						</template>					
					</tbody>
					<tfoot>
						<tr>
							<td colspan="4"><button type="button" class="button button-primary" id="add-local-delivery-row">+ Add Row</button></td>
							<td><div style="display:flex"><input type="date" data-target="cut_off" class="apply-all-date" style="margin:0 5px 0 0;padding: 0 8px"><button type="button" class="apply-all-btn button-secondary">Apply</button></div></td>
							<td><input type="checkbox" name="enable-all"></td>
							<td><button type="button" class="button remove-row-all">×</button></td>									
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
			
			<script>
			jQuery(document).ready(function($){
				
				const groupKey = $('#group_key').val();

			// MODAL = local-collection-table
				const $table  = $('#local-collection-table');
				const keyType = $table.data('key-type');
				const $tbody1 = $table.find('tbody');
				const $modal1 = $('#market-modal');			
				const template1 = $('#local-collection-row-template').html();
			
				$tbody1.sortable({
					items: "tr",  helper: fixHelper, handle: '.drag-handle', placeholder: 'sortable-placeholder', axis: 'y', containment: "parent",  tolerance:'pointer', forcePlaceholderSize: true,
					}).disableSelection();

				const observer = new MutationObserver(function(mutations) {
					mutations.forEach(function(mutation) {

						const currentMarketIds = $tbody1
							.find('tr')
							.map(function(){
								return $(this).data('market-id').toString();
							})
							.get();

						const marketsCount = Object.keys(window.marketLookUp).length;
						const invalidIds = currentMarketIds.filter(id => !(id in window.marketLookUp));

						if (invalidIds.length) {
							$tbody1.find('tr').each(function() {
								const id = $(this).data('market-id').toString();
								if (invalidIds.includes(id)) {
									$(this).css({ background: 'lightpink' });
									$(this).find('.enabled-checkbox').prop('checked', false).prop('disabled', true);
								}
							});
						}
						if (keyType == 'regular') {
							if (marketsCount > currentMarketIds.length - invalidIds.length)	$('#add-local-collection-row').prop('disabled', false);
							else															$('#add-local-collection-row').prop('disabled', true);
						}
					});
				});
				observer.observe($tbody1[0], { childList: true });

				// Render all initial rows
				function renderInitialRows1() {
					if (Array.isArray(window.localCollectionData) && window.localCollectionData.length > 0) {
						window.localCollectionData.forEach((item, i) => {
							const rowHtml = renderRow({
								i: i,
								id: item.id,
								market_id: item.market_id,
								location: window.marketLookUp[item.market_id]?.location || 'Market deleted ?',
								open_day:item.open_day,
								date: item.date,
								cut_off: item.cut_off,
							}, template1);

							// post process row to set checkbox state to checked
							const $row = $(rowHtml);
							if (item.enabled == 1)	$row.find('.enabled-checkbox').prop('checked', true);
							else					$row.find('.enabled-checkbox').prop('checked', false);
							
							// post process row to set dropdown selected
							$row.find('select[name$="[cut_off]"]').val(item.cut_off || 'default');
							
							// check if current selection is still a valid option
							if (item.market_id in window.marketLookUp) {
								if (window.marketLookUp[item.market_id].open_day != item.open_day ) {
									$row.find('.open-day').html(item.open_day + ' <span style="color:red">>> NOW '+window.marketLookUp[item.market_id].day+' <<</span>');
									$row.find('.enabled-checkbox').prop('checked', false).prop('disabled', true);
									$row.css({ opacity: 0.5, background: '#eee' });
								}
								if (item.location != window.marketLookUp[item.market_id].location) {
									$row.find('.location').css({ color: 'green' });
									$row.find('.location').html(window.marketLookUp[item.market_id].location);
								}
							}
						
							$tbody1.append($row);
						});
					};
				}

				renderInitialRows1();

				// Add new row modal
				$('#add-local-collection-row').on('click', function() {
					
					if (keyType == 'event') {
						$modal1.show();
						return;
					}
					
					const existingMarkets = $tbody1
						.find('tr')
						.map(function(){
							return $(this).data('market-id').toString();
						})
						.get();

					$('#market-modal input[name="market_option[]"]').each(function(){

						const $cb = $(this);
						const marketId = $cb.val();

						if(existingMarkets.includes(marketId)){

							$cb.prop('disabled', true)
							   .prop('checked', false)
							   .closest('label')
							   .css({
								   opacity: 0.5,
								   cursor: 'not-allowed'
							   })

						} else {

							// restore if previously disabled
							$cb.prop('disabled', false)
							   .closest('label')
							   .css({
								   opacity: 1,
								   cursor: 'pointer'
							   });
						}
					});					
										
					$modal1.show();
				});

				// Confirm button > add row
				$('#market-modal .confirm-modal').on('click', function() {
					
					$modal1.find('input[name="market_option[]"]:checked').each(function(){
						
						const $cb = $(this);					
						const market_id = $cb.val();
						
						// ---- prevent duplicates ----
						if (keyType == 'regular' && $tbody1.find(`tr[data-market-id="${market_id}"]`).length) {
							return; // skip this one
						}
						
						const index = $tbody1.find('tr').length;

						const rowHtml = renderRow({
							i: index,
							id: [...crypto.getRandomValues(new Uint8Array(4))].map(b => b.toString(16).padStart(2, '0')).join(''),					
							market_id: market_id,
							location: $cb.data('location'),
							open_day:$cb.data('open-day'),
							date: '',
							cut_off: '',
						}, template1);
						
						// post process row to set checkbox state to checked
						const $row = $(rowHtml);
						$row.find('.enabled-checkbox').prop('checked', true);
			
						$tbody1.append($row);
					});
					
					$modal1.find('input[name="market_option[]"]').prop('checked', false);
					$modal1.hide();
				});

				// Cancel button
				$('#market-modal .cancel-modal').on('click', function() {$modal1.hide();});	
				
				// Toggle all checkboxes in the modal
				$('#market-modal').on('click', '.toggle-all', function(){

					$modal1.find('input[name="market_option[]"]').each(function(){
						const $cb = $(this);
						if(!$cb.prop('disabled')) $cb.prop('checked', !$cb.prop('checked'));
					});

				});

			// MODAL = local-delivery-table
				const $tbody2 = $('#local-delivery-table tbody');
				const $modal2 = $('#delivery-modal');			
				const template2 = $('#local-delivery-row-template').html();
			
				$tbody2.sortable({
					items: "tr",  helper: fixHelper, handle: '.drag-handle', placeholder: 'sortable-placeholder', axis: 'y', containment: "parent",  tolerance:'pointer', forcePlaceholderSize: true,
					}).disableSelection();

				// Render all initial rows
				function renderInitialRows2() {
					if (Array.isArray(window.localDeliveryData) && window.localDeliveryData.length > 0) {
						window.localDeliveryData.forEach((item, i) => {
							const rowHtml = renderRow({
								i: i,
								groupKey: item.groupKey,
								id: item.id,
								date: item.date || '',
								slot: item.slot || '',						
								cut_off: item.cut_off || '',
							}, template2);
							// post process row to set checkbox state to checked
							const $row = $(rowHtml);
							if(item.enabled == 1)	$row.find('.enabled-checkbox').prop('checked', true);
							else					$row.find('.enabled-checkbox').prop('checked', false);
							
							$tbody2.append($row);

						});
					};
				}

				renderInitialRows2();

				// Add new row modal
				$('#add-local-delivery-row').on('click', function() {
					const index = $tbody2.find('tr').length;
					
					const rowHtml = renderRow({
						i: index,
						groupKey: groupKey,
						id: [...crypto.getRandomValues(new Uint8Array(4))].map(b => b.toString(16).padStart(2, '0')).join(''),					
						date: '',
						slot: '',					
						cut_off: '',
					}, template2);

					// post process row to set checkbox state to checked
					const $row = $(rowHtml);
					$row.find('.enabled-checkbox').prop('checked', true);
		
					$tbody2.append($row);
				});
	
			});
			</script>
			
        <?php
    }

    public function save() {

		// Validate : group_method_config
		//--------------------------------------
		$schema = [
			'start_date'=> [
				'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
				'default' => '',
			],
			'end_date'=> [
				'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
				'default' => '',
			],
			'enabled'   => fn($v) => $v ? '1' : '0',			
		];

		$cleaned = array_map(
			fn($row) => is_array($row)
				? $this->sanitize_array_by_schema($row, $schema)
				: [],
			wp_unslash($_POST['group_method_config'] ?? [])
		);		

		$group_method_config = get_option('group_method_config', []);
		$group_method_config[$this->group_key] = $cleaned;
		update_option('group_method_config', $group_method_config);
		//error_log("group_method_config >".print_r($group_method_config,true));


		// Validate : local_collection_points
		//--------------------------------------
		$schema = [
			'id'        => 'sanitize_text_field',
			'market_id' => 'sanitize_text_field',
			'location' 	=> 'sanitize_text_field',			
			'open_day' 	=> 'sanitize_text_field',			

			'date' => [
				'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
				'default' => '',
			],
			'cut_off' 	=> fn($v) => max(1, min(8, intval($v))),
			'enabled'   => fn($v) => $v ? '1' : '0',			
		];
		
		if ($this->key_type === 'event') {
			$schema['cut_off'] = [
				'regex' => '/^\d{4}-\d{2}-\d{2}$/',
				'default' => '',
			];
		}
		
		$cleaned = array_map(
			fn($row) => is_array($row)
				? $this->sanitize_array_by_schema($row, $schema)
				: [],
			wp_unslash($_POST['local_collection_points'] ?? [])
		);
			
		$group_local_collection_points = get_option('group_local_collection_points', []);
		$group_local_collection_points[$this->group_key] = $cleaned;
		update_option('group_local_collection_points', $group_local_collection_points);

		//error_log("group_local_collection_points >".print_r($group_local_collection_points,true));
	

		// Validate : local_delivery_days
		//--------------------------------------
		$schema = [
			'id'        => 'sanitize_text_field',
			'name'		=> ['data'	  => $this->label ],
			'date' 		=> [
							'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
							'default' => '',
							],
			'slot' 		=> 'sanitize_text_field',			
			'cut_off' 	=> [
							'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
							'default' => '',
							],
			'enabled'   => fn($v) => $v ? '1' : '0',
		];
		
		$cleaned = array_map(
			fn($row) => is_array($row)
				? $this->sanitize_array_by_schema($row, $schema)
				: [],
			wp_unslash($_POST['local_delivery_days'] ?? [])
		);
		
		$group_local_delivery_days = get_option('group_local_delivery_days', []);
		$group_local_delivery_days[$this->group_key] = $cleaned;
		update_option('group_local_delivery_days', $group_local_delivery_days);
		//error_log("group_local_delivery_days >".print_r($group_local_delivery_days,true));

    }

    public function register_hooks() {
        // Optional: register AJAX if needed for this group
        // add_action('wp_ajax_some_event_action', [$this, 'ajax_handler']);
    }

    public function ajax_handler() {
        wp_send_json_success([
            'html' => 'Dynamic content for ' . $this->group_key
        ]);
    }
}