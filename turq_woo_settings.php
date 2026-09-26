<?php
/*******************************************************************************
 * Customisation for WooC  settings etc...
 * 
 * turq_woo_settings.php
 * 
*******************************************************************************/
// e.g. extend shipping methods

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly




add_filter('woocommerce_no_shipping_available_html', function() {
	return "Currently there are no collection markets or delivery dates available.";
});


add_action('woocommerce_shipping_init', 'my_virtual_shipping_method_init');
function my_virtual_shipping_method_init() {

    class WC_Shipping_Virtual_Delivery extends WC_Shipping_Method {

        public function __construct($instance_id = 0) {

            $this->id                 = 'virtual_delivery';
            $this->instance_id        = absint($instance_id);
            $this->method_title       = 'Virtual Delivery';
            $this->method_description = 'Shipping method for virtual products.';

            $this->supports = [
                'shipping-zones',
                'instance-settings',
            ];

            $this->init();
        }

        public function init() {

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option(
                'title',
                'Virtual Delivery',
            );

            add_action(
                'woocommerce_update_options_shipping_' . $this->id,
                [$this, 'process_admin_options']
            );
        }

        public function init_form_fields() {

            $this->instance_form_fields = [

                'title' => [
                    'title'       => 'Method Title',
                    'type'        => 'text',
                    'description' => 'Displayed to customers.',
                    'default'     => 'Virtual Delivery',
                ],

            ];
        }

        public function calculate_shipping($package = []) {
			//error_log('Virtual Delivery calculate_shipping called');
			if ($package['all_virtual'] > 0) {
				$rate = [
					'id'    => $this->get_rate_id(),
					'label' => $this->title,
					'cost'  => 0,
				];

				$this->add_rate($rate);
				//error_log('rate added ' . $this->get_rate_id());
			}
        }
    }
}


add_filter('woocommerce_shipping_methods', 'my_register_virtual_shipping_method');
function my_register_virtual_shipping_method($methods) {

    $methods['virtual_delivery'] = 'WC_Shipping_Virtual_Delivery';

    return $methods;
}






?>