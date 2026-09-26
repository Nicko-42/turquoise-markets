<?php
// class-turq-section-checker.php

if (!defined('ABSPATH')) exit;

class turq_Section_Checker implements turq_Settings_Section_Interface {
	
    private $turq_Market_Setup;
	private const SECTIONID = 'turq_checker';

    public function __construct($turq_Market_Setup) {
        $this->turq_Market_Setup = $turq_Market_Setup;
    }	
	
    public function get_id() {
        return self::SECTIONID;
    }

    public function get_label() {
        return 'Date Checker';
    }

    public function register_hooks() {
		add_action('woocommerce_settings_tsp_local_delivery', [$this, 'remove_save'] );        
		add_action('wp_ajax_turq_preview_change', [$this, 'turq_preview_change']);
  		add_action('wp_ajax_turq_test_set_today', [$this, 'turq_test_set_today']);		
    }

	public function remove_save() {
		global $hide_save_button;
		global $current_section;
		
		if ($current_section == self::SECTIONID) $hide_save_button = true;
	}

	public function turq_preview_change() {
		$preview_active =  $_POST['preAct'] === 'true' ? 'preview-active' : '';		

		$done = set_transient('turq_preview_active', $preview_active);
		wp_send_json_success($preview_active." = ".$done);
		
	}
	
	public function turq_test_set_today() {
		//error_log("Sent = ".print_r($_POST, true));
		$date = sanitize_text_field($_POST['date'] ?? '');
		$time = sanitize_text_field($_POST['time'] ?? '');
		$weeks = sanitize_text_field($_POST['wks'] ?? '');

		wp_send_json_success(['html' => $this->turq_test_setttings(strtotime("$date $time"),$weeks)]);
	}

	public function turq_test_setttings($today, $weeks_ahead) {
			ob_start();	

			$is_active = function($flag) { return $flag ? "active" : "inactive"; };
			
			echo wpv(['group_method_items' => $this->turq_Market_Setup->group_method_items]);

			echo "Check today - <b>".date('D d-m-y H:i', $today)." : $today</b>";
			echo "<div style='    display:flex;    gap:20px;    align-items:flex-start;    overflow-x:auto;    padding-top:20px; justify-content: center'>";			

			$groups = $this->turq_Market_Setup->multi_shipping_groups;
			foreach($groups	as $group_key => $item) {
				if (empty($item['label']) || !$item['enabled']) continue;
				?>
				<div style="width:30%;    min-width:260px;    background:#f6f7f7;    padding:12px;    border-radius:12px;">	
				<div class='group_pill' style='font-size:1.5em; margin:10px auto 20px ;background:<?= $item['background'] ?>;color:<?= $item['color'] ?>'><?= $item['label'] ?> Setup</div>

				<table class="widefat local-table" style="background:none">
					<?php
					$rows					= [];
					$rows[$item['label']]	= $item;
					$rows				   += $this->turq_Market_Setup->group_method_config[$group_key];
					foreach($rows as $method_id => $method) { 
						$class		= !($method['enabled'] ?? 0) ? "class='inactive'" : "";
						$start_active	= $today >= $method['start_date'];
						$end_active		= $today >= $method['end_date'];
						$period_active	= $start_active && !$end_active;
						$label 			= turq_Market_Setup::TARGETED_METHOD_LABELS[$method_id] ?? $method_id;
					?>
						<tr <?= $class ?>>
							<th class="<?= $is_active($period_active) ?>"><?= $label ?></th>
							<td class="<?= $is_active($start_active) ?>">Start</td>
							<td><?= $method['start_date'] === 0 || $method['start_date'] === PHP_INT_MAX ? '>> not set <<' : date('D d-m-y H:i', $method['start_date'])." : ".($method['start_date'])?></td>
							<td class="<?= $is_active($end_active) ?>">End</td>
							<td><?= $method['end_date'] === 0 || $method['end_date'] === PHP_INT_MAX ? '>> not set <<' : date('D d-m-y H:i', $method['end_date'])." : ".($method['end_date'])?></td>							
						</tr>
					<?php	
					} ?>
				</table>
				<?php
			
				$option_validation = $this->turq_Market_Setup->build_options($group_key, 'p', $today, $weeks_ahead);
				if (!empty($option_validation)) {	
					echo "<h4>Collection Markets</h4>";
					echo "<div class='turq-dash-container'>";
					foreach ($option_validation as $loc) {
						echo "<div class='turq-dash-list'><b>{$loc['location']}</b><br/>";

						$matchingKeys	= preg_grep('/^d\d+$/', array_keys($loc));													// Find all keys that match the pattern "d" followed by one or more digits
						$dElements		= array_intersect_key($loc, array_flip($matchingKeys));										// Use those keys to flip and intersect with the original data
						foreach ($dElements as $m) {
							$cut_off = is_numeric($m['cut_off']) ? date(DATEFORMAT." H:i", $m['cut_off']) : $m['cut_off'];
							echo "<span title='{$m['name']}'>".date(DATEFORMAT,$m['date'])." | $cut_off</span>";
						}
						echo "</div>";
					}
					echo "</div>";
					
					//echo wpv(compact('market_options', 'market_options_location', 'market_options_date', 'option_validation'));
				}
				
				$option_validation = $this->turq_Market_Setup->build_options($group_key, 's', $today);
				if (!empty($option_validation)) {
					echo "<h4>Delivery Dates</h4>";
					echo "<div class='turq-dash-container'>";
					foreach ($option_validation as $key => $loc) {
						echo "<div class='turq-dash-list'><b>Delivery</b><br/>";
						echo "<span title='$key'>".date(DATEFORMAT,$loc['d1']['date'])." ".$loc['d1']['slot']." | ".date(DATEFORMAT." H:i", $loc['d1']['cut_off'])."</span>";
						echo "</div>";
					}
					echo "</div>";
					
					//echo wpv(compact('delivery_options', 'option_validation'));					
				} ?>

			</div> <?php
			}
		echo "</div>";
		return ob_get_clean();
	}
	
