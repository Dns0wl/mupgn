<?php
// mu-plugins/hw-hmac-verify.php

if (!defined('ABSPATH')) { exit; }

define('HW_HMAC_SECRET', 'ht4yct876mhym3ct83yct7thn9tvyoqyt35'); // SAMA persis dgn Apps Script
define('HW_HMAC_DRIFT', 300);   // toleransi 5 menit
define('HW_HMAC_DEBUG', true);  // set false jika sudah stabil

/**
 * Ambil header dg fallback aman (Nginx/FastCGI menaruh di $_SERVER)
 */
function hw_get_header($name) {
    $all = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($all[$name])) return $all[$name];

    $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
    if (isset($_SERVER[$key])) return $_SERVER[$key];

    // beberapa stack juga menaruh lowercase
    if (isset($_SERVER[strtolower($key)])) return $_SERVER[strtolower($key)];
    return null;
}

/**
 * Verifikasi HMAC: base64( HMAC-SHA256( ts+"\n"+rawBody , SECRET ) )
 * return true jika valid, atau WP_Error jika gagal
 */
function hw_verify_hmac_from_request($raw_body) {
    $ts  = hw_get_header('X-HW-Timestamp');
    $sig = hw_get_header('X-HW-Signature');
    $cli = hw_get_header('X-HW-Client');

    if (!$ts || !$sig || !$cli) {
        if (HW_HMAC_DEBUG) error_log('HW-HMAC missing header(s): ts=' . var_export($ts,true) . ' sig=' . var_export($sig,true) . ' cli=' . var_export($cli,true));
        return new WP_Error('hw_sig_missing', 'signature/timestamp missing', ['status' => 401]);
    }

    if ($cli !== 'AppsScript') {
        if (HW_HMAC_DEBUG) error_log('HW-HMAC wrong client: ' . $cli);
        return new WP_Error('hw_client_invalid', 'invalid client', ['status' => 401]);
    }

    // cek drift waktu
    if (!ctype_digit((string)$ts)) {
        if (HW_HMAC_DEBUG) error_log('HW-HMAC bad ts: ' . $ts);
        return new WP_Error('hw_ts_invalid', 'invalid timestamp', ['status' => 401]);
    }
    $now = time();
    if (abs($now - (int)$ts) > HW_HMAC_DRIFT) {
        if (HW_HMAC_DEBUG) error_log('HW-HMAC ts drift: now='.$now.' ts='.$ts);
        return new WP_Error('hw_ts_drift', 'timestamp out of window', ['status' => 401]);
    }

    // hitung ulang signature dari RAW body (harus IDENTIK)
    $toSign = $ts . "\n" . $raw_body;
    $calc   = base64_encode(hash_hmac('sha256', $toSign, HW_HMAC_SECRET, true));

    // bandingkan waktu konstan
    $ok = hash_equals($calc, $sig);

    if (!$ok && HW_HMAC_DEBUG) {
        error_log('HW-HMAC mismatch: calc=' . $calc . ' sent=' . $sig . ' len(raw)=' . strlen($raw_body));
        // log potongan body untuk bantu debugging (maks 256 chars)
        error_log('HW-HMAC body head: ' . substr($raw_body, 0, 256));
    }

    return $ok ? true : new WP_Error('hw_sig_invalid', 'invalid signature', ['status' => 401]);
}
