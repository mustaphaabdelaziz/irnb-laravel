<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\ActivityLog;
use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Support\PermissionMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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

    #[Test]
    public function the_index_lists_reports_and_needs_the_view_right(): void
    {
        $this->report();
        $this->actingAs($this->admin())->get('/literary-reports')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('LiteraryReports/Index')->has('reports', 1)->has('defaults.period_from'));

        $nobody = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => []])->id]);
        $this->actingAs($nobody)->get('/literary-reports')->assertForbidden();
    }

    #[Test]
    public function creating_a_report_enables_every_section_and_opens_the_editor(): void
    {
        $admin = $this->admin();
        $response = $this->actingAs($admin)->post('/literary-reports', [
            'title' => 'Rapport 2025/26', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
        ]);

        $report = LiteraryReport::sole();
        $response->assertRedirect(route('literary-reports.edit', $report));
        $this->assertSame(LiteraryReport::SECTIONS, $report->sections);
        $this->assertSame($admin->id, $report->created_by_user_id);
        $this->assertTrue(ActivityLog::where('action', ActivityAction::LITERARY_REPORT_CREATED)->where('subject_id', $report->id)->exists());
    }

    #[Test]
    public function the_period_end_cannot_precede_its_start_and_sections_must_be_known(): void
    {
        $this->actingAs($this->admin())->post('/literary-reports', [
            'title' => 'X', 'period_from' => '2026-08-31', 'period_to' => '2026-01-01',
        ])->assertSessionHasErrors('period_to');

        $report = $this->report();
        $this->actingAs($this->admin())->put("/literary-reports/{$report->id}", [
            'title' => 'X', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['cover', 'gossip'],
        ])->assertSessionHasErrors('sections.1');
    }

    #[Test]
    public function updating_saves_texts_and_section_choice(): void
    {
        $report = $this->report();
        $this->actingAs($this->admin())->put("/literary-reports/{$report->id}", [
            'title' => 'Final', 'period_from' => '2025-09-01', 'period_to' => '2026-06-30',
            'sections' => ['cover', 'activities'],
            'president_word' => "سطر أول\nسطر ثان", 'introduction' => null, 'conclusion' => 'Outlook',
        ])->assertRedirect()->assertSessionHas('success', 'flash.literary_report_updated');

        $fresh = $report->fresh();
        $this->assertSame(['cover', 'activities'], $fresh->sections);
        $this->assertSame("سطر أول\nسطر ثان", $fresh->president_word);
        $this->assertSame('2026-06-30', $fresh->toArray()['period_to']);
    }

    #[Test]
    public function the_editor_marks_sections_the_user_cannot_see(): void
    {
        $report = $this->report();
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => [
            'literary_reports' => ['view', 'edit'], 'players' => ['view'],
        ]])->id]);

        $this->actingAs($user)->get("/literary-reports/{$report->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('LiteraryReports/Edit')
                ->where('report.period_from', '2025-09-01')
                ->where('sections', fn ($sections) => collect($sections)->pluck('allowed', 'key')->all() === [
                    'cover' => true, 'intro' => true, 'members' => true, 'board' => false, 'attendance' => false,
                    'sports' => false, 'activities' => false, 'finance' => false, 'conclusion' => true,
                ]));
    }

    private function limitedEditor(): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['permissions' => [
            'literary_reports' => ['view', 'edit'], 'players' => ['view'],
        ]])->id]);
    }

    private function payload(array $extra = []): array
    {
        return $extra + ['title' => 'X', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31'];
    }

    #[Test]
    public function an_editor_without_a_sections_right_neither_removes_nor_adds_it(): void
    {
        $report = $this->report(['sections' => ['cover', 'members', 'finance', 'conclusion']]);

        $this->actingAs($this->limitedEditor())->put("/literary-reports/{$report->id}", $this->payload(['sections' => ['cover', 'members', 'board']]))
            ->assertRedirect();

        // finance (not allowed) kept, conclusion (allowed) removed, board (not allowed) not added; print order kept
        $this->assertSame(['cover', 'members', 'finance'], $report->fresh()->sections);
    }

    #[Test]
    public function a_view_only_user_cannot_open_the_editor_or_update(): void
    {
        $report = $this->report();
        $viewer = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['literary_reports' => ['view']]])->id]);

        $this->actingAs($viewer)->get("/literary-reports/{$report->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/literary-reports/{$report->id}", $this->payload(['title' => 'Hacked']))->assertForbidden();
        $this->assertNotSame('Hacked', $report->fresh()->title);
    }

    #[Test]
    public function a_one_day_period_is_accepted(): void
    {
        $this->actingAs($this->admin())->post('/literary-reports', $this->payload(['period_from' => '2026-01-01', 'period_to' => '2026-01-01']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('literary_reports', 1);
    }

    #[Test]
    public function deleting_removes_the_report(): void
    {
        $report = $this->report();
        $this->actingAs($this->admin())->delete("/literary-reports/{$report->id}")
            ->assertRedirect(route('literary-reports.index'));
        $this->assertDatabaseCount('literary_reports', 0);
    }

    #[Test]
    public function updating_and_deleting_are_recorded_in_the_activity_log(): void
    {
        $report = $this->report();
        $admin = $this->admin();

        $this->actingAs($admin)->put("/literary-reports/{$report->id}", [
            'title' => 'Renamed', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31',
        ])->assertRedirect();
        $this->actingAs($admin)->delete("/literary-reports/{$report->id}");

        foreach (['literary_report_updated', 'literary_report_deleted'] as $action) {
            $this->assertSame(1, ActivityLog::where('action', $action)
                ->where('subject_type', (new LiteraryReport)->getMorphClass())
                ->where('subject_id', $report->id)->count(), $action);
        }
    }

    #[Test]
    public function creation_always_enables_every_section(): void
    {
        $this->actingAs($this->admin())->post('/literary-reports', [
            'title' => 'T', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['cover'],
        ])->assertRedirect();

        $this->assertSame(LiteraryReport::SECTIONS, LiteraryReport::first()->sections);
    }
}
