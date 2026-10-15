<?php
// class-turq-section-package-backfill.php
//
// TEMPORARY tool: copy values out of each shipping package's _turq_package_data
// into order-level meta, so the admin order list can filter on them with a plain
// meta_query. Remove this section once checkout writes the keys itself
// (see woocommerce_checkout_order_processed_turq in turq_woo_checkout.php).
//
// Dry run (default)  - read only, shows what would change.
// Commit             - adds missing values, idempotent, batched, via WC CRUD.
// Rollback           - deletes the configured keys from every order with package data.

if (!defined('ABSPATH')) exit;

class turq_Section_Package_Backfill extends turq_Settings_Section_Base {

	private $turq_Market_Setup;
	private const SECTIONID	= 'turq_backfill';
	private const PER_PAGE	= 100;	// orders per dry-run page
	private const BATCH		= 50;	// orders per commit/rollback request

	/**
	 * Package field => [ order meta key, package methods it applies to ]
	 *
	 * Each distinct non-empty value across an order's (matching) packages becomes
	 * one order meta row (non-unique key), so a multi-package order gets several rows.
	 *
	 * market_id is limited to 'p' packages: checkout also stores a value under
	 * _turq_local_option_market_id for 's' packages, but there it is the delivery
	 * row key, not a WPSL market post ID.
	 */
	private const FIELDS = [
		'_turq_local_option_market_id' => ['key' => '_turq_local_option_market_id', 'methods' => ['p']],
	];

	public function __construct($turq_Market_Setup) {
		$this->turq_Market_Setup = $turq_Market_Setup;
	}

	public function get_id() {
		return self::SECTIONID;
	}

	public function get_label() {
		return 'Package Backfill';
	}

	public function register_hooks() {
		add_action('woocommerce_settings_tsp_local_delivery', [$this, 'remove_save']);
	}

	public function remove_save() {
		global $hide_save_button;
		global $current_section;

		if ($current_section == self::SECTIONID) $hide_save_button = true;
	}

	public function save() {
		return;
	}


	/* ------------------------------------------------------------------
	 * Data
	 * ------------------------------------------------------------------ */

	private function base_sql() {
		global $wpdb;
		return "FROM {$wpdb->prefix}woocommerce_order_items oi
				JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oim.order_item_id = oi.order_item_id
				WHERE oi.order_item_type = 'shipping' AND oim.meta_key = '_turq_package_data'";
	}

	// Newest first. $before_id > 0 gives keyset paging for batches (stable while new orders arrive).
	private function order_ids($limit, $offset = 0, $before_id = 0) {
		global $wpdb;
		$where = $before_id ? $wpdb->prepare(' AND oi.order_id < %d', $before_id) : '';
		$sql   = 'SELECT DISTINCT oi.order_id ' . $this->base_sql() . $where . ' ORDER BY oi.order_id DESC LIMIT %d OFFSET %d';
		return array_map('intval', $wpdb->get_col($wpdb->prepare($sql, $limit, $offset)));
	}

	private function stats() {
		global $wpdb;
		$base = $this->base_sql();
		return [
			'orders'   => (int) $wpdb->get_var("SELECT COUNT(DISTINCT oi.order_id) $base"),
			'packages' => (int) $wpdb->get_var("SELECT COUNT(*) $base"),
			'multi'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM ( SELECT oi.order_id $base GROUP BY oi.order_id HAVING COUNT(*) > 1 ) t"),
		];
	}

	// @return array order_id => list of packages
	private function packages(array $order_ids) {
		global $wpdb;
		if (empty($order_ids)) return [];

		$in   = implode(',', array_fill(0, count($order_ids), '%d'));
		$rows = $wpdb->get_results($wpdb->prepare(
			'SELECT oi.order_id, oi.order_item_id, oi.order_item_name, oim.meta_value ' . $this->base_sql() .
			" AND oi.order_id IN ($in) ORDER BY oi.order_id DESC, oi.order_item_id ASC",
			$order_ids
		));

		$out = [];
		foreach ($rows as $r) {
			$data = maybe_unserialize($r->meta_value);
			$out[(int) $r->order_id][] = [
				'item_id' => (int) $r->order_item_id,
				'name'    => $r->order_item_name,
				'data'    => is_array($data) ? $data : [],
			];
		}
		return $out;
	}

	// Compare what the packages say against what is on the order now.
	private function plan(WC_Order $order, array $packages) {
		$plan = [];
		foreach (self::FIELDS as $field => $cfg) {
			$proposed = [];
			foreach ($packages as $p) {
				if (!in_array($p['data']['_turq_local_option_method_name'] ?? '', $cfg['methods'], true)) continue;
				$v = $p['data'][$field] ?? '';
				if ('' !== $v && null !== $v) $proposed[] = (string) $v;
			}
			$proposed = array_values(array_unique($proposed));
			$existing = array_values(array_unique(array_map(
				function ($m) { return (string) $m->value; },
				$order->get_meta($cfg['key'], false)
			)));

			$plan[$cfg['key']] = [
				'proposed' => $proposed,
				'existing' => $existing,
				'add'      => array_values(array_diff($proposed, $existing)),
				'extra'    => array_values(array_diff($existing, $proposed)),
			];
		}
		return $plan;
	}

