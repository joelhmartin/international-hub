<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

/**
 * Read/write of per-user playback progress, shared by Vimeo videos and
 * uploaded video files.
 *
 * The table name is injected rather than resolved here, because table() is a
 * private static on the main plugin class. Permission checks stay in the AJAX
 * handlers, which is where the capability helpers live.
 */
class Anchor_FM_Media_Progress {

    const SOURCE_VIMEO = 'vimeo';
    const SOURCE_FILE  = 'file';

    public static function valid_source($source) {
        return in_array($source, [self::SOURCE_VIMEO, self::SOURCE_FILE], true);
    }

    /**
     * Fold one heartbeat into the user's row, creating it if needed.
     *
     * @param string $now MySQL datetime from current_time('mysql')
     * @return bool `false` only when the underlying `$wpdb->insert()` /
     *              `$wpdb->update()` call itself returned exactly `false`
     *              (a genuine query failure, e.g. a strict-mode out-of-range
     *              value). `true` otherwise — this includes the ordinary
     *              case where `$wpdb->update()` returns integer `0` because
     *              the WHERE matched a row but no column value actually
     *              changed (a heartbeat where the playhead hasn't moved).
     *              That `0` is ROUTINE SUCCESS, not failure, and must be
     *              compared with `=== false`, never a loose/falsy check —
     *              `0 == false` in PHP, so `!$result` would wrongly flag
     *              every no-op heartbeat as a failed save.
     */
    public static function record($table, $source, $item_id, $user_id, $point, $delta, $duration, $ended, $is_new_session, $now) {
        global $wpdb;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT furthest_seconds, total_seconds, sessions FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ), ARRAY_A);

        $merged = Anchor_FM_Watch_Math::apply_progress(
            $existing ?: ['furthest_seconds' => 0, 'total_seconds' => 0],
            $point, $delta, $duration
        );
        $resume = Anchor_FM_Watch_Math::resume_point($point, $duration, $ended);

        if ($existing) {
            $sessions = (int) $existing['sessions'] + ($is_new_session ? 1 : 0);
            $result = $wpdb->update(
                $table,
                [
                    'furthest_seconds' => $merged['furthest_seconds'],
                    'total_seconds'    => $merged['total_seconds'],
                    'resume_seconds'   => $resume,
                    'percent'          => $merged['percent'],
                    'sessions'         => $sessions,
                    'last_viewed_at'   => $now,
                ],
                ['source' => $source, 'video_id' => $item_id, 'user_id' => $user_id],
                ['%d','%d','%d','%d','%d','%s'],
                ['%s','%d','%d']
            );
        } else {
            $result = $wpdb->insert(
                $table,
                [
                    'source'           => $source,
                    'video_id'         => $item_id,
                    'user_id'          => $user_id,
                    'furthest_seconds' => $merged['furthest_seconds'],
                    'total_seconds'    => $merged['total_seconds'],
                    'resume_seconds'   => $resume,
                    'percent'          => $merged['percent'],
                    'sessions'         => 1,
                    'first_viewed_at'  => $now,
                    'last_viewed_at'   => $now,
                ],
                ['%s','%d','%d','%d','%d','%d','%d','%d','%s','%s']
            );
        }

        return $result !== false;
    }

    /**
     * The user's saved position, or 0 when there is none or it has aged out.
     *
     * The staleness check here — not the cron job — is what actually enforces
     * the 30-day rule. WP-Cron only fires on page loads, and a private portal
     * can sit idle for weeks.
     *
     * @param int $now_ts current_time('timestamp'), matching how rows are written
     */
    public static function read_resume($table, $source, $item_id, $user_id, $now_ts) {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT resume_seconds, last_viewed_at FROM {$table}
             WHERE source = %s AND video_id = %d AND user_id = %d",
            $source, $item_id, $user_id
        ));

        if (!$row) return 0;
        if (Anchor_FM_Watch_Math::is_resume_stale((string) $row->last_viewed_at, $now_ts)) return 0;

        return (int) $row->resume_seconds;
    }
}
