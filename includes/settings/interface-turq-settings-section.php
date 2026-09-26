<?php
if (!defined('ABSPATH')) exit;

interface Turq_Settings_Section_Interface {

    public function get_id();
    public function get_label();
    public function render();
    public function save();
    public function register_hooks();
}
