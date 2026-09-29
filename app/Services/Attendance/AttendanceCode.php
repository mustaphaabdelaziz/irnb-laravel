<?php

namespace App\Services\Attendance;

use App\Enums\AttendanceStatus;
use App\Support\AttendanceSettings;

/**
 * The short codes written on the paper sheet and typed into the month grid,
 * as configured in the attendance settings (default P, R<min>, D<min>, B, AE,
 * AN). Codes are letters in any script, compared upper-cased with all
 * whitespace removed; late and left-early codes take 1 to 600 minutes.
 * Minutes may be typed in Arabic-Indic (٠-٩) or Persian (۰-۹) digits, e.g.
 * ت١٥ means ت15; both are normalised to Latin digits before parsing. An
 * empty cell means present.
 */
final class AttendanceCode
{
    /** Arabic-Indic and Persian digits, normalised to Latin 0-9 before parsing. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** @param array<string, string> $codes status value => normalised code */
    private function __construct(private readonly array $codes) {}

    public static function fromSettings(): self
    {
        return self::fromConfig(AttendanceSettings::codes());
    }

    /** @param array<string, array{code: string}> $config the settings' `codes` block */
    public static function fromConfig(array $config): self
    {
        $codes = [];
        foreach (AttendanceStatus::cases() as $status) {
            $codes[$status->value] = self::normalise($config[$status->value]['code'] ?? '');
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

    /** @return array{status: AttendanceStatus, minutes: ?int}|null null when the code is not valid */
    public function parse(?string $code): ?array
    {
        $code = self::normalise($code);

        if ($code === '') {
            return ['status' => AttendanceStatus::Present, 'minutes' => null];
        }

        foreach (AttendanceStatus::cases() as $status) {
            if (! $status->takesMinutes() && $this->codes[$status->value] === $code) {
                return ['status' => $status, 'minutes' => null];
            }
        }

        if (preg_match('/^(\p{L}+)([0-9]{1,3})$/u', $code, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 600) {
            foreach ([AttendanceStatus::Late, AttendanceStatus::LeftEarly] as $status) {
                if ($this->codes[$status->value] === $m[1]) {
                    return ['status' => $status, 'minutes' => (int) $m[2]];
                }
            }
        }

        return null;
    }

    public function format(AttendanceStatus $status, ?int $minutes): string
    {
        return $this->codes[$status->value].($status->takesMinutes() ? (string) $minutes : '');
    }
}
