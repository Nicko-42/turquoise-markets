<?php
// class-turq-section-events.php

if (!defined('ABSPATH')) exit;

class turq_Section_Events extends turq_Settings_Section_Base {
	
    private $turq_Market_Setup;

    public function __construct($turq_Market_Setup) {
        $this->turq_Market_Setup = $turq_Market_Setup;
    }	
	

    public function get_id() {
        return 'turq_s1';
    }

    public function get_label() {
        return 'Event Setup';
    }

    public function register_hooks() {
        //add_action('wp_ajax_turq_test_set_today', [$this, 'ajax_test_today']);
    }

    public function render() {
		
		$groups					= $this->turq_Market_Setup->multi_shipping_groups;

		$saved_ship_events		= get_option('ship_events', turq_Market_Setup::DEFAULT_SHIP_EVENTS_ARRAY);
		$saved_targeted_methods	= get_option('targeted_methods', array());
		$all_shipping_rates		= $this->turq_Market_Setup->get_all_shipping_rates();

		$preview_active			= get_transient('turq_preview_active') ?? '';
        
		?>
		
		<div class="turq_woo_settings <?= $preview_active ?>">
			<h3>Event Setup</h3>
			<p class="description">To setup an event, first define a relevant product tag. Drag rows to set priority - for button/url 'add to cart' and Checkout.</p>
				<table class="widefat local-table" id="ship-event-table">
					<thead>
						<tr>
							<th class="drag-handle">☰</th>
							<th>ID</th>
							<th>Label</th>
							<th>Colour</th>							
							<th>Tag</th>
							<th>Start Date</th>							
							<th>End Date</th>														
							<th class="enabled">Enabled</th>
							<th style="width:50px;">Delete</th>
						</tr>
					</thead>
					<tbody class="sortable"> 
					<?php
					$i = 0; $next_event = 0;
					foreach ($saved_ship_events as $value) { 
					
						$count = count( wc_get_products([	'status'     		=> 'publish',
															'limit'      		=> -1,
															'product_tag_id'	=> [$value['tag']],	
															'stock_status'		=> 'instock',
															'catalog_visibility'=> 'visible',
															'return'     		=> 'ids',														
														]) );
					
						?>
							<tr>
								<td class="drag-handle">☰</td>
								<td><input type="hidden" name="<?= "ship_events[$i][id]"; ?>" value="<?= esc_attr($value['id']); ?>"><?= esc_attr($value['id']); ?></td>
								<td><input type="hidden" name="<?= "ship_events[$i][color]"; ?>" value="<?= esc_attr($value['color']) ?>" /><span class="group_pill" style="background-color:<?= esc_attr($value['background'])?>; color:<?= esc_attr($value['color']) ?>"><?= esc_attr($this->turq_Market_Setup->all_tags[$value['tag']] ?? "ERROR"); ?></span></td>
								<td><input type="color" name="<?= "ship_events[$i][background]"; ?>" value="<?= esc_attr($value['background']) ?>" /></td>
								<td><input type="hidden" name="<?= "ship_events[$i][tag]"; ?>" value="<?= esc_attr($value['tag']) ?>"><?= esc_attr($value['tag']) . " - $count products available" ?></td>
								<td><input type="date" name="<?= "ship_events[$i][start_date]"; ?>" value="<?= esc_attr($value['start_date']) ?>"></td>
								<td><input type="date" name="<?= "ship_events[$i][end_date]"; ?>" value="<?= esc_attr($value['end_date']) ?>"></td>
								<td><input type="checkbox" name="<?= "ship_events[$i][enabled]"; ?>" <?php echo checked($value['enabled'] ?? 0,'1',false); ?> class="enabled-checkbox" value="1" ></td>
								<td><button type="button" class="button remove-row">×</button></td>
							</tr> <?php
						$i++;
					} ?>
					</tbody>
					<?php if (!empty($this->turq_Market_Setup->unused_tags)) { ?>
						<tfoot>
							<tr>
								<td colspan="7"><button type="button" class="button button-primary" id="add-ship-event-row">+ Add Row</button></td>
								<td><input type="checkbox" name="enable-all"></td>
								<td><button type="button" class="button remove-row-all">×</button></td>						
							</tr>
						</tfoot>
					<?php } ?>
				</table>

				<div id="tag-modal" class="ti_modal wp-core-ui" style="display:none;">
					<div>
						<h2>Select a Tag</h2>
						<select id="tag-select" style="width:100%; margin-bottom: 20px;">
									<?php 
									foreach ($this->turq_Market_Setup->unused_tags as $key => $label) {
										printf(	"<option value='%s'>%s</option>",
												esc_attr($key),
												esc_html($label)
										);
									}
									?>
								</select>		
						<p style="text-align:right;">
							<button type="button" class="button button-primary confirm-modal" data-type="regular">Add as Regular</button>
							<button type="button" class="button button-primary confirm-modal" data-type="event">Add as Event</button>
							<button type="button" class="button button-secondary cancel-modal">Cancel</button>							
						</p>
					</div>
				</div> <?php

			$allowed_groups = get_option('ship_events_allowed_groups', array()); ?>

			<table class="form-table" id="allowed_groups">
				<tr valign="top">
					<th scope="row" class="titledesc" style="padding-bottom:0">
						<label>Allowed Groups</label>
					</th>
					<td style="padding-bottom:0;margin:0">
						<p class="description">Configure which products (tags) are allowed in which Event groups.</p> 
					</td>						
				</tr> <?php
			foreach ($groups as $group_key =>$item) { ?>
				<tr valign="top">
					<td style="text-align:right"><span class="group_pill" style="background-color:<?= esc_attr($item['background'])?>; color:<?= esc_attr($item['color']) ?>"><?= $item['label'] ?></span></td>
					<td>
						<div style="display:flex;gap:15px;"> <?php
						$i=2;
						foreach ($groups as $gk =>$value) {
							$checked	= in_array($value['tag_id'], $allowed_groups[$item['tag_id']] ?? []) ? " checked" : "";
							$disabled	= $item['tag_id'] == $value['tag_id'] ? " disabled" : "";
							$order		= $disabled ? '1' : $i++;
							$parent_ena	= !$value['enabled'] ? "color:red;" : ""; ?>
								<label style="<?= $parent_ena ?>display:flex;align-items:center;gap:4px;margin-top:5px;order:<?= $order ?>">
								<input type="checkbox" name="ship_events_allowed_groups[<?= $item['tag_id'] ?>][]" value="<?= esc_attr($value['tag_id']) ?>"<?= $checked.$disabled ?>><?= esc_html($value['label']) ?>
							</label>  <?php 
						} ?>
						</div>
					</td>
				</tr> <?php	
			} ?>
			</table> 
		</div> 
		
		<script>
		jQuery(document).ready(function($){

			var fixHelper = function(e, ui) {  
			  ui.children().each(function() {  
				$(this).width($(this).width());  
			  });  
			  return ui;  
			};

			const $modal1 = $('#tag-modal');
			var table1 = $('#ship-event-table tbody');
			table1.sortable({
				items: "tr:not(.not-sortable)",  helper: fixHelper, handle: '.drag-handle', placeholder: 'sortable-placeholder', axis: 'y', containment: "parent",  tolerance:'pointer', forcePlaceholderSize: true,
				}).disableSelection();

			// Add new row modal
			$('#add-ship-event-row').on('click', function() {
				$modal1.show();
			});

			// Confirm button → add row
			$('#tag-modal .confirm-modal').on('click', function() {
				const $select = $('#tag-select');
				const tagID = $select.val();
				const tagLabel = $select.find('option:selected').text();

				const index = table1.find('tr').length;
				const eventType = this.dataset.type;
				var rt;
				// Find all existing event IDs
				const eventIds = table1.find('input[name^="ship_events"][name*="[id]"]')
					.map(function() { return this.value; }) // array of values
					.get()
					.filter(val => val.startsWith(eventType));

				// Find the largest number 
				let max = 0;
				eventIds.forEach(val => {
					const num = parseInt(val.replace(eventType+'_', ''), 10);
					if (!isNaN(num) && num > max) max = num;
				});

				rt = eventType+'_' + (max + 1); // next sequential index

				var row = `<tr>
					<td class="drag-handle">☰</td>
					<td><input type="hidden" name="ship_events[${index}][id]" value="${rt}">${rt}</td>
					<td><input type="hidden" name="ship_events[${index}][color]" /><span class="group_pill" style="background-color:#000; color:#fff">${tagLabel}</span></td>
					<td><input type="color" name="ship_events[${index}][background]"  /></td>
					<td><input type="hidden" name="ship_events[${index}][tag]" value="${tagID}">${tagID}</td>												
					<td><input type="checkbox" name="ship_events[${index}][enabled]" checked value="1"/></td>
					<td><button type="button" class="button remove-row">×</button></td>
						</tr>`;
				table1.append(row);
				$modal1.hide();				
			});

			// Cancel button
			$('#tag-modal .cancel-modal').on('click', function() {$modal1.hide();});		

		});			
		</script>
		
		<script id="turq_color_picker">
		document.addEventListener('DOMContentLoaded', function () {
			document.addEventListener('input', handleColorPicker);

			function handleColorPicker(e) {

				// Only react to color inputs
				if (!e.target.matches('input[type="color"]')) return;

				const picker = e.target;
				const colorValue = picker.value;

				// Find the table row containing this picker
				const row = picker.closest('tr');
				if (!row) return;

				// Compute luminance for contrast
				const rgb = parseInt(colorValue.slice(1), 16);
				const r = (rgb >> 16) & 0xff;
				const g = (rgb >> 8) & 0xff;
				const b = rgb & 0xff;
				const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
				const newColor = luminance > 150 ? '#000' : '#fff';

				// Update the pill preview
				const pill = row.querySelector('.group_pill');
				if (pill) {
					pill.style.backgroundColor = colorValue;
					pill.style.color = newColor;
				}

				// Update the hidden input
				const hiddenInput = row.querySelector('input[type="hidden"][name*="color"]');
				if (hiddenInput) {
					hiddenInput.value = newColor;
				}
			};

		});
		</script>
		

		<?php
    }

