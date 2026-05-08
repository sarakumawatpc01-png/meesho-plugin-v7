<?php

require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-db.php';

class Meesho_Master_Activator {
public static function activate() {
MM_DB::install();

if ( false === get_option( 'meesho_master_settings' ) ) {
add_option(
'meesho_master_settings',
array(
'pricing_markup_type'    => 'percentage',
'pricing_markup_value'   => '20',
'pricing_rounding'       => 'none',
'scrapling_url'          => 'http://localhost:5000/scrape',
'scrapling_timeout'      => '30',
'cod_risk_threshold'     => '2000',
'copilot_auto_implement' => 'no',
'mm_seo_max_suggestions' => '10',
'mm_copilot_enabled'     => 'yes',
)
);
}
}
}