    public function render() {
      		
		$date = date('Y-m-d', time());
		$time = $this->turq_Market_Setup->get_default_cut_off_time();
		$weeks_ahead = $this->turq_Market_Setup->get_order_setting('weeks_ahead');
		$preview_active = (get_transient('turq_preview_active') ?? "") ? "checked" : "";

		?>
		<div class="turq_woo_settings turq_s2">
			<h3>Collection / Delivery Date Checker</h3> 
			<div style="padding-bottom:20px">Set dummy "TODAY" : 
				<input type="date" id="turq_test_date_input" value="<?= esc_attr($date) ?>">
				<input type="time" id="turq_test_time_input"  value="<?= esc_attr($time) ?>">
				<input type="number" id="turq_test_weeks_input" min="1" max="10" step="1" value="<?= esc_attr($weeks_ahead) ?>"> weeks
			</div>
			<div style="padding-bottom:20px;display: flex;  justify-content: center;  align-items: center;  gap: 30px;">
				<button type="button" id="turq_test_copy" class="button button-secondary" data-type="regular">Copy Live > Preview</button>
				<label><input type="checkbox" id="turq_test_preview" value="1" <?= $preview_active ?> > Enable Preview mode</label>
				<button type="button" id="turq_test_push" class="button button-primary" data-type="event">Push Preview > Live</button>
			</div>
				
			<?php
			echo "<div id='turq_test_panel'>";
			echo $this->turq_test_setttings(strtotime("$date $time"), $weeks_ahead);
			echo "</div>";
		?>
		</div> 
		<script>
		
		jQuery(document).ready(function($){
			$('#mainform').on('keypress',function(e) {
				if (e.which == 13) return false;
			});

			$('#turq_test_preview').on('change', function(){
				const preAct = $('#turq_test_preview');
				$.ajax({
					url: '<?= admin_url('admin-ajax.php') ?>',
					method: 'POST',
					data: {
						action: 'turq_preview_change',
						preAct: $(this).is(':checked'),
					},
					error: function(xhr, status, err){
						console.error('AJAX error:', err);
					}
				});
			});
			
			
			let ajaxTimeout;

			function sendAjax() {
				const dateVal = $('#turq_test_date_input').val();
				const timeVal = $('#turq_test_time_input').val();
				const wksVal = $('#turq_test_weeks_input').val();

				if(!dateVal || !timeVal) return;

				$.ajax({
					url: '<?= admin_url('admin-ajax.php') ?>',
					method: 'POST',
					data: {
						action: 'turq_test_set_today',
						date: dateVal,
						time: timeVal,
						wks: wksVal,
					},
					success: function(response){
						if(response.success && response.data.html){
							$('#turq_test_panel').html(response.data.html);
						}
					},
					error: function(xhr, status, err){
						console.error('AJAX error:', err);
					}
				});
			}

			// Debounced input listener
			$('#turq_test_date_input, #turq_test_time_input, #turq_test_weeks_input').on('input', function(){
				clearTimeout(ajaxTimeout);
				ajaxTimeout = setTimeout(sendAjax, 300);
			});


			// Arrow key handler with hour/minute rolling + date rollover
			$('#turq_test_time_input').on('keydown', function(e){
				if(e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;

				e.preventDefault();

				let [h, m] = $(this).val().split(':').map(Number);
				const step = 1; // minutes per key press
				let rollover = 0; // 1 if forward, -1 if backward, 0 otherwise

				if(e.key === 'ArrowUp'){
					m += step;
					if(m >= 60){ 
						m = 0; 
						h += 1;
						if(h >= 24){ 
							h = 0; 
							rollover = 1; 
						}
					}
				} else {
					m -= step;
					if(m < 0){ 
						m = 59; 
						h -= 1; 
						if(h < 0){ 
							h = 23; 
							rollover = -1; 
						}
					}
				}

				$(this).val(`${h.toString().padStart(2,'0')}:${m.toString().padStart(2,'0')}`);

				if(rollover !== 0){
					let dateInput = $('#turq_test_date_input');
					let currentDate = new Date(dateInput.val());
					if(isNaN(currentDate)) currentDate = new Date();

					currentDate.setDate(currentDate.getDate() + rollover);
					// Format as YYYY-MM-DD for the date input
					const yyyy = currentDate.getFullYear();
					const mm = String(currentDate.getMonth()+1).padStart(2,'0');
					const dd = String(currentDate.getDate()).padStart(2,'0');
					dateInput.val(`${yyyy}-${mm}-${dd}`);
				}

				$(this).trigger('input');
			});
		});

		
		
		</script>
		<style>
			.turq_woo_settings.turq_s2 div {text-align:center}
			pre, pre * {text-align:left !important	}
	
			
			.turq_woo_settings td.active {color:green}
			.turq_woo_settings td.inactive {color:red}
			.turq_woo_settings th.active {background:green; color:white}
			.turq_woo_settings th.inactive {background:red; color:white}
			.turq-dash-container {display:grid;   grid-template-columns: repeat(4, 1fr);  grid-auto-rows: 1fr;  grid-column-gap: 5px;  grid-row-gap: 5px;}
			.turq-dash-list {line-height:1; padding-bottom:5px;font-family:monospace, monospace;}
			.turq-dash-list b {background-color: lightgray;  width: 100%;  display: block;  padding: 5px 0 5px 5px;  box-sizing: border-box;}
			.turq-dash-list span{cursor:pointer;display:block}
			
			.turq_woo_settings tr {background:#fff}
			.turq_woo_settings tr.inactive, .turq_woo_settings tr.inactive th {background:transparent; color:lightgray}
			.turq_woo_settings tr.inactive td {visibility:hidden}
			
		 </style>

		<?php
    }

    public function save() {
        return ;
    }

}