<?php

namespace Tests\Feature;

use App\Enums\AcademicCertificate;
use App\Models\User;
use App\Models\WebsiteConfig;
use App\Support\CertificateThresholds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CertificateThresholdsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_suggest_by_scale(): void
    {
        $this->assertSame(AcademicCertificate::Excellence, CertificateThresholds::suggest(16, 20));
        $this->assertSame(AcademicCertificate::Congratulations, CertificateThresholds::suggest(15.5, 20));
        $this->assertSame(AcademicCertificate::Encouragement, CertificateThresholds::suggest(14, 20));
        $this->assertSame(AcademicCertificate::HonorRoll, CertificateThresholds::suggest(12, 20));
        $this->assertNull(CertificateThresholds::suggest(11.99, 20));
        $this->assertSame(AcademicCertificate::Congratulations, CertificateThresholds::suggest(7.5, 10));
        $this->assertNull(CertificateThresholds::suggest(5.5, 10));
    }

    #[Test]
    public function thresholds_are_saved_from_settings_and_used(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->put(route('settings.update'), [
            'settings' => ['academicCertificates' => [
                '20' => ['excellence' => 17, 'congratulations' => 15, 'encouragement' => 14, 'honor_roll' => 12],
                '10' => ['excellence' => 8, 'congratulations' => 7.5, 'encouragement' => 7, 'honor_roll' => 6],
            ]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(17.0, CertificateThresholds::all()['20']['excellence']);
        $this->assertSame(AcademicCertificate::Congratulations, CertificateThresholds::suggest(16, 20));
        $this->assertSame('DZD', WebsiteConfig::singleton()->settings['currency']); // other settings kept
    }

    #[Test]
    public function thresholds_must_fit_the_scale_and_descend(): void
    {
        $admin = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($admin)->put(route('settings.update'), [
            'settings' => ['academicCertificates' => [
                '20' => ['excellence' => 21, 'congratulations' => 15, 'encouragement' => 16, 'honor_roll' => 12],
                '10' => ['excellence' => 8, 'congratulations' => 7.5, 'encouragement' => 7, 'honor_roll' => 6],
            ]],
        ])->assertSessionHasErrors(['settings.academicCertificates.20.excellence', 'settings.academicCertificates.20.encouragement']);
    }
}
