<?php

namespace Tests\Feature\Reports;

use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Reports\Column;
use App\Reports\Filter;
use App\Reports\ReportFilters;
use App\Reports\ReportRegistry;
use App\Services\Pdf\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportsCenterTest extends TestCase
{
    use RefreshDatabase;

    private function withRights(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_hub_lists_only_what_the_user_may_open(): void
    {
        $this->actingAs($this->withRights(['players' => ['view']]))
            ->get(route('reports.hub'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Index')
                ->where('categories', fn ($c) => collect($c)->pluck('key')->all() === ['players'])
                ->where('cards', fn ($cards) => collect($cards)->pluck('category')->unique()->values()->all() === ['players']
                    && collect($cards)->pluck('id')->contains('players.directory')));
    }

    #[Test]
    public function an_admin_sees_every_category(): void
    {
        $this->actingAs($this->admin())->get(route('reports.hub'))
            ->assertInertia(fn (Assert $page) => $page->where('categories', fn ($c) => collect($c)->pluck('key')->all()
                === ['players', 'finance', 'equipment', 'competitions', 'attendance']));
    }

    #[Test]
    public function a_report_needs_its_module_on_screen_and_on_download(): void
    {
        $user = $this->withRights(['players' => ['view']]);

        $this->actingAs($user)->get(route('reports.category', 'finance'))->assertForbidden();
        $this->actingAs($user)->get(route('reports.view', ['finance', 'summary']))->assertForbidden();
        $this->actingAs($user)->get(route('reports.download', ['finance', 'summary', 'format' => 'csv']))->assertForbidden();
        $this->actingAs($user)->get(route('reports.view', ['players', 'directory']))->assertOk();
    }

    #[Test]
    public function an_unknown_report_is_not_found(): void
    {
        $this->actingAs($this->admin())->get('/reports/players/nope')->assertNotFound();
        $this->actingAs($this->admin())->get('/reports/nope')->assertNotFound();
    }

    #[Test]
    public function the_old_season_summary_pdf_route_still_answers(): void
    {
        $this->mock(PdfService::class, fn (MockInterface $m) => $m->shouldReceive('stream')->once()
            ->andReturn(response('%PDF', 200, ['Content-Type' => 'application/pdf'])));

        $this->actingAs($this->admin())->get('/reports/financial')->assertOk();
    }

    #[Test]
    public function filters_are_validated_on_the_server(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('reports.view', ['players', 'directory', 'gender' => 'Robot']))->assertSessionHasErrors('gender');
        $this->actingAs($admin)->get(route('reports.view', ['players', 'directory', 'sort' => 'password']))->assertSessionHasErrors('sort');
        $this->actingAs($admin)->get(route('reports.view', ['players', 'directory', 'per_page' => 7]))->assertSessionHasErrors('per_page');
        $this->actingAs($admin)->get(route('reports.view', ['finance', 'summary', 'from' => '2026-05-01', 'to' => '2026-04-01']))->assertSessionHasErrors('to');
        $this->actingAs($admin)->get(route('reports.view', ['finance', 'summary', 'from' => '2026-02-31']))->assertSessionHasErrors('from');
    }

    #[Test]
    public function the_viewer_pages_and_sorts_rows(): void
    {
        foreach (['Cc', 'Aa', 'Bb'] as $i => $last) {
            Player::create(['membership_id' => '202600000'.$i, 'firstname' => 'X', 'lastname' => $last]);
        }

        $this->actingAs($this->admin())
            ->get(route('reports.view', ['players', 'directory', 'sort' => 'name', 'dir' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Reports/Show')
                ->where('rows.total', 3)
                ->where('rows.data.0.membership_id', '2026000000')
                ->where('filters.sort', 'name')
                ->where('filters.dir', 'desc'));
    }

    #[Test]
    public function downloads_come_as_spreadsheet_csv_or_pdf(): void
    {
        Player::create(['membership_id' => '2026000001', 'firstname' => 'Ali', 'lastname' => 'Ben']);
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('reports.download', ['players', 'directory']))
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $csv = $this->actingAs($admin)->get(route('reports.download', ['players', 'directory', 'format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString('2026000001', $csv->streamedContent());

        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $m) use ($seen) {
            $m->shouldReceive('stream')->once()->andReturnUsing(function (string $html) use ($seen) {
                $seen['html'] = $html;

                return response('%PDF', 200, ['Content-Type' => 'application/pdf']);
            });
        });
        $this->actingAs($admin)->get(route('reports.download', ['players', 'directory', 'format' => 'pdf', 'gender' => 'Male']))->assertOk();
        $this->assertStringContainsString('2026000001', $seen['html']);
        $this->assertStringContainsString('{PAGENO}', $seen['html']);
    }

    #[Test]
    public function every_report_renders_a_real_pdf_in_arabic(): void
    {
        Player::create(['membership_id' => '2026000001', 'firstname' => 'علي', 'lastname' => 'بن']);
        $admin = User::factory()->admin()->create(['preferred_lng' => 'ar']);

        foreach (app(ReportRegistry::class)->reports() as $report) {
            $response = $this->actingAs($admin)->get(route('reports.download', [$report->category(), $report->key(), 'format' => 'pdf']));
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent(), $report->id());
        }
    }

    #[Test]
    public function every_label_a_report_shows_exists_in_all_three_languages(): void
    {
        $this->actingAs($this->admin());
        $catalogs = collect(['ar', 'fr', 'en'])->mapWithKeys(fn ($l) => [$l => json_decode((string) file_get_contents(resource_path("js/i18n/{$l}.json")), true)]);

        foreach (app(ReportRegistry::class)->reports() as $report) {
            $filters = ReportFilters::fromRequest(Request::create('/'), $report);
            $keys = [
                $report->title(), $report->description(),
                ...array_map(fn (Column $c) => $c->label, $report->columns()),
                ...array_map(fn (Filter $f) => $f->label, $report->filters()),
                ...array_column($report->summary($filters), 'label'),
                ...array_column($report->charts($filters), 'title'),
                ...array_filter(array_column($report->charts($filters), 'unit'), fn ($u) => $u !== 'money'),
            ];
            foreach ($keys as $key) {
                foreach ($catalogs as $locale => $catalog) {
                    $this->assertArrayHasKey($key, $catalog, "{$report->id()}: \"{$key}\" missing in {$locale}");
                }
            }
        }
    }
}
