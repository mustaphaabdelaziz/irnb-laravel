<?php

namespace Tests\Unit;

use App\Enums\AttendanceStatus;
use App\Services\Attendance\AttendanceCode;
use App\Support\AttendanceSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AttendanceCodeTest extends TestCase
{
    private static function defaults(): AttendanceCode
    {
        return AttendanceCode::fromConfig(AttendanceSettings::DEFAULTS['codes']);
    }

    private static function configured(array $codes): AttendanceCode
    {
        $config = AttendanceSettings::DEFAULTS['codes'];
        foreach ($codes as $status => $code) {
            $config[$status]['code'] = $code;
        }

        return AttendanceCode::fromConfig($config);
    }

    public static function valid(): array
    {
        return [
            'empty is present' => ['', AttendanceStatus::Present, null],
            'null is present' => [null, AttendanceStatus::Present, null],
            'P' => ['p', AttendanceStatus::Present, null],
            'late' => ['R15', AttendanceStatus::Late, 15],
            'late spaced' => [' r 5 ', AttendanceStatus::Late, 5],
            'left early' => ['D10', AttendanceStatus::LeftEarly, 10],
            'late at the cap' => ['R600', AttendanceStatus::Late, 600],
            'not training' => ['B', AttendanceStatus::NotTraining, null],
            'excused' => ['ae', AttendanceStatus::AbsentExcused, null],
            'unexcused' => ['AN', AttendanceStatus::AbsentUnexcused, null],
            // Arabic-Indic and Persian digits, as typed on those keyboards, mean the same minutes.
            'arabic-indic digits' => ['R١٥', AttendanceStatus::Late, 15],
            'persian digits at the cap' => ['R۶۰۰', AttendanceStatus::Late, 600],
        ];
    }

    #[Test]
    #[DataProvider('valid')]
    public function it_parses_the_default_codes(?string $code, AttendanceStatus $status, ?int $minutes): void
    {
        $this->assertSame(['status' => $status, 'minutes' => $minutes], self::defaults()->parse($code));
    }

    #[Test]
    public function it_rejects_unknown_codes_and_late_without_minutes(): void
    {
        // R٦٠١ is 601 in Arabic-Indic digits: still over the 1-600 cap.
        foreach (['X', 'R', 'R0', 'D', 'R1000', 'R601', 'A', 'P5', 'R٦٠١'] as $code) {
            $this->assertNull(self::defaults()->parse($code), $code);
        }
    }

    #[Test]
    public function format_round_trips(): void
    {
        foreach (['P', 'R15', 'D10', 'B', 'AE', 'AN'] as $code) {
            $parsed = self::defaults()->parse($code);
            $this->assertSame($code, self::defaults()->format($parsed['status'], $parsed['minutes']));
        }
    }

    #[Test]
    public function configured_codes_replace_the_defaults_in_any_script(): void
    {
        $codes = self::configured([
            'present' => 'ح', 'late' => 'ت', 'left_early' => 'غم',
            'not_training' => 'م', 'absent_excused' => 'غع', 'absent_unexcused' => 'غ',
        ]);

        $this->assertSame(['status' => AttendanceStatus::Late, 'minutes' => 15], $codes->parse('ت15'));
        // Arabic-Indic digits with an Arabic code: ت١٥ means ت15.
        $this->assertSame(['status' => AttendanceStatus::Late, 'minutes' => 15], $codes->parse('ت١٥'));
        $this->assertSame(['status' => AttendanceStatus::LeftEarly, 'minutes' => 10], $codes->parse(' غم 10 '));
        $this->assertSame(['status' => AttendanceStatus::AbsentUnexcused, 'minutes' => null], $codes->parse('غ'));
        $this->assertSame(['status' => AttendanceStatus::AbsentExcused, 'minutes' => null], $codes->parse('غع'));
        $this->assertNull($codes->parse('P'));
        $this->assertNull($codes->parse('R15'));
        $this->assertSame('ت15', $codes->format(AttendanceStatus::Late, 15));
        $this->assertSame('غ', $codes->format(AttendanceStatus::AbsentUnexcused, null));
    }

    #[Test]
    public function codes_compare_ignoring_case(): void
    {
        $codes = self::configured(['present' => 'pr', 'late' => 'rt']);

        $this->assertSame(AttendanceStatus::Present, $codes->parse('Pr')['status']);
        $this->assertSame(['status' => AttendanceStatus::Late, 'minutes' => 5], $codes->parse('rt5'));
        $this->assertSame('PR', $codes->format(AttendanceStatus::Present, null));
    }
}
