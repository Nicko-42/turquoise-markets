<?php
/*******************************************************************************
 * Market settings in admin
 * 
 * class-turq-settings-controller.php
 * 
*******************************************************************************/

if (!defined('ABSPATH')) exit;

require_once TURQ_PLUGIN_PATH . 'includes/settings/interface-turq-settings-section.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-base.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-general.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-events.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-event.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-checker.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-markets.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-orders.php';
require_once TURQ_PLUGIN_PATH . 'includes/settings/class-turq-section-order-meta-editor.php';

class turq_Settings_Controller {

	private $turq_Market_Setup;
    private $sections = [];

    public function __construct($turq_Market_Setup) {

		$this->turq_Market_Setup = $turq_Market_Setup;

        $this->sections[] = new turq_Section_General($this->turq_Market_Setup);
        $this->sections[] = new turq_Section_Events($this->turq_Market_Setup);

		foreach ($this->turq_Market_Setup->multi_shipping_groups as $group_key => $group_data)
			if (!empty($group_data['label'])) $this->sections[] = new turq_Section_Event($turq_Market_Setup, $group_key, $group_data);
		
		$this->sections[] = new turq_Section_Checker($this->turq_Market_Setup);
		$this->sections[] = new turq_Section_Markets($this->turq_Market_Setup);
		$this->sections[] = new turq_Section_Orders($this->turq_Market_Setup);
		$this->sections[] = new turq_Section_Order_Meta_Editor($this->turq_Market_Setup);		

        // Let each section register its own AJAX hooks
        foreach ($this->sections as $section) {
			$section->register_hooks();
        }
    }


    public function render() {

        global $current_section;

		// If no section set, default to first section
		if (empty($current_section)) {
			$current_section = $this->sections[0]->get_id();
		}

        $this->render_menu($current_section);

        foreach ($this->sections as $section) {
            if ($section->get_id() === $current_section ) {

                $section->render();
                return;
            }
        }
    }


    public function save() {

        global $current_section;

        foreach ($this->sections as $section) {
            if ($section->get_id() === $current_section) {
                $section->save();
                return;
            }
        }
    }


    private function render_menu($current_section) {

		$out_items = []; // Start with an array

		foreach ($this->sections as $section) {
			$id		= $section->get_id();
			$label	= $section->get_label();
			$url	= add_query_arg([
							'page'    => 'wc-settings',
							'tab'     => 'tsp_local_delivery',
							'section' => $id,
						], admin_url('admin.php'));

			$class = ($current_section == $id ? ' class="current"' : '');
			$out_items[] = '<li><a href="'.$url.'"'.$class.'>'.$label.'</a></li>';
		}
		echo '<ul class="subsubsub">' . implode(' | ', $out_items) . '</ul><br class="clear" />';
    }


}
?>