<?php
// WPSL extender
//--------------
// Extends and alters functionality of WPSL plugin


/**
 * ============================================================================
 * Build HTML Market Shortcode
 * ============================================================================
 *
 * Provides a shortcode to output details for all published WPSL Store.
 *
 * Used in front end webpage
 * Called as incremental day shortcodes
 * Only one database query is required for first call hence static vars.
 * All subsequent calls on that page(request) use the cached data.
 * ============================================================================
 */
add_shortcode('turq_market', function($atts = [], $content = null, $tag = '' ) {

    $stores = get_posts([
        'post_type'      => 'wpsl_stores',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'meta_query'     => [['key'=>'wpsl_hours','compare'=>'EXISTS']]
    ]);

	static $columns = [];
	if (empty($columns)) {
		foreach ( $stores as $store ) {
			foreach(maybe_unserialize(get_post_meta($store->ID,'wpsl_hours',true)) as $day => $hours)
					if ($hours) $columns[$day][] = $store;
		}
		$style ="<style>";
		$style.=".market_data{display: flex;flex-direction: column} .market_data a {text-decoration:none}";
		$style.="</style>";
	}

	$message_flag	= false;
    $md				= strtolower($atts[0]);
	$items			= $columns[$md];

    if(empty($items)) return;

	// Sort by turq_order for this day
	usort($items, function($a,$b) use ($md){

		$a_meta = get_post_meta($a->ID,'wpsl_turq_order',true);
		$b_meta = get_post_meta($b->ID,'wpsl_turq_order',true);

		$a_pos = $a_meta[$md] ?? PHP_INT_MAX;
		$b_pos = $b_meta[$md] ?? PHP_INT_MAX;

		return $a_pos <=> $b_pos;
	});

	$default_hours	= get_option('wpsl_settings')['editor_hours']['dropdown'];
	$normal_hours	= $default_hours[$md][0];
	$markets		= "<p><strong>".str_replace([' ', ','], ['&nbsp;', ' to '], esc_html($normal_hours))."</strong></p>";

    foreach ( $items as $p ) {
		
		$p_content = $p->post_content ?? "";
		if (!empty($p_content)) {
			$p_content = "<div class='marktet_message'>".preg_replace( '#<p>\s*</p>#', '', do_blocks($p_content) )."</div>";
			$message_flag = true;
		}
		$meta = get_post_meta($p->ID);		
		$correct_day = false;
		$out =  "<div class='market_data'>";

		$out .=	"<div>";
		$out .= "<span style='text-transform: uppercase; text-decoration: underline;font-weight:bold'>".esc_html($meta['wpsl_city'][0])."</span></br>";
		$out .= esc_html($p->post_title)."</br>".esc_html($meta['wpsl_address'][0])."</br>".esc_html($meta['wpsl_zip'][0]);
		$out .=	"</div>";
		
		foreach(unserialize($meta['wpsl_hours'][0]) as $key=>$hours)
			foreach($hours as $hour)
				if($key == $md) {$correct_day = true; $out .=  $normal_hours!=$hour ? "<div><span style='font-style: italic'>NOTE</span> : ".str_replace([' ', ','], ['&nbsp;', ' to '], esc_html($hour))."</div>" : "";}
		
		$html_location = "<div>";
		$url = sanitize_url(get_post_meta($p->ID, 'wpsl_url', true));
		if (!empty($url)) $html_location .= "<a href='".$url."'>Location</a> | ";
		$html_location .= '<a href="https://www.google.com/maps/dir/?api=1&destination='.sanitize_title($p->post_title).','.sanitize_title($meta['wpsl_address'][0]).','.sanitize_title($meta['wpsl_city'][0]).','.esc_html($meta['wpsl_zip'][0]).',UK&travelmode=driving">Directions</a>';
		$html_location .= "</div>";
		
		$out .= $html_location . $p_content;
		$out .= "</div>";

		if ($correct_day) $markets .= $out;
    }

    return ($style ?? '') .$markets;
});


/**
 * ============================================================================
 * Closed Markets Shortcode
 * ============================================================================
 *
 * Provides a shortcode to output closed market information if set as an option
 * ============================================================================
 */
