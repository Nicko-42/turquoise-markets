<?php
/*******************************************************************************
 * Order Meta Editor
 *
 * class-turq-section-order-meta-editor.php
 *
 * Storage model (see woocommerce_checkout_order_processed_turq):
 *  - SOURCE OF TRUTH : each shipping item holds '_turq_package_data', one array per package
 *  - ORDER META      : a de-duplicated SUMMARY of all packages (multiple rows per key are
 *                      possible), used only for quick meta_query searching in reports
 *
 * This editor edits the PACKAGE data, then rebuilds the order summary from the packages,
 * so the two can never drift apart.
 *
 * Dates are stored as "UTC" timestamps of WP local wall-clock time (TODAY = current_time('timestamp')),
 * so they are always formatted in UTC - gmdate() in PHP, timeZone UTC in JS - never the browser's timezone.
*******************************************************************************/
if (!defined('ABSPATH')) exit;

class turq_Section_Order_Meta_Editor extends turq_Settings_Section_Base {

	private const SECTIONID		= 'turq_some';
	private const PER_PAGE		= 25;
	private const NONCE			= 'ome-save';
	private const HIDDEN_META	= 'ome_hidden_columns';
	private const CAPABILITY	= 'manage_woocommerce';

	// package keys that are NOT copied to the order summary meta (see woocommerce_checkout_order_processed_turq)
	private const PACKAGE_ONLY_KEYS	= ['_turq_package_items', '_turq_local_option_group', '_turq_package_total_items', '_turq_local_option_market_id'];

	// package keys displayed but never edited - changing these would not match the actual shipping item / line items
	private const READ_ONLY_KEYS	= ['_turq_package_items', '_turq_local_option_group', '_turq_package_total_items', '_turq_local_option_method_name'];

	// standard columns, in display order
	private const COLUMNS = [
		'_turq_local_option'				=> 'Label',
		'_turq_local_option_date'			=> 'Date',
		'_turq_local_option_cut_off'		=> 'Cut Off',
		'_turq_local_option_slot'			=> 'Slot',
		'_turq_local_option_market_id'		=> 'Market ID',
		'_turq_local_option_key'			=> 'Key',
		'_turq_local_option_name'			=> 'Name',
		'_turq_local_option_tag_id'			=> 'Tag ID',
		'_turq_local_option_method_name'	=> 'Method',
		'_turq_local_option_group'			=> 'Group',
		'_turq_package_total_items'			=> 'Items',
	];

	private const DEFAULT_HIDDEN = ['_turq_local_option_key', '_turq_local_option_tag_id', '_turq_local_option_method_name', '_turq_local_option_group'];

	// fields each method writes at checkout, so an input is offered even if the value is currently missing
	private const EXPECTED_KEYS = [
		'p'		=> ['_turq_local_option', '_turq_local_option_date', '_turq_local_option_cut_off', '_turq_local_option_market_id', '_turq_local_option_key', '_turq_local_option_name', '_turq_local_option_tag_id'],
		's'		=> ['_turq_local_option', '_turq_local_option_date', '_turq_local_option_cut_off', '_turq_local_option_slot', '_turq_local_option_market_id', '_turq_local_option_key', '_turq_local_option_tag_id'],
		'cp'	=> ['_turq_local_option', '_turq_local_option_name', '_turq_local_option_tag_id'],
		'v'		=> ['_turq_local_option', '_turq_local_option_name', '_turq_local_option_tag_id'],
	];

	private $turq_Market_Setup;

	public function __construct($turq_Market_Setup) {
		$this->turq_Market_Setup = $turq_Market_Setup;
	}

	public function get_id() {
		return self::SECTIONID;
	}

	public function get_label() {
		return 'Order Meta Edit';
	}

	public function register_hooks() {
		add_action('wp_ajax_ome_save_hidden_columns',	[$this, 'ajax_save_hidden_columns']);
		add_action('wp_ajax_ome_save_order',			[$this, 'ajax_save_order']);
	}

	// rows are saved individually by AJAX
	public function save() {
	}


	////////////////////////////////////////////////////////////////////////////
	// Helpers
	////////////////////////////////////////////////////////////////////////////

	private function is_date_key($key) {
		return (bool) preg_match('/(date|cut_?off)/', $key);
	}

	private function is_editable_key($key) {
		return !in_array($key, self::READ_ONLY_KEYS, true) && preg_match('/^_turq_local_option(_[a-z0-9_]+)?$/', $key);
	}

	private function get_hidden_columns() {
		$hidden = get_user_meta(get_current_user_id(), self::HIDDEN_META, true);
		return is_array($hidden) ? $hidden : self::DEFAULT_HIDDEN;
	}

	private function format_value($key, $value) {
		if (is_array($value))										return wp_json_encode($value);
		if ($this->is_date_key($key) && is_numeric($value))			return gmdate(DATEFORMAT.' H:i', (int)$value);
		return (string)$value;
	}

