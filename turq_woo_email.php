<?php
/*******************************************************************************
 * E-Mail customisation
 * 
 * turq_woo_email.php
 * 
*******************************************************************************/
//do_action( 'qm/debug', );
//error_log("product : ".print_r($product,true));


if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


//
// Add custom Reply-To
// -------------------
// Only for WooCommerce customer emails,
// always return headers as a string for consistency.
add_filter( 'woocommerce_email_headers', function ( $headers, $email_id, $order, $email ) {
    // List of customer-facing Woo emails
    $customer_emails = array(
        'customer_new_account',
		'customer_on_hold_order',
        'customer_processing_order',
        'customer_completed_order',
        'customer_refunded_order',
        'customer_reset_password',
        'customer_invoice',
        'customer_note',
		'cancelled_order',
    );

    // DEBUG: optional logging
    //error_log( 'Woo email ID: ' . $email_id );
    //error_log( 'Headers before filter: ' . print_r( $headers, true ) );
    // return $headers;

    // Normalize headers into array
    if ( is_string( $headers ) && ! empty( $headers ) ) {
        // Split on any line break: \r\n, \r, or \n
        $headers = preg_split("/\r\n|\r|\n/", $headers);
    } elseif ( empty( $headers ) ) {
        $headers = array();
    }

    // Only modify customer emails
    if ( in_array( $email_id, $customer_emails, true ) ) {
        // Remove any existing Reply-To headers
        $headers = array_filter( $headers, function( $header ) {
            return stripos( $header, 'Reply-To:' ) !== 0;
        });

        // Add custom Reply-To
        $headers[] = 'Reply-To: The Sussex Peasant <orders@thesussexpeasant.co.uk>';
    }

    // Return as string with \r\n line breaks (WP Mail SMTP compatible)
   	// error_log( 'Headers after filter: ' . print_r( $headers, true ) );
    return implode( "\r\n", $headers );
	
}, 9999, 4 );

//
// Override email title to add in our order type
// ---------------------------------------------
add_filter( 'woocommerce_email_subject_customer_processing_order', 'custom_email_subject_with_order_type', 10, 2 );
add_filter( 'woocommerce_email_subject_customer_on_hold_order', 'custom_email_subject_with_order_type', 10, 2 );
function custom_email_subject_with_order_type( $subject, $order ) {
    if ( ! $order instanceof WC_Order ) return $subject;
	
	$order_type = $order->get_meta( '_turq_local_option_name')??'';
	if (empty($order_type)) $ot_string = " ";
	else 					$ot_string = " ".ucwords($order_type)." ";
	
    $new_subject = "Your Sussex Peasant".$ot_string."Order!";
	return $new_subject;
}




add_action('woocommerce_email_after_order_table', 'turq_add_ticket_codes_to_email', 20, 4);

function turq_add_ticket_codes_to_email( $order, $sent_to_admin, $plain_text, $email ) {

    // Customer emails only
    if ( $sent_to_admin ) return;

    // only on emails...
	if (! in_array($email->id, [ 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ], true ) ) return;

    if ( ! $order->meta_exists( '_turq_ticket_codes' ) ) return;

    $codes = $order->get_meta( '_turq_ticket_codes' );

    if ( empty( $codes ) || ! is_array( $codes ) ) return;

    if ( $plain_text ) {

        echo "\n\n";
        echo "YOUR TICKET CODES\n";
        echo "=================\n";
		echo "Please bring these codes with you to the event.\n";		

        foreach ( $codes as $code ) {
            echo $code . "\n";
        }

    } else {

		$need_s = count($codes)>1 ? 's' : '';
		
        echo "<h2>Your Ticket Code$need_s</h2>";
        echo "<p>Please bring ". ($need_s ? "these" : "this") . " code$need_s with you to the event.</p>";
		foreach ( $codes as $code ) {
			echo "<div style='font-family:monospace;font-size:18px;font-weight:bold;line-height:1.6;'>";
			echo esc_html( $code );
			echo "</div>";
		}
    }
}


//
// Disable messages about the mobile apps in WooCommerce emails
// ------------------------------------------------------------
//add_action( 'woocommerce_email', function ( $mailer ) { remove_action( 'woocommerce_email_footer', array( $mailer->emails['WC_Email_New_Order'], 'mobile_messaging' ), 9 ); });

//
// email footers
// -------------
add_filter( 'kadence_blocks_form_email_footer_text', function($footer){brand_footer($footer);});
//add_filter( 'woocommerce_email_footer_text', 'brand_footer',2,99999);
function brand_footer($footer, $email=NULL) {
	return $footer."<p>Powered by <a href='https://turquoise-internet.co.uk'>Turquoise Internet</a></p>";
}

?>