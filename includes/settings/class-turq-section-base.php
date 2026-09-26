<?php
if (!defined('ABSPATH')) exit;

/**
 * Base class for all WooCommerce settings sections
 * Provides common utilities (e.g., sanitization) to all sections
 */
abstract class turq_Settings_Section_Base implements turq_Settings_Section_Interface {

    /**
     * Sanitize an array based on a schema
     *
     * $schema = [
     *     'field_name' => 'sanitize_text_field'
     *     'qty'        => 'absint',
     *     'day'        => ['regex' => '/^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)$/', 'default' => 'Tue']
     * ]
     *
     * @param array $data   Raw $_POST data
     * @param array $schema Schema of sanitizers
     * @return array        Sanitized array
     */
    protected function sanitize_array_by_schema(array $data, array $schema): array {
        $clean = [];

        foreach ($schema as $key => $sanitizer) {
            $value = $data[$key] ?? null;

            // Callable sanitizer (sanitize_text_field, absint, etc)
            if (is_callable($sanitizer)) {
                $clean[$key] = $sanitizer($value);
                continue;
            }

            // Extended validation or data injection
            if (is_array($sanitizer)) {
                if (isset($sanitizer['regex'])) {
                    $clean[$key] = preg_match($sanitizer['regex'], (string)$value)
                        ? $value
                        : ($sanitizer['default'] ?? '');
                    continue;
                }
                if (isset($sanitizer['data'])) {
                    $clean[$key] = $sanitizer['data'];
                    continue;
                }
            }

            // Fallback
            $clean[$key] = '';
        }
		//error_log("cleaned-------------");
		//error_log(dpv(compact('schema', 'data', 'clean')));
        return $clean;
    }


    /**
     * Every section must define these methods
     */
    abstract public function get_id();
    abstract public function get_label();
    abstract public function render();
    abstract public function save();
    abstract public function register_hooks();
}