add_shortcode('turq_markets_closed', function($atts = [], $content = null, $tag = '' ) {

	$today_start	= strtotime(date("d-m-Y", TODAY));														// get today as plain date UTC with no current time element
	$closed_dates	= turq_Market_Setup()->get_market_closed_dates();

	$out = '';
	foreach ($closed_dates as $date_UTC => $details)
		if (TODAY >= $details['notice_start'] && TODAY < $details['notice_end'] ) {
			$out .= $details['label']." : ".$details['closed_date'];
			if ($today_start == $date_UTC ) $out .= " <mark style='color:red'>TODAY</mark>";
			$out .= ", ";
		}

	if ($out)	return "<span>".trim($out, ', ')."</span>";
	else		return '';
});



/**
 * ============================================================================
 * Latest Market Shortcode
 * ============================================================================
 *
 * Provides a shortcode to output details from the latest published WPSL Store.
 *
 * Usage: e.g. [turq_latest_market field="location"]
 *
 * Market details are cached indefinitely in a transient.
 * The cache is automatically rebuilt whenever a WPSL Store is saved.
 * Only one database query is required after an update.
 * All subsequent page loads use the cached data.
 * ============================================================================
 */
const TURQ_LATEST_MARKET_CACHE = 'turq_latest_market';


// 
// Build (or rebuild) the cached market data.
// 
// @return array
// 
function turq_build_latest_market_cache() {

	$posts = get_posts( [
		'post_type'		 => 'wpsl_stores',
		'posts_per_page' => 1,
		'orderby'		 => 'date',							// use 'date' for production, 'modified' for debug
		'order'          => 'DESC',
		'post_status'    => 'publish',
	] );

	if ( empty( $posts ) ) {
		delete_transient( TURQ_LATEST_MARKET_CACHE );
		return [];
	}

	$post = $posts[0];
	$meta = get_post_meta( $post->ID );

	$day_time = [];
	foreach(unserialize($meta['wpsl_hours'][0]) as $day => $hours)
		if ($hours) $day_time[] = ucwords($day)." from ".str_replace([' ', ','], ['&nbsp;', ' to '], $hours[0] );

	$market = [
		'location'	=> $meta['wpsl_city'][0] ?? '',
		'place'		=> $post->post_title ?? '',
		'address'	=> implode("<br>", [$post->post_title ?? '', $meta['wpsl_address'][0] ?? '', $meta['wpsl_city'][0] ?? '', $meta['wpsl_zip'][0] ?? '', ]),
		'day_time'	=> (count($day_time)>1 ? "<br>" : "") . implode("<br>", $day_time),
	];

	// Cache forever (until manually refreshed).
	set_transient( TURQ_LATEST_MARKET_CACHE, $market, 0 );

	return $market;
}


// 
// Return the cached market data.
// 
// If the cache doesn't yet exist, build it.
// 
// @return array
// 
function turq_get_latest_market() {

	$market = get_transient( TURQ_LATEST_MARKET_CACHE );

	if ( $market === false ) {
		$market = turq_build_latest_market_cache();
	}

	return $market;
}


// 
// Shortcode:
// 
// [turq_latest_market field="location"]
// 
add_shortcode( 'turq_latest_market', function ( $atts ) {

	$atts = shortcode_atts(
		[
			'field' => '',
		],
		$atts,
		'turq_latest_market'
	);

	$market = turq_get_latest_market();

	return wp_kses(($market[ $atts['field'] ] ?? ''), array('br' => array()));
});

//
// Refresh the cache whenever a WPSL Store is saved.
//
// This keeps the cache permanently warm without requiring
// the first visitor after an edit to rebuild it.
//
add_action( 'save_post_wpsl_stores', 'turq_refresh_latest_market_cache' );
function turq_refresh_latest_market_cache( $post_id ) {

	// Ignore autosaves and revisions.
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	turq_build_latest_market_cache();
}




//
// Also refresh if a store is deleted.
//
add_action( 'deleted_post', 'turq_refresh_latest_market_after_delete' );
function turq_refresh_latest_market_after_delete( $post_id ) {

	if ( get_post_type( $post_id ) !== 'wpsl_stores' ) {
		return;
	}

	turq_build_latest_market_cache();
}





//
// Modify WPSL features
// --------------------
// This is the template that appears in the store locator popup

add_filter( 'wpsl_hide_closed_hours', '__return_true' );