	// same formula as turq_Market_Setup::build_options()
	private function market_location($market_id) {
		$title = wp_specialchars_decode(get_post_field('post_title', $market_id), ENT_QUOTES);
		return mb_convert_case($title.", ".get_post_meta($market_id, 'wpsl_city', true), MB_CASE_TITLE, 'UTF-8');
	}

	// same format as checkout, see woocommerce_checkout_order_processed_turq()
	private function build_label($method, $pkg) {
		$date = $pkg['_turq_local_option_date'] ?? '';
		if (!is_numeric($date)) return null;

		if ($method == 'p') {
			$market_id = absint($pkg['_turq_local_option_market_id'] ?? 0);
			return $market_id ? $this->market_location($market_id).' - '.gmdate(DATEFORMAT, (int)$date) : null;
		}
		if ($method == 's') return gmdate(DATEFORMAT, (int)$date)." ".($pkg['_turq_local_option_slot'] ?? '');

		return null;
	}

	// returns false for an invalid date
	private function sanitize_field($key, $raw) {
		$raw = is_scalar($raw) ? trim((string)$raw) : '';
		if ($raw === '') return '';

		if ($this->is_date_key($key)) {
			if (ctype_digit($raw)) return (int)$raw;

			// datetime-local "Y-m-d\TH:i" is wall-clock time, stored as if UTC
			$dt = DateTime::createFromFormat('!Y-m-d\TH:i', substr($raw, 0, 16), new DateTimeZone('UTC'));
			return $dt ? $dt->getTimestamp() : false;
		}

		return sanitize_text_field($raw);
	}


	////////////////////////////////////////////////////////////////////////////
	// Packages
	////////////////////////////////////////////////////////////////////////////

	private function get_packages($order) {
		$targeted_methods	= $this->turq_Market_Setup->get_targeted_methods();
		$packages			= [];

		foreach ($order->get_shipping_methods() as $item_id => $item) {
			$pkg	= $item->get_meta('_turq_package_data');
			$pkg	= is_array($pkg) ? $pkg : [];
			$group	= $item->get_meta('group');

			$packages[$item_id] = [
				'item'		=> $item,
				'pkg'		=> $pkg,
				'has_pkg'	=> !empty($pkg),
				'method'	=> $pkg['_turq_local_option_method_name'] ?? ($targeted_methods[$item->get_method_id().':'.$item->get_instance_id()] ?? ''),
				'group_key'	=> $pkg['_turq_local_option_group'] ?? (is_array($group) ? ($group['key'] ?? '') : ''),
			];
		}

		return $packages;
	}

	// base package data for a shipping item that has none (incomplete checkout or legacy order)
	private function new_package($order, $p) {
		$group_key	= $p['group_key'];
		$items		= [];
		$total		= 0;

		foreach ($order->get_items() as $id => $line) {
			if ($group_key !== '' && $line->get_meta('_group_key') != $group_key) continue;
			$items[] = $id;
			$total	+= $line->get_quantity();
		}

		$pkg = [];
		if ($group_key !== '') {
			$pkg['_turq_package_items']		 = $items;
			$pkg['_turq_local_option_group'] = $group_key;
		}
		$pkg['_turq_local_option_method_name']	= $p['method'];
		$pkg['_turq_package_total_items']		= $total;

		return $pkg;
	}

	// best guess values to offer for a shipping item with no package data
	private function prefill_package($order, $p, $single) {
		$pkg	= $this->new_package($order, $p);
		$group	= $this->turq_Market_Setup->get_multi_shipping_groups()[$p['group_key']] ?? null;

		if ($group) $pkg['_turq_local_option_tag_id'] = $group['tag_id'];

		if ($p['method'] == 'v')  { $pkg['_turq_local_option'] = 'Virtual Delivery';	$pkg['_turq_local_option_name'] = $group['label'] ?? ''; }
		if ($p['method'] == 'cp') { $pkg['_turq_local_option'] = 'Postage';			$pkg['_turq_local_option_name'] = ''; }

		// legacy single package orders kept everything in order meta
		if ($single) {
			foreach ($order->get_meta_data() as $meta) {
				$key	= $meta->key;
				$value	= $meta->value;
				if (!$this->is_editable_key($key)) continue;

				if ($key === '_turq_local_option' && is_array($value)) {		// legacy format [market_id => label]
					$pkg['_turq_local_option_market_id'] ??= (string)array_key_first($value);
					$value = (string)current($value);
				}
				if (is_scalar($value)) $pkg[$key] = $value;
			}
		}

		return $pkg;
	}


	////////////////////////////////////////////////////////////////////////////
	// Order summary meta
	////////////////////////////////////////////////////////////////////////////

