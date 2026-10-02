<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Models\AttendanceCustomStatus;
use App\Support\AttendanceSettings;
use App\Support\UiLang;
use Illuminate\Support\Collection;

/**
 * Every attendance status in one place: the six built-in ones (their code,
 * colour and names from AttendanceSettings) followed by the owner's custom
 * codes (attendance_custom_statuses), in that order. A mark stores a status
 * key: a built-in status value, or a custom key (c_<id>).
 *
 * Each status has a behaviour, which is how its marks count in scores,
 * expected sessions, missed hours, streaks and alerts: a built-in status
 * behaves as itself; a custom one as present, absent_excused,
 * absent_unexcused, or not_counted (the session is left out for that player).
 * Every status is still counted and shown on its own in breakdowns.
 *
 * Read once per instance: the custom codes are one query; codes, colours
 * and names also read the settings, which behaviours never need.
 */
final class AttendanceStatusCatalog
{
    public const NOT_COUNTED = 'not_counted';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $entries = null;

    /** @var Collection<int, AttendanceCustomStatus>|null */
    private ?Collection $customs = null;

    /** @var array<string, array{behaviour: string, active: bool}>|null */
    private ?array $kinds = null;

    /**
     * Key => entry (key, code, color, label per locale, behaviour, custom,
     * active, id): built-in first, then custom by sort order and id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $entries = [];
        $codes = AttendanceSettings::codes();
        foreach (AttendanceStatus::values() as $status) {
            $entries[$status] = [
                'key' => $status,
                'code' => (string) ($codes[$status]['code'] ?? ''),
                'color' => (string) ($codes[$status]['color'] ?? '#64748b'),
                'label' => $codes[$status]['label'] ?? array_fill_keys(AttendanceSettings::LOCALES, null),
                'behaviour' => $status,
                'custom' => false,
                'active' => true,
                'id' => null,
            ];
        }

        foreach ($this->customs() as $custom) {
            $entries[$custom->key] = [
                'key' => $custom->key,
                'code' => $custom->code,
                'color' => $custom->color,
                'label' => self::customLabels($custom),
                'behaviour' => $custom->behaviour,
                'custom' => true,
                'active' => $custom->is_active,
                'id' => $custom->id,
            ];
        }

        return $this->entries = $entries;
    }

    /** @return list<string> every status key in order; $activeOnly drops hidden custom codes */
    public function keys(bool $activeOnly = false): array
    {
        return array_keys(array_filter($this->kinds(), fn (array $k) => ! $activeOnly || $k['active']));
    }

    /**
     * What a marking screen or paper sheet offers: every active status, plus
     * any hidden custom code still used by the marks it shows.
     *
     * @param  iterable<string>  $used  status keys of the marks on screen
     * @return list<string>
     */
    public function shown(iterable $used = []): array
    {
        $used = array_flip(array_map('strval', is_array($used) ? $used : iterator_to_array($used, false)));

        return array_keys(array_filter($this->kinds(), fn (array $k, string $key) => $k['active'] || isset($used[$key]), ARRAY_FILTER_USE_BOTH));
    }

    /** A built-in status or a custom code not hidden. */
    public function isActive(string $key): bool
    {
        return $this->kinds()[$key]['active'] ?? false;
    }

    public function has(string $key): bool
    {
        return isset($this->kinds()[$key]);
    }

    /**
     * The `attendanceCodes` prop and the PDFs' `codes`: per status its code,
     * colour, names (a custom name left empty in one language falls back to
     * another, then to the code), behaviour, and whether it is custom/active.
     *
     * @return array<string, array{code: string, color: string, label: array<string, ?string>, behaviour: string, custom: bool, active: bool}>
     */
    public function codes(): array
    {
        return array_map(fn (array $e) => [
            'code' => $e['code'],
            'color' => $e['color'],
            'label' => $e['label'],
            'behaviour' => $e['behaviour'],
            'custom' => $e['custom'],
            'active' => $e['active'],
        ], $this->entries());
    }

