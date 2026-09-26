<?php
/*******************************************************************************
 * Customisation to product archives/single and admin setup
 * 
 * turq_woo_product.php
 * 
*******************************************************************************/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


//.ti-archive-outofstock:not(:has(.ajax_add_to_cart)) .kb-buttons-wrap {display: none;}


// Radio select for group add to cart
// --------------------------------------
add_action('woocommerce_before_variations_form','render_package_select_radios',5);
add_action('woocommerce_before_add_to_cart_button',function() {
	global $product;
    
	if ($product->is_type('variable')) return;

	render_package_select_radios();
		
},5);

function render_package_select_radios() {
    global $product;

    if (!$product || !$product->is_purchasable()) return;

    $package_tag_map = turq_Market_Setup()->get_active_shipping_groups();
	//print_r($package_tag_map);

	$terms = wp_get_post_terms( $product->get_id(), 'product_tag' );
    if (empty($terms) || is_wp_error($terms)) return;

	//print_r($packages);
	
	$e = [];
    foreach ( $terms as $term ) {
        if (isset($package_tag_map[$term->term_id])) $e[$term->name] = '<input type="radio" name="package_group" value="'.esc_attr($package_tag_map[ $term->term_id ]).'" required>';
	};
	
	if (count($e) == 1) {
		echo str_replace(['radio', 'required'], ['hidden', ''], current($e));
	} elseif (!empty($e)) {	?>
		<table class="variations" cellspacing="0" role="presentation">
			<tbody>
				<tr>
					<th class="label"><label>Add for</label></th>
					<td class="value">
						<div class="turq-variation-radios">
							<?php foreach ($e as $k => $i) echo $i."<label>".esc_html($k)."</label>"; ?>
						</div>
					</td>
				</tr>
			</tbody>
		</table>
	<?php };

}

// Helper - if product has no "enabled" groups/tags
//-------------------------------------------------
function OLDis_product_group_enabled() {
	$product	= wc_get_product( get_queried_object_id() );
	$terms		= wp_get_post_terms( $product->get_id(), 'product_tag' );
	if (empty($terms) || is_wp_error($terms)) return false;

	$package_tag_map = turq_Market_Setup()->get_active_shipping_groups();

	$valid = false;
	foreach ( $terms as $term ) if (isset($package_tag_map[$term->term_id])) $valid = true;

	return $valid;
}

function is_product_group_enabled() {
	$product		= wc_get_product( get_queried_object_id() );
	if (!$product)	return false;

	$product_groups = array_flip($product->get_tag_ids());
	$events			= turq_Market_Setup()->get_active_shipping_groups();
	$valid			= !empty(array_intersect_key( $events, $product_groups ));
	
	return $valid;
}


// Remove product "add to cart" and associated elements
//-----------------------------------------------------
// if product has no "enabled" groups/tags remove ability to buy
add_filter( 'pre_render_block', function ( $pre_render, $block ) {

    if ( ! is_product() ) return $pre_render;	// only product pages

	if ( $block['blockName'] === 'kadence/column' && ! empty( $block['attrs']['className'] ) && str_contains( $block['attrs']['className'], 'turq_product_panel' ) && !is_product_group_enabled())
		return "<div class='woocommerce-info'>Sorry this product cannot be purchased at the moment.</div><style>.kadence-sticky-add-to-cart {display:none}</style>
	<script>document.addEventListener('DOMContentLoaded', function() { document.querySelectorAll('.kadence-sticky-add-to-cart').forEach(el => el.remove());}); </script>" ;

    return $pre_render;
}, 10, 2 );



// SEO adjustments
//----------------
// set out of stock if product has no "enabled" groups/tags
// only on single product page
add_filter( 'woocommerce_structured_data_product_offer', function ( $offer ) {
    if ( is_product() && !is_product_group_enabled() ) {
        $offer['availability'] = "https://schema.org/OutOfStock";
    }
    return $offer;
});

