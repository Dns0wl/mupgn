<?php
/**
 * Plugin Name: HW Dedupe Queries (MU)
 * Description: Kurangi duplicate queries (Elementor upgrades, Woo Blocks patterns, option thrash) di FRONTEND tanpa mengubah plugin/tema lain.
 * Version: 1.1.0
 * Author: HW
 *
 * Cara pakai:
 * 1) Upload file ini ke /wp-content/mu-plugins/
 * 2) (Opsional) Tambah toggle di wp-config.php:
 *      define('HW_DEDUPE_DISABLE', false);                // true = matikan plugin ini
 *      define('HW_DEDUPE_DISABLE_ELEMENTOR', false);      // true = JANGAN sentuh Elementor Upgrades
 *      define('HW_DEDUPE_DISABLE_WOO_PATTERNS', false);   // true = JANGAN sentuh Woo Blocks patterns
 *      define('HW_DEDUPE_DISABLE_OPTIONS_DEBOUNCE', false);// true = JANGAN debounce get/update_option
 *
 * Frontend-only: di wp-admin / cron semuanya tetap berjalan normal.
 */

if (!defined('ABSPATH')) exit;
if (defined('HW_DEDUPE_DISABLE') && HW_DEDUPE_DISABLE) return;

/* ===== Util: deteksi pure frontend ===== */
function hw_is_pure_frontend_dedupe(): bool {
    if (is_admin() || wp_doing_cron()) return false;
    if (defined('WP_CLI') && WP_CLI) return false;
    return true;
}

/* ===== 1) Hentikan cek/migrasi Elementor di FRONTEND saja ===== */
add_action('plugins_loaded', function () {
    if (!hw_is_pure_frontend_dedupe()) return;
    if (defined('HW_DEDUPE_DISABLE_ELEMENTOR') && HW_DEDUPE_DISABLE_ELEMENTOR) return;

    // Elementor Core upgrades
    if (class_exists('\Elementor\Core\Base\DB_Upgrades_Manager')) {
        try {
            $mgr = \Elementor\Core\Base\DB_Upgrades_Manager::instance();
            // Elementor biasanya hook 'start_run' di init/wp (prio tinggi)
            remove_action('init', [$mgr, 'start_run'], 100);
            remove_action('wp',   [$mgr, 'start_run'], 100);
        } catch (\Throwable $e) { /* noop */ }
    }

    // Beberapa versi memicu upgrades lain; cegah pola umum jika tersedia.
    if (has_action('init', 'elementor_pro_db_upgrades')) {
        remove_action('init', 'elementor_pro_db_upgrades', 100);
    }
}, 20);

/* ===== 2) Matikan WooCommerce Blocks "patterns" scheduler di FRONTEND ===== */
add_action('plugins_loaded', function () {
    if (!hw_is_pure_frontend_dedupe()) return;
    if (defined('HW_DEDUPE_DISABLE_WOO_PATTERNS') && HW_DEDUPE_DISABLE_WOO_PATTERNS) return;

    // Filter resmi (nama bisa beda antar versi — set keduanya aman)
    add_filter('woocommerce_blocks_load_patterns', '__return_false', 999);
    add_filter('woocommerce_should_load_block_patterns', '__return_false', 999);

    // Jaga-jaga: lepas hook inisialisasi patterns bila ada
    if (function_exists('remove_all_actions')) {
        remove_all_actions('woocommerce_blocks_patterns_init');
    }
}, 21);

/* ===== 3) Debounce option get/update yang sering berulang dalam satu request ===== */
add_action('init', function () {
    if (!hw_is_pure_frontend_dedupe()) return;
    if (defined('HW_DEDUPE_DISABLE_OPTIONS_DEBOUNCE') && HW_DEDUPE_DISABLE_OPTIONS_DEBOUNCE) return;

    /**
     * Tambahkan kunci option yang sering muncul di Query Monitor Anda.
     * - 'elementor-custom-breakpoints-files' (sering di-update berulang)
     * - '_elementor_assets_data'
     * Anda boleh menambah kunci lain jika terlihat spam di Duplicate Queries.
     */
    $targets = [
        'elementor-custom-breakpoints-files',
        '_elementor_assets_data',
    ];

    // Cache lokal per-request
    static $seen_get = [];
    static $seen_update = [];

    // Short-circuit GET → hindari SELECT berulang (jika sebelumnya sudah dimuat)
    foreach ($targets as $opt) {
        add_filter("pre_option_{$opt}", function ($pre) use ($opt, &$seen_get) {
            return array_key_exists($opt, $seen_get) ? $seen_get[$opt] : $pre;
        }, 1);

        add_filter("option_{$opt}", function ($val) use ($opt, &$seen_get) {
            $seen_get[$opt] = $val;
            return $val;
        }, 999);
    }

    // Debounce UPDATE → kalau dalam request ini sudah menulis nilai yang sama, skip
    add_filter('pre_update_option', function ($value, $option, $old_value) use (&$seen_update, $targets) {
        if (!in_array($option, $targets, true)) return $value;

        // Jika nilai sama persis, biarkan WordPress menggagalkan update (no-op), menghindari query.
        if ($value === $old_value) return $old_value;

        // Hindari write berulang-ulang ke nilai yang identik pada request yang sama
        $sig = $option . '::' . md5(maybe_serialize($value));
        if (isset($seen_update[$sig])) {
            // Kembalikan nilai lama untuk menghentikan update kedua (no-op)
            return $old_value;
        }
        $seen_update[$sig] = true;
        return $value;
    }, 10, 3);
}, 1);

/* ===== 4) Whitelist jalur/route tertentu (opsional) =====
 * Jika Anda punya endpoint yang HARUS mengaktifkan patterns/upgrade di frontend, ganti return true.
 */
function hw_dedupe_is_whitelisted_route(): bool {
    // Contoh: aktifkan kembali pada /healthcheck-dont-dedupe
    // $u = $_SERVER['REQUEST_URI'] ?? '/';
    // if (strpos($u, '/healthcheck-dont-dedupe') === 0) return true;
    return false;
}
if (hw_dedupe_is_whitelisted_route()) {
    // Matikan semua optimasi untuk route whitelist
    return;
}
