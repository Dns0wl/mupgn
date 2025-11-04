<?php
if (!defined('ABSPATH')) exit;

function hw_has_personal_cookie() {
  if (empty($_COOKIE)) return false;
  foreach ($_COOKIE as $k => $v) {
    $kl = strtolower((string)$k);
    $v  = (string)$v;
    if (strpos($kl,'wordpress_logged_in_')!==false) return true;
    if (strpos($kl,'wp_woocommerce_session_')!==false) return true;
    if (strpos($kl,'woocommerce_items_in_cart')!==false && $v!='0') return true;
    if (strpos($kl,'yith_wcwl')!==false) return true;
    if (strpos($kl,'store_api_')!==false) return true;
    if (strpos($kl,'woocommerce_recently_viewed')!==false) return true;
  }
  return false;
}
function hw_safe_header($name,$value){ if(!headers_sent()) @header($name.': '.$value,true); }

add_action('send_headers', function () {
  if (headers_sent()) return;

  // BYPASS untuk user & stateful
  if (hw_has_personal_cookie()) {
    hw_safe_header('Cache-Control','private, no-store, no-cache, must-revalidate, max-age=0');
    hw_safe_header('Vary','Cookie, Accept-Encoding');
    @header('X-HW-Guard: danger', true);
    if (!defined('DONOTCACHEPAGE'))   define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);
    return;
  }

  // Query ?filter_* → cache 20m (edge), browser 5m
  $has_filter = false;
  if (!empty($_GET)) {
    foreach (array_keys($_GET) as $k) { if (stripos((string)$k,'filter_')===0) { $has_filter = true; break; } }
  }
  if ($has_filter) {
    hw_safe_header('Cache-Control','public, max-age=300, stale-while-revalidate=120, stale-if-error=3600, s-maxage=1200');
    hw_safe_header('Vary','Accept-Encoding');
    @header('X-HW-Guard: filter', true);
    return;
  }

  // Anon default → cache 30m (edge), browser 5m
  hw_safe_header('Cache-Control','public, max-age=300, stale-while-revalidate=120, stale-if-error=3600, s-maxage=1800');
  hw_safe_header('Vary','Accept-Encoding');
  @header('X-HW-Guard: allowlist', true);
}, 999999);
