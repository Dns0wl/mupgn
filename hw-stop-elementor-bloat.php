<?php
/**
 * Plugin Name: HW Stop Elementor Bloat (frontend gate)
 * Description: Jangan muat aset Elementor/Elementor Pro di halaman yang tidak dibangun dengan Elementor.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Deteksi sederhana apakah halaman saat ini dibangun dengan Elementor.
 * Aman untuk singular (page/post). Untuk archive/search, kita cek keberadaan Template Kit via hook render.
 */
function hw_is_current_request_elementor_built(): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // Ajax/REST editor Elementor atau wp-admin harus lolos.
    if (defined('DOING_AJAX') && DOING_AJAX) return $cached = true;
    if (defined('REST_REQUEST') && REST_REQUEST) return $cached = true;
    if (is_admin()) return $cached = true;

    // Singular: cek meta _elementor_edit_mode / _elementor_data.
    if (is_singular()) {
        $post_id = get_queried_object_id();
        if ($post_id) {
            $edit_mode = get_post_meta($post_id, '_elementor_edit_mode', true);
            if ($edit_mode === 'builder') {
                return $cached = true;
            }

            $data = get_post_meta($post_id, '_elementor_data', true);
            if (!empty($data)) {
                return $cached = true;
            }
        }
    }

    // Elementor punya helper internal yang lebih ringan.
    if (did_action('elementor/loaded') && class_exists('Elementor\\Plugin')) {
        try {
            $plugin = \Elementor\Plugin::instance();

            if ($plugin && isset($plugin->frontend) && method_exists($plugin->frontend, 'has_elementor_in_page')) {
                if ($plugin->frontend->has_elementor_in_page()) {
                    return $cached = true;
                }
            }

            if ($plugin && isset($plugin->documents) && method_exists($plugin->documents, 'get_current')) {
                $document = $plugin->documents->get_current();
                if ($document) {
                    if (method_exists($document, 'is_built_with_elementor')) {
                        if ($document->is_built_with_elementor()) {
                            return $cached = true;
                        }
                    } else {
                        // Jika dokumen terdeteksi namun tanpa method helper, anggap Elementor aktif.
                        return $cached = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Jika helper Elementor gagal, lanjutkan ke fallback default (anggap non-Elementor).
        }
    }

    return $cached = false;
}

/**
 * Dequeue aset jika halaman bukan Elementor.
 */
add_action('wp_enqueue_scripts', function () {
    if (hw_is_current_request_elementor_built()) return;

    $styles = array(
        'elementor-frontend',
        'elementor-post-'.get_queried_object_id(),
        'elementor-icons',
        'elementor-icons-shared-0',   // eicons varian lama
        'elementor-pro',
        'elementor-global',
        'elementor-common',
        'elementor-animations',
        'elementor-icons-fa-solid',
        'elementor-icons-fa-regular',
        'elementor-icons-fa-brands',
        'elementor-pro-frontend',
        'e-animations',
        'swiper',                     // library slider
        'flatpickr',                  // datepicker yang kadang terikut
        'lity', 'photoswipe',         // jika ada
    );

    $scripts = array(
        'elementor-frontend',
        'elementor-webpack-runtime',
        'elementor-assets-js-frontend',
        'elementor-pro-frontend',
        'elementor-pro-webpack-runtime',
        'elementor-waypoints',
        'jquery-waypoints',
        'swiper',
        'imagesloaded',
        'lodash',
        'jquery-ui-core',
        'dialog',             // dialog/polyfill yang kadang didaftarkan
        'share-link',
        'flatpickr',
        'photoswipe',
        'lottie',
    );

    foreach ($styles as $h) { if (wp_style_is($h, 'enqueued')) wp_dequeue_style($h); }
    foreach ($scripts as $h){ if (wp_script_is($h, 'enqueued')) wp_dequeue_script($h); }
}, 9999);

add_filter('script_loader_tag', function($tag, $handle){
    $defer_list = array(
        'elementor-frontend',
        'elementor-pro-frontend',
        'swiper',
        'imagesloaded',
    );
    if (in_array($handle, $defer_list, true)) {
        // hindari double defer
        if (strpos($tag, 'defer') === false) {
            $tag = str_replace('<script ', '<script defer ', $tag);
        }
    }
    return $tag;
}, 10, 2);

add_action('elementor/widgets/register', function($widgets_manager){
    $to_remove = array(
        'audio', 'progress', 'toggle', 'social-icons',
        'image-carousel', 'slides', 'testimonial-carousel',
        'lottie', 'share-buttons'
    );
    foreach ($to_remove as $name) {
        try { $widgets_manager->unregister($name); } catch (\Throwable $e) {}
    }
}, 100);

