<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    // Allow standalone include in tests; block direct web access in WP.
    if (php_sapi_name() !== 'cli') { exit; }
}

class Anchor_FM_Vimeo {

    /**
     * Extract the numeric Vimeo id from any common URL form or a bare id.
     * Returns '' when no id can be found.
     */
    public static function parse_id($input) {
        $input = trim((string) $input);
        if ($input === '') return '';

        if (ctype_digit($input)) return $input;

        // Match the first run of >=6 digits that follows a vimeo path segment
        // or video/ segment. Falls back to the first long digit run in a vimeo URL.
        if (preg_match('~(?:player\.)?vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/)?(\d{6,})~i', $input, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Build the privacy-friendly player embed URL for a numeric id.
     */
    public static function embed_url($vimeo_id) {
        $vimeo_id = preg_replace('/\D+/', '', (string) $vimeo_id);
        if ($vimeo_id === '') return '';
        return 'https://player.vimeo.com/video/' . $vimeo_id;
    }
}
