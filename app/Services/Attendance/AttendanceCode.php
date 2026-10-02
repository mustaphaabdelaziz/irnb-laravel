<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;

/**
 * The short codes written on the paper sheet and typed into the month grid,
 * as configured in the attendance settings (default P, R<min>, D<min>, B, AE,
 * AN) plus the owner's custom codes (AttendanceStatusCatalog). Codes are
 * letters in any script, compared upper-cased with all whitespace removed;
 * late and left-early codes take 1 to 600 minutes, other codes none.
 * Minutes may be typed in Arabic-Indic (٠-٩) or Persian (۰-۹) digits, e.g.
 * ت١٥ means ت15; both are normalised to Latin digits before parsing. An
 * empty cell means present. Statuses are status keys (strings).
 */
final class AttendanceCode
{
    /** Arabic-Indic and Persian digits, normalised to Latin 0-9 before parsing. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** @param array<string, string> $codes status key => normalised code */
    private function __construct(private readonly array $codes) {}

    /** Every status of the catalog, hidden custom codes included (an old mark must still parse). */
    public static function fromSettings(): self
    {
        return self::fromConfig(app(AttendanceStatusCatalog::class)->codes());
    }

    /** @param array<string, array{code: string}> $config status key => ['code' => …] (the settings' `codes` block, or the catalog's) */
    public static function fromConfig(array $config): self
    {
        $codes = [];
        foreach ($config as $status => $row) {
            $codes[(string) $status] = self::normalise($row['code'] ?? '');
        }

        return new self($codes);
    }

    /**
     * Upper-cased, with all whitespace removed and Arabic-Indic/Persian
     * digits mapped to Latin digits. Arabic letters have no case and pass
     * through.
     */
    public static function normalise(?string $code): string
    {
        $code = (string) preg_replace('/\s+/u', '', (string) $code);
        $code = strtr($code, self::DIGITS);

        return mb_strtoupper($code);
    }

    /** @return array{status: string, minutes: ?int}|null null when the code is not valid */
    public function parse(?string $code): ?array
    {
        $code = self::normalise($code);

        if ($code === '') {
            return ['status' => AttendanceStatus::Present->value, 'minutes' => null];
        }

        foreach ($this->codes as $status => $statusCode) {
            if ($statusCode !== '' && ! self::takesMinutes($status) && $statusCode === $code) {
                return ['status' => $status, 'minutes' => null];
            }
        }

        if (preg_match('/^(\p{L}+)([0-9]{1,3})$/u', $code, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 600) {
            foreach ([AttendanceStatus::Late->value, AttendanceStatus::LeftEarly->value] as $status) {
                if (($this->codes[$status] ?? null) === $m[1]) {
                    return ['status' => $status, 'minutes' => (int) $m[2]];
                }
            }
        }

        return null;
    }

    public function format(string $status, ?int $minutes): string
    {
        return ($this->codes[$status] ?? '').(self::takesMinutes($status) ? (string) $minutes : '');
    }

    private static function takesMinutes(string $status): bool
    {
        return AttendanceStatus::tryFrom($status)?->takesMinutes() ?? false;
    }
}
