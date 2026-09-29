<?php

namespace App\Support;

use App\Models\WebsiteConfig;

/**
 * Attendance scoring settings, kept in WebsiteConfig.settings['attendance'].
 * Points and rules only change the score used for ranking and alerts; the
 * status counts shown in reports are always the raw marks.
 */
final class AttendanceSettings
{
    public const DEFAULTS = [
        'points' => [
            'present' => 1, 'late' => 0.75, 'left_early' => 0.75, 'not_training' => 0.5,
            'absent_excused' => 0, 'absent_unexcused' => -1,
        ],
        'rules' => ['lates_per_unexcused' => 3, 'late_minutes_as_absent' => 30],
        'alerts' => ['min_score_pct' => 60, 'unexcused_streak' => 3],
    ];

    public static function get(): array
    {
        $stored = (WebsiteConfig::singleton()->settings ?? [])['attendance'] ?? [];

        return array_replace_recursive(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function save(array $values): void
    {
        $config = WebsiteConfig::singleton();
        $settings = $config->settings ?? [];
        $settings['attendance'] = array_replace_recursive(self::get(), $values);
        $config->settings = $settings;
        $config->save();
    }
}
