<?php
/*******************************************************************************
 * Market setup - CORE
 * 
 * class-turq-market-setup.php
 * 
*******************************************************************************/
// An array entry per market
// Address/location are obvious!! location is in bold on front end
// Message field will display in list if not empty
// Can have multiple array entries in "date_and_time' array. Key is day number 0-6 = Mon-Sun
// 'cut-off'	: either EMPTY or DAY or DATE	- if empty, no Click&Collect available, otherwise a day for recurring markets cc cut off. Can be a single proper date (after which CC is not aviailable)
// 'skip'		: Can have multiple array entries. Set 2 dates between which the market is not taking place. If the same then indicates a single day. If no end then closed until further notice. Hide removes from list if skip active

// Xmas 2024
// Deliveries only between 21st - 24th Dec AM
// Possibly limit collection slots available for the day

// dev notes for 2025
// ---------------------------------------------------------------------------
// BASKETS CANNOT BE MIXED
// all products are for special market collection date, be that Easter, Xmas etc... Only one date can be set at a time. Change "recurring" field to "special-day", ie Xmas - this will be for ALL products only
// only those products set for cc are for regular market pickups. regular CC products are a sub set of ALL products and cannot exist otherwise.
// need to process baskets and clearly show message
// JUST rcc in basket = NO MESSAGE, simply pick collection/delivery date, either regular or special
// MIXED = only allow special collection/delivery BUT show message to explain
// ONLY =  only allow special collection/delivery
// if skip active FORMAT d|d|h, d|d|h...


// HTML date picker saves in format RFC 3339/ISO 8601 "wire format": YYYY-MM-DD, although browser can display as localised.
// ALL DATES are UTC values until point of display, unless required by settings, e.g. woo admin setup tab date picker.
// Cut of dates for single events area stored as an actual UTC date+time as this is pre defined with event setup
// Regular events have a RELATIVE cut off value stored, e.g. "Tue 13:00" since the event date is dynamic

//do_action( 'qm/debug', );
//error_log("product : ".print_r($product,true));


if (!defined('ABSPATH')) exit;

class turq_Market_Setup {

	public const DEFAULT_SHIP_EVENTS_ARRAY	= [['id' =>'all_items', 'tag' =>0, 'enabled' => true]];
	public const TARGETED_METHOD_LABELS		= [ 'p' => 'Local Pickup', 's' =>'Local Delivery', 'cp' =>'National Courier/Postage', 'v' =>'Virtual Delivery'];	
	public const METHOD_SETUP 				= [
												'p' => [
														'field_id'			=> ['ti_market', 'ti_date'],					// front end select field Id
														'field_label'		=> ['Market location', 'Market date'],
														'method_description'=> 'Collection Market',
												],
												's' => [
														'field_id'			=> 'turq_local_option',							// front end select field Id
														'field_label'		=> 'Delivery Option',													
														'method_description'=> 'Delivery Date',
												],
												'cp' => [
														'method_description'=> '',
												],
												'v' => [
														'method_description'=> 'Virtual/Download',
												],
												
											];


	public const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

	public $targeted_methods = [];
	public $multi_shipping_groups = [];	
	public $group_method_config = [];
	public $group_method_items = [];
	public $option_validation = [];
	
	public $all_tags	= [];
	public $active_tags = [];
	public $used_tags 	= [];	
	public $unused_tags	= [];
	
	public $group_lookup = [];

	public $extra_closed_dates = [];

