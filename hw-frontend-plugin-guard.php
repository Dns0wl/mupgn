<?php
/**
 * Plugin Name: HW Frontend Plugin Guard (1.8.0 Ultra-Strict)
 * Description: Biteship hanya aktif di /checkout (+ AJAX/Store API checkout) & wp-admin. Di halaman lain: unhook semua enqueue Biteship, cegah shipping method, blokir asset URL, dequeue sisa, dan bersihkan via output buffer. YITH hanya aktif di /pos & wp-admin.
 * Author: Hayu Widyas
 * Version: 1.8.0
 */

if (!defined('ABSPATH')) exit;

/* =======================
 * Helpers (path & context)
 * ======================= */
function hw_uri(): string {
    static $uri = null;
    if ($uri !== null) {
        return $uri;
    }
    $uri = isset($_SERVER['REQUEST_URI']) ? rawurldecode((string) $_SERVER['REQUEST_URI']) : '/';
    if ($uri === '') {
        $uri = '/';
    }
    return $uri;
}
function hw_request_path(): string {
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $u = hw_uri();
    $p = parse_url($u, PHP_URL_PATH);
    $path = ($p === null || $p === false || $p === '') ? '/' : $p;
    return $path;
}
function hw_request_path_for_match(): string {
    static $match_path = null;
    if ($match_path !== null) {
        return $match_path;
    }
    $match_path = strtolower(rtrim(hw_request_path(), '/'));
    return $match_path;
}
/** cocok segmen multilingual: /en/checkout, /checkout/order-pay/123 */
function hw_path_has_segment(array $segments): bool {
    $path = hw_request_path_for_match();
    if ($path === '') {
        // path root → tidak ada segmen
        return false;
    }

    foreach ($segments as $seg) {
        $seg = strtolower((string) $seg);
        if ($seg === '') {
            continue;
        }
        $needle = '/' . $seg;
        if ($path === $needle) {
            return true;
        }
        if (strpos($path, $needle . '/') !== false) {
            return true;
        }
        if (substr($path, -strlen($needle)) === $needle) {
            return true;
        }
    }
    return false;
}
function hw_is_admin_like(): bool { return is_admin() || (defined('WP_CLI') && WP_CLI); }
function hw_is_pos_request(): bool {
    static $result = null;
    if ($result !== null) return $result;
    return $result = hw_path_has_segment(['pos']);
}
function hw_is_checkout_like_path(): bool {
    static $result = null;
    if ($result !== null) return $result;
    return $result = hw_path_has_segment(['checkout','checkout-2']);
}
function hw_ref_is_checkout(): bool {
    static $result = null;
    if ($result !== null) return $result;
    $ref = isset($_SERVER['HTTP_REFERER']) ? strtolower((string) $_SERVER['HTTP_REFERER']) : '';
    return $result = ($ref && (strpos($ref, '/checkout') !== false || strpos($ref, '/checkout-2') !== false));
}
function hw_is_checkout_wc_ajax(): bool {
    static $result = null;
    if ($result !== null) return $result;
    if (!function_exists('wp_doing_ajax') || !wp_doing_ajax()) return $result = false;
    $wc_ajax = isset($_REQUEST['wc-ajax']) ? strtolower((string) $_REQUEST['wc-ajax']) : '';
    if ($wc_ajax === 'get_refreshed_fragments') return $result = hw_ref_is_checkout();
    $allow = ['update_order_review','update_shipping_method','checkout','apply_coupon','remove_coupon','update_cart','get_cart_totals','get_variation','add_to_cart'];
    if ($wc_ajax && in_array($wc_ajax, $allow, true)) return $result = true;
    $action = isset($_REQUEST['action']) ? strtolower((string) $_REQUEST['action']) : '';
    return $result = in_array($action, ['woocommerce_update_order_review','woocommerce_checkout','woocommerce_apply_coupon','woocommerce_remove_coupon','woocommerce_update_cart_action','woocommerce_get_cart_totals'], true);
}
function hw_is_checkout_store_api(): bool {
    static $result = null;
    if ($result !== null) return $result;
    if (!defined('REST_REQUEST') || !REST_REQUEST) return $result = false;
    $u = isset($_GET['rest_route']) ? (string) $_GET['rest_route'] : hw_uri();
    $u = strtolower($u);
    if (strpos($u, '/wc/store/') === false && strpos($u, '/wp-json/wc/store/') === false) return $result = false;
    return $result = ((strpos($u, '/checkout') !== false) || (strpos($u, '/shipping-rates') !== false) || (strpos($u, '/cart/extensions') !== false));
}
function hw_guard_allow_flags(): array {
    static $flags = null;
    if ($flags !== null) {
        return $flags;
    }

    $allow_biteship = false;
    if (hw_is_admin_like()) {
        $allow_biteship = true;
    } elseif (hw_is_checkout_like_path()) {
        $allow_biteship = true;
    } elseif (function_exists('is_checkout') && is_checkout()) {
        $allow_biteship = true;
    } elseif (hw_is_checkout_wc_ajax()) {
        $allow_biteship = true;
    } elseif (hw_is_checkout_store_api()) {
        $allow_biteship = true;
    }

    $allow_yith = hw_is_admin_like() ? true : hw_is_pos_request();

    $flags = [
        'biteship' => $allow_biteship,
        'yith'     => $allow_yith,
    ];

    return $flags;
}

