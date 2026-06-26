<?php
/**
 * Pure (WordPress-free) name-collision helpers for copy/paste/duplicate.
 * No WP functions here so tests/run.php can exercise it.
 */
class Anchor_FM_Copy_Namer {

    const MAX_SUFFIX = 1000;

    /** Split into [base, ext]; ext includes the leading dot, or '' if none. */
    public static function split_extension($name) {
        $name = (string) $name;
        if (preg_match('/^(.+)(\.[A-Za-z0-9]{1,10})$/', $name, $m)) {
            return [$m[1], $m[2]];
        }
        return [$name, ''];
    }

    /** Append or bump a "(copy)" suffix on a base (no extension handling). */
    public static function add_copy_suffix($base) {
        $base = (string) $base;
        if (preg_match('/^(.*) \(copy(?: (\d+))?\)$/', $base, $m)) {
            $stem = $m[1];
            $n = (isset($m[2]) && $m[2] !== '') ? (int) $m[2] : 1;
            return $stem . ' (copy ' . ($n + 1) . ')';
        }
        return $base . ' (copy)';
    }
}
