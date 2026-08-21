<?php
if (!defined('ABSPATH') && !defined('AFM_TEST')) {
    if (php_sapi_name() !== 'cli') { exit; }
}

class Anchor_FM_Watch_Math {

    const MAX_BEAT_SECONDS = 60; // clamp per-heartbeat watched-delta

    /** Positions below this are not worth remembering. */
    const RESUME_MIN_SECONDS = 10;

    /** This close to the end counts as finished — resuming here is worse than restarting. */
    const RESUME_END_PAD_SECONDS = 15;

    /** How long a saved position survives without being touched. */
    const RESUME_TTL_DAYS = 30;

    /** Upper bound on a stored playhead — 24h. Guards the INT(10) UNSIGNED
     *  column when duration is unknown and the per-duration clamp cannot fire. */
    const RESUME_MAX_SECONDS = 86400;

    /**
     * WordPress defines DAY_IN_SECONDS, but tests/run.php has no WP bootstrap.
     * A class constant keeps the math identical in both environments without
     * polluting the global namespace.
     */
    const SECONDS_PER_DAY = 86400;

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

    /**
     * The furthest position the viewer has ever reached.
     *
     * Kept as a factual record only. It deliberately does NOT drive the
     * watch percentage any more: dragging the scrubber to the end moves this
     * value without playing a frame, which is exactly the bug coverage
     * tracking exists to fix. Percent comes from Anchor_FM_Coverage.
     *
     * This is a high-water mark: it never goes backwards. A later discovery
     * of duration cannot retroactively shrink a position already recorded as fact.
     *
     * @param int $prev_furthest   previously recorded high-water mark
     * @param int $point_seconds   current playhead position
     * @param int $duration_seconds total video length, 0 when unknown
     * @return int                 maximum of $prev_furthest and the (clamped) point
     */
    public static function furthest_point($prev_furthest, $point_seconds, $duration_seconds) {
        $prev     = max(0, (int) $prev_furthest);
        $point    = max(0, (int) $point_seconds);
        $duration = max(0, (int) $duration_seconds);

        // Clamp the incoming point only. Clamping the result would let a
        // later, smaller, newly-known duration retroactively shrink a value
        // already recorded as fact — and this is a high-water mark, so it
        // must never go backwards.
        if ($duration > 0 && $point > $duration) {
            $point = $duration;
        }
        return max($prev, $point);
    }

    /**
     * The playhead position worth storing for resume, or 0 for "start over".
     *
     * Deliberately separate from furthest_seconds: resume answers "where did
     * you stop", furthest answers "how far did you ever get". Rewinding and
     * quitting must not read as completion in the watch report.
     *
     * @param int  $point_seconds    current playhead
     * @param int  $duration_seconds total length, 0 when unknown
     * @param bool $ended            player fired its ended event
     * @return int
     */
    public static function resume_point($point_seconds, $duration_seconds, $ended) {
        if ($ended) return 0;

        $point    = max(0, (int) $point_seconds);
        $duration = max(0, (int) $duration_seconds);

        if ($duration > 0 && $point > $duration) {
            $point = $duration;
        }
        // With duration unknown the clamp above cannot fire, so a client is
        // otherwise free to post an arbitrarily large point straight into an
        // INT(10) UNSIGNED column and make its own row fail under strict mode.
        // No legitimate playhead exceeds a day.
        $point = min($point, self::RESUME_MAX_SECONDS);
        if ($point < self::RESUME_MIN_SECONDS) return 0;

        // Unknown duration means the end-pad rule cannot be evaluated; keep
        // the position rather than silently discarding it.
        if ($duration > 0 && $point >= ($duration - self::RESUME_END_PAD_SECONDS)) {
            return 0;
        }
        return $point;
    }

    /**
     * Whether a stored position has aged out. Fails closed: anything we cannot
     * parse is treated as stale, so a bad timestamp can never pin a position
     * open forever.
     *
     * Exactly $ttl_days old is NOT stale; one second older is.
     *
     * @param string $last_viewed_at MySQL datetime in WordPress local time
     * @param int    $now_ts         timestamp in the SAME clock, i.e. current_time('timestamp')
     * @param int    $ttl_days
     * @return bool
     */
    public static function is_resume_stale($last_viewed_at, $now_ts, $ttl_days = self::RESUME_TTL_DAYS) {
        if (!is_string($last_viewed_at)) return true;
        $last_viewed_at = trim($last_viewed_at);
        if ($last_viewed_at === '' || strpos($last_viewed_at, '0000-00-00') === 0) return true;

        $ts = strtotime($last_viewed_at);
        if ($ts === false) return true;

        return ((int) $now_ts - $ts) > ((int) $ttl_days * self::SECONDS_PER_DAY);
    }
}
