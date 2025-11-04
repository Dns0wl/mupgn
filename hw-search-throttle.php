<?php
/**
 * Plugin Name: HW Search Throttle
 */
add_action('wp_enqueue_scripts', function() {
  if (!is_admin()) {
    wp_add_inline_script('jquery-core', <<<JS
(function(){
  var t, xhr;
  function throttleSearch(){
    var $inp = jQuery('input[name="keyword"]');
    if(!$inp.length) return;
    $inp.off('input.hw').on('input.hw', function(){
      var v = this.value || '';
      if (xhr && xhr.readyState !== 4) { xhr.abort(); }
      clearTimeout(t);
      if (v.length < 3) return; // minimal 3 huruf
      t = setTimeout(function(){
        // ganti selector/endpoint sesuai widget kamu
        xhr = jQuery.get('/wp-admin/admin-ajax.php', {
          action: 'styler_ajax_search_product',
          keyword: v,
          category: ''
        });
      }, 400);
    });
  }
  jQuery(document).on('ready ajaxComplete', throttleSearch);
})();
JS
);
  }
});