	// mirrors woocommerce_checkout_order_processed_turq()
	private function build_summary($order) {
		$shipping	= $order->get_shipping_methods();
		$summary	= [];
		$complete	= true;

		foreach ($shipping as $item) {
			$pkg = $item->get_meta('_turq_package_data');
			if (!is_array($pkg) || empty($pkg)) continue;

			$method = $pkg['_turq_local_option_method_name'] ?? '';
			if (($method == 'p' || $method == 's') && empty($pkg['_turq_local_option_date'])) $complete = false;

			foreach ($pkg as $key => $value) {
				if (!is_scalar($value)) continue;

				// market_id IS summarised for collection packages, so the order list can filter by market
				// (for 's' it is the delivery row key, not a market post ID) - matches the Package Backfill section
				$summarise = !in_array($key, self::PACKAGE_ONLY_KEYS, true) || ($key === '_turq_local_option_market_id' && $method === 'p');
				if ($summarise) $summary[$key][] = $value;
			}
		}

		// package count is also the "processed" flag, 0 = incomplete and excluded from reports
		$summary = ['_turq_package_count' => [$complete ? count($shipping) : 0]] + $summary;

		return array_map('array_unique', $summary);
	}

	private function has_package_data($order) {
		foreach ($order->get_shipping_methods() as $item) {
			if (!empty($item->get_meta('_turq_package_data'))) return true;
		}
		return false;
	}

	private function rebuild_order_summary($order) {
		// never touch legacy orders that only have order level meta
		if (!$this->has_package_data($order)) return;

		foreach ($order->get_meta_data() as $meta) {
			if (str_starts_with($meta->key, '_turq') && $meta->key !== '_turq_ticket_codes') $order->delete_meta_data($meta->key);
		}

		foreach ($this->build_summary($order) as $key => $values) {
			foreach ($values as $value) $order->add_meta_data($key, $value);
		}
	}

	private function summary_in_sync($order) {
		$expected = [];
		foreach ($this->build_summary($order) as $key => $values) {
			foreach ($values as $value) $expected[] = $key.'='.$value;
		}

		$actual = [];
		foreach ($order->get_meta_data() as $meta) {
			if (str_starts_with($meta->key, '_turq') && $meta->key !== '_turq_ticket_codes')
				$actual[] = $meta->key.'='.(is_scalar($meta->value) ? $meta->value : wp_json_encode($meta->value));
		}

		sort($expected);
		sort($actual);
		return $expected === $actual;
	}


	////////////////////////////////////////////////////////////////////////////
	// Market / delivery choices
	////////////////////////////////////////////////////////////////////////////

	// the package fields a choice sets - mirrors woocommerce_checkout_order_processed_turq()
	private function choice_fields($method, $group, $primary, $row, $opt) {
		$fields = [
			'_turq_local_option_market_id'	=> (string)$primary,
			'_turq_local_option_tag_id'		=> $row['tag_id'],
		];
		foreach ($opt as $key => $value) $fields['_turq_local_option_'.$key] = $value;

		if ($method == 'p') {
			$fields['_turq_local_option_name']	= preg_replace('/\[A\]$/', '', $opt['name'] ?? $group['label']);	// [A] = admin only date, not part of the group name
			$fields['_turq_local_option']		= $row['location'].' - '.gmdate(DATEFORMAT, $opt['date']);
		}
		else $fields['_turq_local_option'] = gmdate(DATEFORMAT, $opt['date'])." ".$opt['slot'];

		return $fields;
	}

	// currently valid collection markets/delivery dates per group and method, from the same data as checkout
	private function get_choices() {
		$choices = [];

		foreach ($this->turq_Market_Setup->get_multi_shipping_groups() as $group_key => $group) {
			foreach (['p', 's'] as $method) {
				foreach ($this->turq_Market_Setup->get_option_validation($group_key, $method) as $primary => $row) {
					$dates = [];
					foreach ($row as $dkey => $opt) {
						if (!is_array($opt) || !preg_match('/^d\d+$/', $dkey)) continue;

						$label	 = gmdate(DATEFORMAT, $opt['date']).($method == 's' ? ' '.$opt['slot'] : '');
						$label	.= str_ends_with($opt['name'] ?? '', '[A]') ? ' (admin only)' : '';
						$dates[] = ['value' => $dkey, 'label' => $label, 'fields' => $this->choice_fields($method, $group, $primary, $row, $opt)];
					}
					if (empty($dates)) continue;

					$choices[$group_key][$method][] = [
						'value'	=> (string)$primary,
						'label'	=> $method == 'p' ? $row['location'] : $dates[0]['label'],
						'dates'	=> $dates,
					];
				}
			}
		}

		return $choices;
	}


	////////////////////////////////////////////////////////////////////////////
	// Render
	////////////////////////////////////////////////////////////////////////////

