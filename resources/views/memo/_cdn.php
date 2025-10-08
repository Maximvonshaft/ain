<?php
if (!defined('MEMO_CDN_HOST')) {
    $memoCdnHost = getenv('MEMO_CDN_HOST');
    if (!$memoCdnHost) {
        $memoCdnHost = 'https://fastly.jsdelivr.net';
    }
    $memoCdnHost = rtrim($memoCdnHost, '/');
    define('MEMO_CDN_HOST', $memoCdnHost);
    define('MEMO_CDN_NPM', MEMO_CDN_HOST . '/npm');
    define('MEMO_CDN_COMBINE', MEMO_CDN_HOST . '/combine');
}
