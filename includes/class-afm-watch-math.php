<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

class Anchor_FM_Watch_Math {

    const MAX_BEAT_SECONDS = 60; // clamp per-heartbeat watched-delta

    /**
     * Fold one progress heartbeat into an existing view record.
     *
     * @param array $existing ['furthest_seconds'=>int,'total_seconds'=>int]
     * @param int   $point_seconds    current playhead position
     * @param int   $delta_seconds    seconds watched since last beat (client-reported)
     * @param int   $duration_seconds total video length
     * @return array ['furthest_seconds'=>int,'total_seconds'=>int,'percent'=>int]
     */
    public static function apply_progress($existing, $point_seconds, $delta_seconds, $duration_seconds) {
        $prev_furthest = max(0, (int) ($existing['furthest_seconds'] ?? 0));
        $prev_total    = max(0, (int) ($existing['total_seconds'] ?? 0));
        $point         = max(0, (int) $point_seconds);
        $delta         = max(0, (int) $delta_seconds);
        $duration      = max(0, (int) $duration_seconds);

        $delta = min($delta, self::MAX_BEAT_SECONDS);

        $furthest = max($prev_furthest, $point);
        if ($duration > 0) {
            $furthest = min($furthest, $duration);
        }

        $total = $prev_total + $delta;
        if ($duration > 0) {
            $total = min($total, $duration);
        }

        $percent = 0;
        if ($duration > 0) {
            $percent = (int) floor(($furthest / $duration) * 100);
            if ($percent > 100) $percent = 100;
            if ($percent < 0) $percent = 0;
        }

        return [
            'furthest_seconds' => $furthest,
            'total_seconds'    => $total,
            'percent'          => $percent,
        ];
    }
}