add_filter( 'wpsl_listing_template', function() {

    global $wpsl, $wpsl_settings;
    
    $listing_template = '<li data-store-id="<%= id %>">' . "\r\n";
    $listing_template .= "\t\t" . '<div class="wpsl-store-location">' . "\r\n";
    $listing_template .= "\t\t\t" . '<p>' . "\r\n";
    $listing_template .= "\t\t\t\t" . '<span style="text-decoration: underline;"><strong><%= city %></strong></span></br>' . "\r\n";
	$listing_template .= "\t\t\t\t" . '<span><%= store %></span>' . "\r\n";
    $listing_template .= "\t\t\t\t" . '<span class="wpsl-street"><%= address %></span>' . "\r\n";
    $listing_template .= "\t\t\t\t" . '<% if ( zip ) { %>' . "\r\n";
    $listing_template .= "\t\t\t\t" . '<span><%= zip %></span>' . "\r\n";
    $listing_template .= "\t\t\t\t" . '<% } %>' . "\r\n";

    if ( !$wpsl_settings['hide_country'] ) {
        $listing_template .= "\t\t\t\t" . '<span class="wpsl-country"><%= country %></span>' . "\r\n";
    }

    $listing_template .= "\t\t\t" . '</p>' . "\r\n";
    
    // Include the opening hours, unless they are set to hidden on the settings page.
    if ( !$wpsl_settings['hide_hours'] ) {
        $listing_template .= "\t\t\t" . '<% if ( hours ) { %>' . "\r\n";
        $listing_template .= "\t\t\t" . '<p><%= hours %></p>' . "\r\n";
        $listing_template .= "\t\t\t" . '<% } %>' . "\r\n";
    }

    // Show the phone, fax or email data if they exist.
    if ( $wpsl_settings['show_contact_details'] ) {
        $listing_template .= "\t\t\t" . '<p class="wpsl-contact-details">' . "\r\n";
        $listing_template .= "\t\t\t" . '<% if ( phone ) { %>' . "\r\n";
        $listing_template .= "\t\t\t" . '<span><strong>' . esc_html( $wpsl->i18n->get_translation( 'phone_label', __( 'Phone', 'wpsl' ) ) ) . '</strong>: <%= formatPhoneNumber( phone ) %></span>' . "\r\n";
        $listing_template .= "\t\t\t" . '<% } %>' . "\r\n";
        $listing_template .= "\t\t\t" . '<% if ( fax ) { %>' . "\r\n";
        $listing_template .= "\t\t\t" . '<span><strong>' . esc_html( $wpsl->i18n->get_translation( 'fax_label', __( 'Fax', 'wpsl' ) ) ) . '</strong>: <%= fax %></span>' . "\r\n";
        $listing_template .= "\t\t\t" . '<% } %>' . "\r\n";
        $listing_template .= "\t\t\t" . '<% if ( email ) { %>' . "\r\n";
        $listing_template .= "\t\t\t" . '<span><strong>' . esc_html( $wpsl->i18n->get_translation( 'email_label', __( 'Email', 'wpsl' ) ) ) . '</strong>: <%= email %></span>' . "\r\n";
        $listing_template .= "\t\t\t" . '<% } %>' . "\r\n";
        $listing_template .= "\t\t\t" . '</p>' . "\r\n";
    }

    $listing_template .= "\t\t\t" . wpsl_more_info_template() . "\r\n"; // Check if we need to show the 'More Info' link and info
    $listing_template .= "\t\t" . '</div>' . "\r\n";
    $listing_template .= "\t\t" . '<div class="wpsl-direction-wrap">' . "\r\n";

    if ( !$wpsl_settings['hide_distance'] ) {
        $listing_template .= "\t\t\t" . '<%= distance %> ' . esc_html( $wpsl_settings['distance_unit'] ) . '' . "\r\n";
    }

    $listing_template .= "\t\t\t" . '<%= createDirectionUrl() %>' . "\r\n"; 
    $listing_template .= "\t\t" . '</div>' . "\r\n";
    $listing_template .= "\t" . '</li>';

    return $listing_template;
});




// ============================
//       Admin features
// ============================


//
// Rename "store" to "market"
// --------------------------
add_filter('wpsl_post_type_labels', function() {

            $labels = array(
                    'name'               => __( 'Market Locator', 'wpsl' ),
                    'all_items'          => __( 'All Markets', 'wpsl' ),
                    'singular_name'      => __( 'Marker', 'wpsl' ),
                    'add_new'            => __( 'New Market', 'wpsl' ),
                    'add_new_item'       => __( 'Add New Market', 'wpsl' ),
                    'edit_item'          => __( 'Edit Market', 'wpsl' ),
                    'new_item'           => __( 'New Market', 'wpsl' ),
                    'view_item'          => __( 'View Markets', 'wpsl' ),
                    'search_items'       => __( 'Search Markets', 'wpsl' ),
                    'not_found'          => __( 'No Markets found', 'wpsl' ),
                    'not_found_in_trash' => __( 'No Markets found in trash', 'wpsl' ),
                ); 
            return $labels;		
});

