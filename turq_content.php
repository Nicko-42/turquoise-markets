<?php
/*******************************************************************************
 * Modify non-specific page/post content
 * 
 * turq_content.php
 * 
*******************************************************************************/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


// custom kadence element shortcode drop in !!
/*
add_shortcode( 'turq_cat_description', function() {
	ob_start();
	do_action('turq_producer_description'); // custom hook as defined in element
	return ob_get_clean();
});
*/

// remove menu class as it mucks up nav with anchor links !!
add_filter( 'nav_menu_css_class', function ( $classes, $item ) {
	if ( ( $key = array_search( 'current-menu-item', $classes ) ) !== false ) {
		unset( $classes[$key] );
	}
	return $classes;
}, 10, 2 );

/*
add_filter( 'gettext', function ( $translated, $untranslated, $domain ) {
    global $current_screen;

    if ( !is_admin() && 'woocommerce' === $domain ) {
        switch ( $untranslated ) {
    		case 'Billing &amp; Shipping'	:       $translated = 'Your details'; break;	
    		case 'Billing address'			:       $translated = 'Billing details'; break;					
	    }
    }

	return $translated;
},99999, 3 );
*/


add_filter( 'woocommerce_cross_sells_columns', function( $columns ) {return 6;}, 99999, 1 );
add_filter( 'woocommerce_cross_sells_total', function( $columns ) {return 60;}, 99999, 1 );
add_filter( 'woocommerce_upsell_display_args', function ( $args ) {
	$args['posts_per_page'] = 12;
	$args['columns'] = 6; 
	return $args;
}, 9999 );


/* add 'ON THE VAN' Tag to all queries on page*/
add_filter( 'kadence_blocks_pro_query_loop_query_vars', function( $query, $ql_query_meta, $ql_id ) {
	if (get_the_ID()==2986) array_push($query['tax_query'], Array('relation' => 'OR',Array('taxonomy' => 'product_tag','terms' => Array(68)))); 
   return $query;
}, 10, 3 );



// show all cc options on page
add_shortcode( 'turq_all_reg_cc', function() {
	if (is_admin()) return;

	extract( carrier_settings() );
	$mdata = generate_options('p', $method_setup); //builds drop downs (into buffer) identical to checkout with days correct as per cutoff with ref to today
	
	ob_start();
	echo "<div class='turq-dash-container'>";
	foreach ($mdata as $loc) {
		echo "<div class='turq-dash-list'><b>".array_shift($loc)."</b><br/>";
		foreach($loc as $m) echo "<span>".date("D d-m-y",$m[0])."</span><br>";
		echo "</div>";
	}
	echo "</div>";
	$output = ob_get_clean();
	$output .= 	 "<style>
		.turq-dash-container {display:grid;   grid-template-columns: repeat(4, 1fr);  grid-auto-rows: 1fr;  grid-column-gap: 5px;  grid-row-gap: 5px;}
		.turq-dash-list {line-height:1; padding-bottom:5px;font-family:monospace, monospace;}

		.turq-dash-list b {background-color: lightgray;  width: 100%;  display: block;  padding: 5px 0 5px 5px;  box-sizing: border-box;}
	</style>";
	return $output;
});



/**
 * Auto-inject glossary tooltips and handle manual tooltip placeholders.
 *
 * Builds glossary data dynamically from Kadence Elements (title must start with "TOOLTIP ")
 * Injects tooltips automatically into <p> sentences
 * $all_terms prebuilt outside the walker
 * Longest-first matching
 * Multiple matches per text node
 * Manual placeholders support - replace {{tooltip_ID}} in HTML
 * data-tooltip-placement="auto" enforced
 * Detailed debug logging
 */
