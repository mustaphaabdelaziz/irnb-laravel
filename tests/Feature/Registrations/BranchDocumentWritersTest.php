<?php

namespace Tests\Feature\Registrations;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class BranchDocumentWritersTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    private DocumentType $medical;

    private User $admin;

    private Player $player;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
        $this->admin = User::factory()->admin()->create(['email_verified_at' => now()]);
        $this->medical = DocumentType::create(['code' => 'med', 'name' => 'Medical', 'validity' => 'none', 'is_required' => true, 'is_active' => true, 'copies' => 3]);
        $this->medical->branches()->sync([$this->foot->id]);
        $this->player = $this->bothActive();
    }

    private function registrationId(\App\Models\Branch $branch): int
    {
        return $this->player->registrations()->where('branch_id', $branch->id)->value('id');
    }

    private function receive(\App\Models\Branch $branch, array $extra = [])
    {
        return $this->actingAs($this->admin)->post(route('players.documents.store', $this->player), [
            'document_type_id' => $this->medical->id,
            'branch_registration_id' => $this->registrationId($branch),
            'received_at' => now()->toDateString(),
            ...$extra,
        ]);
    }

    #[Test]
    public function receiving_in_a_branch_that_does_not_require_the_type_is_refused(): void
    {
        $this->receive($this->swim, ['copies_received' => 3])->assertSessionHas('error', 'flash.document_not_required_in_branch');
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function exempting_in_a_branch_that_does_not_require_the_type_is_refused(): void
    {
        $this->actingAs($this->admin)->post(route('players.documents.exempt', $this->player), [
            'document_type_id' => $this->medical->id,
            'branch_registration_id' => $this->registrationId($this->swim),
            'reason' => 'Not needed',
        ])->assertSessionHas('error', 'flash.document_not_required_in_branch');
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function renewing_a_retired_record_is_refused(): void
    {
        $document = PlayerDocument::create(['player_id' => $this->player->id, 'branch_registration_id' => $this->registrationId($this->swim), 'document_type_id' => $this->medical->id, 'state' => 'received', 'received_at' => '2026-09-01', 'copies_received' => 3]);

        $this->actingAs($this->admin)->put(route('players.documents.update', [$this->player, $document]), [
            'received_at' => now()->toDateString(), 'copies_received' => 3,
        ])->assertSessionHas('error', 'flash.document_not_required_in_branch');
    }

    #[Test]
    public function the_copies_received_are_stored(): void
    {
        $this->receive($this->foot, ['copies_received' => 2])->assertSessionHas('success');

        $this->assertSame(2, PlayerDocument::firstOrFail()->copies_received);
    }

    #[Test]
    public function copies_default_to_the_full_number(): void
    {
        $this->receive($this->foot)->assertSessionHas('success');

        $this->assertSame(3, PlayerDocument::firstOrFail()->copies_received);
    }

    #[Test]
    public function copies_out_of_range_are_rejected(): void
    {
        $this->receive($this->foot, ['copies_received' => 0])->assertSessionHasErrors('copies_received');
        $this->receive($this->foot, ['copies_received' => 4])->assertSessionHasErrors('copies_received');
        $this->assertSame(0, PlayerDocument::count());
    }

    #[Test]
    public function renewing_can_raise_the_copies(): void
    {
        $this->receive($this->foot, ['copies_received' => 2]);
        $document = PlayerDocument::firstOrFail();

        $this->actingAs($this->admin)->put(route('players.documents.update', [$this->player, $document]), [
            'received_at' => now()->toDateString(), 'copies_received' => 3,
        ])->assertSessionHas('success');

        $this->assertSame(3, $document->fresh()->copies_received);
    }

    private function reportRows()
    {
        $report = app(\App\Reports\Players\PlayerDocuments::class);

        return $report->rows(\App\Reports\ReportFilters::fromRequest(\Illuminate\Http\Request::create('/'), $report));
    }

    #[Test]
    public function the_report_lists_a_type_only_where_its_branch_requires_it(): void
    {
        $mine = $this->reportRows()->where('player_id', $this->player->id)->where('document', 'Medical');

        $this->assertSame(['Football'], $mine->pluck('branch')->values()->all());
    }

    #[Test]
    public function the_report_shows_an_incomplete_record_with_its_copies(): void
    {
        $this->receive($this->foot, ['copies_received' => 2]);

        $row = $this->reportRows()->where('player_id', $this->player->id)->where('document', 'Medical')->first();

        $this->assertSame(\App\Services\Player\DocumentChecklist::INCOMPLETE, $row['state_code']);
        $this->assertStringContainsString('2 / 3', $row['state']);
    }

    #[Test]
    public function the_missing_type_options_follow_the_selected_branch(): void
    {
        $options = fn ($branch) => collect($this->actingAs($this->admin)
            ->get(route('players.index', ['branch_id' => [$branch->id]]))
            ->assertOk()->viewData('page')['props']['documentTypes']);

        $this->assertFalse($options($this->swim)->contains('id', $this->medical->id));
        $this->assertTrue($options($this->foot)->contains('id', $this->medical->id));
    }
}