    /**
     * Each status's name for server-rendered documents (PDF, spreadsheets),
     * in $locale (default: the app locale): the configured name, else the
     * built-in `att.status.<status>` for a built-in status.
     *
     * @param  list<string>|null  $keys  only these statuses (default: all), in catalog order
     * @return array<string, string>
     */
    public function labels(?string $locale = null, ?array $keys = null): array
    {
        $locale ??= app()->getLocale();
        $labels = [];
        foreach ($this->entries() as $key => $entry) {
            if ($keys !== null && ! in_array($key, $keys, true)) {
                continue;
            }
            $labels[$key] = ($entry['label'][$locale] ?? null)
                ?: ($entry['custom'] ? $entry['code'] : UiLang::get("att.status.{$key}", null, $locale));
        }

        return $labels;
    }

    /** How a status's marks count; an unknown key counts as not_counted. */
    public function behaviour(string $key): string
    {
        return $this->kinds()[$key]['behaviour'] ?? self::NOT_COUNTED;
    }

    /**
     * Every status key that counts as one of $behaviours (a built-in status
     * counts as itself), e.g. ('absent_unexcused') → absent_unexcused and
     * every custom unexcused code.
     *
     * @return list<string>
     */
    public function keysBehavingAs(string ...$behaviours): array
    {
        return array_keys(array_filter($this->kinds(), fn (array $k) => in_array($k['behaviour'], $behaviours, true)));
    }

    /**
     * Raw marks per status → marks per built-in status as they count: a
     * custom code adds to the status it behaves as; not_counted (and unknown
     * keys) are dropped.
     *
     * @param  array<string, int>  $counts  status key => marks
     * @return array<string, int> the six built-in statuses, in order
     */
    public function fold(array $counts): array
    {
        $scored = array_fill_keys(AttendanceStatus::values(), 0);
        foreach ($counts as $key => $n) {
            $behaviour = $this->behaviour((string) $key);
            if (isset($scored[$behaviour])) {
                $scored[$behaviour] += (int) $n;
            }
        }

        return $scored;
    }

    /** Only late and left-early (built-in) marks carry minutes. */
    public function takesMinutes(string $key): bool
    {
        return AttendanceStatus::tryFrom($key)?->takesMinutes() ?? false;
    }

    public function takesReason(string $key): bool
    {
        return AttendanceStatus::tryFrom($key)?->takesReason() ?? false;
    }

    public function requiresReason(string $key): bool
    {
        return AttendanceStatus::tryFrom($key)?->requiresReason() ?? false;
    }

    /** @return Collection<int, AttendanceCustomStatus> the custom codes, by sort order then id (one query) */
    private function customs(): Collection
    {
        return $this->customs ??= AttendanceCustomStatus::orderBy('sort_order')->orderBy('id')->get();
    }

    /** @return array<string, array{behaviour: string, active: bool}> every status key, in order, with how it counts */
    private function kinds(): array
    {
        if ($this->kinds === null) {
            $this->kinds = [];
            foreach (AttendanceStatus::values() as $status) {
                $this->kinds[$status] = ['behaviour' => $status, 'active' => true];
            }
            foreach ($this->customs() as $custom) {
                $this->kinds[$custom->key] = ['behaviour' => $custom->behaviour, 'active' => $custom->is_active];
            }
        }

        return $this->kinds;
    }

    /** @return array<string, string> per locale: its own name, else the first other name given, else the code */
    private static function customLabels(AttendanceCustomStatus $custom): array
    {
        $given = [];
        foreach (AttendanceSettings::LOCALES as $locale) {
            $given[$locale] = $custom->{'label_'.$locale} ?: null;
        }
        $fallback = collect($given)->filter()->first() ?? $custom->code;

        return array_map(fn (?string $label) => $label ?? $fallback, $given);
    }
}
