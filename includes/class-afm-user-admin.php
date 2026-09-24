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
}
