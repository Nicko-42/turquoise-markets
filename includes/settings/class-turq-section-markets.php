<?php
// class-turq-section-markets.php

if (!defined('ABSPATH')) exit;

class turq_Section_Markets extends turq_Settings_Section_Base {
	
    private $turq_Market_Setup;

    public function __construct($turq_Market_Setup) {
        $this->turq_Market_Setup = $turq_Market_Setup;
    }	
	

    public function get_id() {
        return 'turq_s3';
    }

    public function get_label() {
        return 'Market Settings';
    }

    public function register_hooks() {
    }

    public function render() {
      
		?>
		<div class="turq_woo_settings">
			<h3>Market Settings</h3>
			<table class="form-table">

				<?php $value = get_option('default_cut_off_time', '');?>						
				<tr>
					<th scope="row"><label for="default_cut_off_time">Cut-off Time</label></th>
					<td>
						<input type="time" name="default_cut_off_time" id="default_cut_off_time" value="<?= esc_attr($value) ?>">
						<p class="description">This will be applied to all cut off days.</p>											
					</td>
				</tr>			

				<?php $value = get_option('default_cut_off_day', '');?>
				<tr>
					<th scope="row"><label for="default_cut_off_day">Default Cut-off Day</label></th>
					<td>
						<div style="display:flex;gap:15px;"> <?php
							$first = true;
							foreach (turq_Market_Setup::WEEKDAYS as $label) {
								$required = $first ? 'required' : '';
								$checked  = ($label == $value) ? 'checked' : '';
								echo '
									<label style="display:flex;align-items:center;gap:4px;margin-top:5px">
										<input type="radio" name="default_cut_off_day" value="'.esc_attr($label).'" '.$checked.' '.$required.'>'.esc_html($label).'
									</label>
								';
								$required = false;
							} ?>
						</div>
						<p class="description">This day will be used if no cut off is given for delivery or collection options.</p>												
					</td>
				</tr>
		
			</table>			

			<script>
				window.globalMarketData = <?= wp_json_encode(array_values(get_option('global_extra_closed_dates', []))); ?>;
			</script>

			<h3>Market Closed dates</h3>
			<p class="description">Drag to order. These dates affect all markets. A note will automatically appear under the summary table if 'today' is between the start date and the end of the closed day.</p>
			<table class="widefat local-table" id="global-closed-table">
				<thead>
					<tr>
						<th class="drag-handle">☰</th>
						<th>Label</th>
						<th>Closed Date</th>
						<th>Notice Start</th>							
						<th class="enabled">Enabled</th>
						<th style="width:50px;">Delete</th>
					</tr>
				</thead>
				<tbody class="sortable"> 
					<template id="global-closed-row-template">				
						<tr>
							<td class="drag-handle">☰</td>
							<td>
								<input type="hidden" name="global_extra_closed_dates[{{i}}][id]" value="{{id}}">
								<input type="text" name="global_extra_closed_dates[{{i}}][label]" value="{{label}}">
							</td>
							<td><input type="date" name="global_extra_closed_dates[{{i}}][closed_date]" value="{{closed_date}}" ></td>
							<td><input type="date" name="global_extra_closed_dates[{{i}}][notice_start]" value="{{notice_start}}" ></td>
							<td><input type="checkbox" name="global_extra_closed_dates[{{i}}][enabled]" class="enabled-checkbox" value="1"></td>							
							<td><button type="button" class="button remove-row">×</button></td>
						</tr>
					</template>
				</tbody>
				<tfoot>
					<tr>
						<td colspan="3"><button type="button" class="button button-primary" id="add-closed-date-row">+ Add Row</button></td>
						<td><div style="display:flex"><input type="date" data-target="notice_start" class="apply-all-date" style="margin:0 5px 0 0;padding: 0 8px"><button type="button" class="apply-all-btn button-secondary">Apply</button></div></td>						
						<td><input type="checkbox" name="enable-all"></td>
						<td><button type="button" class="button remove-row-all">×</button></td>
					</tr>
				</tfoot>				
			</table>

		</div> 
		<script>
		jQuery(document).ready(function($){					

			const $table  = $('#global-closed-table');
			const $tbody1 = $table.find('tbody');
			const template1 = $('#global-closed-row-template').html();
		
			$tbody1.sortable({
				items: "tr",  helper: fixHelper, handle: '.drag-handle', placeholder: 'sortable-placeholder', axis: 'y', containment: "parent",  tolerance:'pointer', forcePlaceholderSize: true,
				}).disableSelection();

			// Render all initial rows
			function renderInitialRows1() {
				if (Array.isArray(window.globalMarketData) && window.globalMarketData.length > 0) {
					window.globalMarketData.forEach((item, i) => {
						const rowHtml = renderRow({
							i: i,
							id: item.id,
							label: item.label,
							closed_date: item.closed_date || '',
							notice_start: item.notice_start || '',						
						}, template1);
						// post process row to set checkbox state to checked
						const $row = $(rowHtml);
						if(item.enabled == 1)	$row.find('.enabled-checkbox').prop('checked', true);
						else					$row.find('.enabled-checkbox').prop('checked', false);						
						$tbody1.append($row);
					});
				};
			}

			renderInitialRows1();

			// Add new row modal
			$('#add-closed-date-row').on('click', function() {
				const index = $tbody1.find('tr').length;
				const rowHtml = renderRow({
					i: index,
					id: [...crypto.getRandomValues(new Uint8Array(4))].map(b => b.toString(16).padStart(2, '0')).join(''),					
					label: '',
					closed_date: '',
					notice_start: '',						
					enabledChecked: 'checked'
				}, template1);

				// post process row to set checkbox state to checked
				const $row = $(rowHtml);
				$row.find('.enabled-checkbox').prop('checked', true);				
				
				$tbody1.append($row);
			});
		
		});			
		</script>
		<?php
    }


    public function save() {
		// Validate : default_cut_off_time
		//--------------------------------------
		$time = $_POST['default_cut_off_time'] ?? null;
		if ($time !== null) {
			$dt = DateTime::createFromFormat('H:i', $time);

			if (!$dt || $dt->format('H:i') !== $time) {
				$time = null;
			}
			update_option('default_cut_off_time', $time);
		}

		
		// Validate : default_cut_off_day
		//--------------------------------------
		$day = $_POST['default_cut_off_day'] ?? '';
		if (!empty($day)) {

			// whitelist against allowed weekday keys
			if (!in_array($day, turq_Market_Setup::WEEKDAYS)) {
				$day = ''; // invalid / tampered value
			}
			update_option('default_cut_off_day', $day);
		}
		
		
		// Validate : global_extra_closed_dates
		//--------------------------------------
		$schema = [
			'id'			=> 'sanitize_text_field',
			'label'			=> 'sanitize_text_field',
			'closed_date'	=> [
								'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
								'default' => '',
								],
			'notice_start'	=> [
								'regex'   => '/^\d{4}-\d{2}-\d{2}$/',
								'default' => '',
								],
			'enabled'   => fn($v) => $v ? '1' : '0',
		];
		
		$cleaned = array_map(
			fn($row) => is_array($row)
				? $this->sanitize_array_by_schema($row, $schema)
				: [],
			wp_unslash($_POST['global_extra_closed_dates'] ?? [])
		);

		update_option('global_extra_closed_dates', $cleaned);
		//error_log("global_extra_closed_dates >".print_r($cleaned,true));
	
		
	}
	
}