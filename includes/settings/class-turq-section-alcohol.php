<?php
// class-turq-section-alcohol.php
//
// Texts for alcohol sales / age declaration - see turq_woo_alcohol.php

if (!defined('ABSPATH')) exit;

class turq_Section_Alcohol extends turq_Settings_Section_Base {

	private const FIELDS = [
		'product_notice'	=> ['label' => 'Product page notice',	'rows' => 2, 'desc' => 'Shown by the [turq_alcohol_notice] shortcode on alcohol product pages.'],
		'checkout_notice'	=> ['label' => 'Checkout legal notice',	'rows' => 3, 'desc' => 'Shown above the declaration checkbox when the basket contains alcohol.'],
		'checkbox_label'	=> ['label' => 'Declaration checkbox',	'rows' => 2, 'desc' => 'The customer must tick this to place the order. This wording is saved with the order.'],
		'checkout_error'	=> ['label' => 'Checkout error',		'rows' => 2, 'desc' => 'Shown if the order is placed without ticking the declaration.'],
		'email_reminder'	=> ['label' => 'Customer ID reminder', 'rows' => 2, 'desc' => 'Added to the customer Processing, On-hold and Invoice emails, and the Thank you page.'],
	];

    public function get_id() {
        return 'turq_alcohol';
    }

    public function get_label() {
        return 'Alcohol';
    }

    public function register_hooks() {
    }

    public function render() {
		$saved		= get_option(TURQ_ALCOHOL_OPTION, []);
		$defaults	= turq_alcohol_default_texts();
		$term		= get_term_by('slug', TURQ_ALCOHOL_CAT_SLUG, 'product_cat');
		?>
		<div class="turq_woo_settings">
			<h3>Alcohol Sales - Age Declaration</h3>

			<?php if ($term) : ?>
				<p>Alcohol products are those in the <a href="<?= esc_url(get_edit_term_link($term->term_id, 'product_cat', 'product')) ?>"><strong><?= esc_html($term->name) ?></strong></a> category (slug <code><?= TURQ_ALCOHOL_CAT_SLUG ?></code>) or any of its sub-categories.
				Currently <strong><?= count(turq_alcohol_term_ids()) ?></strong> categor<?= count(turq_alcohol_term_ids()) > 1 ? 'ies' : 'y' ?>.</p>
			<?php else : ?>
				<div class="notice notice-error inline"><p><strong>No product category with slug <code><?= TURQ_ALCOHOL_CAT_SLUG ?></code> found.</strong> No products will be treated as alcohol until it is created.</p></div>
			<?php endif; ?>

			<p class="description">Leave a field blank to use the default text.</p>

			<table class="form-table">
			<?php foreach (self::FIELDS as $key => $field) : ?>
				<tr valign="top">
					<th scope="row" class="titledesc"><label for="turq_alcohol_<?= $key ?>"><?= esc_html($field['label']) ?></label></th>
					<td class="forminp">
						<textarea id="turq_alcohol_<?= $key ?>" name="<?= TURQ_ALCOHOL_OPTION ?>[<?= $key ?>]" rows="<?= $field['rows'] ?>" style="width:100%;max-width:600px" placeholder="<?= esc_attr($defaults[$key]) ?>"><?= esc_textarea($saved[$key] ?? '') ?></textarea>
						<p class="description"><?= esc_html($field['desc']) ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
			</table>
		</div>
		<?php
    }

    public function save() {
		$posted = wp_unslash($_POST[TURQ_ALCOHOL_OPTION] ?? []);

		$clean = [];
		foreach (array_keys(self::FIELDS) as $key) {
			$clean[$key] = trim(wp_kses_post($posted[$key] ?? ''));
		}

		update_option(TURQ_ALCOHOL_OPTION, $clean);
    }
}
