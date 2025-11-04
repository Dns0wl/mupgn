<?php
/**
 * Plugin Name: HW Meta Pixel Scope (fast & safe)
 * Description: Matikan CAPI S3 dan batasi Meta Pixel ke checkout/thank-you saja. Tambahkan defer agar non-blocking. Aman untuk rollback.
 */
if (!defined('ABSPATH')) exit;

/**
 * QUICK ROLLBACK:
 * Set define('HW_META_PIXEL_SCOPE_DISABLE', true); di wp-config.php untuk mematikan plugin ini sewaktu-waktu.
 */
if (defined('HW_META_PIXEL_SCOPE_DISABLE') && HW_META_PIXEL_SCOPE_DISABLE) {
    return;
}

/** Halaman yang diizinkan memuat Pixel */
function hw_allow_pixel_here(): bool {
    static $allowed = null;
    if ($allowed !== null) {
        return $allowed;
    }

    // Tetap NO di wp-admin/API
    if (is_admin()) return $allowed = false;
    if (defined('REST_REQUEST') && REST_REQUEST) return $allowed = false;

    // Ya hanya di checkout & thank-you
    if (function_exists('is_order_received_page') && is_order_received_page()) return $allowed = true;
    if (function_exists('is_checkout') && is_checkout()) return $allowed = true;
    return $allowed = false;
}

/** Blokir CAPI S3 & Pixel di halaman lain (paling aman: dequeue + deregister) */
add_action('wp_print_scripts', function () {
    if (hw_allow_pixel_here()) return;

    global $wp_scripts;
    if (empty($wp_scripts) || empty($wp_scripts->queue)) return;

    foreach ($wp_scripts->queue as $handle) {
        $reg = $wp_scripts->registered[$handle] ?? null; if (!$reg) continue;
        $src = (string) ($reg->src ?? '');

        // Sumber umum: fbevents.js / connect.facebook.net / signals, dan CAPI helper di S3
        if (
            stripos($src, 'connect.facebook.net') !== false ||
            stripos($src, 'facebook.net') !== false ||
            stripos($src, 'capi-automation.s3') !== false ||
            stripos($src, 'amazonaws.com') !== false
        ) {
            wp_dequeue_script($handle);
            wp_deregister_script($handle);
        }
    }
}, 100);

/** Pastikan script Meta yang tersisa non-blocking (defer) pada halaman yang diizinkan */
add_filter('script_loader_tag', function($tag, $handle, $src){
    if (hw_allow_pixel_here() && strpos($src, 'connect.facebook.net') !== false) {
        $tag = '<script src="'.esc_url($src).'" defer></script>';
    }
    return $tag;
}, 10, 3);

/**
 * OPSIONAL – fallback Purchase di thank-you bila plugin pixel tidak auto-fire.
 * Aman: hanya berjalan jika fbq tersedia.
 */
add_action('woocommerce_thankyou', function($order_id){
    if (!$order_id) return;
    if (!function_exists('is_order_received_page') || !is_order_received_page()) return;

    $order = wc_get_order($order_id); if (!$order) return;
    $value    = (float) $order->get_total();
    $currency = get_woocommerce_currency();
    ?>
    <script>
      document.addEventListener('DOMContentLoaded', function(){
        if (typeof fbq === 'function') {
          fbq('track', 'Purchase', {
            value: <?php echo json_encode($value); ?>,
            currency: <?php echo json_encode($currency); ?>,
            contents: [],
            content_type: 'product'
          });
        }
      });
    </script>
    <?php
}, 99);