function hw_allow_biteship_now(): bool {
    $flags = hw_guard_allow_flags();
    return $flags['biteship'];
}
function hw_allow_yith_now(): bool {
    $flags = hw_guard_allow_flags();
    return $flags['yith'];
}

/* ======================================
 * Debug header untuk verifikasi cepat
 * ====================================== */
add_action('send_headers', function(){
    $flags = hw_guard_allow_flags();
    if (headers_sent()) return;
    header('X-HW-Guard: B:' . ($flags['biteship'] ? 'on' : 'off')
        . ';Y:' . ($flags['yith'] ? 'on' : 'off')
        . ';path=' . hw_request_path());
}, 0);

/* ============================================================
 * 1) UNHOOK semua callback Biteship dari hook publik (non-checkout)
 *    Deteksi berdasarkan path file callback mengandung /plugins/biteship/
 * ============================================================ */
function hw_callable_matches_hints($callable, array $hints): bool {
    foreach ($hints as $hint) {
        if ($hint === '') continue;
        if (is_string($callable)) {
            if (stripos($callable, $hint) !== false) return true;
        } elseif (is_array($callable) && isset($callable[0])) {
            $target = $callable[0];
            if (is_object($target)) {
                $target = get_class($target);
            }
            if (is_string($target) && stripos($target, $hint) !== false) {
                return true;
            }
        }
    }
    return false;
}

function hw_callable_source_file($callable) {
    try {
        if (is_array($callable)) {
            if (is_object($callable[0])) {
                $ref = new ReflectionMethod($callable[0], $callable[1]);
                return $ref->getFileName();
            }
            if (is_string($callable[0])) {
                $ref = new ReflectionMethod($callable[0], $callable[1]);
                return $ref->getFileName();
            }
        } elseif ($callable instanceof Closure) {
            $ref = new ReflectionFunction($callable);
            return $ref->getFileName();
        } elseif (is_string($callable) && function_exists($callable)) {
            $ref = new ReflectionFunction($callable);
            return $ref->getFileName();
        }
    } catch (\Throwable $e) {
        return false;
    }
    return false;
}

function hw_unhook_all_from_dir(string $dir_fragment, array $hooks, array $name_hints = []){
    global $wp_filter;
    static $file_cache = [];
    $normalized_hints = [];
    foreach ($name_hints as $hint) {
        $hint = strtolower((string) $hint);
        if ($hint !== '') {
            $normalized_hints[] = $hint;
        }
    }
    foreach ($hooks as $hook) {
        if (empty($wp_filter[$hook])) continue;
        foreach ($wp_filter[$hook]->callbacks ?? [] as $prio => $callbacks) {
            foreach ($callbacks as $cb_id => $cb) {
                $callable = $cb['function'];
                if ($normalized_hints && hw_callable_matches_hints($callable, $normalized_hints)) {
                    remove_filter($hook, $callable, $prio);
                    continue;
                }
                $file = $file_cache[$cb_id] ?? null;
                if ($file === null) {
                    $file = hw_callable_source_file($callable);
                    $file_cache[$cb_id] = $file !== false ? $file : false;
                }
                if ($file && stripos($file, $dir_fragment) !== false) {
                    remove_filter($hook, $callable, $prio);
                }
            }
        }
    }
}
add_action('init', function(){
    if (hw_allow_biteship_now()) return; // Biarkan di checkout/admin
    // Unhook Biteship dari semua hook umum UI & enqueue
    hw_unhook_all_from_dir('/wp-content/plugins/biteship/', [
        'init','wp','wp_head','wp_footer','wp_enqueue_scripts','wp_print_styles','wp_print_scripts',
        'wp_print_footer_scripts','template_redirect','rest_api_init'
    ], ['biteship']);
}, 99); // setelah kebanyakan plugin mendaftarkan hook-nya