    /**
     * Get the single instance of the class
     */
    private static $instance = null;	 
    public  static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;	
    }


    /**
     * Construct the class
     */
    public function __construct() {
        add_action('init', [$this, 'setup_all_data']);
    		
		// Other hooks
		add_action('delete_product_tag', [$this, 'product_tag_deleted'], 10, 4);		
    }
	
	
    /**
     * Initialise...
     */
	public function setup_all_data() {
	
        $saved_targeted_methods = get_option('targeted_methods', []);
        $local_pickup			= !empty($saved_targeted_methods['p'])  ? array_fill_keys($saved_targeted_methods['p'],  'p')  : [];
        $local_delivery			= !empty($saved_targeted_methods['s'])  ? array_fill_keys($saved_targeted_methods['s'],  's')  : [];
        $national_courier		= !empty($saved_targeted_methods['cp']) ? array_fill_keys($saved_targeted_methods['cp'], 'cp') : [];
		$virtual_delivery		= !empty($saved_targeted_methods['v'])  ? array_fill_keys($saved_targeted_methods['v'],  'v')  : [];		
		//$virtual				= ['virtual_delivery:19' => 'v', 'virtual_delivery:20' => 'v'];
		
        $this->targeted_methods = array_merge(
            $local_pickup,
            $local_delivery,
            $national_courier,
			$virtual_delivery,
        );		

		$this->all_tags	= $this->get_all_tags();														// tags basically provide a readable "name" for the shipping or event (and allow for product filtering)

        $ship_events	= get_option('ship_events', self::DEFAULT_SHIP_EVENTS_ARRAY);
		$allowed_groups	= get_option('ship_events_allowed_groups', []);
		
		foreach ($ship_events as $item) {
			$group_key		= $item['id'];
			
			$start_date		= strtotime($item['start_date']) ?: 0;
			$start_active	= TODAY >= $start_date;
			$end_date		= strtotime($item['end_date']) ?: PHP_INT_MAX;
			$end_active		= TODAY >= $end_date;
			$period_active	= $start_active && !$end_active;
			
			$label			= $item['tag'] ? ($this->all_tags[$item['tag']] ?? 'ERROR') : '';
	
			$this->multi_shipping_groups[$group_key] = [
				'group_key'			=> $group_key,
				'label'				=> $label,
				'tag_id'			=> $item['tag'],
				'enabled'			=> $item['enabled'],
				'start_date'		=> $start_date,
				'end_date'			=> $end_date,
				'active'			=> $period_active,
				'background'		=> $item['background'],
				'color'				=> $item['color'],
				'allowed_groups'	=> $allowed_groups[$item['tag']] ?? [],
			];

			$this->group_lookup[$group_key]		= &$this->multi_shipping_groups[$group_key];					
			$this->group_lookup[$label]			= &$this->multi_shipping_groups[$group_key];
			$this->group_lookup[$item['tag']]	= &$this->multi_shipping_groups[$group_key];

			$this->used_tags[$item['tag']] = $group_key;			
			if ($item['enabled'] && $period_active) $this->active_tags[$item['tag']] = $group_key;
		}
		
		$this->unused_tags = array_diff_key($this->all_tags, $this->used_tags);
		
		$group_local_collection_points	= get_option('group_local_collection_points', []);	
		$group_local_delivery_dates		= get_option('group_local_delivery_days', []);

		foreach (get_option('group_method_config', []) as $group_key => $methods) {
			foreach($methods as $method_id => $method) {
				$this->group_method_config[$group_key][$method_id]['start_date']	= strtotime($method['start_date']) ?: 0;
				$this->group_method_config[$group_key][$method_id]['end_date']		= strtotime($method['end_date']) ?: PHP_INT_MAX;
				$this->group_method_config[$group_key][$method_id]['enabled']		= $method['enabled'] ?? 0;

				if (!$this->group_method_config[$group_key][$method_id]['enabled']) continue;

				$source = match($method_id) {
					'p'		=> $group_local_collection_points[$group_key] ?? [],
					's'		=> $group_local_delivery_dates[$group_key] ?? [],
					'cp'	=> [['id' => 'dummy', 'enabled' => 1]],
					'v'		=> [['id' => 'dummy', 'enabled' => 1]],					
					default => null
				};

				if ($source) {
					foreach ($source as $point) {
						if (!$point['enabled']) continue;
						unset($point['enabled']);
						
						$id = $point['id'];
						//unset($point['id']);						
						
						foreach ($point as $key => $item) {
							//error_log(dpv(compact('group_key', 'point', 'item')));
							switch ($key) {
								case 'market_id' :
										$point[$key]  = strtok($item, '-');
										break;
								case 'cut_off' : 
										if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $item))	$point[$key]  = strtotime($this->get_default_cut_off_time(), strtotime($item));
										else if (empty($item))							$point[$key]  = strtotime('last '.$this->get_default_cut_off_string(), $point['date']);
										else if ($item == 8)							$point[$key]  = $this->get_default_cut_off_string();
										else if ($item >= 1 && $item <= 7)				$point[$key]  = self::WEEKDAYS[$item-1].' '.$this->get_default_cut_off_time();
										break;
								default :
										if (is_string($item) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $item)) $point[$key] = strtotime($item);
								
							}
						}
						$this->group_method_items[$group_key][$method_id][$id] = $point;
					}
					$this->option_validation[$group_key][$method_id] = $this->build_options($group_key, $method_id, TODAY);
				}
			}
		}

		foreach(get_option('global_extra_closed_dates', []) as $closed_date) {
			if (!$closed_date['enabled'] || empty($closed_date['closed_date'])) continue;

			$date_key = strtotime($closed_date['closed_date']);
			foreach ($closed_date as $key => $data) {
				if ($key == 'notice_start')	{
					$this->extra_closed_dates[$date_key]['notice_start'] = strtotime($data) ?: 0;
					$this->extra_closed_dates[$date_key]['notice_end']	 = strtotime('+1 day', $date_key);
				}
				else if ($key == 'closed_date') {
					$this->extra_closed_dates[$date_key][$key] = date(DATEFORMAT, strtotime($data));
				}
				else $this->extra_closed_dates[$date_key][$key] = $data;
			}
		}
		
		//error_log("setUp: INIT ------------------");
		//error_log(print_r($this, true));
		//error_log(dpv(compact('saved_targeted_methods', 'ship_events', 'group_local_collection_points', 'group_local_delivery_dates')));
	
	}	
	
	public function build_options($group_key, $method_id, $today, $wks = null) {

		$option_validation 	= [];
		$method				= $this->group_method_config[$group_key][$method_id];						

		if (!$method['enabled']) return $option_validation;

		$method_items		= $this->group_method_items[$group_key][$method_id];
		$tag_id				= $this->multi_shipping_groups[$group_key]['tag_id'];
		
		if ($method_id == 'p') {
			$weeks_ahead			= $wks ?? $this->get_order_setting('weeks_ahead');
			$weeks_ahead_admin		= $this->get_order_setting('weeks_ahead_admin');
			$set_limit				= is_admin() && ($weeks_ahead_admin > $weeks_ahead) ? $weeks_ahead_admin : $weeks_ahead;

			$market_options			= [];	
			$label					= $this->multi_shipping_groups[$group_key]['label'];
			
			foreach ($method_items as $key=>$setup) {
				$market_id			= $setup['market_id'];
				$title				= get_post_field('post_title', $market_id);
				$title				= wp_specialchars_decode($title, ENT_QUOTES);
				$market_location	= mb_convert_case($title.", ".get_post_meta($market_id,'wpsl_city',true), MB_CASE_TITLE, 'UTF-8');
			
				$market_options[$market_id]['location']	= $market_location;

				$is_regular			= !empty($setup['open_day']) &&  empty($setup['date']);
				$is_single			=  empty($setup['open_day']) && !empty($setup['date']);

				$dates = [];
				if ($is_regular) {
					$limit 			= $set_limit;
					$cut_off  		= strtotime($setup['cut_off'], $today);													// e.g. Tue 13:00
					$today_start	= strtotime(date("d-m-Y", $today));														// get today as plain date UTC with no current time element
					$base_day		= strtotime(($today >= $cut_off ? 'next ' : '').date("l", $cut_off), $today_start);		// add today as base day to allow for testing using future "today" dates above

					for ($wk=0; $wk<$limit && $limit<52; $wk++) {
						$rel_cut_off	= strtotime($setup['cut_off'] ." +".$wk." week", $base_day);
						$pickup_date	= strtotime($setup['open_day']." +".$wk." week", $base_day);
						$start_active	= $pickup_date >= $method['start_date'];
						$end_active		= $pickup_date >= $method['end_date'];
						$period_active	= $start_active && !$end_active;
						
						$is_closed		= array_key_exists($pickup_date, $this->extra_closed_dates);
				
						if (!$period_active || $is_closed) continue;

						// save valid result
						$dates[$pickup_date] = ['name'=>$label.($wk >= $weeks_ahead ? '[A]' : ''), 'date'=>$pickup_date, 'cut_off'=>$rel_cut_off, 'key'=>$key];
					}
				}

				if ($is_single) {
						$pickup_date	= $setup['date'];
						$start_active	= $today >= $method['start_date'];
						$end_active		= $today >= $method['end_date'];
						$period_active	= $start_active && !$end_active;
						
						$is_closed		= array_key_exists($pickup_date, $this->extra_closed_dates);				
				
						if (!$period_active || $is_closed || $today >= $setup['cut_off']) continue;

						// save valid result						
						$dates[$pickup_date] = ['name'=>$label, 'date'=>$pickup_date, 'cut_off'=>$setup['cut_off'], 'key'=>$key];
				}
				
				$market_options[$market_id] += $dates;
			}	

			foreach ($market_options as $market_id => $dates) {
				$market_location = $dates['location'];
				unset($dates['location']);
				if (empty($dates)) continue;

				// save valid result
				$option_validation[$market_id] = ['location'=>$market_location, 'id'=>$market_id, 'tag_id' => $tag_id];					
				ksort($dates); $d=1;
				foreach ($dates as $d_opt)
					$option_validation[$market_id]['d'.$d++] = $d_opt;
			}
		}

		if ($method_id == 's') {
			foreach($method_items as $key=>$delivery_data) {
						$delivery_date	= $delivery_data['date'];
						$start_active	= $today >= $method['start_date'];
						$end_active		= $today >= $method['end_date'];
						$period_active	= $start_active && !$end_active;

						$is_closed		= array_key_exists($delivery_date, $this->extra_closed_dates);				
					
						if (!$period_active || $is_closed || $today >= $delivery_data['cut_off']) continue;

						// reshape array to match other match other method
						$id = $delivery_data['id'];
						unset($delivery_data['id']);
						$delivery_data['key'] = $id;
						
						// save valid result						
						$option_validation[$key] = ['id' => $id, 'tag_id' => $tag_id, 'd1' =>  $delivery_data ];
			}
		}		

		if ($method_id == 'cp' || $method_id == 'v') {		
			foreach($method_items as $key=>$delivery_data) {		
						$start_active	= $today >= $method['start_date'];
						$end_active		= $today >= $method['end_date'];
						$period_active	= $start_active && !$end_active;

						if (!$period_active) continue;

						// save valid result
						$option_validation[$key] = true;
			}
		}
		
		return $option_validation;
	}


	public function get_targeted_methods()							{ return $this->targeted_methods; }		
	public function get_multi_shipping_groups() 					{ return $this->multi_shipping_groups; }
	public function get_group_lookup($key = null)					{ return $key !== null ? ($this->group_lookup[$key] ?? null) : $this->group_lookup; }

	public function get_active_shipping_groups() 					{ return $this->active_tags; }
	public function get_used_shipping_groups() 						{ return $this->used_tags; }	
	public function get_option_validation($group_key, $method_id)	{ return $this->option_validation[$group_key][$method_id] ?? []; }	
		
	public function get_market_closed_dates()						{ return $this->extra_closed_dates;}
					
	public function get_weekday_labels()							{ return self::WEEKDAYS; }		
	public function get_default_cut_off_time()						{ return get_option('default_cut_off_time', []); }		
	public function get_default_cut_off_day()						{ return get_option('default_cut_off_day', 'Tue'); }
	public function get_default_cut_off_string()					{ return $this->get_default_cut_off_day()." ".$this->get_default_cut_off_time(); }
				
	public function get_pickup_only_products()						{ return get_option('pickup_only_products', ''); }			
	public function get_cp_only_products()							{ return get_option('cp_only_products', ''); }					
					
	public function get_order_settings()							{ return get_option('order_settings', ''); }					
	public function get_order_setting($key)							{ return (get_option('order_settings', '')[$key] ?? ''); }						
				
	public function get_delivery_data($group_key)					{ return $this->group_method_items[$group_key]['s']; }
	public function get_method_config($group_key, $method_id)		{ return $this->group_method_config[$group_key][$method_id] ?? []; }	


	public function get_delivery_data_array() {
		$delivery_options = $this->get_delivery_data();
		foreach($delivery_options as $key=>$ship_option) {
			//$ship_option = parse_delivery_string($ship_string);
			if (!$ship_option['enabled']) continue;
			$options[$key]=$ship_option['date'];
		}
		return $options ?? [];
	}

	public function get_method_setup(string $method_name='', string $field_id='') {
		$setup = self::METHOD_SETUP;

		if ($method_name == '' && $field_id == 'field_id') {
			$all_values = [];
			
			foreach ($setup as $m) {
				foreach ( (array) ( $m['field_id'] ?? [] ) as $field_id ) {
					$all_values[] = $field_id;
				}
			}
			return array_values( array_unique( $all_values ) );
		}
		
		if ($method_name != '')						$setup = $setup[$method_name] ?? null;
		if ($field_id != '' && is_array($setup))	$setup = $setup[$field_id] ?? null;

		return $setup;
	}		

	public function get_shipping_method_options() {
		foreach(self::TARGETED_METHOD_LABELS as $key => $label) $options[$key] = $label;
		return $options;
	}
	
	public function product_tag_deleted($term_id, $tt_id, $deleted_term, $object_ids) {

		$saved_ship_events = get_option('ship_events', []);
		if (empty($saved_ship_events)) return;
		
		$updated = false;

		foreach ($saved_ship_events as $index => $event) {
			if (isset($event['tag']) && intval($event['tag']) === intval($term_id)) {
				// Disable this event
				$saved_ship_events[$index]['enabled'] = 0;
				$updated = true;
			}
		}

		if ($updated) update_option('ship_events', $saved_ship_events);
	}
	
	
	public function get_all_tags() {	
		$terms = get_terms(['taxonomy'   => 'product_tag', 'hide_empty' => false, ]);

		if (!is_wp_error($terms)) {
			foreach ($terms as $term) {
				$tags[$term->term_id] = $term->name; // key = ID, value = label
			}
		}

		return $tags;
	}

	public function get_all_shipping_rates() {
		$shipping_rates = array();
		$shipping_zones = WC_Shipping_Zones::get_zones();

		foreach ($shipping_zones as $zone) {
			$zone_obj = new WC_Shipping_Zone($zone['id']);
			$methods = $zone_obj->get_shipping_methods(true);
			foreach ($methods as $method) {
				$key = $method->id . ':' . $method->instance_id;
				$shipping_rates[$key] = $method->get_title();
			}
		}

		// Add default zone rates
		$default_methods = WC_Shipping_Zones::get_zone('0')->get_shipping_methods(true);
		foreach ($default_methods as $method) {
			$key = $method->id . ':' . $method->instance_id;
			$shipping_rates[$key] = $method->get_title();
		}

		return $shipping_rates;
	}
	
}