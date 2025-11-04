<?php
/**
 * Plugin Name: HW – Stop Frontend Product Lookup Updates (Safe)
 */
if ( ! defined('ABSPATH') ) exit;

// NON-INVASIVE: hanya mematikan update lookup saat request publik
add_filter('woocommerce_attribute_lookup_update_enabled', function($enabled){
    if ( defined('WP_CLI') && WP_CLI ) return $enabled; // tetap jalan di WP-CLI
    if ( is_admin() ) return $enabled;                  // tetap jalan di Admin
    if ( defined('REST_REQUEST') && REST_REQUEST ) return $enabled; // REST internal
    return false;                                       // blok di frontend
}, 999);

// Hindari write lain yang tak perlu di pageview
add_filter('woocommerce_update_product_lookup_tables_on_edit', '__return_false', 999);