add_filter('the_content', function ($content) {
    global $post;

    if (empty($post) || wp_get_post_parent_id($post->ID) != 3369) return $content;

    $tooltip_terms = [];
    $tooltip_by_id = [];
    $script_needed = false;

    // -------------------------------
    // Build tooltip_terms from Kadence Elements
    // -------------------------------
    $tooltips = get_posts([
        'post_type'      => 'kadence_element',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    foreach ($tooltips as $tp) {
        $title = trim($tp->post_title);
        if (stripos($title, 'tooltip ') !== 0) continue;

        $key = trim(substr($title, 8));
        $key_upper = strtoupper($key);

        $raw_content = preg_replace('/<!--.*?-->/s', '', $tp->post_content);
        $parts = array_map('trim', explode('#', $raw_content));

        $allowed_tags = ['b'=>[], 'strong'=>[], 'em'=>[], 'i'=>[], 'u'=>[], 'br'=>[]];
        $text_clean = wp_kses($parts[0] ?? '', $allowed_tags);
        $text_attr  = esc_attr(preg_replace('/\s+/', ' ', $text_clean));
        $anchor_clean = esc_attr($parts[1] ?? '');
        $variants = isset($parts[2]) && $parts[2] ? array_map('trim', explode(',', strtolower($parts[2]))) : [];

        $tooltip_terms[$key_upper] = [
            'post_id'  => $tp->ID,
            'content'  => $text_attr,
            'anchor'   => $anchor_clean,
            'variants' => $variants,
        ];

        $tooltip_by_id[$tp->ID] = $key_upper;
    }

    // -------------------------------
    // Build flattened term+variant array (once)
    // -------------------------------
    $all_terms = [];
    foreach ($tooltip_terms as $term => $details) {
        $variants = !empty($details['variants']) ? array_merge([$term], $details['variants']) : [$term];
        foreach ($variants as $v) {
            $all_terms[strtolower($v)] = $details; // lowercase for consistent matching
        }
    }

    // Sort longest first to avoid substring collisions
    uksort($all_terms, function($a, $b) {
        $len_diff = strlen($b) - strlen($a);
        return $len_diff !== 0 ? $len_diff : strcasecmp($a, $b);
    });

    // -------------------------------
    // Load content into DOMDocument
    // -------------------------------
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    // -------------------------------
    // Recursive walker for <p> text nodes
    // -------------------------------
    $walker = function($node, $depth = 0) use (&$walker, $all_terms, &$script_needed, $dom) {
        $indent = str_repeat('  ', $depth); // for debug logs

        if ($node->nodeType === XML_TEXT_NODE) {
            $text = $node->nodeValue;
            if (trim($text) === '') return;

            //error_log("{$indent}Walker: Text node found: " . substr($text, 0, 50));

            $frag = $dom->createDocumentFragment();
            $remaining_text = $text;

            while (strlen($remaining_text) > 0) {
                $earliest_pos = false;
                $match_text = '';
                $match_details = null;

                foreach ($all_terms as $term_variant => $details) {
                    if (preg_match('/\b' . preg_quote($term_variant, '/') . '\b/i', $remaining_text, $m, PREG_OFFSET_CAPTURE)) {
                        $byte_pos = $m[0][1];
                        $pos = mb_strlen(substr($remaining_text, 0, $byte_pos)); // convert byte offset to char offset
                        if ($earliest_pos === false || $pos < $earliest_pos) {
                            $earliest_pos = $pos;
                            $match_text = mb_substr($remaining_text, $pos, mb_strlen($m[0][0]));
                            $match_details = $details;
                        }
                    }
                }

                if ($earliest_pos === false) {
                    // append remaining text
                    $frag->appendChild($dom->createTextNode($remaining_text));
                    break;
                }

                // append text before match
                if ($earliest_pos > 0) {
                    $frag->appendChild($dom->createTextNode(mb_substr($remaining_text, 0, $earliest_pos)));
                }

                // append tooltip link
                $a = $dom->createElement('a', $match_text);
                $href = home_url('/farming-glossary/' . ltrim($match_details['anchor'], '#'));
                $a->setAttribute('data-kb-tooltip-content', ucfirst($match_details['content']));
                $a->setAttribute('href', $href);
                $a->setAttribute('data-tooltip-placement', 'auto');
                $a->setAttribute('class', 'kb-tooltips');
                $a->setAttribute('aria-expanded', 'false');
                $frag->appendChild($a);
                $script_needed = true;

                //error_log("{$indent}  Tooltip match: '{$match_text}' -> {$match_details['content']}");

                // remove matched portion from remaining text
                $remaining_text = mb_substr($remaining_text, $earliest_pos + mb_strlen($match_text));
            }

            $node->parentNode->replaceChild($frag, $node);
            return;
        }

        if ($node->hasChildNodes()) {
            foreach (iterator_to_array($node->childNodes) as $child) {
                $walker($child, $depth + 1);
            }
        }
    };

    foreach ($dom->getElementsByTagName('p') as $p) {
        foreach (iterator_to_array($p->childNodes) as $child) {
            $walker($child);
        }
    }

    $content_html = $dom->saveHTML();
	
    // ------------------------------------------------------------------
    // Handle manual placeholders like {{tooltip_123}}
    // ------------------------------------------------------------------
    $content_html = preg_replace_callback(
        '/<a([^>]*)data-kb-tooltip-content="\{\{tooltip_(\d+)\}\}"([^>]*)>(.*?)<\/a>/i',
        function ($m) use ($tooltip_by_id, $tooltip_terms) {
            $id = (int) $m[2];
            $before = $m[1];
            $after = $m[3];
            $link_text = $m[4];

            if (!isset($tooltip_by_id[$id])) return $m[0];

            $term_key = $tooltip_by_id[$id];
            $details = $tooltip_terms[$term_key] ?? null;
            if (!$details) return $m[0];

            $tooltip_text = esc_attr($details['content']);
            $href = esc_url(home_url('/farming-glossary/' . ltrim($details['anchor'], '#')));

            // Replace or add attributes
            $before = preg_replace('/href="[^"]*"/', '', $before);
            $after  = preg_replace('/href="[^"]*"/', '', $after);

			$script_needed = false;
			
            $new_a = sprintf(
                '<a%1$sdata-kb-tooltip-content="%2$s" href="%3$s" data-tooltip-placement="auto"%4$s>%5$s</a>',
                $before,
                $tooltip_text,
                $href,
                $after,
                $link_text
            );

            return $new_a;
        },
        $content_html
    );

    if ($script_needed) wp_enqueue_script('kadence-blocks-tippy');

    return $content_html;
});



?>