// Helper - Text length, trim ...
//-------------------------------
function trim_text($text) {

	$max  = 310;	
	$text = trim( $text );
	
	if ( mb_strlen( $text ) <= $max ) {
		return [
			'text'    => $text,
			'trimmed' => false,
		];
    }

	// Cut to max length
	$cut = mb_substr( $text, 0, $max );

	// Backtrack to last whitespace
	$cut = preg_replace( '/\s+\S*$/u', '', $cut );

    return [
        'text'    => rtrim( $cut ) . '...',
        'trimmed' => true,
    ];

}

// Pre-pend producer description to structured data description
// ------------------------------------------------------------
// check if on product page only
add_filter( 'woocommerce_structured_data_product', function ( $data, $product ) {

        if ( empty($GLOBALS['turq_producer_description']) ) return $data;

        $seo = $GLOBALS['turq_producer_description'];

        // Guard: on product page or wrong product
        if ( !is_product() || empty( $seo['id'] ) || $seo['id'] != $product->get_id()) return $data;
		
		// already trimmed ?
		if ($seo['trimmed']) {
			$data['description'] = $seo['text'];
			return $data;
		}
			
		$existing = wp_strip_all_tags( $data['description'] ?? '' );		

		if (!$existing) {
			$data['description'] = $seo['text'];
			return $data;
		}

		$result = trim_text($seo['text'].' '.$existing);
		$data['description'] = $result['text'];

        return $data;
    },
    50,
    2
);

// Capture HTML output by Elements for producer description
//---------------------------------------------------------
// turq_producer_description hook only runs from single product template page proirty=10
// text injected into structured data (above)
add_action( 'turq_producer_description', 'turq_buffer_start', 0 );
add_action( 'turq_producer_description', 'turq_buffer_end',  PHP_INT_MAX );

function turq_buffer_start() { ob_start();}
function turq_buffer_end()   {

    $html = ob_get_clean();

    if ( empty( $html ) ) return;
    
    // Remove HTML comments (Kadence block markers)
    $html = preg_replace( '/<!--(.|\s)*?-->/', '', $html );

    // Strip all HTML tags
    $text = wp_strip_all_tags( $html );

    // Decode entities (&nbsp;, &amp;, etc.)
    $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    // Normalise whitespace
    $text = preg_replace( '/\s+/', ' ', $text );

    // Trim - array returned
	$result = trim_text($text);
	
	// Get ID from query if on single product page
	$p_id 	= is_product() ? get_queried_object_id() : null;	

    $GLOBALS['turq_producer_description'] = ['id' => $p_id, 'trimmed' => $result['trimmed'], 'text' => $result['text'] ];

	echo $html;
}