	private function get_orders($view, $paged, $find) {
		if ($find) {
			$order = wc_get_order($find);
			return [($order && $order->get_type() === 'shop_order') ? [$order] : [], 1];
		}

		$args = [
			'type'		=> 'shop_order',
			'limit'		=> self::PER_PAGE,
			'paged'		=> $paged,
			'paginate'	=> true,
			'orderby'	=> 'date',
			'order'		=> 'DESC',
		];

		if ($view === 'upcoming')	$args['meta_query'] = [[ 'key' => '_turq_local_option_date', 'value' => strtotime(gmdate('Y-m-d', TODAY)), 'compare' => '>=', 'type' => 'NUMERIC' ]];
		if ($view === 'incomplete')	$args['meta_query'] = [[ 'key' => '_turq_package_count', 'value' => '0' ]];

		$result = wc_get_orders($args);
		return [$result->orders, max(1, (int)$result->max_num_pages)];
	}

	public function render() {

		$GLOBALS['hide_save_button'] = true;		// WC "Save changes" would only reload the page and lose edits

		$view		= sanitize_key($_GET['ome_view'] ?? 'all');
		$paged		= max(1, absint($_GET['ome_page'] ?? 1));
		$find		= absint($_GET['ome_order'] ?? 0);
		$base_url	= admin_url('admin.php?page=wc-settings&tab=tsp_local_delivery&section='.self::SECTIONID);

		[$orders, $max_pages] = $this->get_orders($view, $paged, $find);
		$hidden = $this->get_hidden_columns();

		$views = ['all' => 'All', 'upcoming' => 'Upcoming date', 'incomplete' => 'Incomplete'];

		?>
		<div id="turq_ome" class="turq_woo_settings">

			<h3>Order Meta Editor</h3>
			<p class="description">Edits the shipping package data (the source of truth). The order summary meta is rebuilt from the packages on every save.</p>

			<div class="ome-toolbar">
				<span>
					<?php foreach ($views as $key => $label): ?>
						<a href="<?= esc_url(add_query_arg('ome_view', $key, $base_url)) ?>" class="<?= (!$find && $view === $key) ? 'current' : '' ?>"><?= esc_html($label) ?></a>
					<?php endforeach; ?>
				</span>
				<span>
					<input type="number" id="ome-find" min="1" placeholder="Order #" value="<?= $find ?: '' ?>" style="width:10ch">
					<button type="button" class="button" id="ome-find-btn">Find</button>
				</span>
			</div>

			<div class="ome-toolbar">
				<span>Columns:</span>
				<?php foreach (self::COLUMNS + ['other' => 'Other'] as $key => $label): ?>
					<label><input type="checkbox" class="ome-col-toggle" data-col="<?= esc_attr($key) ?>" <?= checked(!in_array($key, $hidden, true), true, false) ?>> <?= esc_html($label) ?></label>
				<?php endforeach; ?>
			</div>

			<div class="ome-table-wrap">
				<table class="widefat ome-table">
					<thead>
						<tr>
							<th>Order</th>
							<th>Status</th>
							<th>Created</th>
							<th>Customer</th>
							<th>Total</th>
							<th>Package</th>
							<th>Market / Delivery</th>
							<?php foreach (self::COLUMNS + ['other' => 'Other'] as $key => $label): ?>
								<th class="ome-col-<?= esc_attr($key) ?>" <?= in_array($key, $hidden, true) ? 'style="display:none"' : '' ?>><?= esc_html($label) ?></th>
							<?php endforeach; ?>
							<th>Save</th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ($orders as $order) echo $this->render_order_rows($order, $hidden);
						if (empty($orders)) echo '<tr><td colspan="'.(9 + count(self::COLUMNS)).'">No orders found.</td></tr>';
						?>
					</tbody>
				</table>
			</div>

			<?php if (!$find && $max_pages > 1): ?>
				<div class="ome-toolbar">
					<?php if ($paged > 1): ?><a class="button" href="<?= esc_url(add_query_arg(['ome_view' => $view, 'ome_page' => $paged - 1], $base_url)) ?>">&laquo; Prev</a><?php endif; ?>
					<span>Page <?= $paged ?> of <?= $max_pages ?></span>
					<?php if ($paged < $max_pages): ?><a class="button" href="<?= esc_url(add_query_arg(['ome_view' => $view, 'ome_page' => $paged + 1], $base_url)) ?>">Next &raquo;</a><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php $this->render_assets($base_url);
	}

