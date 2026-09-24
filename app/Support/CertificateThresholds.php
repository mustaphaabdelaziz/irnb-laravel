<?php

namespace App\Support;

use App\Enums\AcademicCertificate;
use App\Models\WebsiteConfig;

/** Minimum grade for each certificate, per grading scale, from club settings. */
final class CertificateThresholds
{
    public const DEFAULTS = [
        '20' => ['excellence' => 16.0, 'congratulations' => 15.0, 'encouragement' => 14.0, 'honor_roll' => 12.0],
        '10' => ['excellence' => 8.0, 'congratulations' => 7.5, 'encouragement' => 7.0, 'honor_roll' => 6.0],
    ];

    /** @return array<string, array<string, float>> */
    public static function all(): array
    {
        $saved = (WebsiteConfig::singleton()->settings ?? [])['academicCertificates'] ?? [];
        $out = [];

        foreach (self::DEFAULTS as $scale => $defaults) {
            foreach ($defaults as $certificate => $default) {
                $value = $saved[$scale][$certificate] ?? null;
                $out[$scale][$certificate] = is_numeric($value) ? (float) $value : $default;
            }
        }

        return $out;
    }

    /** The highest certificate the grade reaches on its scale, or null. */
    public static function suggest(float $gpa, int $scale): ?AcademicCertificate
    {
        $thresholds = self::all()[(string) $scale] ?? null;
        if ($thresholds === null) {
            return null;
        }

        foreach (AcademicCertificate::cases() as $certificate) { // highest first
            if ($gpa >= $thresholds[$certificate->value]) {
                return $certificate;
            }
        }

        return null;
    }
}