	// Process one batch. Idempotent, so re-running a batch is harmless.
	// @return [ orders scanned, orders changed, last order id ]
	private function run_batch($mode, $before_id) {
		$ids      = $this->order_ids(self::BATCH, 0, $before_id);
		$packages = $this->packages($ids);
		$changed  = 0;

		foreach ($ids as $id) {
			$order = wc_get_order($id);
			if (!$order instanceof WC_Order) continue;	// skips refunds and missing orders

			$dirty = false;
			foreach ($this->plan($order, $packages[$id] ?? []) as $key => $p) {
				if ('commit' === $mode) {
					foreach ($p['add'] as $v) {
						$order->add_meta_data($key, $v, false);
						$dirty = true;
					}
				} elseif ('rollback' === $mode && $p['existing']) {
					$order->delete_meta_data($key);
					$dirty = true;
				}
			}
			if ($dirty) {
				$order->save();
				$changed++;
			}
		}
		return [count($ids), $changed, $ids ? end($ids) : 0];
	}


	/* ------------------------------------------------------------------
	 * Display helpers
	 * ------------------------------------------------------------------ */

	private function section_url(array $args = []) {
		return add_query_arg(array_merge([
			'page'    => 'wc-settings',
			'tab'     => 'tsp_local_delivery',
			'section' => self::SECTIONID,
		], $args), admin_url('admin.php'));
	}

	private function action_url($mode, $before_id = 0, $scanned = 0, $done = 0) {
		return wp_nonce_url(
			$this->section_url(['tsp_action' => $mode, 'tsp_before' => $before_id, 'tsp_scanned' => $scanned, 'tsp_done' => $done]),
			'tsp_backfill_' . $mode
		);
	}

	// Market IDs are WPSL post IDs; get_the_title() still works once a market is unpublished.
	private function market_label($id) {
		static $cache = [];
		if ('' === $id || null === $id) return '—';

		if (!isset($cache[$id])) {
			$is_market  = ctype_digit((string) $id) && 'wpsl_stores' === get_post_type((int) $id);
			$cache[$id] = $id . ($is_market ? ' · ' . wp_specialchars_decode(get_the_title((int) $id), ENT_QUOTES) : ' · (not a market)');
		}
		return $cache[$id];
	}

	// Stored as UTC midnight (date only), so format in UTC.
	private function fmt_date($ts) {
		return is_numeric($ts) ? gmdate(DATEFORMAT, (int) $ts) : '—';
	}

	private function list_vals(array $vals) {
		if (empty($vals)) return '—';
		return implode('<br>', array_map(function ($v) { return esc_html($this->market_label($v)); }, $vals));
	}