add_filter( 'wpsl_store_category_args', function($args){$args['show_ui']=false;return $args;});
add_filter( 'wpsl_sub_menu_items',  function($args){array_pop($args);return $args;});


//
// Add open day into CPT column
// ----------------------------
// register the column and sets it's title
add_filter('manage_wpsl_stores_posts_columns', function ($columns) {
    $new_columns = [];

    foreach ( $columns as $key => $value ) {
        $new_columns[$key] = $value;
        if ( $key === 'title' ) {
			$new_columns['open_day'] = "Market Days";
		}
    }

    return $new_columns;	
});

//
// display the column value
// ------------------------
add_action( 'manage_wpsl_stores_posts_custom_column', function ($column_name, $post_id){

	if ( $column_name === 'open_day' ) {

		$hours = get_post_meta( $post_id, 'wpsl_hours', true );

		// Unserialize if needed
		if ( ! empty( $hours ) && is_serialized( $hours ) ) {
			$hours = maybe_unserialize( $hours );
		}

		if ( is_array( $hours ) && ! empty( $hours ) ) {
			$output = [];

			foreach ( $hours as $day => $slots ) {
				if ( ! empty( $slots ) && is_array( $slots ) ) {
					$day_name = ucfirst( $day );
					$formatted_slots = [];

					foreach ( $slots as $slot ) {
						// Expecting "9:00 AM,2:00 PM"
						$times = explode( ',', $slot );

						if ( count( $times ) === 2 ) {
							$formatted_slots[] = trim( $times[0] ) . '–' . trim( $times[1] );
						} else {
							// If format unexpected, just show raw value
							$formatted_slots[] = $slot;
						}
					}

					$output[] = sprintf(
						'<strong>%s:</strong> %s',
						$day_name,
						implode( ', ', $formatted_slots )
					);
				}
			}

			echo ! empty( $output )
				? implode( '<br>', $output )
				: '<em>Closed</em>';

		} else {
			echo '<em>No hours</em>';
		}
	}
	
}, 10, 2); // priority, number of args - MANDATORY HERE! 


//
// Darg & Drop re-order
// --------------------
// Add new menu item and a clean screen to re order markets as generated by shortcode.
add_action('admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=wpsl_stores',
        'Reorder Stores by Day',
        'Reorder by Day',
        'edit_posts',
        'reorder-stores-day',
        'wpsl_render_reorder_by_day'
    );
});

function wpsl_render_reorder_by_day() {

    $stores = get_posts([
        'post_type'      => 'wpsl_stores',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'ASC',
        'meta_query'     => [['key'=>'wpsl_hours','compare'=>'EXISTS']]
    ]);

    $weekdays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

    // Initialize weekday columns
    $columns = array_fill_keys($weekdays, []);

    // Assign stores to columns
    foreach ($stores as $store) {
        $days = maybe_unserialize(get_post_meta($store->ID,'wpsl_hours',true));
        if(!$days) continue;

        foreach($weekdays as $day) {
            if(!empty($days[$day])) {
                $columns[$day][] = $store;
            }
        }
    }

    wp_nonce_field('wpsl_reorder_nonce','wpsl_nonce');

    echo "<div class='wrap'><h1>Reorder Markets within Open Day</h1>"
		."<p class='description'>Drag and drop markets within the day column to reorder the display as shown on the front-end webpage.</p>"
		."<div id='wpsl-board' style='display:flex;gap:20px;'>";


    foreach($columns as $day => $items) {
        if(empty($items)) continue;

        // Sort by turq_order for this day
		usort($items, function($a,$b) use ($day){

			$a_meta = get_post_meta($a->ID,'wpsl_turq_order',true);
			$b_meta = get_post_meta($b->ID,'wpsl_turq_order',true);

			$a_pos = $a_meta[$day] ?? PHP_INT_MAX;
			$b_pos = $b_meta[$day] ?? PHP_INT_MAX;

			return $a_pos <=> $b_pos;
		});

        echo "<div class='wpsl-column'><h2>".ucfirst($day)."</h2><ul class='wpsl-sort' data-day='".$day."' style='min-width:260px;padding:5px'>";

        foreach($items as $store) {

            // Multi-day badge
            $store_days = maybe_unserialize(get_post_meta($store->ID,'wpsl_hours',true));
            $used_days = [];
            if($store_days){
                foreach($store_days as $d => $hours){
                    if(!empty($hours)) $used_days[] = ucfirst($d);
                }
            }
            $badge = count($used_days) > 1 ? '<span class="wpsl-day-badge">'.implode(' ',$used_days).'</span>' : '';

            $weight = maybe_unserialize(get_post_meta($store->ID,'wpsl_turq_order',true))[$day] ?? 1000;
			$city = get_post_meta($store->ID,'wpsl_city',true) ?? '';

            echo "<li data-id='".$store->ID."' data-order='".$weight."' style='margin-top:8px;padding:12px;background:#fff;border-radius:8px;cursor:move;box-shadow:0 1px 3px rgba(0,0,0,.08);'>"
                ."<span class='dashicons dashicons-move' style='float:right'></span> 
				<span style='text-transform: uppercase; text-decoration: underline;font-weight:bold'>".esc_html($city)."</span></br>".esc_html($store->post_title)."</br>"
                .$badge
                ."</li>";
        }

        echo "</ul></div>";
    }

    echo "</div></div>";
}