// Style TAG cloud
// ---------------
add_filter( 'render_block', function( $contents, $block ) {
	//error_log("block = ".print_r($block,true));	
	//error_log("name = ".$block['blockName']);
	//error_log("atts = ".print_r($block['attrs'],true));	
	
    if ($block['blockName'] === 'core/post-terms' && !empty($contents) && ($block['attrs']['term'] ?? '') === 'product_tag') {

		$package_tag_map	= turq_Market_Setup()->get_active_shipping_groups();
		
		// Load HTML safely
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML($contents,  LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		$good_links = 0;
		$links_to_remove = [];
		$links = $dom->getElementsByTagName( 'a' );
		
		foreach ( $links as $link ) {
			$slug = basename( $link->getAttribute( 'href' ) );

			$term = get_term_by( 'slug', $slug, 'product_tag' );
			if (!$term) continue;

			if ($package_tag_map[$term->term_id] ?? false) {
				$good_links++;
				
				$existing_class = $link->getAttribute( 'class' );
				$link->setAttribute(
					'class',
					trim( $existing_class . ' group_pill')
				);

				$colours = turq_Market_Setup()->get_group_lookup($term->term_id);
				$link->setAttribute(
					'style',
					sprintf(
						'background:%s;color:%s;',
						esc_attr( $colours['background'] ),
						esc_attr( $colours['color'] ?: '#000' )
					)
				);
			}
			else  $links_to_remove[] = $link; // remove any tags that are not related to shipping/event groups
		}

		foreach ( $links_to_remove as $link )$link->parentNode->removeChild( $link );

		$style = "<style>a.group_pill {display:inline-block; padding: 0.3em 0.7em; border-radius: 999px; font-size: 0.85em; margin: 0.3em; text-decoration: none}</style>";

		if ($good_links <= 1 && count($package_tag_map) <= 1) {
			$style .= "<p class='turq_single_event'>only one tag</p><style>#turq_product_panel .wp-block-kadence-column:has(.turq_single_event) {display:none}</style>";			
		}
		return $dom->saveHTML().$style;
    }
	
    return $contents;
}, 10, 2 );



// ============================
// ADD TO CART VALIDATION
// ============================
// This is for LOOP items, not single product page.

// Helper - checks product event/group/tag details
// -----------------------------------------------
function check_product_options($product) {

    static $cache	= [];
    static $groups	= null;
    static $events	= null;

    $product_id 	= $product->get_id();

    if (isset($cache[$product_id])) return $cache[$product_id];

    $groups ??= turq_Market_Setup()->get_multi_shipping_groups();
    $events ??= turq_Market_Setup()->get_active_shipping_groups();

    $single_event   = count($events) <= 1;
	$valid			= array_intersect_key($events, array_flip($product->get_tag_ids()));
	$group_key		= $events[array_key_first($valid)] ?? '';
	$label          = $group_key ? $groups[$group_key]['label'] : '';

/*
	error_log("product".print_r($product_id,true));
	error_log("product_groups".print_r($product->get_tag_ids(),true));
	error_log("events".print_r($events,true));
	error_log("single".print_r($single_event,true));
	error_log("valid".print_r($valid,true));
*/
    return $cache[$product_id] = [
        'valid'			=> $valid,
        'single_event'	=> $single_event,
        'label'			=> $label,
		'group_key'		=> $group_key,
    ];
}

// Adds class to hide other button
// -------------------------------
add_filter('woocommerce_loop_add_to_cart_args', function($args, $product){
	//error_log("woocommerce_loop_add_to_cart_args--------------- ".print_r($args,true));
	
	$button = check_product_options($product);

    if ( $product->is_type('variable') || count($button['valid']) <=1 ) $args['class'] .= ' turq_hide_other';

	return $args;
}, 1, 2);

// Change button link to product
// -----------------------------
add_filter('woocommerce_product_add_to_cart_url', function($url, $product){
	//error_log("woocommerce_product_add_to_cart_url--------------- ".print_r($url,true));

	$button = check_product_options($product);

    if  (!$button['valid']) $url =  $product->get_permalink();

	return $url;
}, PHP_INT_MAX, 2);

// Button text depending
// ---------------------
add_filter('woocommerce_product_add_to_cart_text', function($html, $product){
	//error_log("woocommerce_product_add_to_cart_text--------------- ".print_r($html,true));

	$button = check_product_options($product);

    if 		(!$button['valid'])												$html = "Read more";
    else if (!$button['single_event'] && !$product->is_type('variable'))	$html = "Add for ".$button['label'];

	return $html;
}, PHP_INT_MAX , 2);

// Remove "View Basket" message after ajax add to cart
// ---------------------------------------------------
add_filter('woocommerce_add_to_cart_redirect', '__return_false');

//
// global styling of product loop item
// -----------------------------------
// instead of in loop template as this over populates css
add_action( 'wp_footer', function () {
	?>
	<style id="turq_loop_items">
		li.outofstock .ti-archive-outofstock {min-height: 26px;background-color: var(--global-palette2);  color: #fff}
		li.outofstock .button {display:none !important}
		li.outofstock .ti-archive-outofstock::after {content: "Out of Stock";padding-left:10px}
		/*li.outofstock.product_tag-on-the-van  .ti-archive-outofstock::after {content: "On the van only"}*/
		li:has(a.turq_hide_other) .turq_other_options {display:none}
		#main ul.products.woo-archive-btn-button li.product .button {display:flex}
		#main ul.products.woo-archive-btn-button li.product .button.added {height: auto;  padding:5px 12px 5px 12px;  overflow: visible;}
		#main .added_to_cart {display:none}
		
		#wrapper form.variations_form table:has(.turq-variation-radios) {margin-bottom:0}
	</style>
<?php	
});



//
// Output the result count text (Showing x - x of x results).
// ---------------------------------------------------------
if ( ! function_exists( 'woocommerce_result_count' ) ) {
	function woocommerce_result_count() {
		if ( ! wc_get_loop_prop( 'is_paginated' ) || ! woocommerce_products_will_display() ) {
			return;
		}
		$total    = wc_get_loop_prop( 'total' );
		$per_page = wc_get_loop_prop( 'per_page' );
		$current  = wc_get_loop_prop( 'current_page' );
		?>
			<p class="woocommerce-result-count">
				<?php
				if (is_product_category()) {
					global $wp_query;
					$q = $wp_query->get_queried_object();
					//print_r($q);
					if ($q->parent != 0) {
						$term = get_term_by( 'id', $q->parent, 'product_cat', 'ARRAY_A' );
						//print_r($term);
						$parent = '<a class="turq-breabcrumb" href="'.get_category_link($term['term_id']).'">'.ucwords($term['name'])."</a> / ";
					}
					//print_r(get_category_link($q->term_id));

					echo $parent.'<a class="turq-breabcrumb" href="'.get_category_link($q->term_id).'">'.ucwords($q->name)."</a> : ";
					echo '<style>.turq-breabcrumb{text-decoration:none;color:unset;}.turq-breabcrumb:hover{color:var(--global-palette1)}</style>';
					
				}
				if ( 1 === intval( $total ) ) {
					_e( 'Showing the single result', 'woocommerce' );
				} elseif ( $total <= $per_page || -1 === $per_page ) {
					printf( _n( 'Showing all %d result', 'Showing all %d results', $total, 'woocommerce' ), $total );
				} else {
					$first = ( $per_page * $current ) - $per_page + 1;
					$last  = min( $total, $per_page * $current );
					printf( _nx( 'Showing %1$d&ndash;%2$d of %3$d result', 'Showing %1$d&ndash;%2$d of %3$d results', $total, 'with first and last result', 'woocommerce' ), $first, $last, $total );
				}
				?>
			</p><?php	}
}

//
// [product_count] shortcode
// -------------------------
add_shortcode( 'product_count', function ( ) {

	global $wpdb;

	$count = $wpdb->get_var("
		SELECT COUNT(DISTINCT p.ID)
		FROM wp_posts p
		INNER JOIN wp_postmeta pm ON p.ID = pm.post_id
		WHERE p.post_type = 'product'
		AND p.post_status = 'publish'
		AND pm.meta_key = '_stock_status'
		AND pm.meta_value = 'instock'
		AND p.ID NOT IN (
			SELECT tr.object_id
			FROM wp_term_relationships tr
			INNER JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN wp_terms t ON tt.term_id = t.term_id
			WHERE tt.taxonomy = 'product_visibility'
			AND t.slug = 'exclude-from-catalog'
		)
	");

	return (int) $count;	
	
});

//
// Convert dropdown to radio buttons
// ---------------------------------
add_filter( 'woocommerce_dropdown_variation_attribute_options_html', function ( $html, $args ) {
	
	// in wc_dropdown_variation_attribute_options() they also extract all the array elements into variables
	$options   = $args[ 'options' ];
	$product   = $args[ 'product' ];
	$attribute = $args[ 'attribute' ];
	$name      = $args[ 'name' ] ? $args[ 'name' ] : 'attribute_' . sanitize_title( $attribute );
	$id        = $args[ 'id' ] ? $args[ 'id' ] : sanitize_title( $attribute );
	$class     = $args[ 'class' ];

	if( empty( $options ) || ! $product ) {
		return $html;
	}
	
	// HTML for our radio buttons
	$radios = '<div class="turq-variation-radios">';
	$radio_cnt = 0;

	// taxonomy-based attributes
	if( taxonomy_exists( $attribute ) ) {

		$terms = wc_get_product_terms(
			$product->get_id(),
			$attribute,
			array(
				'fields' => 'all',
			)
		);
		
		$single = count($terms)==1;
		foreach( $terms as $term ) {
			if( in_array( $term->slug, $options, true ) ) {
				$radios .= "<div".($single?" class='turq-variations-single'" : '')."><input type=\"radio\" id=\"{$name}-{$term->slug}\" name=\"{$name}\" value=\"{$term->slug}\"" . checked( $args[ 'selected' ], $term->slug, false ) . "><label for=\"{$name}-{$term->slug}\">{$term->name}</label></div>";
				$radio_cnt++;
			}
		}
	// individual product attributes
	} else {
		foreach( $options as $option ) {
			$checked = sanitize_title( $args[ 'selected' ] ) === $args[ 'selected' ] ? checked( $args[ 'selected' ], sanitize_title( $option ), false ) : checked( $args[ 'selected' ], $option, false );
			$radios .= "<div><input type=\"radio\" id=\"{$name}-{$option}\" name=\"{$name}\" value=\"{$option}\" id=\"{$option}\" {$checked}><label for=\"{$name}-{$option}\">{$option}</label></div>";
			$radio_cnt++;
		}
	}
  
	$radios .= '</div>';

	if ($radio_cnt==1) $radios .= '<script>window.addEventListener("load", function() {setTimeout(function() {const firstRadio = document.querySelector(".turq-variation-radios input[type=radio]");if (firstRadio) {firstRadio.click();}}, 150);})</script><style>.kwt-price-single{display:none} tr:has(td .turq-variations-single) {display: none !important}</style>';
	
	return $html . $radios;
	
}, 20, 2 );

//
// show variations in archive loop
// -------------------------------
//add_action( 'woocommerce_after_shop_loop_item', 'product_variable_linked_variations_on_loop', 7 );
function product_variable_linked_variations_on_loop() {
    global $product;

    if( $product->is_type('variable') ) { // Only for variable products
        $output = array(); // Initializing

        // Loop through visible children Ids (variations ids)
        foreach( $product->get_visible_children() as $variation_id ) {
            $variation  = wc_get_product($variation_id); // The varition object instance
            $permalink  = $variation->get_permalink(); // The link to the variation
            $attributes = array(); // Initializing

            // Loop through attributes
            foreach( $variation->get_attributes() as $attribute => $value ) {
                $attribute_label = wc_attribute_label( $attribute, $product );
                $attribute_value = $variation->get_attribute($attribute);
                $attributes[]    = $attribute_label . ':&nbsp;' . $attribute_value;
            }
            $output[] = '<a href="' . $permalink . '">' . implode(', ', $attributes) . '</a>';
        }

        echo '<div class="product-attributes">';
        echo '<span>' . implode('</span><br><span>', $output) . '</span>';
        echo '</div>';
    }
}


// NEW DEV ????

function turq_product_has_weight_range( $product ) {

	if ( ! $product->is_type( 'variable' ) ) {
		return false;
	}

	foreach ( $product->get_children() as $variation_id ) {

		$v = get_post_meta( $variation_id, '_weight_range', true );

		if ( ! empty( $v ) ) {
			return true;
		}
	}

	return false;
}

add_filter( 'woocommerce_display_product_attributes', function ( $product_attributes, $product ) {

	if (turq_product_has_weight_range( $product )) $product_attributes['weight_range'] = array('label' => 'Weight','value' => "N/A");	
	
	$fieldname = $product->get_meta( 'turq_optional_field_name', true );
	if (!empty($fieldname)) $product_attributes[ 'turq_variation_opt1' ] = array('label' => $fieldname,'value' => 'N/A');	
	
	if (empty($v = $product->get_meta('tsp_allergens', true))) $v='None';
	if ($v!='n/a') $product_attributes[ 'turq_variation_opt2' ] = array('label' => 'Allergens','value' => $v);
	
	return $product_attributes;
}, 10, 2 );




/*
//
// Frontend Simple products > additional information
// -------------------------------------------------
add_filter( 'woocommerce_display_product_attributes', function ( $product_attributes, $product ) {

	if (in_array($product->get_id(),[1339, 1364])) $product_attributes['weight_range'] = array('label' => 'Weight','value' => "N/A");	
	
	$fieldname = $product->get_meta( 'turq_optional_field_name', true );
	if (!empty($fieldname)) $product_attributes[ 'turq_variation_opt1' ] = array('label' => $fieldname,'value' => 'N/A');	
	
	if (empty($v = $product->get_meta('tsp_allergens', true))) $v='None';
	if ($v!='n/a') $product_attributes[ 'turq_variation_opt2' ] = array('label' => 'Allergens','value' => $v);
	
	return $product_attributes;
}, 10, 2 );
*/ 

//
// Frontend Variable products: Link variation custom field value
// -------------------------------------------------------------
add_filter( 'woocommerce_available_variation', 'set_available_variation_custom_field', 10, 3 );
function set_available_variation_custom_field( $variation_data, $product, $variation ) {
	$nv = array(
		'weight_range'			=> empty($v=$variation->get_meta('_weight_range')) ? "N/A" : $v."kg",
		'turq_variation_opt1'	=> empty($v=$variation->get_meta('_turq_variation_opt1')) ? "N/A" : $v,
		'turq_variation_opt2'	=> empty($v=$product->get_meta('tsp_allergens')) ? "None" : $v,
	);	
	if ($v=='n/a') unset($nv['turq_variation_opt2']);
	return array_merge( $variation_data, $nv);	
}

//
// Variable Product (jQuery): Selected variation displays custom field value
// -------------------------------------------------------------------------
add_action( 'woocommerce_before_variations_form', function() { ?>
	<script type="text/javascript" id="turq_before_variations_form">
    jQuery( function($){
        var sizeObj1 = $('tr.woocommerce-product-attributes-item--turq_variation_opt1 > td');
		var sizeObj2 = $('tr.woocommerce-product-attributes-item--turq_variation_opt2 > td');
        var sizeObj3 = $('tr.woocommerce-product-attributes-item--weight_range > td');		
        $('form.variations_form').on('found_variation', function(event, data){
            sizeObj1.text(data.turq_variation_opt1);
			sizeObj2.text(data.turq_variation_opt2);
			sizeObj3.text(data.weight_range);			
        })
    })
	</script> <?php 
});



//
// Modify price output
// -------------------
add_filter( 'woocommerce_variation_prices_price', 'calculate_price_by_weight', 10, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'calculate_price_by_weight', 10, 2 );
function calculate_price_by_weight($price, $product){
	wc_delete_product_transients($product->get_id());
	if (empty($price) && ($pid=$product->get_parent_id())) {
		$meta = get_post_meta($pid);
		$weight = $product->get_weight();
		if ($weight > 0 && array_key_exists('turq_price_per_kg', $meta) && ($ppkgs = $meta['turq_price_per_kg'][0])!=0) $price = $ppkgs * $weight;
	}
    return $price;
}

//
// Control variation prices
// ------------------------
// since individual variations are set to price=0 when using £/kg we need to force them to show as they all have same price
add_filter( 'woocommerce_show_variation_price', '__return_true' );




////////////////////////////////////////////////////////////////////////////////
// Admin customisation
////////////////////////////////////////////////////////////////////////////////

//
// product custom fields in ADVANCED tab
// -------------------------------------
add_action( 'woocommerce_product_options_advanced', function (){

	echo '<div class="options_group">';
    woocommerce_wp_text_input( array( 
        'id' => 'tsp_allergens', 
        'class' => 'short wc_input_text', 
        'label' => 'Product Allergens :',
    ));      
	echo '</div>';

	echo '<div class="options_group">';
    woocommerce_wp_text_input( array( 
        'id' => 'turq_optional_field_name', 
        'class' => 'short wc_input_text', 
        'label' => 'Optional Field name :',
    ));      
	echo '</div>';

});

//
// save new meta on form process
// -----------------------------
add_action( 'woocommerce_process_product_meta', function( $id ){
	if (isset( $_POST[ 'tsp_allergens' ] )) update_post_meta( $id, 'tsp_allergens', $_POST[ 'tsp_allergens' ] );
	if (isset( $_POST[ 'turq_optional_field_name' ] )) update_post_meta( $id, 'turq_optional_field_name', $_POST[ 'turq_optional_field_name' ] );	
});


//
// product custom fields per variant
// ---------------------------------
add_action( 'woocommerce_variation_options_dimensions', function( $loop, $variation_data, $post ) {
	 $variation_id = $post->ID;
	
	//error_log("variation loop:".print_r($loop,true).":".print_r($variation_data,true).":".print_r($post,true));
    woocommerce_wp_text_input( array(
        'id'			=> "_weight_range_{$loop}",
        'name'			=> "_weight_range[{$variation_id}]",
        'value'			=> get_post_meta( $variation_id, '_weight_range', true ),
        'label'			=> 'Weight Range',
		'wrapper_class'	=> 'form-row form-row-full',
		'placeholder'	=> 'optional weight range...',
		'desc_tip'		=> true,
        'description'	=> 'Optional free-text weight range (e.g. "1–2kg", "Lightweight") - will override weight display on front-end',
    ) );	
		
	$fieldname = get_post_meta( $post->post_parent, 'turq_optional_field_name', true );
	if (empty($fieldname)) $fieldname = "OPTIONAL FIELD NOT SET - see advanced options";
	
	woocommerce_wp_text_input( array(
        'id'			=> "turq_variation_opt1{$loop}",
		'name'        	=> "turq_variation_opt1[{$variation_id}]",			
		'value'         => get_post_meta( $variation_id, '_turq_variation_opt1', true ),
		'label'         => $fieldname,
		'wrapper_class' => 'form-row form-row-full',
		'placeholder'   => 'your custom data...',
		'desc_tip'      => true,
		'description'   => 'An optional field to show in variation table',
																			  
   
	) );

}, 25, 3 );

//
// Admin Product > Variations: Save input text field submited value
// ----------------------------------------------------------------
add_action( 'woocommerce_admin_process_variation_object', 'save_admin_product_variations_custom_field', 10, 2 );
function save_admin_product_variations_custom_field( $variation, $i ) {
    if (isset($_POST['_weight_range'][$variation->get_id()]))		$variation->update_meta_data('_weight_range',			sanitize_text_field($_POST['_weight_range'][$variation->get_id()]));
	if (isset($_POST['turq_variation_opt1'][$variation->get_id()]))	$variation->update_meta_data( '_turq_variation_opt1',	sanitize_text_field($_POST['turq_variation_opt1'][$variation->get_id()]));
	 
}

//
// product custom fields in INVENTORY tab
// --------------------------------------
add_action( 'woocommerce_product_options_sku', function() {
	woocommerce_wp_text_input(
		array(
			'id'            => 'turq_price_per_kg',
			'label'         => 'Default £/kgs',
			'description'   => 'Only used if no variation price set.',
			'desc_tip'      => 'true',
		)
	);
}, 10);

//
// save new meta on form process
// -----------------------------
add_action( 'woocommerce_process_product_meta', function($id){
	if (isset($_POST['turq_price_per_kg']) && !empty($_POST['turq_price_per_kg'])) update_post_meta( $id, 'turq_price_per_kg', (float)$_POST[ 'turq_price_per_kg' ]);
	else delete_post_meta( $id, 'turq_price_per_kg');
}, 10, 1);

/*
add_action( 'woocommerce_single_product_summary', function(){
    global $product;
    do_action( 'woocommerce_product_additional_information', $product );
	echo "HERE";
}, 25 );
*/




////////////////////////////
// All Product admin columns
//--------------------------

//
// add custom xolumns
// ------------------
add_filter( 'manage_edit-product_columns', function($columns) {
	if(!is_array($columns)) $columns = array();
    $new_columns = array();

    foreach ( $columns as $column_name => $column_info ) {
        $new_columns[ $column_name ] = $column_info;
        if ( 'is_in_stock' === $column_name ) {
			$new_columns['other_sells'] = 'Cross/Up Sell';
			$new_columns['product_visibility'] = 'Visibility';
			$new_columns['ppkgs'] = 'Price per Kg';
		}
    }	
    return $new_columns;
}, 11 );

//
// get custom columns values
// -------------------------
add_action( 'manage_product_posts_custom_column', function($column, $id) {
    if($column === 'product_visibility'){
        $product = wc_get_product($id);
        if(!$product) return;

        $visibility = $product->get_catalog_visibility();

        $labels = [
            'visible' => 'Visible',
            'catalog' => 'Catalog only',
            'search'  => 'Search only',
            'hidden'  => 'Hidden',
        ];

        $colors = [
            'visible' => '#2ecc71',   // green
            'catalog' => '#3498db',   // blue
            'search'  => '#e67e22',   // orange
            'hidden'  => '#95a5a6',   // gray
        ];

        $label = $labels[$visibility] ?? $visibility;
        $color = $colors[$visibility] ?? '#000';

        echo '<span style="display:inline-block;padding:2px 6px;border-radius:3px;background-color:'.$color.';color:#fff;font-weight:600;">'.esc_html($label).'</span>';
    }
	
    if ( $column == 'ppkgs' ) {
		$meta = get_post_meta($id);
		if (array_key_exists('turq_price_per_kg', $meta) && ($ppkgs = $meta['turq_price_per_kg'][0])!=0) echo wc_price($ppkgs);
		else echo "-";
	}
	
	if ( $column == 'other_sells') {
        $product = wc_get_product($id);
        if(!$product) return;
		
		echo (count($product->get_cross_sell_ids()) ?: "-") ."/". (count($product->get_upsell_ids()) ?: "-");
	}
	
	if ($column == 'product_tag') {
        $terms = get_the_terms($id, 'product_tag');

        if (!empty($terms) && !is_wp_error($terms)) {
            $output = [];
			
            foreach ($terms as $term) {	
				$colours = turq_Market_Setup()->get_group_lookup($term->term_id);
				if (!empty($colours))	$style = "background:{$colours['background']}; color:{$colours['color']}";
				else					$style = "border:1px solid #0073aa;";
				$output[] = "<a href='".esc_url(admin_url('edit.php?post_type=product&product_tag='.$term->slug))."' class='group_pill' style='$style'>".esc_html($term->name)."</a>";
            }

            echo implode(' ', $output);
        }
    }
	
}, 10, 2 );

//
// format column
// -------------
add_action('admin_head', function() {
  echo '<style> .column-ppkgs, .column-product_visibility {width:10ch} .group_pill{border-radius: 100px;padding: 0 10px 3px 10px;width: fit-content;} .product_tag:has(a.group_pill) {color:transparent} .product_tag a:not(.group_pill) {display: none;}</style>';
});


?>