	/* ------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	public function render() {
		if (!current_user_can('manage_woocommerce')) wp_die('Not allowed.');

		echo '<div class="turq_woo_settings turq_backfill"><h3>Package Meta Backfill</h3>';

		$mode = isset($_GET['tsp_action']) ? sanitize_key($_GET['tsp_action']) : '';
		if (in_array($mode, ['commit', 'rollback'], true)) {
			check_admin_referer('tsp_backfill_' . $mode);
			$this->render_batch($mode);
		} else {
			$this->render_dry_run();
		}
		echo '</div>';
		?>
		<style>
			.turq_backfill tr.multi td {background:#fff8e5}
			.turq_backfill td {vertical-align:top}
		</style>
		<?php
	}

	private function render_batch($mode) {
		$before  = absint($_GET['tsp_before'] ?? 0);
		$scanned = absint($_GET['tsp_scanned'] ?? 0);
		$done    = absint($_GET['tsp_done'] ?? 0);
		$total   = $this->stats()['orders'];

		list($n, $changed, $last_id) = $this->run_batch($mode, $before);
		$scanned += $n;
		$done    += $changed;

		printf('<p><strong>%s:</strong> scanned %d of %d orders, %d changed so far.</p>', esc_html(ucfirst($mode)), $scanned, $total, $done);

		if (self::BATCH === $n) {
			// Reload the page with the next batch. The link is a manual fallback.
			$next = $this->action_url($mode, $last_id, $scanned, $done);
			printf('<p>Continuing… <a href="%s">click here if it stalls</a>.</p>', esc_url($next));
			printf('<script>setTimeout(function(){ window.location.href = %s; }, 400);</script>', wp_json_encode($next));
		} else {
			printf('<p><strong>Finished.</strong> <a href="%s">Back to dry run</a></p>', esc_url($this->section_url()));
		}
	}

	private function render_dry_run() {
		$stats = $this->stats();
		$paged = max(1, absint($_GET['tsp_paged'] ?? 1));
		$only  = !empty($_GET['tsp_only']);
		$pages = max(1, (int) ceil($stats['orders'] / self::PER_PAGE));
		$ids   = $this->order_ids(self::PER_PAGE, ($paged - 1) * self::PER_PAGE);
		$pkgs  = $this->packages($ids);
		$keys  = implode(', ', array_column(self::FIELDS, 'key'));

		printf(
			'<p>%d orders with package data · %d packages · <strong>%d multi-package orders</strong>. Order meta key(s): <code>%s</code>. Dry run: nothing is written on this screen.</p>',
			$stats['orders'], $stats['packages'], $stats['multi'], esc_html($keys)
		);

		printf(
			'<p><a class="button button-primary" href="%s" onclick="return confirm(\'Add missing order meta to all orders?\');">Commit backfill (all orders)</a> ' .
			'<a class="button" href="%s" onclick="return confirm(\'Delete these order meta keys from ALL orders with package data, including any written at checkout?\');">Rollback (remove keys)</a></p>',
			esc_url($this->action_url('commit')),
			esc_url($this->action_url('rollback'))
		);

		// Filter + pagination as plain links: this section is already inside the
		// WC settings <form id="mainform">, and forms cannot be nested.
		$toggle = $this->section_url(['tsp_paged' => $paged, 'tsp_only' => (int) !$only]);
		echo '<p>';
		printf('<a class="button" href="%s">%s</a> ', esc_url($toggle), $only ? 'Show all orders' : 'Only orders that would change (this page)');
		printf(' &nbsp; Page %d of %d ', $paged, $pages);
		if ($paged > 1)			printf('<a class="button" href="%s">‹ Prev</a> ', esc_url($this->section_url(['tsp_paged' => $paged - 1, 'tsp_only' => (int) $only])));
		if ($paged < $pages)	printf('<a class="button" href="%s">Next ›</a>', esc_url($this->section_url(['tsp_paged' => $paged + 1, 'tsp_only' => (int) $only])));
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr>
			<th>Order</th><th>Status</th><th>Pkgs</th><th>Item</th><th>Method / group</th><th>Option</th>
			<th>Market (package)</th><th>Date</th><th>Order meta now</th><th>Will add</th><th>Unexpected on order</th>
		</tr></thead><tbody>';

		$shown = 0;
		foreach ($ids as $id) {
			$order = wc_get_order($id);
			$list  = $pkgs[$id] ?? [];
			if (!$order instanceof WC_Order) {
				printf('<tr><td>#%d</td><td colspan="10"><em>Not a loadable order (refund or deleted), skipped.</em></td></tr>', $id);
				continue;
			}

			$plan = $this->plan($order, $list);
			$p    = reset($plan);	// first configured key drives the summary columns
			$adds = array_sum(array_map(function ($x) { return count($x['add']); }, $plan));
			if ($only && !$adds) continue;

			$shown++;
			$span  = count($list);
			$class = $span > 1 ? ' class="multi"' : '';

			foreach ($list as $i => $pkg) {
				$d = $pkg['data'];
				echo "<tr$class>";
				if (0 === $i) {
					printf('<td rowspan="%1$d"><a href="%2$s">#%3$s</a></td><td rowspan="%1$d">%4$s</td><td rowspan="%1$d">%1$d</td>',
						$span, esc_url($order->get_edit_order_url()), esc_html($order->get_order_number()), esc_html(wc_get_order_status_name($order->get_status())));
				}
				printf('<td>%d<br><small>%s</small></td>', $pkg['item_id'], esc_html($pkg['name']));
				printf('<td>%s / %s</td>', esc_html($d['_turq_local_option_method_name'] ?? '—'), esc_html($d['_turq_local_option_group'] ?? '—'));
				printf('<td>%s</td>', esc_html(is_scalar($d['_turq_local_option'] ?? null) ? $d['_turq_local_option'] : '—'));
				printf('<td>%s</td>', esc_html($this->market_label($d['_turq_local_option_market_id'] ?? '')));
				printf('<td>%s</td>', esc_html($this->fmt_date($d['_turq_local_option_date'] ?? null)));
				if (0 === $i) {
					printf('<td rowspan="%d">%s</td>', $span, $this->list_vals($p['existing']));
					printf('<td rowspan="%d"><strong>%s</strong></td>', $span, $this->list_vals($p['add']));
					printf('<td rowspan="%d">%s</td>', $span, $this->list_vals($p['extra']));
				}
				echo '</tr>';
			}
		}
		if (!$shown) echo '<tr><td colspan="11">Nothing to show on this page.</td></tr>';
		echo '</tbody></table>';
	}
}