/* ============================================================
 * 2) Cegah shipping method Biteship di non-checkout
 * ============================================================ */
add_filter('woocommerce_shipping_methods', function($methods){
    if (hw_allow_biteship_now()) return $methods;
    foreach ($methods as $id => $class) {
        $id_l = strtolower((string) $id);
        $class_l = strtolower((string) $class);
        if (strpos($id_l, 'biteship') !== false || strpos($class_l, 'biteship') !== false) {
            unset($methods[$id]);
        }
    }
    return $methods;
}, 0);

/* ============================================================
 * 3) Blokir URL asset yang langsung menunjuk ke folder Biteship/YITH
 * ============================================================ */
add_filter('script_loader_src', function($src){
    $flags = hw_guard_allow_flags();
    if (!$flags['biteship'] && stripos($src, '/wp-content/plugins/biteship/') !== false) return false;
    if (!$flags['yith']     && stripos($src, '/wp-content/plugins/yith-point-of-sale-for-woocommerce-premium/') !== false) return false;
    return $src;
}, 9999);
add_filter('style_loader_src', function($src){
    $flags = hw_guard_allow_flags();
    if (!$flags['biteship'] && stripos($src, '/wp-content/plugins/biteship/') !== false) return false;
    if (!$flags['yith']     && stripos($src, '/wp-content/plugins/yith-point-of-sale-for-woocommerce-premium/') !== false) return false;
    return $src;
}, 9999);

/* ============================================================
 * 4) Dequeue/Deregister handle yang tersisa (sweeper)
 * ============================================================ */
function hw_kill_handles_like(string $needle){
    global $wp_scripts, $wp_styles;
    if ($wp_scripts instanceof WP_Scripts) {
        foreach ((array) $wp_scripts->queue as $h) {
            if (stripos($h, $needle) !== false) { wp_dequeue_script($h); wp_deregister_script($h); }
        }
    }
    if ($wp_styles instanceof WP_Styles) {
        foreach ((array) $wp_styles->queue as $h) {
            if (stripos($h, $needle) !== false) { wp_dequeue_style($h); wp_deregister_style($h); }
        }
    }
}
add_action('wp_enqueue_scripts', function(){
    $flags = hw_guard_allow_flags();
    if (!$flags['biteship']) hw_kill_handles_like('biteship');
    if (!$flags['yith'])     hw_kill_handles_like('yith-pos');
}, 9999);
add_action('wp_print_footer_scripts', function(){
    $flags = hw_guard_allow_flags();
    if (!$flags['biteship']) hw_kill_handles_like('biteship');
    if (!$flags['yith'])     hw_kill_handles_like('yith-pos');
}, 9999);

/* ============================================================
 * 5) Output Buffer: hapus tag <script>/<link> yang memuat Biteship jika lolos
 * ============================================================ */
function hw_buffer_strip_biteship($html){
    if (hw_allow_biteship_now()) return $html;

    if (stripos($html, '/wp-content/plugins/biteship/') === false) {
        return $html;
    }

    // Hapus <script> & <link> yang mengarah ke folder plugin Biteship
    $html = preg_replace('~<script[^>]+src=["\'][^"\']*/wp-content/plugins/biteship/[^"\']*["\'][^>]*>\s*</script>~i', '', $html);
    $html = preg_replace('~<link[^>]+href=["\'][^"\']*/wp-content/plugins/biteship/[^"\']*["\'][^>]*>~i', '', $html);
    return $html;
}
add_action('template_redirect', function(){
    if (hw_allow_biteship_now() || is_admin()) return;
    ob_start('hw_buffer_strip_biteship');
}, 0);