add_action('admin_head', function(){

    $screen = get_current_screen();
    if($screen->id !== 'wpsl_stores_page_reorder-stores-day') return;
?>
	<style>

		#wpsl-board{
			display:flex;
			gap:20px;
			align-items:flex-start;
			overflow-x:auto;
			padding-top:20px;
		}

		.wpsl-column{
			min-width:260px;
			background:#f6f7f7;
			padding:12px;
			border-radius:12px;
		}

		.wpsl-column h2{
			text-align:center;
			margin-bottom:10px;
		}

		.wpsl-sort{
			min-height:40px;
		}

		.wpsl-sort li{
			background:#fff;
			padding:12px;
			margin-bottom:8px;
			border-radius:8px;
			cursor:move;
			box-shadow:0 1px 3px rgba(0,0,0,.08);
		}

		.wpsl-sort li:hover{
			transform:scale(1.02);
		}

		.wpsl-day-badge{
			display:inline-block;
			margin-top:8px;
			padding:2px 6px;
			font-size:11px;
			font-weight:600;
			background:#fffae6;
			border:1px solid #f0e68c;
			border-radius:4px;
			color:#333;
		}

		.wpsl-sort.wpsl-saved {
		  outline: 2px solid #46b450;
		  outline-offset: 2px;
		}
		
	</style>
<?php
});

add_action('admin_enqueue_scripts', function($hook){
    if($hook !== 'wpsl_stores_page_reorder-stores-day') return;

    wp_enqueue_script('jquery-ui-sortable');

    wp_add_inline_script('jquery-ui-sortable', '
		jQuery(function($){

		  $(".wpsl-sort").sortable({
			connectWith: false,
			placeholder:"ui-state-highlight",
			update: function () {

			  let day   = $(this).data("day");
			  let order = [];

			  $(this).children("li").each(function(index){
				order.push({
				  id: $(this).data("id"),
				  pos: index
				});
			  });

			  $.ajax({
				url: ajaxurl,
				type: "POST",
				data: {
				  action: "wpsl_save_turq_order",
				  nonce: $("#wpsl_nonce").val(),
				  day: day,
				  order: order
				},
				success: function(resp){
				  if(resp.success){
					// instant visual confirmation
					$(this).addClass("wpsl-saved");
					setTimeout(() => {
					  $(".wpsl-saved").removeClass("wpsl-saved");
					}, 400);
				  }
				}.bind(this)
			  });

			}
		  });

		});
    ');
});


add_action('wp_ajax_wpsl_save_turq_order', function(){

    check_ajax_referer('wpsl_reorder_nonce','nonce');
    if(!current_user_can('edit_posts')){
        wp_send_json_error();
    }

    $day   = sanitize_text_field($_POST['day']);
    $order = $_POST['order']; // [{id, pos}]

    foreach($order as $row){

        $post_id = (int)$row['id'];
        $pos     = (int)$row['pos'];

        $meta = get_post_meta($post_id,'wpsl_turq_order',true);
        $meta = is_array($meta) ? $meta : [];

        $meta[$day] = $pos;

        update_post_meta($post_id,'wpsl_turq_order',$meta);
    }

    wp_send_json_success();
});

?>