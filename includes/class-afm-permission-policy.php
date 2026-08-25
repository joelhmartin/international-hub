<?php
if (!defined('ABSPATH') && PHP_SAPI !== 'cli') exit;

class Anchor_FM_Permission_Policy {

    public static function empty_policy() {
        return [
            'operator' => 'any',
            'rules' => [],
        ];
    }

    public static function normalize($policy, array $valid_roles = []) {
        if (is_string($policy)) {
            $decoded = json_decode($policy, true);
            $policy = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($policy)) {
            $policy = [];
        }

        $valid_map = [];
        foreach ($valid_roles as $role) {
            $key = self::sanitize_key($role);
            if ($key !== '') {
                $valid_map[$key] = true;
            }
        }

        $normalized = [
            'operator' => self::normalize_operator(isset($policy['operator']) ? $policy['operator'] : 'any'),
            'rules' => [],
        ];

        $rules = isset($policy['rules']) && is_array($policy['rules']) ? $policy['rules'] : [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;

            $conditions = [];
            $raw_conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
            foreach ($raw_conditions as $condition) {
                $condition = self::normalize_condition($condition, $valid_map);
                if ($condition) {
                    $conditions[] = $condition;
                }
            }

            if (!$conditions) continue;

            $normalized['rules'][] = [
                'operator' => self::normalize_operator(isset($rule['operator']) ? $rule['operator'] : 'all'),
                'conditions' => $conditions,
            ];
        }

        return $normalized;
    }

    public static function evaluate($policy, array $context, $now = null) {
        $policy = self::normalize($policy);
        if (empty($policy['rules'])) {
            return false;
        }

        $matches = [];
        foreach ($policy['rules'] as $rule) {
            $matches[] = self::evaluate_rule($rule, $context, $now);
        }

        if ($policy['operator'] === 'all') {
            return !in_array(false, $matches, true);
        }

        return in_array(true, $matches, true);
    }

    public static function rule_count($policy) {
        $policy = self::normalize($policy);
        return count($policy['rules']);
    }

    private static function evaluate_rule(array $rule, array $context, $now = null) {
        $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
        if (!$conditions) {
            return false;
        }

        $matches = [];
        foreach ($conditions as $condition) {
            $matches[] = self::evaluate_condition($condition, $context, $now);
        }

        $operator = self::normalize_operator(isset($rule['operator']) ? $rule['operator'] : 'all');
        if ($operator === 'any') {
            return in_array(true, $matches, true);
        }

        return !in_array(false, $matches, true);
    }

    private static function evaluate_condition(array $condition, array $context, $now = null) {
        $type = isset($condition['type']) ? (string) $condition['type'] : '';
        if ($type === 'role') {
            $role = isset($condition['role']) ? self::sanitize_key($condition['role']) : '';
            $roles = isset($context['roles']) && is_array($context['roles']) ? $context['roles'] : [];
            $roles = array_map([__CLASS__, 'sanitize_key'], $roles);
            return $role !== '' && in_array($role, $roles, true);
        }

        if ($type === 'user') {
            $user_id = isset($condition['userId']) ? (int) $condition['userId'] : 0;
            $context_user_id = isset($context['userId']) ? (int) $context['userId'] : 0;
            return $user_id > 0 && $user_id === $context_user_id;
        }

        if ($type === 'date') {
            $today = self::normalize_date($now ?: date('Y-m-d'));
            if ($today === '') return false;
            $start = isset($condition['start']) ? self::normalize_date($condition['start']) : '';
            $end = isset($condition['end']) ? self::normalize_date($condition['end']) : '';
            if ($start !== '' && strcmp($today, $start) < 0) return false;
            if ($end !== '' && strcmp($today, $end) > 0) return false;
            return $start !== '' || $end !== '';
        }

        return false;
    }

    private static function normalize_condition($condition, array $valid_map) {
        if (!is_array($condition)) return null;
        $type = isset($condition['type']) ? strtolower(trim((string) $condition['type'])) : '';

        if ($type === 'role') {
            $role = isset($condition['role']) ? self::sanitize_key($condition['role']) : '';
            if ($role === '') return null;
            if ($valid_map && empty($valid_map[$role])) return null;
            if ($role === 'administrator') return null;
            return ['type' => 'role', 'role' => $role];
        }

        if ($type === 'user') {
            $user_id = isset($condition['userId']) ? (int) $condition['userId'] : 0;
            if ($user_id <= 0) return null;
            return ['type' => 'user', 'userId' => (string) $user_id];
        }

        if ($type === 'date') {
            $start = isset($condition['start']) ? self::normalize_date($condition['start']) : '';
            $end = isset($condition['end']) ? self::normalize_date($condition['end']) : '';
            if ($start === '' && $end === '') return null;
            if ($start !== '' && $end !== '' && strcmp($start, $end) > 0) {
                $tmp = $start;
                $start = $end;
                $end = $tmp;
            }
            return ['type' => 'date', 'start' => $start, 'end' => $end];
        }

        return null;
    }

    private static function normalize_operator($operator) {
        $operator = strtolower((string) $operator);
        return $operator === 'all' ? 'all' : 'any';
    }

    private static function normalize_date($date) {
        $date = trim((string) $date);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
            return $m[1];
        }
        return '';
    }

    private static function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
