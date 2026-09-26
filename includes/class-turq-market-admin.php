<?php
/*******************************************************************************
 * Market settings in admin
 * 
 * turq_market_admin.php
 * 
*******************************************************************************/

if (!defined('ABSPATH')) exit;

require_once TURQ_PLUGIN_PATH . 'includes/class-turq-settings-controller.php';

class turq_Market_Admin {

    private $settings_controller;

    public function __construct($turq_Market_Setup) {

		add_action('init', function() use ($turq_Market_Setup) { $this->settings_controller = new turq_Settings_Controller($turq_Market_Setup);});

		add_action('admin_head', [$this, 'inline_scripts_styles']);

        add_filter('woocommerce_settings_tabs_array', [$this, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_tsp_local_delivery', [$this, 'render_tab']);
        add_action('woocommerce_update_options_tsp_local_delivery', [$this, 'save']);
    }

    public function add_settings_tab($tabs) {
        $tabs['tsp_local_delivery'] = 'TSP Settings';
        return $tabs;
    }

	// Inline JS & CSS for table interactivity
    public function inline_scripts_styles() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-settings') return;
	
        ?>
        <style>
			.local-table {width:min(1280px,100%)}
			.local-table thead tr {background-color: #fafafa;}			
            .local-table tbody tr:not(.not-sortable):hover { background: #f9f9f9; }
            .local-table tr:not(.not-sortable) td.drag-handle { cursor: move;}
			.local-table .drag-handle {text-align: center; width:30px}						
			.sortable-placeholder {background: #ffe; border: 1px dashed #ccc;	}
			.ui-sortable-helper {display:table; filter: invert(15%);}			
            .local-table input:not([type="checkbox"], [type="radio"]) { width: 100%; }
			.local-table td{vertical-align:middle}
			.local-table td input[type="checkbox"] {width: fit-content;display: block;margin: 0 auto;}
			.local-table th.enabled {width: 68px;text-align: center;}
			.local-table tfoot td {border-top:none}
			
			.open-day {text-transform: capitalize}
		
			.local-table[data-key-type="regular"] .special-date {display:none}
			.local-table[data-key-type="event"] .open-day {display:none}

			.turq_woo_settings.preview-active {background: lightpink; padding: 10px; margin-top: 10px; border-radius: 8px;}			
			.turq_woo_settings.preview-active::before {content: 'PREVIEW MODE ACTIVE'; text-align: center; font-weight: 800; display: block; width: 100%;}	
		

			
		    .ti_modal  {position: fixed;top: 0;left: 0;width: 100%;height: 100%;background: rgba(0,0,0,0.5);display: flex;align-items: center;justify-content: center;z-index: 9999;}
			.ti_modal>div {background-color:#fff; padding: 0 20px; min-width: 300px}
			.group_pill {padding: 0.25em 0.6em; border-radius: 999px; font-size: 0.85em; line-height: 1.4; white-space: nowrap; width: fit-content;margin-right:0.2em}					
		</style>		

		<script id="turq_utils">
		jQuery(document).ready(function($){
			
			// --- Helper to sync master checkbox for a table ---
			function syncMaster($table){
				const $children = $table.find('.enabled-checkbox');
				const $master = $table.find('input[name="enable-all"]');
				if(!$master.length) return;

				const checked = $children.filter(':checked');

				if(checked.length === 0){
					$master.prop('checked', false).prop('indeterminate', false);
				} else if(checked.length === $children.length){
					$master.prop('checked', true).prop('indeterminate', false);
				} else {
					$master.prop('checked', false).prop('indeterminate', true);
				}
			}

			// --- Master checkbox toggles all child checkboxes ---
			$('.turq_woo_settings table').each(function(){
				const $table = $(this);
				const $master = $table.find('input[name="enable-all"]');
				if(!$master.length) return;

				$master.on('change', function(){
					const isChecked = $(this).prop('checked');
					$table.find('.enabled-checkbox').prop('checked', isChecked);
				});

				// Initial sync
				syncMaster($table);

				// --- Observe row changes dynamically ---
				const observer = new MutationObserver(function(mutationsList){
					for(const mutation of mutationsList){
						if(mutation.type === 'childList'){
							syncMaster($table);
						}
					}
				});

				observer.observe($table.find('tbody')[0], { childList: true });
			});

			// --- Delegated event for child checkboxes (dynamic rows) ---
			$('.turq_woo_settings').on('change', '.enabled-checkbox', function(){
				const $table = $(this).closest('table');
				syncMaster($table);
			});
			
			
			// --- Delegated handler for "Remove All Rows" button ---
			$('.turq_woo_settings').on('click', '.remove-row-all', function(){
				const $table = $(this).closest('table');
				const $tbody = $table.find('tbody');

				if($tbody.find('tr').length === 0) return; // nothing to remove

				// Confirmation
				if(!confirm("Are you sure you want to remove all rows from this table?")) return;

				// Animate row removal
				$tbody.find('tr').each(function(index, row){
					$(row).fadeOut(150, function(){
						$(this).remove();

						// When last row is removed, reset master checkbox
						if($tbody.find('tr').length === 0){
							const $master = $table.find('input[name="enable-all"]');
							if($master.length){
								$master.prop('checked', false).prop('indeterminate', false);
							}
						}
					});
				});

			});			
						
			// All table - remove row and reindex
			//$(document).on('click', '.remove-row', function () {
			$('.turq_woo_settings').on('click', '.remove-row', function(){				
				const $table = $(this).closest('tbody');
				
				if($table.find('tr').length === 0) return; // nothing to remove				
				$(this).closest('tr').remove();

				$table.find('tr').each(function (i, row) {
					$(row).find('input, select, checkbox').each(function () {
						let name = $(this).attr('name');
						if (name) {
							$(this).attr('name', name.replace(/\[\d+\]/, '[' + i + ']'));
						}
					});
				});
			});
			
			// All table - apply date to all column above
			$('.apply-all-btn').on('click', function(e) {
				e.preventDefault();
				
				const $btn = $(this);
				const $sourceInput = $btn.siblings('input[type="date"]');
				const newValue = $sourceInput.val();

				if (!newValue) return alert('Please select a date first!');

				if (confirm("Apply this date to all collection points?")) {
			
					$sourceInput.val('');
					const $targets = $btn.closest('table').find('tbody input[name$="['+ $sourceInput.data('target') +']"]');
					$targets.val(newValue).css('background-color', '#fff9c4');
					
					setTimeout(() => {
						$targets.css({
							'background-color': '',
							'transition': 'background-color 0.5s ease'
						});
					}, 300);
				}
			});				
			
		});
		
		(function($){

			// All table drag fixer
			window.fixHelper = function(e, ui) {  
			  ui.children().each(function() {  
				$(this).width($(this).width());  
			  });  
			  return ui;  
			};

			// Render a row from data
			window.renderRow = function(data, html) {
				for (const key in data) {
					html = html.replaceAll(`{{${key}}}`, data[key]);
				}
				return html;
			}
	
		})(jQuery);
		</script>
		
        <?php
    }
	

    public function render_tab() {
        $this->settings_controller->render();
    }

    public function save() {
        $this->settings_controller->save();
    }
}