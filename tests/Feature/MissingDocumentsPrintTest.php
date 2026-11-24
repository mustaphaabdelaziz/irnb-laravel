<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Support\UiLang;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The players list prints what each listed player still owes (and the Reports
 * center lists the same), with the player page's checklist rules.
 */
class MissingDocumentsPrintTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private DocumentType $birth;

    private DocumentType $medical;

    private DocumentType $photo;

    protected function setUp(): void
    {
        parent::setUp();

        DocumentType::query()->update(['is_active' => false]);
        $this->birth = DocumentType::create(['code' => 'birth_x', 'name' => 'Birth', 'validity' => 'none', 'is_required' => true, 'is_active' => true, 'sort_order' => 1]);
        $this->medical = DocumentType::create(['code' => 'med_x', 'name' => 'Medical', 'validity' => 'date', 'is_required' => true, 'is_active' => true, 'sort_order' => 2]);
        $this->photo = DocumentType::where('code', DocumentType::PHOTO)->first();
        $this->photo->update(['name' => 'Photo', 'name_ar' => null, 'name_fr' => null, 'name_en' => null, 'is_active' => true, 'is_required' => true, 'copies' => 2, 'sort_order' => 3]);
        DocumentType::create(['code' => 'opt_x', 'name' => 'Optional', 'validity' => 'none', 'is_required' => false, 'is_active' => true, 'sort_order' => 4]);
    }

    private function player(string $lastname, array $extra = []): Player
    {
        return Player::create($extra + [
            'membership_id' => '7'.str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            'firstname' => 'Ali', 'lastname' => $lastname,
        ]);
    }

    private function received(Player $player, DocumentType $type, array $extra = []): void
    {
        PlayerDocument::create($extra + ['player_id' => $player->id, 'document_type_id' => $type->id, 'state' => 'received']);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_sheet_names_each_missing_document_and_why(): void
    {
        $this->player('Amrani');
        $benali = $this->player('Benali');
        $this->received($benali, $this->birth);
        $this->received($benali, $this->medical, ['valid_until' => today()->subDay()->toDateString()]);
        $this->received($benali, $this->photo);
        $chikhi = $this->player('Chikhi');
        $this->received($chikhi, $this->birth);
        $this->received($chikhi, $this->medical, ['valid_until' => today()->addYear()->toDateString()]);
        $this->received($chikhi, $this->photo, ['copies_received' => 1]);
        // A profile picture settles the Photo.
        $dahmani = $this->player('Dahmani', ['picture_url' => '/media/players/d.jpg']);
        $this->received($dahmani, $this->birth);
        $this->received($dahmani, $this->medical, ['valid_until' => today()->addYear()->toDateString()]);

        $html = $this->capturePdfHtml();
        $this->actingAs($this->admin())->get(route('players.print-documents'))->assertOk();
        $html = $html();

        $this->assertStringContainsString(UiLang::get('missing_docs.title'), $html);
        $row = fn (string $name) => $this->rowOf($html, $name);
        $this->assertStringContainsString('Birth', $row('Amrani'));
        $this->assertStringContainsString('Medical', $row('Amrani'));
        $this->assertStringContainsString('Photo', $row('Amrani'));
        $this->assertStringContainsString('Medical ('.UiLang::get('doc_state_expired').')', $row('Benali'));
        $this->assertStringNotContainsString('Birth', $row('Benali'));
        $this->assertStringContainsString('Photo (1/2)', $row('Chikhi'));
        $this->assertStringContainsString(UiLang::get('doc_complete'), $row('Dahmani'));
        $this->assertStringNotContainsString('Optional', $html, 'an optional type is never owed');

        // 4 players · 1 complete · 3 incomplete · 25 %; Medical missed by 2, Photo by 2, Birth by 1.
        $this->assertMatchesRegularExpression('/class="n">4<.*class="n">1<.*class="n">3<.*25%/s', $html);
        $this->assertMatchesRegularExpression('/Birth: <span class="k">1<.*Medical: <span class="k">2<.*Photo: <span class="k">2</s', $html);
    }

    #[Test]
    public function each_branch_owes_its_own_ticked_types_and_the_branch_filter_narrows_them(): void
    {
        $foot = Branch::create(['name' => 'Foot', 'code' => 'FT']);
        $tennis = Branch::create(['name' => 'Tennis', 'code' => 'TN']);
        $foot->documentTypes()->sync([$this->medical->id]);
        $tennis->documentTypes()->sync([$this->birth->id]);

        $both = $this->player('Ezzine');
        $both->registrations()->create(['branch_id' => $foot->id, 'membership_id' => 'FT-2026-00001']);
        $both->registrations()->create(['branch_id' => $tennis->id, 'membership_id' => 'TN-2026-00001']);
        // Left the tennis section: only football still owes.
        $half = $this->player('Fares');
        $half->registrations()->create(['branch_id' => $foot->id, 'membership_id' => 'FT-2026-00002']);
        $half->registrations()->create(['branch_id' => $tennis->id, 'membership_id' => 'TN-2026-00002', 'category_id' => null, 'status_id' => Player::leftStatusId()]);

        $html = $this->capturePdfHtml();
        $this->actingAs($this->admin())->get(route('players.print-documents'))->assertOk();
        $this->assertStringContainsString('Foot: Medical', $this->rowOf($html(), 'Ezzine'));
        $this->assertStringContainsString('Tennis: Birth', $this->rowOf($html(), 'Ezzine'));
        $this->assertStringContainsString('Medical', $this->rowOf($html(), 'Fares'));
        $this->assertStringNotContainsString('Birth', $this->rowOf($html(), 'Fares'));
        $this->assertStringNotContainsString('Photo', $html(), 'neither branch ticks the Photo');

        $html = $this->capturePdfHtml();
        $this->get(route('players.print-documents', ['branch_id' => [$foot->id]]))->assertOk();
        $row = $this->rowOf($html(), 'Ezzine');
        $this->assertStringContainsString('Medical', $row);
        $this->assertStringNotContainsString('Birth', $row);
        $this->assertStringNotContainsString('Foot:', $row, 'one branch, no branch label');
        $this->assertStringContainsString('FT-2026-00001', $row, 'the branch ID');
    }

    #[Test]
    public function printing_needs_both_players_and_documents_view(): void
    {
        $this->capturePdfHtml();
        $as = fn (array $permissions) => User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(),
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);

        $this->actingAs($as(['players' => ['view']]))->get(route('players.print-documents'))->assertForbidden();
        $this->actingAs($as(['documents' => ['view']]))->get(route('players.print-documents'))->assertForbidden();
        $this->actingAs($as(['players' => ['view'], 'documents' => ['view']]))->get(route('players.print-documents'))->assertOk();
    }

    #[Test]
    public function the_report_lists_every_player_with_figures_and_filters(): void
    {
        $this->player('Amrani');
        $done = $this->player('Benali', ['picture_url' => '/media/players/b.jpg']);
        $this->received($done, $this->birth);
        $this->received($done, $this->medical, ['valid_until' => today()->addYear()->toDateString()]);
        $chikhi = $this->player('Chikhi', ['picture_url' => '/media/players/c.jpg']);
        $this->received($chikhi, $this->medical, ['valid_until' => today()->addYear()->toDateString()]);

        $props = $this->report();
        $rows = collect($props['rows']['data']);
        $this->assertCount(3, $rows);
        $amrani = $rows->first(fn (array $r) => str_contains($r['name'], 'Amrani'));
        $this->assertSame(3, $amrani['missing_count']);
        $this->assertStringContainsString('Birth', $amrani['missing']);
        $this->assertSame(UiLang::get('doc_complete'), $rows->first(fn (array $r) => str_contains($r['name'], 'Benali'))['missing']);
        $this->assertSame([3, 1, 2], array_slice(array_column($props['summary'], 'value'), 0, 3));
        $this->assertEqualsWithDelta(33.3, $props['summary'][3]['value'], 0.01);

        $this->assertSame(2, $this->report(['completeness' => 'incomplete'])['rows']['total']);
        $this->assertSame(1, $this->report(['completeness' => 'complete'])['rows']['total']);
        // Missing the Medical: Amrani only.
        $this->assertSame(1, $this->report(['document_type_id' => [$this->medical->id]])['rows']['total']);
    }

    /** @return array<string, mixed> */
    private function report(array $query = []): array
    {
        $props = [];
        $this->actingAs($this->admin())->get(route('reports.view', ['players', 'missing_documents', ...$query]))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** The table row that names this player. */
    private function rowOf(string $html, string $name): string
    {
        preg_match('/<tr>(?:(?!<\/tr>).)*'.preg_quote($name, '/').'(?:(?!<\/tr>).)*<\/tr>/s', $html, $match);
        $this->assertNotEmpty($match, "no row for {$name}");

        return $match[0];
    }

    /** Records the HTML the controller hands to mPDF instead of rendering it. */
    private function capturePdfHtml(): Closure
    {
        $captured = new stdClass;
        $captured->html = '';

        $fake = new class($captured) extends PdfService
        {
            public function __construct(private stdClass $captured) {}

            public function stream(string $html, string $filename, ?bool $rtl = null, bool $landscape = false): Response
            {
                $this->captured->html = $html;

                return response('', 200, ['Content-Type' => 'application/pdf']);
            }
        };

        $this->app->instance(PdfService::class, $fake);

        return fn () => $captured->html;
    }
}
