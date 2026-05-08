<?php

class Meesho_Master_Admin {
private $plugin_name;
private $version;

public function __construct( $plugin_name, $version ) {
$this->plugin_name = $plugin_name;
$this->version     = $version;
}

public function enqueue_styles( $hook_suffix ) {
if ( false === strpos( $hook_suffix, 'meesho-master' ) ) {
return;
}
wp_enqueue_style( $this->plugin_name, MEESHO_MASTER_PLUGIN_URL . 'admin/css/meesho-admin.css', array(), $this->version, 'all' );
}

public function enqueue_scripts( $hook_suffix ) {
if ( false === strpos( $hook_suffix, 'meesho-master' ) ) {
return;
}
wp_enqueue_script( $this->plugin_name, MEESHO_MASTER_PLUGIN_URL . 'admin/js/meesho-admin.js', array(), $this->version, true );
wp_localize_script(
$this->plugin_name,
'meesho_ajax',
array(
'ajax_url' => admin_url( 'admin-ajax.php' ),
'nonce'    => wp_create_nonce( 'mm_nonce' ),
)
);
}

public function add_plugin_admin_menu() {
add_menu_page( 'Meesho Master', 'Meesho Master', 'manage_options', $this->plugin_name, array( $this, 'display_plugin_setup_page' ), 'dashicons-cart', 25 );
}

public function display_plugin_setup_page() {
require_once MEESHO_MASTER_PLUGIN_DIR . 'admin/partials/meesho-admin-display.php';
}
}
