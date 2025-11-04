<?php
/**
 * Plugin Name: HW REST Load More (MU)
 * Description: Ultra-light REST endpoint untuk infinite scroll / load-more yang ramah cache (Nginx/Cloudflare).
 * Author: Hayu Widyas
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) exit;

add_action('rest_api_init', function () {

  register_rest_route('hw/v1', '/load-more', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $r) {

      // 1) Sanitasi & batas aman
      $page = (int) $r->get_param('page');
      $per  = (int) $r->get_param('per_page');

      if ($page < 1) $page = 1;
      if ($per  < 6) $per  = 12;         // default
      if ($per  > 24) $per  = 24;        // hard cap agar kueri tetap ringan

      // (Opsional) filter dasar kalau nanti dibutuhkan, biarkan kosong saja bila tak dipakai
      $orderby = sanitize_key($r->get_param('orderby') ?: 'date'); // 'date','meta_value','rand',dst.
      $order   = strtoupper($r->get_param('order') ?: 'DESC');
      if (!in_array($order, ['ASC','DESC'], true)) $order = 'DESC';

      // 2) Query super-hemat (IDs only, tanpa hitung total, tanpa meta/term cache)
      $q = new WP_Query([
        'post_type'              => 'product',
        'post_status'            => 'publish',
        'paged'                  => $page,
        'posts_per_page'         => $per,
        'no_found_rows'          => true,   // tidak hitung total → jauh lebih cepat
        'fields'                 => 'ids',  // hanya ID → response kecil
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
        'orderby'                => $orderby,
        'order'                  => $order,
      ]);

      $ids = $q->posts ?: [];

      // 3) Informasi minimal untuk navigasi berikutnya
      $has_more = count($ids) === $per; // heuristik: jika penuh, kemungkinan masih ada page berikutnya

      // 4) Response 200 tanpa header yang mengganggu cache (biar Nginx/CF yang atur TTL)
      return new WP_REST_Response([
        'ok'        => true,
        'page'      => $page,
        'per_page'  => $per,
        'count'     => count($ids),
        'has_more'  => $has_more,
        'ids'       => array_map('intval', $ids),
      ], 200);
    }
  ]);

});
