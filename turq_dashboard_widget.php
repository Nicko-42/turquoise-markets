<?php
/*******************************************************************************
 * Admin Dashboard Widget for Woo products
 * 
 * turq_dashboard_widget.php
 * 
*******************************************************************************/
//do_action( 'qm/debug', );
//$log = new WC_Logger();$log_entry = 'Order: ' . print_r( wc_get_order(2921), true );$log->log( 'TURQUOISE', $log_entry );

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


add_action( 'wp_dashboard_setup', function() {

	$groups			= turq_Market_Setup()->get_multi_shipping_groups();
	$active_groups	= turq_Market_Setup()->get_active_shipping_groups();	
	foreach($active_groups	as $id => $group_key) {
	wp_add_dashboard_widget( "turq_market_$group_key", "Options for {$groups[$group_key]['label']}", 'turqmarkets_group_widget', '', $groups[$group_key]);
	}
	wp_add_dashboard_widget( 'turq_market_3', 'Regular Click&Collect Items', 'turqOTV_dashboard_widget', '', '');	

	wp_add_dashboard_widget( 'turq_market_4', 'Local Delivery Reports as of '.date(DATEFORMAT.' @ h:i', TODAY), 'turq_deliver_report', '', '');
	wp_add_dashboard_widget( 'turq_market_2', 'Collection Market Reports as of '.date(DATEFORMAT.' @ h:i', TODAY), 'turq_collect_report', '', '');	
});