	private function render_order_rows($order, $hidden) {

		$order_id	= $order->get_id();
		$packages	= $this->get_packages($order);
		$single		= count($packages) === 1;
		$rowspan	= max(1, count($packages));
		$created	= $order->get_date_created();
		$cols		= self::COLUMNS + ['other' => 'Other'];

		// order level warnings
		$warnings = [];
		if ($this->has_package_data($order)) {
			if (empty($order->get_meta('_turq_package_count')))	$warnings[] = 'Incomplete - excluded from reports';
			if (!$this->summary_in_sync($order))				$warnings[] = 'Order summary meta out of sync - Save to rebuild';
		}

		$order_cells = function() use ($order, $order_id, $rowspan, $created, $warnings) { ?>
			<td rowspan="<?= $rowspan ?>">
				<a href="<?= esc_url($order->get_edit_order_url()) ?>" target="_blank">#<?= $order_id ?></a>
				<?php foreach ($warnings as $warning) echo '<div class="ome-warn">'.esc_html($warning).'</div>'; ?>
			</td>
			<td rowspan="<?= $rowspan ?>"><?= esc_html(wc_get_order_status_name($order->get_status())) ?></td>
			<td rowspan="<?= $rowspan ?>"><?= $created ? esc_html($created->date('D d-m-y H:i')) : '&mdash;' ?></td>
			<td rowspan="<?= $rowspan ?>"><?= esc_html($order->get_formatted_billing_full_name()) ?></td>
			<td rowspan="<?= $rowspan ?>"><?= wc_price($order->get_total()) ?></td>
		<?php };

		$save_cell = function() use ($order_id, $rowspan) { ?>
			<td rowspan="<?= $rowspan ?>"><button type="button" class="button button-primary ome-save" data-order-id="<?= $order_id ?>">Save</button></td>
		<?php };

		ob_start();

		if (empty($packages)) { ?>
			<tr class="ome-order-start" data-order-id="<?= $order_id ?>">
				<?php $order_cells(); ?>
				<td colspan="<?= 2 + count($cols) ?>"><span class="ome-na">No shipping on this order</span></td>
				<td></td>
			</tr>
		<?php }

		$first = true;
		foreach ($packages as $item_id => $p) {

			$item		= $p['item'];
			$method		= $p['method'];
			$values		= $p['has_pkg'] ? $p['pkg'] : ($method ? $this->prefill_package($order, $p, $single) : []);
			$colors		= $this->turq_Market_Setup->get_group_lookup($p['group_key']);
			$extras		= array_diff_key($values, self::COLUMNS);

			?>
			<tr class="<?= $first ? 'ome-order-start' : '' ?>" data-order-id="<?= $order_id ?>" data-item-id="<?= $item_id ?>" data-group="<?= esc_attr($p['group_key']) ?>" data-method="<?= esc_attr($method) ?>" data-new="<?= ($method && !$p['has_pkg']) ? 1 : 0 ?>">

				<?php if ($first) $order_cells(); ?>

				<td>
					<?= esc_html($item->get_method_title()) ?>
					<?php if ($colors && $colors['label']): ?>
						<div class="group_pill" style="background:<?= esc_attr($colors['background']) ?>;color:<?= esc_attr($colors['color']) ?>"><?= esc_html($colors['label']) ?></div>
					<?php endif; ?>
					<?php if (!$method): ?>
						<div class="ome-na">Not a market shipping method</div>
					<?php elseif (!$p['has_pkg']): ?>
						<div class="ome-warn">No package data - check values and Save to create</div>
					<?php endif; ?>
				</td>

				<td class="ome-choice">
					<?php if ($method == 'p' || $method == 's'): ?>
						<select class="ome-choice-primary"></select>
						<?php if ($method == 'p'): ?><select class="ome-choice-date"></select><?php endif; ?>
					<?php else: ?>
						<span class="ome-na">n/a</span>
					<?php endif; ?>
				</td>

				<?php foreach (self::COLUMNS as $key => $label): ?>
					<td class="ome-col-<?= esc_attr($key) ?>" <?= in_array($key, $hidden, true) ? 'style="display:none"' : '' ?>>
						<?= $method ? $this->render_field($key, $method, $values) : '' ?>
					</td>
				<?php endforeach; ?>

				<td class="ome-col-other ome-other" <?= in_array('other', $hidden, true) ? 'style="display:none"' : '' ?>>
					<?php foreach ($extras as $key => $value): ?>
						<div class="ome-extra">
							<small><?= esc_html($key) ?></small>
							<?php if ($this->is_editable_key($key) && is_scalar($value)): ?>
								<?= $this->render_field($key, $method, $values) ?>
							<?php else: ?>
								<div><?= esc_html($this->format_value($key, $value)) ?></div>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</td>

				<?php if ($first) $save_cell(); ?>
			</tr>
			<?php
			$first = false;
		}

		return ob_get_clean();
	}

