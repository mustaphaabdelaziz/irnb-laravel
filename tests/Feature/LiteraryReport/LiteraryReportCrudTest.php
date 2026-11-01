<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LiteraryReportCrudTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function report(array $attributes = []): LiteraryReport
    {
        return LiteraryReport::create($attributes + [
            'title' => 'التقرير الأدبي 2025/26',
            'period_from' => '2025-09-01',
            'period_to' => '2026-08-31',
            'sections' => LiteraryReport::SECTIONS,
        ]);
    }

    #[Test]
    public function enabled_sections_keep_the_fixed_order_and_drop_unknown_keys(): void
    {
        $report = $this->report(['sections' => ['finance', 'bogus', 'cover', 'members']]);

        $this->assertSame(['cover', 'members', 'finance'], $report->fresh()->enabledSections());
        $this->assertSame('2025-09-01', $report->fresh()->toArray()['period_from']);
    }

    #[Test]
    public function system_roles_get_the_literary_reports_module_and_others_do_not(): void
    {
        $system = Role::factory()->create(['key' => 'sys', 'is_system' => true, 'permissions' => ['players' => ['view']]]);
        $custom = Role::factory()->create(['key' => 'custom', 'is_system' => false, 'permissions' => ['players' => ['view']]]);

        $migration = require database_path('migrations/2026_11_01_200001_create_literary_reports_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(['view', 'add', 'edit', 'delete'], $system->fresh()->permissions['literary_reports']);
        $this->assertArrayNotHasKey('literary_reports', $custom->fresh()->permissions);
        $this->assertSame(['literary_reports', 'view'], PermissionMap::resolve('literary-reports.pdf'));

        $this->assertContains('literary_reports', Role::MODULES);
        $this->assertContains('literary_reports', Role::MODULE_GROUPS['social']);
        $this->assertFalse(User::factory()->create(['role_id' => $custom->id])->hasPermission('literary_reports', 'view'));
    }
}
