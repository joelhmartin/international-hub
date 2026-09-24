<?php
/**
 * Pure (WordPress-free) rules for managing portal users from the front end:
 * password policy, which password a new user gets, and which accounts an
 * admin may act on. Kept free of WP calls so tests/run.php can cover it.
 */
class Anchor_FM_User_Admin {

    const MIN_PASSWORD_LENGTH = 10;

    public static function validate_password($pw) {
        $pw = trim((string) $pw);
        // Characters, not bytes: "ééééé" is 10 bytes but only 5 characters.
        $length = preg_match_all('/./us', $pw);
        if ($length === false || $length < self::MIN_PASSWORD_LENGTH) {
            return ['ok' => false, 'error' => 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters'];
        }
        return ['ok' => true, 'error' => ''];
    }

    /** Row password beats the batch default; neither means the caller generates one. */
    public static function resolve_password($row_pw, $default_pw) {
        $row_pw = trim((string) $row_pw);
        if ($row_pw !== '') return ['source' => 'row', 'password' => $row_pw];
        $default_pw = trim((string) $default_pw);
        if ($default_pw !== '') return ['source' => 'default', 'password' => $default_pw];
        return ['source' => 'generate', 'password' => ''];
    }

    public static function is_manageable(array $target_roles, $target_id, $actor_id) {
        $target_id = (int) $target_id;
        if ($target_id <= 0) return false;
        if ($target_id === (int) $actor_id) return false;
        return !in_array('administrator', array_map('strtolower', $target_roles), true);
    }

    public static function valid_role($role, array $valid_keys) {
        $role = (string) $role;
        if ($role === '' || $role === 'administrator') return false;
        return in_array($role, $valid_keys, true);
    }

    /** WordPress core and WooCommerce roles: never created, renamed or deleted from the portal. */
    const RESERVED_ROLE_KEYS = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager'];
    const MAX_ROLE_NAME_LENGTH = 60;
    const MAX_ROLE_KEY_LENGTH = 40;

    /** The display name an admin typed, tidied: trimmed, single-spaced, capped. */
    public static function normalize_role_name($name) {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));
        return function_exists('mb_substr')
            ? mb_substr($name, 0, self::MAX_ROLE_NAME_LENGTH)
            : substr($name, 0, self::MAX_ROLE_NAME_LENGTH);
    }

    /** Role key derived from a display name ("TMJ Patient" → "tmj_patient"); '' when nothing usable remains. */
    public static function role_key_from_name($name) {
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $name));
        $key = substr(trim($key, '_'), 0, self::MAX_ROLE_KEY_LENGTH);
        return rtrim($key, '_');
    }

    public static function is_reserved_role_key($key) {
        $key = (string) $key;
        return $key === '' || in_array($key, self::RESERVED_ROLE_KEYS, true);
    }

    /** Only roles the portal created, and only once nobody holds them. */
    public static function can_delete_role($key, array $owned_keys, $user_count) {
        if (!in_array((string) $key, $owned_keys, true)) {
            return ['ok' => false, 'error' => 'Only roles created here can be deleted.'];
        }
        $user_count = (int) $user_count;
        if ($user_count > 0) {
            $who = $user_count === 1 ? '1 user still has' : $user_count . ' users still have';
            return ['ok' => false, 'error' => $who . ' this role. Move them to another role first.'];
        }
        return ['ok' => true, 'error' => ''];
    }
}