	private function render_field($key, $method, $values) {

		$exists	= array_key_exists($key, $values);
		$value	= $values[$key] ?? '';

		if (in_array($key, self::READ_ONLY_KEYS, true))									return esc_html($this->format_value($key, $value));
		if (!$exists && !in_array($key, self::EXPECTED_KEYS[$method] ?? [], true))		return '<span class="ome-na">&mdash;</span>';

		if (is_array($value)) $value = (string)current($value);		// legacy [market_id => label]

		if ($this->is_date_key($key) && ($value === '' || is_numeric($value))) {
			$input	= $value === '' ? '' : gmdate('Y-m-d\TH:i', (int)$value);
			$text	= $value === '' ? '' : gmdate(DATEFORMAT.' H:i', (int)$value);
			return '<input type="datetime-local" class="ome-input" data-key="'.esc_attr($key).'" data-type="date" value="'.esc_attr($input).'">'
				 . '<div class="ome-date-text">'.esc_html($text).'</div>';
		}

		return '<input type="text" class="ome-input" data-key="'.esc_attr($key).'" value="'.esc_attr($value).'">';
	}

	private function render_assets($base_url) {
		?>
		<style>
			#turq_ome .ome-toolbar					{display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin:10px 0}
			#turq_ome .ome-toolbar a.current		{font-weight:600; color:#000}
			#turq_ome .ome-table-wrap				{overflow-x:auto}
			#turq_ome .ome-table td					{vertical-align:top}
			#turq_ome tr.ome-order-start td			{border-top:2px solid #c3c4c7}
			#turq_ome .ome-input					{width:100%; min-width:8ch}
			#turq_ome .ome-input[data-key="_turq_local_option"] {min-width:28ch}
			#turq_ome .ome-input[type="datetime-local"] {min-width:20ch}
			#turq_ome .ome-choice select			{display:block; width:100%; min-width:22ch; margin-bottom:4px}
			#turq_ome .ome-date-text				{font-size:12px; color:#555; margin-top:2px}
			#turq_ome .ome-warn						{font-size:12px; color:#b32d2e}
			#turq_ome .ome-na						{color:#999}
			#turq_ome .ome-extra					{margin-bottom:4px}
			#turq_ome .ome-dirty					{background:#fff8d6}
			#turq_ome tr.ome-row-changed td			{background:#fffbe6}
			#turq_ome tr.ome-row-saved td			{background:#d7ffd9}
		</style>

		<script>
		jQuery(function($){

			const OME = <?= wp_json_encode([
				'choices'	=> $this->get_choices(),
				'nonce'		=> wp_create_nonce(self::NONCE),
				'baseUrl'	=> $base_url,
			]) ?>;
			const $wrap = $('#turq_ome');

			// --- Dates -----------------------------------------------------------
			// Timestamps are WP local time stored as if UTC, so always convert using UTC.
			// Using the browser timezone would shift times by an hour during BST.

			// 1726531200 -> "2024-09-17T00:00" (datetime-local input value)
			function tsToInput(ts) {
				if (ts === '' || ts === null || isNaN(ts)) return '';
				return new Date(ts * 1000).toISOString().slice(0, 16);
			}

			// "2024-09-17T00:00" -> "Tue 17-09-24 00:00" (same as PHP DATEFORMAT.' H:i')
			function inputToText(val) {
				if (!val) return '';
				const d = new Date(val.slice(0, 16) + ':00Z');
				if (isNaN(d.getTime())) return '';
				const pad  = n => String(n).padStart(2, '0');
				const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
				return days[d.getUTCDay()] + ' ' + pad(d.getUTCDate()) + '-' + pad(d.getUTCMonth() + 1) + '-' + String(d.getUTCFullYear()).slice(-2)
					 + ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes());
			}

			// --- Market / delivery selects ----------------------------------------

			function getChoices($tr) {
				return (OME.choices[$tr.data('group')] || {})[$tr.data('method')] || [];
			}

			function findChoice($tr) {
				const value = $tr.find('.ome-choice-primary').val();
				return getChoices($tr).find(c => c.value === value);
			}

			function inputFor($tr, key) {
				return $tr.find('.ome-input[data-key="' + key + '"]');
			}

			function fillDates($tr, choice) {
				const $date   = $tr.find('.ome-choice-date').empty();
				const current = inputFor($tr, '_turq_local_option_date').val();

				const match   = choice && choice.dates.find(d => tsToInput(d.fields._turq_local_option_date) === current);

				// current date no longer offered (past cut off etc.) - show it, rather than a blank "keep current"
				$date.append($('<option>').val('').text(match ? '— keep current —' : ($tr.data('orig-date-text') || '— keep current —')));
				if (!choice) return;

				choice.dates.forEach(d => $date.append($('<option>').val(d.value).text(d.label)));
				if (match) $date.val(match.value);
			}

			// populate selects with the current market/delivery pre-selected (if still a valid option)
			function initRow($tr) {
				const $primary = $tr.find('.ome-choice-primary');
				if (!$primary.length) return;

				const list    = getChoices($tr);
				const current = String(inputFor($tr, '_turq_local_option_market_id').val() || '');
				const label   = String(inputFor($tr, '_turq_local_option').val() || '');
				const date    = inputFor($tr, '_turq_local_option_date').val();
				const offered = list.some(c => c.value === current);

				// text for the "keep current" options when the saved value is not (or no longer) offered
				// e.g. "Cliffe Hall, Lewes (current)" and "Thu 16-07-26 (current - not offered)"
				const currentText = $tr.data('method') === 'p' ? label.replace(/ - \w{3} \d\d-\d\d-\d\d$/, '') : label;
				$tr.data('orig-date-text', date ? inputToText(date).slice(0, 12) + ' (current - not offered)' : '');

				$primary.empty().append($('<option>').val('').text(
					offered || !currentText ? (list.length ? '— keep current —' : '— no current options —') : currentText + ' (current - not offered)'
				));
				list.forEach(c => $primary.append($('<option>').val(c.value).text(c.label)));
				if (offered) $primary.val(current);

				fillDates($tr, findChoice($tr));

				// remember the starting values so "keep current" can put them back
				$tr.data('orig-choice', $primary.val());
				$tr.find('.ome-input').each(function(){ $(this).data('orig', $(this).val()); });
			}

			// "keep current" chosen - undo everything the selects changed and go back to the saved values
			function revertChoice($tr) {
				$tr.find('.ome-input.ome-added').remove();
				$tr.find('.ome-input.ome-from-choice').each(function(){
					const $input = $(this);
					$input.val($input.data('orig')).removeClass('ome-from-choice ome-dirty');
					if ($input.data('type') === 'date') $input.siblings('.ome-date-text').text(inputToText($input.val()));
				});

				$tr.find('.ome-choice-primary').val($tr.data('orig-choice'));
				fillDates($tr, findChoice($tr));

				// clear the order highlight if nothing else is left changed
				const $rows = $wrap.find('tr[data-order-id="' + $tr.data('order-id') + '"]');
				if (!$rows.find('.ome-dirty').length) $rows.removeClass('ome-row-changed');
			}

			// copy a choice's values into the row inputs, so what is saved is what is shown
			function applyFields($tr, fields) {
				$.each(fields, function(key, value){
					let $input = inputFor($tr, key);

					// no visible input for this key on this package - keep it in a hidden one
					if (!$input.length) $input = $('<input type="hidden" class="ome-input ome-added">').attr('data-key', key).appendTo($tr.find('.ome-other'));

					$input.val($input.data('type') === 'date' ? tsToInput(value) : value).addClass('ome-from-choice');
					markDirty($input);
				});
			}

			function markDirty($input) {
				$input.addClass('ome-dirty');
				if ($input.data('type') === 'date') $input.siblings('.ome-date-text').text(inputToText($input.val()));
				$wrap.find('tr[data-order-id="' + $input.closest('tr').data('order-id') + '"]').addClass('ome-row-changed').removeClass('ome-row-saved');
			}

			$wrap.on('change', '.ome-choice-primary', function(){
				const $tr    = $(this).closest('tr');
				const choice = findChoice($tr);

				if (!choice) return revertChoice($tr);

				fillDates($tr, choice);

				// new market chosen - default to its first date (delivery options only have one)
				$tr.find('.ome-choice-date').val(choice.dates[0].value);
				applyFields($tr, choice.dates[0].fields);
			});

			$wrap.on('change', '.ome-choice-date', function(){
				const $tr    = $(this).closest('tr');
				const choice = findChoice($tr);
				const date   = choice && choice.dates.find(d => d.value === this.value);

				if (date) applyFields($tr, date.fields);
				else revertChoice($tr);
			});

			// manual edits
			$wrap.on('input change', '.ome-input', function(){ markDirty($(this)); });

			// --- Save ------------------------------------------------------------

			$wrap.on('click', '.ome-save', function(){
				const $btn    = $(this);
				const orderId = $btn.data('order-id');
				const $rows   = $wrap.find('tr[data-order-id="' + orderId + '"]');
				const packages = {};

				// send every field, plus which ones were changed - only changed ones are saved (all for new packages)
				$rows.filter('[data-item-id]').each(function(){
					const $tr = $(this);
					const pkg = { fields: {}, dirty: [] };

					$tr.find('.ome-input').each(function(){
						const key = $(this).data('key');
						pkg.fields[key] = $(this).val();
						if ($(this).hasClass('ome-dirty')) pkg.dirty.push(key);
					});
					packages[$tr.data('item-id')] = pkg;
				});

				$btn.prop('disabled', true).text('Saving…');

				$.post(ajaxurl, { action: 'ome_save_order', _wpnonce: OME.nonce, order_id: orderId, packages: packages })
					.done(function(res){
						if (!res || !res.success) {
							alert('Not saved: ' + ((res && res.data && res.data.message) || 'unknown error'));
							$btn.prop('disabled', false).text('Save');
							return;
						}
						// replace the order's rows with the fresh server version
						const $new = $($.parseHTML(res.data.html.trim())).filter('tr');
						$rows.first().before($new);
						$rows.remove();
						$new.each(function(){ initRow($(this)); }).addClass('ome-row-saved');
					})
					.fail(function(xhr){
						alert('Not saved: ' + ((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || xhr.statusText));
						$btn.prop('disabled', false).text('Save');
					});
			});

			// --- Columns ---------------------------------------------------------

			$wrap.on('change', '.ome-col-toggle', function(){
				$wrap.find('.ome-col-' + $(this).data('col')).toggle(this.checked);

				const hidden = $wrap.find('.ome-col-toggle:not(:checked)').map(function(){ return $(this).data('col'); }).get();
				$.post(ajaxurl, { action: 'ome_save_hidden_columns', _wpnonce: OME.nonce, hidden: hidden });
			});

			// --- Find / Enter key ------------------------------------------------

			function findOrder() {
				const id = parseInt($('#ome-find').val(), 10);
				window.location = OME.baseUrl + (id > 0 ? '&ome_order=' + id : '');
			}
			$('#ome-find-btn').on('click', findOrder);

			// this page sits inside the WC settings form - Enter would submit it and lose all edits
			$wrap.on('keydown', 'input', function(e){
				if (e.key !== 'Enter') return;
				e.preventDefault();
				if (this.id === 'ome-find') findOrder();
			});

			$wrap.find('tr[data-item-id]').each(function(){ initRow($(this)); });
		});
		</script>
		<?php
	}


