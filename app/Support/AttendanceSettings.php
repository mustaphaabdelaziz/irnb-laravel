<?php

namespace App\Support;

use App\Enums\AttendanceStatus;
use App\Models\WebsiteConfig;

/**
 * Attendance settings, kept in WebsiteConfig.settings['attendance'].
 * Points and rules only change the score used for ranking and alerts; the
 * status counts shown in reports are always the raw marks. Codes are what is
 * written on paper and typed in the month grid; marks store the status, so
 * changing a code never rewrites history.
 */
final class AttendanceSettings
{
    /** Languages a status name can be given in. */
    public const LOCALES = ['ar', 'fr', 'en'];

    /** What the letter text can contain, each written {name}. */
    public const LETTER_PLACEHOLDERS = ['player', 'category', 'period', 'absences', 'lates', 'club'];

    public const DEFAULTS = [
        'points' => [
            'present' => 1, 'late' => 0.75, 'left_early' => 0.75, 'not_training' => 0.5,
            'absent_excused' => 0, 'absent_unexcused' => -1,
        ],
        'rules' => ['lates_per_unexcused' => 3, 'late_minutes_as_absent' => 30],
        'alerts' => ['min_score_pct' => 60, 'unexcused_streak' => 3],
        // A null name falls back to the built-in translation att.status.<status>.
        'codes' => [
            'present' => ['code' => 'P', 'color' => '#059669', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'late' => ['code' => 'R', 'color' => '#f59e0b', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'left_early' => ['code' => 'D', 'color' => '#f97316', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'not_training' => ['code' => 'B', 'color' => '#0284c7', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'absent_excused' => ['code' => 'AE', 'color' => '#64748b', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
            'absent_unexcused' => ['code' => 'AN', 'color' => '#e11d48', 'label' => ['ar' => null, 'fr' => null, 'en' => null]],
        ],
        // The parent letter, per language. A null subject or body falls back to
        // the built-in att.letter.default_subject / att.letter.default_body.
        'letter' => [
            'subject' => ['ar' => null, 'fr' => null, 'en' => null],
            'body' => ['ar' => null, 'fr' => null, 'en' => null],
        ],
    ];

    public static function get(): array
    {
        $stored = (WebsiteConfig::singleton()->settings ?? [])['attendance'] ?? [];

        return array_replace_recursive(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    /** @return array<string, array{code: string, color: string, label: array<string, ?string>}> */
    public static function codes(): array
    {
        return self::get()['codes'];
    }

    /**
     * Each status's name for server-rendered documents (PDF, spreadsheets):
     * the configured name in $locale (default: the app locale), else the
     * built-in `att.status.<status>` from the UI catalogs, so a document
     * reads exactly like the screen.
     *
     * @return array<string, string> status value => name, in the fixed status order
     */
    public static function labels(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $codes = self::codes();

        $labels = [];
        foreach (AttendanceStatus::values() as $status) {
            $labels[$status] = ($codes[$status]['label'][$locale] ?? null) ?: UiLang::get("att.status.{$status}", null, $locale);
        }

        return $labels;
    }

    /**
     * The parent letter's subject and body in $locale (default: the app
     * locale): the configured text, else the built-in text from the UI
     * catalogs, with every {placeholder} replaced by its value. Not escaped:
     * the letter view prints it with {{ }}.
     *
     * @param  array<string, string|int>  $values  placeholder name (without braces) => value
     * @return array{subject: string, body: string}
     */
    public static function letter(array $values, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $letter = self::get()['letter'];

        $replace = [];
        foreach (self::LETTER_PLACEHOLDERS as $name) {
            $replace['{'.$name.'}'] = (string) ($values[$name] ?? '');
        }
        $text = fn (string $part): string => strtr(
            ($letter[$part][$locale] ?? null) ?: UiLang::get("att.letter.default_{$part}", null, $locale),
            $replace,
        );

        return ['subject' => $text('subject'), 'body' => $text('body')];
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
