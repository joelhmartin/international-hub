<?php
/**
 * Pure (WordPress-free) helpers for bulk user import: CSV parsing,
 * username derivation, and row validation. No WP functions here so the
 * plain-PHP test runner (tests/run.php) can exercise it.
 */
class Anchor_FM_User_Import {

    const MAX_ROWS = 1000;

    /** Lowercase and keep only characters allowed in a username. */
    public static function sanitize_username($raw) {
        $raw = strtolower(trim((string) $raw));
        return preg_replace('/[^a-z0-9._\-]/', '', $raw);
    }

    /** Derive a username base from a first + last name: first initial + '.' + last. */
    public static function derive_username($first, $last) {
        $first = trim((string) $first);
        $last  = trim((string) $last);
        $initial = $first === '' ? '' : substr($first, 0, 1);
        return self::sanitize_username($initial . '.' . $last);
    }
}