	////////////////////////////////////////////////////////////////////////////
	// AJAX
	////////////////////////////////////////////////////////////////////////////

	public function ajax_save_hidden_columns() {
		check_ajax_referer(self::NONCE);
		if (!current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'Permission denied.'], 403);

		$hidden = array_map('sanitize_text_field', (array)wp_unslash($_POST['hidden'] ?? []));
		$hidden = array_values(array_intersect($hidden, array_keys(self::COLUMNS + ['other' => 'Other'])));

		update_user_meta(get_current_user_id(), self::HIDDEN_META, $hidden);
		wp_send_json_success();
	}

	public function ajax_save_order() {
		check_ajax_referer(self::NONCE);
		if (!current_user_can(self::CAPABILITY)) wp_send_json_error(['message' => 'Permission denied.'], 403);

		$order = wc_get_order(absint($_POST['order_id'] ?? 0));
		if (!$order || $order->get_type() !== 'shop_order') wp_send_json_error(['message' => 'Invalid order.']);

		$posted		= wp_unslash($_POST['packages'] ?? []);
		$posted		= is_array($posted) ? $posted : [];
		$packages	= $this->get_packages($order);
		$notes		= [];

		foreach ($posted as $item_id => $data) {
			$p = $packages[absint($item_id)] ?? null;			// must be a shipping item on THIS order
			if (!$p || !$p['method'] || !is_array($data)) continue;

			$before	= $p['pkg'];
			$pkg	= $p['has_pkg'] ? $p['pkg'] : $this->new_package($order, $p);
			$fields	= is_array($data['fields'] ?? null) ? $data['fields'] : [];
			$dirty	= $p['has_pkg'] ? array_intersect(array_keys($fields), (array)($data['dirty'] ?? [])) : array_keys($fields);

			foreach ($dirty as $key) {
				if (!$this->is_editable_key($key)) continue;

				$value = $this->sanitize_field($key, $fields[$key]);
				if ($value === false) wp_send_json_error(['message' => "Invalid date for $key on order #".$order->get_id()]);

				$pkg[$key] = $value;
			}

			// keep the label in step with a manually changed date/slot/market, unless the label itself was edited
			if (!in_array('_turq_local_option', $dirty, true) && array_intersect(['_turq_local_option_date', '_turq_local_option_slot', '_turq_local_option_market_id'], $dirty)) {
				$label = $this->build_label($p['method'], $pkg);
				if ($label !== null) $pkg['_turq_local_option'] = $label;
			}

			if ($p['has_pkg'] && $pkg == $before) continue;

			$p['item']->update_meta_data('_turq_package_data', $pkg);
			$p['item']->save();

			$changes = [];
			foreach (array_unique(array_merge(array_keys($before), array_keys($pkg))) as $key) {
				$old = $this->format_value($key, $before[$key] ?? '');
				$new = $this->format_value($key, $pkg[$key] ?? '');
				if ($old !== $new) $changes[] = (self::COLUMNS[$key] ?? $key).": $old → $new";
			}
			$notes[] = $p['item']->get_name().($p['has_pkg'] ? '' : ' (package data created)').' - '.implode('; ', $changes);
		}

		// always rebuild, so Save also repairs a summary that has drifted
		$this->rebuild_order_summary($order);
		if ($notes) $order->add_order_note("Order Meta Editor:\n".implode("\n", $notes), 0, true);
		$order->save();

		wp_send_json_success(['html' => $this->render_order_rows(wc_get_order($order->get_id()), $this->get_hidden_columns())]);
	}
}