    public function save() {
				
		// Validate : ship_events
		//--------------------------------------
		$schema = [
			'id'			=> 'sanitize_text_field',
			'tag'			=> 'sanitize_text_field',
			'color'			=> [
								'regex'   => ' /^#([A-Fa-f0-9]{3}){1,2}$/',
								'default' => '',
								],
			'background'	=> [
								'regex'   => ' /^#([A-Fa-f0-9]{3}){1,2}$/',
								'default' => '',
								],
			'start_date'	=> [
								'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
								'default' => '',
								],
			'end_date'		=> [
								'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
								'default' => '',
								],
			'enabled'		=> fn($v) => $v ? '1' : '0',			
		];

		$cleaned = array_map(
			fn($row) => is_array($row)
				? $this->sanitize_array_by_schema($row, $schema)
				: [],
			wp_unslash($_POST['ship_events'] ?? [])
		);		

		update_option('ship_events', $cleaned);
		//error_log("ship_events >".print_r($cleaned,true));

		

		// Validate : ship_events_allowed_groups
		//--------------------------------------
		$valid_ids		= array_map('intval',array_column($this->turq_Market_Setup->multi_shipping_groups, 'tag_id'));
		$valid_lookup	= array_flip($valid_ids);
		$cleaned		= [];

		foreach ($_POST['ship_events_allowed_groups'] ?? [] as $group_id => $allowed) {

			$group_id = (int) $group_id;

			// Skip invalid parent groups
			if (!isset($valid_lookup[$group_id])) continue;

			$allowed   = (array) $allowed;
			$allowed   = array_map('intval', $allowed);
			$allowed   = array_intersect($allowed, $valid_ids);
			$allowed[] = $group_id;
			$allowed   = array_values(array_unique($allowed));

			$cleaned[$group_id] = $allowed;
		}
		// check self only
		foreach ($valid_ids as $id) if (!isset($cleaned[$id])) $cleaned[$id] = [$id];

		update_option('ship_events_allowed_groups', $cleaned);			
		
	}

    //public function ajax_test_today() {

}