add_action('admin_footer', function(){
    $screen = get_current_screen();
	//error_log("dash-screen>".print_r($screen, true));

	if ( $screen && ($screen->id === 'dashboard')) {		

		$groups			= turq_Market_Setup()->get_multi_shipping_groups();
		$active_groups	= turq_Market_Setup()->get_active_shipping_groups();	

		$widget_style = "";
		foreach($active_groups	as $id => $group_key) {
			$widget_style .= "#turq_market_$group_key .postbox-header{background:{$groups[$group_key]['background']};} ";
			$widget_style .= "#turq_market_$group_key .postbox-header h2 {color:{$groups[$group_key]['color']};} ";			
		  //$widget_style .= "#turq_market_$group_key h4 {padding: 5px;text-align: center;background:{$groups[$group_key]['background']}; color:{$groups[$group_key]['color']};} ";						
		} ?>
		
		<style>
			div[id^='turq_market'] .postbox-header{background-color:#9fb783}
			div[id^='turq_market'] .postbox-header h2 {color:#fff}
			<?= $widget_style ?>
			#dashboard-widgets div[id^='turq_market'] h4 {padding: 5px;text-align: center;background: #e8e8e8;}
			.turq_market_widget_footer{margin-top:10px;margin-bottom:-7px;border-top:1px dotted grey;color:grey}
			.turq-dash-order-debug select option{font-family:monospace, monospace;}
			.turq-dash-order-debug {background: #fce9ed;padding: 0 10px;margin-bottom: 10px;border: 1px solid pink;	border-radius: 5px;}
			
			.turq-dash-container {display:grid;   grid-template-columns: repeat(4, 1fr);  grid-auto-rows: 1fr;  grid-column-gap: 5px;  grid-row-gap: 5px;}
			.turq-dash-list {line-height:1; padding-bottom:5px;font-family:monospace, monospace;}
			.turq-dash-list span{cursor:pointer;display:block}		
			.radioGroupBelow {font-family:monospace, monospace;}				
			.turq-dash-list b {background-color: lightgray;  width: 100%;  display: block;  padding: 5px 0 5px 5px;  box-sizing: border-box;}
			.turq-dash-radio {display: flex; align-items: center;justify-content: space-evenly}
			.radioGroupBelow label {display: inline-block; text-align: center; margin: 0 0.2em; }
			.radioGroupBelow label input[type='checkbox'] {display: flex; flex-direction: column; margin: 21px auto 2px;}
			.turq_server_message {flex-grow:1;display:inline-flex;align-items:center;align-self:center}
			.turq_server_message a {text-decoration:none}
			.turq_report_status {border-collapse: collapse; margin:15px 0; width:100%}
			.turq_report_status * {font-size:15px}
			.turq_report_status .turq_admin_button {margin: 0 10px}
			.turq_report_status .spinner {margin-top:0;align-self:center}
			#turq_market table th {border-bottom: 1px solid lightgrey}
			#turq_market table tr {text-align: center}
			#turq_market table tr *:first-of-type {text-align: left}		
			
			.local-table td.active {color:green}
			.local-table td.inactive {color:red}
			.local-table th.active {background:green; color:white}
			.local-table th.inactive {background:red; color:white}
			.local-table tr.inactive, .local-table tr.inactive th {background:transparent; color:lightgray}
			.local-table tr.inactive td {visibility:hidden}
			
			
		</style> <?php
	}
});

function widget_footer() { ?>
	<div class="turq_market_widget_footer">
		&copy; <?= date("Y") ?> Nick Oakes, widget under license from Turquoise Internet
	</div> <?php
}

function turqmarkets_group_widget( $post, $callback_args ) {
	
	$group_key = $callback_args['args']['group_key'];
	$user_id   = get_current_user_id();
	
	?>
	<table class="widefat local-table" style="background:none">
		<?php
		
		$is_active = function($flag) { return $flag ? "active" : "inactive"; };		

		$groups					= turq_Market_Setup()->get_multi_shipping_groups();	
		$options				= turq_Market_Setup()->get_shipping_method_options();

		$item					= $groups[$group_key];
		$rows					= [];
		$rows[$item['label']]	= $item;
		$rows				   += turq_Market_Setup()->group_method_config[$group_key];
		foreach($rows as $method_id => $method) { 
			if ( !($method['enabled'] ?? 0) ) continue;
			$start_active	= TODAY >= $method['start_date'];
			$end_active		= TODAY >= $method['end_date'];
			$period_active	= $start_active && !$end_active;
			$label 			= $options[$method_id] ?? $method_id;
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

	foreach (turq_Market_Setup()->group_method_config[$group_key] as $method => $config) {
		$option_validation = turq_Market_Setup()->get_option_validation($group_key, $method);

		if (empty($option_validation)) {
			if (turq_Market_Setup()->get_method_config($group_key, $method)['enabled']) echo "No valid {$methods[$method]['title']} found.<br>";
			continue;
		}

		// Cache the validation data
		set_transient("turq_dash_options_validation_{$group_key}_{$method}_{$user_id}", $option_validation, HOUR_IN_SECONDS);

		echo "<h4>".turq_Market_Setup()->get_method_setup($method, 'method_description')."</h4>";
		echo "<div class='turq-dash-container'>";

		foreach ($option_validation as $key => $loc) {
			echo "<div class='turq-dash-list'>";
			
			if ($method === 'p') {
				echo "<b>{$loc['location']}</b><br/>";

				$matchingKeys	= preg_grep('/^d\d+$/', array_keys($loc));													// Find all keys that match the pattern "d" followed by one or more digits
				$dElements		= array_intersect_key($loc, array_flip($matchingKeys));										// Use those keys to flip and intersect with the original data
				foreach ($dElements as $m) {
					$cut_off = is_numeric($m['cut_off']) ? date(DATEFORMAT, $m['cut_off']) : $m['cut_off'];
					echo "<span title='{$m['name']}'>" . date(DATEFORMAT, $m['date']) . " | $cut_off</span>";
				}
			} else {
				echo "<b>Delivery</b><br/>";
				echo "<span title='$key'>" . date(DATEFORMAT, $loc['d1']['date']) . " {$loc['d1']['slot']} | " . date(DATEFORMAT, $loc['d1']['cut_off']) . "</span>";
			}
			
			echo "</div>";
		}
		echo "</div>";
/*
		printf(
			"<div class='turq-dash-order-debug' data-group='%s' data-method='%s' style='display:flex;align-items:center;justify-content:space-evenly;'>",
			esc_attr($group_key),
			esc_attr($method)
		);
			echo "<div class='turq_shipping_container' style='display:flex'>";
			echo render_options($method, $group_key);
			echo "</div>";

			woocommerce_form_field('ti_oqty_' . $group_key, [
				'type'     => 'select',
				'class'    => ['form-row-wide', 'ti_oqty'],
				'required' => true,
				'options'  => array_combine(range(0, 10), ['Qty', 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]),
			]);

			echo "<span class='turq_dgo turq_admin_button button-secondary' style='margin:1em 0'>Build Orders</span>";
			echo "<span class='spinner'></span><span class='turq_server_message'></span>";
		echo "</div>";
*/		
	}

	?>
	<table id="turq_reports_<?= $group_key ?>" class="turq_report_status">
		<tr>
			<td>
				<div style="display:flex;justify-content:center;margin-top:20px;align-items:flex-start;">
					<span class="turq_report turq_admin_button button-secondary">Generate Reports</span>
					<span class="turq_clear turq_admin_button button-secondary" style="visibility: hidden">Clear</span>					
					<span class="spinner"></span>
					<span class="turq_server_message"><?= $out ?></span>
				</div>
			</td>
		</tr>
	</table>			
	<?php
	
	widget_footer();		

	?><script id="turqtick_dashboard_widgets_debug">
	(function ( $, window ) {
		
			$( '#turq_reports_<?= $group_key ?> .turq_report' ).on( 'click', function(e){ get_edit($(this), true);});

			function get_edit(t, o) {
				if (t.hasClass('disabled')) return;
		
				var $m = t.closest('table');
				$m.find('.spinner').addClass( 'is-active' );
				$m.find('.turq_admin_button').addClass( 'disabled' );
				$m.find('.turq_server_message').html('Processing');		
				$.ajax( {
					type: 'POST',
					url: '<?php echo admin_url( 'admin-ajax.php' )?>',
					data: {
						action      : 'turq_get_group_report',
						groupKey	: '<?= $group_key ?>',
						security    : '<?php echo wp_create_nonce( 'turq-ajax-verification' )?>'
					},
					dataType: 'json',
					success: function( response ) {
						var fromServer = response.data;				
						if ( response && response.success ) {
							$m.find('.turq_server_message').html(fromServer);
							reset_form($m);
						} else {
							clear_form($m);				    
							$m.find('.turq_server_message').html(fromServer);
							//window.console.log( response );
						}
						$m.find( '.spinner' ).removeClass( 'is-active' );
					}
				} ).fail( function( response ) {
					clear_form($m);
					alert( 'Request Failed, please reload and try again' );
					window.console.log( response );
				} );
				
				function clear_form($m){
					$m.find('.turq_server_message').html('');
					reset_form($m);
				}

				function reset_form($m){		
					$m.find( '.spinner' ).removeClass( 'is-active' );
					$m.find('.turq_admin_button').removeClass( 'disabled' );
				}
				
			}
			
		
		// debug order creation JS
		$('#dashboard-widgets-wrap').find('.turq_shipping_container').each(function(){
			$(this).on('change', function(e) {
				const $market  = $(this).find('.ti_market select');
				
				if (e.target != $market[0]) return;
				
				const marketVal  = $market.val();
				const $dateSelect = $(this).find('.ti_date select');
				const ti_dates   = $dateSelect.data('ti_dates_data');
					
				if (marketVal){
					const optionsArray = [
							{ v: '', o: $dateSelect.data('placeholder') },
							...(ti_dates[marketVal] || [])
						];
					
					$dateSelect.html(optionsArray.reduce((acc,opt)=>acc+`<option value="${opt.v}">${opt.o}</option>`,""));

					// auto-select if only one valid
					const valid = optionsArray.filter(o=>o.v.trim()!=="");
					if(valid.length===1){
						$dateSelect.val(valid[0].v);
					}
				}
			});
		});
		
		
		$( '.turq_dgo' ).click(function() {
			if ($(this).hasClass('disabled')) return;
			
			$(this).addClass( 'disabled' );
			const $section = $(this).closest('.turq-dash-order-debug');
			$section.find('.spinner').addClass( 'is-active' );
			$section.find('.turq_server_message').html('');
		
			const data = {};
			data.group = $section.data('group');
			data.method = $section.data('method');
			$section.find('select').each(function(){ data[this.name] = $(this).val(); });

			$.ajax( {
				type: 'POST',
				url: '<?php echo admin_url( 'admin-ajax.php' )?>',
				data: {
					action      : 'turq_generate_debug',
					data		: data,
					security    : '<?php echo wp_create_nonce( 'turq-ajax-verification-dbg' )?>'
				},
				dataType: 'json',
				success: function( response ) {
					var fromServer = response.data;				
					$section.find('.turq_server_message').html(fromServer);
					reset_form($section);
				}
			} ).fail( function( response ) {
				clear_form($section);
				alert( 'Request Failed, please reload and try again' );
				window.console.log( response );
			} );
		});
		function reset_form($section) {		
			$section.find('.turq_dgo').removeClass( 'disabled' );
			$section.find('.spinner').removeClass( 'is-active' );					
		}
		function clear_form($section) {
			reset_form();
			$section.find('.turq_server_message').html('');
		}
	})( jQuery, window );</script><?php
}

/**
 * Ajax callback function.
 */
 add_action( 'wp_ajax_turq_get_group_report', function() {
    check_ajax_referer( 'turq-ajax-verification', 'security' );
	
	if (!isset($_POST['groupKey']) ) wp_send_json_error('Error - Sent Data syntax [1]');

	$group_key	= sanitize_text_field($_POST['groupKey']);	
	$lookup		= turq_Market_Setup()->get_group_lookup($group_key);
	if (!$lookup) wp_send_json_error( 'Error - Sent Data syntax [2]');
	
	$label		= $lookup['label'];

	ob_start();

	foreach (turq_Market_Setup()->group_method_config[$group_key] as $method_id => $method) {	

		if ( !($method['enabled'] ?? 0) ) continue;
	
		if ($method_id == 'p') {
			$order_count = 0;			
			$orders = wc_get_orders([
				'limit'        => -1,
				'type'         => 'shop_order',
				'post_status'  => turq_Market_Setup()->get_order_setting('report_order_status') ?? [],
				'meta_query'   => [
					'relation' => 'AND',
					[ 'key' => '_turq_local_option_method_name', 'value' => 'p' ],
					[ 'key' => '_turq_local_option_tag_id',      'value' => $lookup['tag_id'] ],					
					[ 'key' => '_turq_local_option_date',        'value' => TODAY, 'compare' => '>=', 'type' => 'NUMERIC' ],
				],
			]);	

			if ( empty( $orders ) ) continue;	

			// Filter orders on package and group by cut off then pickup location
			$pickup_orders	= [];
			$pickup_pkgmeta	= [];
			$pickup_labels	= [];
			$process_order	= [];

			foreach ( $orders as $order ) {
				$pkg_count = $order->get_meta('_turq_package_count');
				if (empty($pkg_count)) continue; // check to see if order has been processed with our correct meta added. Count is also flag, 0 or '' = empty

				foreach ($order->get_shipping_methods() as $id => $shipping_method) {	
					$package_meta = $shipping_method->get_meta('_turq_package_data');
					if ($package_meta['_turq_local_option_method_name'] != 'p') continue;
					
					if ($pkg_count == 1 || (
						$package_meta['_turq_local_option_tag_id']	== $lookup['tag_id'] &&
						$package_meta['_turq_local_option_date']	>= TODAY)) {

							$order_count++;
							$co_key									= $package_meta['_turq_local_option_cut_off'];
							$loc_key								= $package_meta['_turq_local_option_market_id'].'-'.$package_meta['_turq_local_option_date'];

							$pickup_orders[$co_key][$loc_key][$id]	= $order;
							$pickup_pkgmeta[$co_key][$loc_key][$id]	= $package_meta;
							$pickup_labels[$co_key][$loc_key]		= $package_meta['_turq_local_option'];
							$process_order[$co_key][$loc_key]		= $package_meta['_turq_local_option_date'];
					}
				}
			}
			?>
			<table style="width:100%" cellspacing="0">			
				<tbody>
				<?php

					foreach ( $process_order as $co => $loc_order ) {

						asort($loc_order);

						// flatten to only actual order data
						$gen_orders			= array_replace(...array_values($pickup_orders[$co]));
						$gen_orders_meta	= array_replace(...array_values($pickup_pkgmeta[$co]));

						$file_token = generate_report( 'buy', $label,  $gen_orders, $gen_orders_meta);
						
						?>
						<tr style="background-color:#ccc">
							<td>Week following</td>
							<td><?= esc_html( date(DATEFORMAT, $co) ) ?></td>
							<td><?= count($gen_orders) ?></td>
							<td>
								<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
									<?= turq_download_icon() ?>
								</a>
							</td>
						</tr>
						<?php


						foreach ( $loc_order as $loc_key => $value ) :
							$gen_orders	= $pickup_orders[$co][$loc_key];
							$file_token = generate_report( 'pickup', $label.'-'.$pickup_labels[$co][$loc_key], $gen_orders, $pickup_pkgmeta[$co][$loc_key] );
							?>
							<tr>
								<td></td>
								<td><?= esc_html( $pickup_labels[$co][$loc_key] ) ?></td>
								<td><?= count($gen_orders) ?></td>
								<td>
								<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
										<?= turq_download_icon() ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>


						<?php
						$file_token = generate_report( 'pickup', 'all-'.$label, $gen_orders, $gen_orders_meta);
						?>
						<tr><td colspan="4" style="height:10px"></td></tr>
						<tr>
							<td></td>
							<td style="text-align:right;border-top:3px double lightgrey">
								Combined picking&nbsp;
							</td>
							<td style="border-top:3px double lightgrey">
								<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['csv']}" ) ?>">
									<?= turq_download_icon('green') ?>
								</a>
							</td>
							<td style="border-top:3px double lightgrey">
								<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
									<?= turq_download_icon() ?>
								</a>
							</td>
						</tr>
						<tr><td>&nbsp;</td></tr> <?php
					} ?>
				</tbody>
			</table>
			<?php
		}

		if ($method_id == 's') { 
			$order_count = 0;			
			$orders = wc_get_orders([
				'limit'        => -1,
				'type'         => 'shop_order',
				'post_status'  => turq_Market_Setup()->get_order_setting('report_order_status') ?? [],
				'meta_query'   => [
					'relation' => 'AND',
					[ 'key' => '_turq_local_option_method_name', 'value' => 's' ],
					[ 'key' => '_turq_local_option_tag_id',      'value' => $lookup['tag_id'] ],					
					[ 'key' => '_turq_local_option_date',        'value' => TODAY, 'compare' => '>=', 'type' => 'NUMERIC' ],
				],
			]);	

			if ( empty( $orders ) ) continue;			
			
			// Filter orders on package and group by delivery date			
			$all_orders			= array();
			$delivery_orders	= [];
			$delivery_pkgmeta	= [];	
			$process_order		= [];
			
			foreach ( $orders as $order ) {
				$pkg_count = $order->get_meta('_turq_package_count');
				if (empty($pkg_count)) continue; // check to see if order has been processed with our correct meta added. Count is also flag, 0 or '' = empty

				foreach ($order->get_shipping_methods() as $id => $shipping_method) {	
					$package_meta = $shipping_method->get_meta('_turq_package_data');
					if ($package_meta['_turq_local_option_method_name'] != 's') continue;
					
					if ($pkg_count == 1 || (
						$package_meta['_turq_local_option_tag_id']	== $lookup['tag_id'] &&
						$package_meta['_turq_local_option_date']	>= TODAY)) {

							$order_count++;
							$del_key  						= $package_meta['_turq_local_option_date'].'-'.$package_meta['_turq_local_option_slot'];

							$delivery_orders[$del_key][]	= $order;
							$delivery_pkgmeta[$del_key][]	= $package_meta;
							$process_order[$del_key]		= $package_meta['_turq_local_option_date'];
					}
				}
			}

			$all_orders			= array_replace(...array_values($delivery_orders));
			$all_orders_meta	= array_replace(...array_values($delivery_pkgmeta));
			$file_token			= generate_report( 'buy', $label, $all_orders,  $all_orders_meta);			
			
			?>
			<table style="width:100%" cellspacing="0">			
				<tbody>
					<tr style="background-color:#ccc">
						<td>Delivery</td>
						<td>&nbsp;</td>
						<td><?= $order_count ?></td>
						<td>
							<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
								<?= turq_download_icon() ?>
							</a>
						</td>
					</tr>
				
				<?php
					asort($process_order);

					foreach ( $process_order as $co => $loc_order ) {
						
						
						
						
					}
		
		
		
		}
	}

	$html = ob_get_clean();

	if ( $order_count === 0 ) wp_send_json_error( 'No orders found for '.$group );

	wp_send_json_success( $html );
	
});

add_action( 'wp_ajax_turq_generate_debug', function() {
    check_ajax_referer( 'turq-ajax-verification-dbg', 'security' );
	
	if (!isset($_POST['data']) || !is_array($_POST['data'])) wp_send_json_error("Error - Sent Data syntax [1]");

	$active_groups	= turq_Market_Setup()->get_active_shipping_groups();
	$data			= $_POST['data'];
	$group_key		= get_post_value($data, 'group');

	if ($group_key === null || !in_array($group_key, $active_groups)) wp_send_json_error("Error - Sent Data syntax [2]");
	
	$method		= get_post_value($data, 'method');
	$user_id	= get_current_user_id();
	$dd			= get_transient("turq_dash_options_validation_{$group_key}_{$method}_{$user_id}");

	if (empty($dd)) wp_send_json_error("Error - Sent Data syntax [3]");
	set_transient('turq_options_validation_'.get_current_user_id(), $dd, 30);

	$options = [];
	foreach($data as $k => $v) if(str_ends_with($k, "_$group_key")) $options[str_replace("_$group_key", "", $k)] = sanitize_text_field($v);

	error_log(dpv(compact('active_groups', 'data', 'group_key', 'options', 'dd')));	

	$qty = $options['ti_oqty'] ?? null;
	if (!$qty) wp_send_json_error("Error - Sent Data syntax [4]");

	if ($method == 'p') {
		$market_id = $options['ti_market'] ?? null;
		if (!$market_id) wp_send_json_error("Error - Sent Data syntax [5.1]");
		$market = $dd[$market_id];
		
		$date_option = $options['ti_date'] ?? null;		
		if (!$date_option) wp_send_json_error("Error - Sent Data syntax [5.2]");

		$date	= $market[$date_option];				
		$place	= $market['location'];
		$date	= $date['date'];

		$done	= build_orders($group_key, $method, $options, abs($qty));
	
		if ($done) wp_send_json_success("$place : ".date('d-m-y', $date)." x{$qty}");
		else wp_send_json_error("Error - Cannot create orders");
	}

	if ($method == 's') {
		$delivery_id = $options['turq_local_option'] ?? null;
		if (!$delivery_id) wp_send_json_error("Error - Sent Data syntax [5.1]");
		$delivery = $dd[$delivery_id];

		$done	= build_orders($group_key, $method, $options, abs($qty));
	
		if ($done) wp_send_json_success("Orders created x{$qty}");
		else wp_send_json_error("Error - Cannot create orders");
	}

	wp_send_json_error("Error - Invalid Method");
});

function build_orders($group_key, $method_name, $post, $qty) {
	static $available_products;
	$available_products ??= get_all_products();

	$targeted_methods	= turq_Market_Setup()->get_targeted_methods();
	$chosen_method = current(array_keys($targeted_methods, $method_name));
	
	set_error_handler(function($severity, $message) { throw new ErrorException($message); });		// catch ANY error or warning
	$status = false;

	try {
		for ($o=0; $o<$qty; $o++) {

			$customer_id	= get_current_user_id();
			$customer		= new WC_Customer( $customer_id  );	

			$order = wc_create_order([
				'created_via' => 'debug', 
				'customer_id' => $customer_id,
			]);

		// add products
			for ($p=0; $p < rand(5,10); $p++) {
				$product = wc_get_product( $available_products[mt_rand(0, count($available_products)-1)] );
				if (!$product) continue; //protect against rogue 0's....!
				$order->add_product( $product, mt_rand(1, mt_rand(1, 5)) );		
			}

		// add shipping
			[$mid, $iid] = explode(':', $chosen_method);
			$shipping = new WC_Order_Item_Shipping();
			$shipping->set_method_title( ucwords(str_replace('_', ' ', $mid)) );
			$shipping->set_method_id( $mid );
			$shipping->set_instance_id( $iid );
			$shipping->set_total( 0 ); // optional
			$order->add_item( $shipping );

		// add billing and shipping addresses
			$order->set_billing_address($customer->get_billing());
			$order->set_shipping_address($customer->get_shipping());			

		// add payment method
			//$order->set_payment_method( 'stripe' );
			//$order->set_payment_method_title( 'Credit/Debit card' );

		// order status
			$order->set_status( 'on-hold', 'Order is created programmatically' );

		// add meta values
			set_order_turq_options('', $chosen_method, $post, $order);

		// calculate and save
			$order->calculate_totals();
			$order->save();
		}
		
		$status = true;
	}
	catch (Throwable $e) {
		error_log(dpv(['ERROR' => $e]));
		$status = false;
	}

	restore_error_handler();				// Restore the original handler
	return $status;
}

// DELIVER reports
// ---------------
function turq_deliver_report( $post, $callback_args ) {
    $details    = $callback_args['args'];
	
	//$st_date	= strtotime('-1 year', TODAY);
	$st_date	= strtotime('-1 month	', TODAY);
	$orders		= wc_get_orders([
					'limit' => -1,
					'post_status' => turq_Market_Setup()->get_order_setting('report_order_status') ?? [],
					'type' => 'shop_order',
					'date_created'  => $st_date.'...'.TODAY,
					'meta_query' =>	['relation' => 'AND',
										[ 'key' => '_turq_local_option_method_name', 'value' => 's', 'compare' => '='],
										[ 'key' => '_turq_local_option_date', 'value' => $st_date, 'compare' => '>='],
									],
					'return' => 'ids',
				]);
	
	echo "Processing all DELIVERY orders[".count($orders)."] from ".date(DATEFORMAT, $st_date);
	?>
	
	<table id="turq_deliver_report" class="turq_report_status">
		<tr>
			<td>
				<div style="display:flex;justify-content:center;margin-top:20px;align-items:flex-start;">
					<span class="turq_report turq_admin_button button-secondary">Generate Reports</span>
					<span class="turq_clear turq_admin_button button-secondary" style="visibility: hidden">Clear</span>					
					<span class="spinner"></span>
					<span class="turq_server_message"><?= $out ?></span>
				</div>
			</td>
		</tr>
	</table>	
	
	<?php

    widget_footer();
	
	?>
	<script id="turqtick_dashboard_deliver">
		(function ( $, window ) {
			
			$( '#turq_deliver_report .turq_report' ).on( 'click', function(e){ get_edit($(this), true);});

			function get_edit(t, o) {
				if (t.hasClass('disabled')) return;
		
				var $m = t.closest('table');
				$m.find('.spinner').addClass( 'is-active' );
				$m.find('.turq_admin_button').addClass( 'disabled' );
				$m.find('.turq_server_message').html('Processing');		
				$.ajax( {
					type: 'POST',
					url: '<?php echo admin_url( 'admin-ajax.php' )?>',
					data: {
						action      : 'turq_get_report_deliver',
						security    : '<?php echo wp_create_nonce( 'turq-ajax-verification' )?>'
					},
					dataType: 'json',
					success: function( response ) {
						var fromServer = response.data;				
						if ( response && response.success ) {
							$m.find('.turq_server_message').html(fromServer);
							reset_form($m);
						} else {
							clear_form($m);				    
							$m.find('.turq_server_message').html(fromServer);
							//window.console.log( response );
						}
						$m.find( '.spinner' ).removeClass( 'is-active' );
					}
				} ).fail( function( response ) {
					clear_form($m);
					alert( 'Request Failed, please reload and try again' );
					window.console.log( response );
				} );
			}
			
			function clear_form($m){
				$m.find('.turq_server_message').html('');
				reset_form($m);
			}

			function reset_form($m){		
				$m.find( '.spinner' ).removeClass( 'is-active' );
				$m.find('.turq_admin_button').removeClass( 'disabled' );
			}
			
			$(".turq_clear").click(function(){
				if ($(this).hasClass('disabled')) return;
			})

			
		})( jQuery, window );
	</script><?php	
}

/**
 * Ajax callback function.
 */
 add_action( 'wp_ajax_turq_get_report_deliver', function() {
    check_ajax_referer( 'turq-ajax-verification', 'security' );
	
	ob_start();

	$all_total			= 0;
	$all_orders			= array();
	$delivery_orders	= [];
	$delivery_pkgmeta	= [];	
	$process_order		= [];

	//$st_date	= strtotime('-1 year', TODAY);
	$st_date	= strtotime('-1 month', TODAY);

	$orders		= wc_get_orders([
					'limit' => -1,
					'post_status' => turq_Market_Setup()->get_order_setting('report_order_status') ?? [],
					'type' => 'shop_order',
					'date_created'  => $st_date.'...'.TODAY,
					'meta_query' =>	['relation' => 'AND',
										[ 'key' => '_turq_local_option_method_name', 'value' => 's', 'compare' => '='],
										[ 'key' => '_turq_local_option_date', 'value' => $st_date, 'compare' => '>='],
									]
				]);

	foreach ( $orders as $order ) {
		$pkg_count = $order->get_meta('_turq_package_count');
		if (empty($pkg_count)) continue; // check to see if order has been processed with our correct meta added. Count is also flag, 0 or '' = empty

		foreach ($order->get_shipping_methods() as $id => $shipping_method) {	
			$package_meta = $shipping_method->get_meta('_turq_package_data');
			if ($package_meta['_turq_local_option_method_name'] != 's') continue;
			
			if ($pkg_count == 1 || (
				$package_meta['_turq_local_option_date']	>= $st_date )) {

					$key  							= $package_meta['_turq_local_option_date'].'-'.$package_meta['_turq_local_option_slot'];
					$tag							= $package_meta['_turq_local_option_tag_id'];

					$delivery_orders[$tag][$key][]	= $order;
					$delivery_pkgmeta[$tag][$key][]	= $package_meta;
					$process_order[$tag][$key]		= $package_meta['_turq_local_option_date'];
			
			}
		}
	}
	
	?>
	<table style="width:100%" cellspacing="0">
		<tbody>		
	<?php


	foreach ( $process_order as $tag => $delivery_point ) {
		$name				= turq_Market_Setup()->get_group_lookup($tag)['label'] ?? $tag;				
		$all_orders			= array_reduce($delivery_orders[$tag], 'array_merge', []);
		$all_orders_meta	= array_reduce($delivery_pkgmeta[$tag], 'array_merge', []);		
		$file_token			= generate_report( 'buy', $name, $all_orders,  $all_orders_meta);
		?>
			<tr style="background-color:#ccc">
				<td><?= $name ?></td>
				<td><?= esc_html( $co_key ) ?></td>
				<td><?= count($all_orders) ?></td>
				<td>
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
						<?= turq_download_icon() ?>
					</a>
				</td>
			</tr>
			<?php
			
			asort($delivery_point);
			
			foreach ( $delivery_point as $key => $date ) {
				$gen_orders	= $delivery_orders[$tag][$key];
				$slot		= str_replace($date.'-', '', $key);
				$date_slot	= date(DATEFORMAT, $date).' '.$slot;
				$file_token	= generate_report( 'delivery', $date_slot, $gen_orders, $delivery_pkgmeta[$tag][$key] );
				?>
				<tr>
					<td></td>
					<td><?= esc_html( $date_slot ) ?></td>
					<td><?= count($gen_orders) ?></td>
					<td>
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
							<?= turq_download_icon() ?>
						</a>
					</td>
				</tr> <?php 
			}
			
			$file_token = generate_report( 'delivery', 'all-'.$name, $all_orders, $all_orders_meta);
			?>
			<tr><td colspan="4" style="height:10px"></td></tr>
			<tr>
				<td></td>
				<td style="text-align:right;border-top:3px double lightgrey">
					Combined picking&nbsp;
				</td>
				<td style="border-top:3px double lightgrey">
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['csv']}" ) ?>">
						<?= turq_download_icon('green') ?>
					</a>
				</td>
				<td style="border-top:3px double lightgrey">
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
						<?= turq_download_icon() ?>
					</a>
				</td>
			</tr>
			<tr><td colspan=4>&nbsp;</td></tr>
		<?php
	} ?>
		</tbody>
	</table>
	<?php

	
	$html = ob_get_clean();

	if ( count($orders) == 0 ) wp_send_json_error( 'No orders found' );

	wp_send_json_success( $html );
});






// COLLECT reports
// ---------------
function turq_collect_report( $post, $callback_args ) {
    $details    = $callback_args['args'];

	$st_date	= strtotime('Mon -1 week', TODAY);
	$end_date	= strtotime('Sun +'.turq_Market_Setup()->get_order_setting('weeks_ahead').' week', TODAY);
	$orders		= wc_get_orders([
					'limit' => -1,
					'post_status' => turq_Market_Setup()->get_order_setting('report_order_status') ?? [],
					'type' => 'shop_order',
					'date_created'  => '<' . TODAY,
					'meta_query' =>	['relation' => 'AND',
										[ 'key' => '_turq_local_option_method_name', 'value' => 'p', 'compare' => '='],
										[ 'key' => '_turq_local_option_date', 'value' => $st_date, 'compare' => '>='],
										[ 'key' => '_turq_local_option_date', 'value' => $end_date, 'compare' => '<='],
									],
				]);

	//error_log("O>".print_r(wp_list_pluck($orders, 'id'), true)."{".print_r($orders,true)."}");
	
	echo "Processing all COLLECTION orders[".count($orders)."] between ".date(DATEFORMAT, $st_date)." and ".date(DATEFORMAT, $end_date);

	foreach ($orders as $order) {
		$cut_off = $order->get_meta('_turq_local_option_cut_off', true) ?: '';
		if ($cut_off !== '') {
			$day = date('D', $cut_off);
			$cut_off_days[$day][$cut_off] ??= 0;
			$cut_off_days[$day][$cut_off]++;
		}
	}

	$cut_off_lookup = [];

	?>
	<table id="turq_collect_report" class="turq_report_status">
		<tr>
			<td>Select Cut-Off day(s) to get all orders for following week</td>
		</tr>

		<?php foreach ( $cut_off_days as $cut_off_day => $cut_off_date ) : ?>
			<tr>
				<td>
					<div class="turq-dash-radio radioGroupBelow">
						<?= esc_html( $cut_off_day ) ?>
						<?php
							$limit    = turq_Market_Setup()->get_order_setting( 'weeks_ahead' );
							$st_date  = strtotime( 'Mon -1 week', TODAY );
							$end_date = strtotime( 'Sun +' . $limit . ' week', TODAY );

							// Create DateTime objects for cleaner comparison and iteration
							$current_date = new DateTime();
							$current_date->setTimestamp($st_date);

							$final_date = new DateTime();
							$final_date->setTimestamp($end_date);

							// Loop while our moving pointer is less than or equal to our end date
							while ( $current_date <= $final_date ) :

								$date_id	= $current_date->format( "d-m" );
								$day		= $current_date->format( "D" );
								$is_today 	= $date_id === date( "d-m", TODAY ) ? 'style="color:red"' : '';

								// dot or checkbox ?
								if ($day == $cut_off_day) { 
									$cut_off_date_st	= $current_date->getTimestamp();
									$st_date			= $cut_off_date_st;
									$cut_off_date_end	= clone($current_date);
									$end_date			= clone($current_date);								
									
									$cut_off_date_end->modify('+1 day');
									$end_date->modify('+1 week');
							
									$cut_off_lookup[$cut_off_date_st] = ['cut_off_date_st' => $cut_off_date_st, 'cut_off_date_end' => $cut_off_date_end->getTimestamp(), 'st_date' => $st_date, 'end_date' => $end_date->getTimestamp()];
								
								?>
									<label for="<?= esc_attr( $date_id ) ?>" <?= $is_today ?>> 
										<input type="checkbox"
											   class="fakeRadio fR-<?= esc_attr( $day ) ?>"
											   name="<?= esc_attr( $day ) ?>"
											   id="<?= esc_attr( $date_id ) ?>"
											   value="<?= esc_attr( $cut_off_date_st ) ?>">
										<?= esc_html( $date_id ) ?>
									</label> <?php 
								}
								else echo "<span $is_today > ● </span>";
								$current_date->modify('+1 day'); 
							endwhile; 
						?>
					</div>
				</td>
			</tr>
		<?php endforeach;
		
		set_transient( 'turq_cut_off_lookup', $cut_off_lookup, HOUR_IN_SECONDS );
		
		?>

		<tr>
			<td>
				<div style="display:flex;justify-content:center;margin-top:20px;align-items:flex-start;">
					<span class="turq_report turq_admin_button button-secondary">Generate Reports</span>
					<span class="turq_clear turq_admin_button button-secondary">Clear</span>
					<span class="spinner"></span>
					<span class="turq_server_message"></span>
				</div>
			</td>
		</tr>
	</table>

	<?php
	widget_footer();
	?>

	<script id="turqtick_dashboard_collect">
		(function ( $, window ) {
			
			$( '#turq_collect_report .turq_report' ).on( 'click', function(e){ get_edit($(this), true);});

			function get_edit(t, o) {
				if (t.hasClass('disabled')) return;
		
				var $m = t.closest('table');
				$m.find('.spinner').addClass( 'is-active' );
				$m.find('.turq_admin_button').addClass( 'disabled' );
				$(".fakeRadio").prop( "disabled", true );
				$m.find('.turq_server_message').html('Processing');		
				var cutoff = $.map($('.fakeRadio:checked'), (e) => e.value);
				if (cutoff.length == 0) {
					$m.find('.turq_server_message').html('Please select some dates');
					reset_form($m);
					return;
				}
				$.ajax( {
					type: 'POST',
					url: '<?php echo admin_url( 'admin-ajax.php' )?>',
					data: {
						action      : 'turq_get_report_collect',
						//rid         : t.data('r-type'),
						cut_off    	: cutoff,
						security    : '<?php echo wp_create_nonce( 'turq-ajax-verification' )?>'
					},
					dataType: 'json',
					success: function( response ) {
						var fromServer = response.data;				
						if ( response && response.success ) {
							$m.find('.turq_server_message').html(fromServer);
							reset_form($m);
						} else {
							clear_form($m);				    
							$m.find('.turq_server_message').html(fromServer);
							//window.console.log( response );
						}
						$m.find( '.spinner' ).removeClass( 'is-active' );
					}
				} ).fail( function( response ) {
					clear_form($m);
					alert( 'Request Failed, please reload and try again' );
					window.console.log( response );
				} );
			}

			function reset_form($m){		
				$(".fakeRadio").removeAttr( "disabled");
				$m.find( '.spinner' ).removeClass( 'is-active' );
				$m.find('.turq_admin_button').removeClass( 'disabled' );
			}
			
			function clear_form($m){
				$m.find('.turq_server_message').html('');
				reset_form($m);
			}

			$(".turq_clear").click(function(){
				if ($(this).hasClass('disabled')) return;
				$(".fakeRadio").prop( "checked", false );
			})
			
			$(".fakeRadio").click(function(){
				$('#turq_report_status .turq_server_message' ).html('');
			})
			
		})( jQuery, window );
	</script><?php
}


/**
 * Ajax callback function.
 */
 add_action( 'wp_ajax_turq_get_report_collect', function() {
    check_ajax_referer( 'turq-ajax-verification', 'security' );
	
	if (!isset($_POST['cut_off']) ) wp_send_json_error('Error - Sent Data syntax [1]');
	if ( isset($_POST['cut_off']) && gettype($_POST['cut_off'])!='array') wp_send_json_error('Error - Sent Data syntax [2]');
	if ( isset($_POST['cut_off']) && gettype($_POST['cut_off'])=='array' && count($_POST['cut_off'])==0) wp_send_json_error("Error - Sent Data syntax [3]");
	//error_log(print_r($_POST['cut_off'],true));	
	
	$order_count = 0;
	$cut_off_lookup = get_transient( 'turq_cut_off_lookup' );

	ob_start();
	?>
	<table style="width:100%" cellspacing="0">
		<tbody>
		<?php
		foreach ( array_map( 'abs', $_POST['cut_off'] ) as $index ) :

			extract ($cut_off_lookup[$index]); 
			$co_key				= date( 'D d-m', $cut_off_date_st );
			
			// NOT LEGACY COMPATIBLE !!!!
			// get pre filtered orders from order meta summary
			$orders = wc_get_orders([
				'limit'        => -1,
				'type'         => 'shop_order',
				'post_status'  => turq_Market_Setup()->get_order_setting('report_order_status') ?? [],
				'date_created' => '<' . $st_date,
				'meta_query'   => [
					'relation' => 'AND',
					[ 'key' => '_turq_local_option_method_name', 'value' => 'p' ],
					[ 'key' => '_turq_local_option_cut_off',     'value' => $cut_off_date_st,	'compare' => '>=' ],
					[ 'key' => '_turq_local_option_cut_off',     'value' => $cut_off_date_end,	'compare' => '<' ],					
					[ 'key' => '_turq_local_option_date',        'value' => $st_date,			'compare' => '>=' ],
					[ 'key' => '_turq_local_option_date',        'value' => $end_date,			'compare' => '<' ],
				],
			]);	

			if ( empty( $orders ) ) continue;

			// Filter orders on package and group by pickup location
			$pickup_orders	= [];
			$pickup_pkgmeta	= [];
			$pickup_labels	= [];
			$process_order	= [];

			foreach ( $orders as $order ) {
				$pkg_count = $order->get_meta('_turq_package_count');
				if (empty($pkg_count)) continue; // check to see if order has been processed with our correct meta added. Count is also flag, 0 or '' = empty

				foreach ($order->get_shipping_methods() as $id => $shipping_method) {	
					$package_meta = $shipping_method->get_meta('_turq_package_data');
					if ($package_meta['_turq_local_option_method_name'] != 'p') continue;
					
					if ($pkg_count == 1 || (
						$package_meta['_turq_local_option_cut_off']	>= $cut_off_date_st  &&
						$package_meta['_turq_local_option_cut_off']	<  $cut_off_date_end &&
						$package_meta['_turq_local_option_date']	>= $st_date &&							
						$package_meta['_turq_local_option_date']	<  $end_date)) {

							$order_count++;
							$location_key						= $package_meta['_turq_local_option_market_id'].'-'.$package_meta['_turq_local_option_date'];

							$pickup_orders[$location_key][$id]	= $order;
							$pickup_pkgmeta[$location_key][$id]	= $package_meta;
							$pickup_labels[$location_key]		= $package_meta['_turq_local_option'];
							$process_order[$location_key]		= $package_meta['_turq_local_option_date'];
					}
				}
			}

			asort($process_order);

			// flatten to only actual order data
			$gen_orders			= array_replace(...array_values($pickup_orders));
			$gen_orders_meta	= array_replace(...array_values($pickup_pkgmeta));

			$file_token = generate_report( 'buy', $co_key,  $gen_orders, $gen_orders_meta);
			
			?>
			<tr style="background-color:#ccc">
				<td>Week following</td>
				<td><?= esc_html( $co_key ) ?></td>
				<td><?= count($gen_orders) ?></td>
				<td>
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
						<?= turq_download_icon() ?>
					</a>
				</td>
			</tr>
			<?php


			foreach ( $process_order as $location_key => $value ) :
				$gen_orders	= $pickup_orders[$location_key];
				$file_token = generate_report( 'pickup', $pickup_labels[$location_key], $gen_orders, $pickup_pkgmeta[$location_key] );
				?>
				<tr>
					<td></td>
					<td><?= esc_html( $pickup_labels[$location_key] ) ?></td>
					<td><?= count($gen_orders) ?></td>
					<td>
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
							<?= turq_download_icon() ?>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>


			<?php
			$file_token = generate_report( 'pickup', 'all-'.$co_key, $gen_orders, $gen_orders_meta);
			?>
			<tr><td colspan="4" style="height:10px"></td></tr>
			<tr>
				<td></td>
				<td style="text-align:right;border-top:3px double lightgrey">
					Combined picking&nbsp;
				</td>
				<td style="border-top:3px double lightgrey">
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['csv']}" ) ?>">
						<?= turq_download_icon('green') ?>
					</a>
				</td>
				<td style="border-top:3px double lightgrey">
					<a href="<?= admin_url( "admin-post.php?action=download_turq_report&token={$file_token['doc']}" ) ?>">
						<?= turq_download_icon() ?>
					</a>
				</td>
			</tr>
			<tr><td>&nbsp;</td></tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php

	$html = ob_get_clean();

	if ( $order_count === 0 ) wp_send_json_error( 'No orders found' );

	wp_send_json_success( $html );
	
});

function turq_download_icon($color='blue'): string {
    return <<<SVG
				<svg viewBox="0 0 24 24" height="15" width="15" fill="none" stroke="$color"
					 xmlns="http://www.w3.org/2000/svg" stroke-width="2"
					 stroke-linecap="round" stroke-linejoin="round">
					<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
					<polyline points="7 10 12 15 17 10"></polyline>
					<line x1="12" y1="15" x2="12" y2="3"></line>
				</svg>
			SVG;
}


function turqOTV_dashboard_widget( $post, $callback_args ) {
    $details    = $callback_args['args'];

	//$products = wc_get_products(array('limit'=>-1, 'orderby' => 'name', 'order' => 'ASC', 'status' => 'publish', )); //'stock_status' => 'instock'
	$products = wc_get_products(['category' => 'meat', 'limit'=>-1, 'orderby' => 'menu_order', 'order' => 'ASC', 'status' => 'publish']);

	echo "<ul class='otv-list'>";
	foreach($products as $p){
		$p_id = $p->get_id();
		echo "<li class='otv-chk'><input type='checkbox' class='otv-checkbox' value='".$p_id."' ".($p->get_tag_ids() ? 'checked' : '')."><label for='".$p_id."'>".$p->get_name()."</label></li>";
	}
	echo "</ul>";
	echo "<style>.otv-list{columns:3} .otv-chk{display:flex;align-items: center;padding:3px;margin:0} .otv-chk:nth-of-type(2n+1){background-color:#eee} .otv-chk input{margin: 0 5px 0 0}</style>";
	widget_footer();
	
	?><script id="turqtick_dashboard_widgets_otv">
	(function ( $, window ) {
		
		$(".otv-checkbox").click(function(){
			$.ajax( {
				type: 'POST',
				url: '<?php echo admin_url( 'admin-ajax.php' )?>',
				data: {
					action      : 'turq_toggle_tag',
					pid         : $(this).val(),
					chk			: $(this).prop("checked"),
					security    : '<?php echo wp_create_nonce( 'turq-ajax-verification-otv' )?>'
				},
				dataType: 'json',
				success: function( response ) {
				}
			} ).fail( function( response ) {
				alert( 'Request Failed, please reload and try again' );
				window.console.log( response );
			} );
		})
		
	})( jQuery, window );</script><?php	
}

add_action( 'wp_ajax_turq_toggle_tag', function() {
    check_ajax_referer( 'turq-ajax-verification-otv', 'security' );
	
	if (!isset($_POST['pid']) || !isset($_POST['chk'])) wp_send_json_error('Error - Sent Data syntax [1]');
	if ( isset($_POST['pid']) && abs($_POST['pid'])==0) wp_send_json_error('Error - Sent Data syntax [2]');
	if ( isset($_POST['chk']) && !($_POST['chk']=='true' || $_POST['chk']=='false')) wp_send_json_error('Error - Sent Data syntax [3]');
	
	$product = wc_get_product(abs($_POST['pid']));
	$tags = $product->get_tag_ids();
	
	if ($_POST['chk']=='false' && (($key = array_search('68', $tags)) !== false)) unset($tags[$key]);
	elseif ($_POST['chk']=='true') $tags[]='68';
	$product->set_tag_ids($tags);
	$product->save();
	
	wp_send_json_success();
});

 


/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
// CREATE DUMMY TEST DATA
/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
function get_all_products() {
	// get all available products (flattened!!!)
	$children = get_posts( array(
		'posts_per_page' => -1,
		'post_type' => array('product','product_variation'),
		'post_parent__not_in' => array(0), // gets all "chidren" of product variant without the parent
		'fields' => 'ids',
		'meta_query' => array(
			array(
				'key' => '_stock_status',
				'value' => 'instock',
				'compare' => '=',
			)
		)		
	));

	// get all products excluding children and the parent of (which is a useless product)
	global $wpdb;
	$sql = "SELECT ID
			FROM   {$wpdb->posts} AS p 
			INNER JOIN {$wpdb->postmeta} pm ON ( p.ID = pm.post_id ) 
			WHERE  p.post_type LIKE 'product'
				   AND p.post_status = 'publish'
				   AND pm.meta_key = '_stock_status'
				   AND pm.meta_value = 'instock'
				   AND p.ID NOT IN (SELECT post_parent 
									FROM   {$wpdb->posts} AS p 
									INNER JOIN {$wpdb->postmeta} pm ON ( p.ID = pm.post_id )                                 
									WHERE  p.post_type = 'product_variation' 
									AND p.post_status = 'publish'                                
									AND p.post_parent != '0' 
									AND pm.meta_key = '_stock_status'
									AND pm.meta_value = 'instock'
									GROUP  BY post_parent)";

	$parents = wp_list_pluck($wpdb->get_results($sql), 'ID');
	//error_log("IDSc:".print_r($children, true));
	//error_log("IDSp:".print_r($parents,true));
	//error_log("Dups:".print_r(array_intersect($children, $parents),true));

	// useful routine -
	//foreach(get_all_products() as $p_id) $products[] = wc_get_product( $p_id );
	//usort($products, function($object1, $object2){return strcmp($object1->get_name(), $object2->get_name());});
		
	return array_merge($parents, $children);
}

/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////




/*******************************************************************************
 * Report generation & file handling
*******************************************************************************/

// define column names and order
function get_csv_cols($report_type) {
  
    if ($report_type == 'buy')
        return array(
            'order_link' => '_edit_order_url',
		);

	if ($report_type == 'pickup')
        return array(
            'order_ref_no' => '_id',
			'market_pickup',		
            'billing_name' => '_formatted_billing_full_name',
			'_billing_email',        		
			'_billing_phone',        		
			'_customer_note',
			'_order_total' => '_total',
			'split_order',			
			'coupons_used',
            'order_items',		
		);
	
	if ($report_type == 'delivery')
        return array(
            'order_ref_no' => '_id',
			'delivery_day',		
            'billing_name' => '_formatted_billing_full_name',
			'_billing_email',        		
			'_billing_phone',        		
			'delivery_addr' => '_shipping_address_index',	
			'_customer_note',
			'_order_total' => '_total',
			'split_order',			
			'coupons_used',			
            'order_items',		
		);

}

function build_order_data($order, $report, $pkgmeta){
	$line = array();
    foreach (get_csv_cols($report) as $key => $col) {
		if (is_int($key)) $key = $col;
        switch ($col) {
			case 'market_pickup' :
            case 'delivery_day' :				
				$opts	= $pkgmeta['_turq_local_option'];
				if (!empty($opts))	$line[$key] = wp_strip_all_tags(str_replace(",", " -", $opts));
				else 				$line[$key] = "ERROR - CHECK OPTIONS";
                break;
			case 'order_items' :								
				$line[$key] = "Order items -";
				break;
			case 'split_order' :								
				$line[$key] = count($order->get_shipping_methods());
				break;
			case 'coupons_used' :
				$line[$key] = rtrim(implode(',', $order->get_coupon_codes()) , ',');
				break;
            case (method_exists($order,($function = 'get'.$col)) ? $col : 'xxx') :
                $line[$key] = ucwords($order->$function());
                break;
            default :
                $line[$key] = ucwords($order->get_meta($col));
        }
    }
	return $line;    
}


function build_order_item_data($order, $report="", $pkgmeta=[]){
	static $total_items = [];
	
	if ($report == 'get') {
		ksort ($total_items, SORT_NATURAL);
		$formatted_total_items = '';
		foreach ($total_items as $item=>$tot) $formatted_total_items .= "$tot,$item\n";
		$total_items = [];
		return $formatted_total_items;
	}
	
	$line		= [];

	$refunds = $order->get_refunds();	
	if (!empty($refunds)) error_log("REFUND[".$order->get_id()."]"); // .print_r($refunds,true));

	foreach ($pkgmeta['_turq_package_items'] as $order_line_item_id) {
		
		$item		= $order->get_item($order_line_item_id);
	
		// get order line item details
		$product    = $item->get_product();
		if (!$product) continue;					// just incase an invalid product
		$product_id = $product->get_id();
		$qty        = $item->get_quantity(); 		// Original quantity
		$refunded   = 0;                      

		// Loop through refunds looking for line item
		foreach ($refunds as $refund) {
			foreach ($refund->get_items() as $ref_item) {
				// get refunded line item details
				$ref_product    = $ref_item->get_product();
				$ref_product_id = $ref_product->get_id();
				
				if ($ref_product_id == $product_id) {
					$refunded += $ref_item->get_quantity(); // Add refunded qty (will be negative since refund)
					//error_log("item[$product_id]=$ref_product_id :Qty[".$ref_item->get_quantity()."]".print_r($ref_item, true));														
				}
			}
		}

		$available_qty = $qty + $refunded;		
		if ($refunded!=0) error_log("ORDER[".$order->get_id()."] Ordered[$qty] Refund item[".$item->get_name()."]$refunded=avail[$available_qty]");
	    if ($available_qty <= 0) continue; // skip this line item if necessary
		
		$this_key	= str_replace(" - ", ",", $item->get_name());
		$line[]		= $available_qty."x ".$item->get_name();
		
		if (array_key_exists($this_key, $total_items))	$total_items[$this_key] += $available_qty;
		else											$total_items[$this_key]  = $available_qty;
	}
	return $line;
}


// write out whole file from scratch with new query on current data
function generate_report($report, $option_value, $orders, $order_pkgmeta = []){

	$token				= [];
    $reports_dir		= plugin_dir_path( __FILE__ ) . 'reports/';
	$base_name			= $report."-".sanitize_title($option_value);	
	$all_special		= strtok($option_value, '-') == 'all';
	
	$file_name_doc		= $base_name.'.doc';
	$token['doc']		= hash_hmac( 'sha256', $file_name_doc, wp_salt( 'nonce' ) );
	$outstream_doc		= fopen($reports_dir.$file_name_doc, "w");		
	
	if ($all_special) {
		$file_name_csv	= $base_name.'.csv';	
		$token['csv']	= hash_hmac( 'sha256', $file_name_csv, wp_salt( 'nonce' ) );
		$outstream_csv	= fopen($reports_dir.$file_name_csv, "w");		
	}

	foreach ($orders as $okey => $order) {
		$details	= build_order_data($order, $report, $order_pkgmeta[$okey]);
		$items		= build_order_item_data($order, 'set', $order_pkgmeta[$okey]);
		foreach ($details as $key => $line) if (!empty($line) && $key!='order_items') fwrite($outstream_doc, ucwords(str_replace("_", " ", trim($key, "_"))).":\t".$line."\n");
		$csv_output = $details;
		
		if (array_key_exists('order_items', $csv_output)) unset($csv_output['order_items']);
		
		if (array_key_exists('order_items', $details)) {
			fwrite ($outstream_doc, "\nOrder Items:\n".implode("\n",array_map('trim',$items,array_fill(0,count($items),',')))); //."\n".chr(12));
			array_push($csv_output, rtrim(implode(', ', $items), ', '));
			array_push($csv_output, count($items));
		}
		
		$notes = wc_get_order_notes( ['order_id' => $order->get_id()] );
		if ($notes) {
			$output = "\n\nAdmin Order Notes\n";
			$csv_notes = '';				

			foreach ( $notes as $note ) {
				$date  = $note->date_created ? $note->date_created->date_i18n( 'Y-m-d H:i' ) : '';
				$added = $note->added_by ? $note->added_by : "System";
				$type  = $note->customer_note ? "Customer Note" : "Admin Note";
				$content = wp_strip_all_tags($note->content);					

				$output .= "[$date] $type by {$added}:\n";
				$output .= $content."\n";
				
				$csv_notes .= $content." | ";					
				
			}
			fwrite ($outstream_doc, $output);
			array_push($csv_output, rtrim($csv_notes, '| '));					
		}
		fwrite ($outstream_doc, "\n".chr(12));
		if ($all_special) fputcsv($outstream_csv, $csv_output);
	}

	$summary = build_order_item_data($order, 'get');
	$summary_title = "\n\nSummary, ".count($orders)." orders, ".ucwords(str_replace(",", " -", $option_value).", ".$report)."\n";
	fwrite($outstream_doc, $summary_title."\n".$summary);
	fclose($outstream_doc);

	if (isset($outstream_csv)) fclose($outstream_csv);		

	return $token;
}

add_action( 'admin_post_download_turq_report', function() {
	//error_log(">>>".print_r($_GET, true));
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

    $received_hash	= $_GET['token'] ?? '';
    $reports_dir    = plugin_dir_path( __FILE__ ) . 'reports/';
    
    // Open the directory and look for a file that matches this nonce
    $files = scandir( $reports_dir );
    $target_file = '';

    if ( is_dir( $reports_dir ) ) {
        $files = array_diff( scandir( $reports_dir ), array( '.', '..' ) );

		foreach ( $files as $file ) {
            $expected_hash = hash_hmac( 'sha256', $file, wp_salt( 'nonce' ) );

            if ( hash_equals( $expected_hash, $received_hash ) ) {
                $target_file = $file;
                break;
            }
		}
	}

    if ( !empty($target_file) && file_exists( $reports_dir . $target_file ) ) {
        $full_path     = $reports_dir . $target_file;
        $timestamp     = 'generate['.date( "d-m_H-i" ).']';
        $path_info     = pathinfo( $full_path );
        $download_name = "{$path_info['filename']}_{$timestamp}.{$path_info['extension']}";

        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
        readfile( $full_path );
        exit;
    }

    wp_die( 'Invalid or expired download link.' );
});

?>