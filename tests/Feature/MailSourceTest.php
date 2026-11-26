<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\PlayerEmergencyContact;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MailRegister;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MailSourceTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private const OCTOBER = ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-31'];

    private const SEPTEMBER = ['period' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-20 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function french(): User
    {
        return User::factory()->admin()->create(['preferred_lng' => 'fr']);
    }

    private function order(string $status = PurchaseOrder::SENT, int $number = 3): PurchaseOrder
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Test Sport Shop'], ['address' => '12 rue des Sports', 'rib' => '00100000000000001234']);

        return PurchaseOrder::create([
            'year' => 2026, 'number' => $number, 'status' => $status, 'order_date' => '2026-10-15', 'supplier_id' => $supplier->id,
        ]);
    }

    /** @return array<string, mixed> route parameters of a source, plus the PDF's query */
    private function source(string $type, int $id, array $query = []): array
    {
        return ['type' => $type, 'id' => $id, ...$query];
    }

    /**
     * Swaps mPDF for a spy, once per test: every stream() call's HTML lands in
     * the returned list, in order. (A controller that takes PdfService in its
     * constructor is kept by its route between two requests of one test, so a
     * second mock would never be seen.)
     */
    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->andReturnUsing(function (string $html) use ($seen) {
                $seen[] = $html;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function a_convocation_is_looked_up_then_registered_once(): void
    {
        $game = $this->game($this->team());
        $this->actingAs($this->userWith(['mail' => ['view', 'add'], 'games' => ['view']]));

        $this->getJson(route('mail.sources.show', $this->source('game', $game->id)))->assertOk()->assertExactJson(['entry' => null]);
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))
            ->assertOk()->assertJsonPath('entry.reference', '2026/01')->assertJsonPath('created', true);
        // A reprint reuses the same reference.
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))
            ->assertOk()->assertJsonPath('entry.reference', '2026/01')->assertJsonPath('created', false);
        $this->getJson(route('mail.sources.show', $this->source('game', $game->id)))->assertJsonPath('entry.reference', '2026/01');

        $entry = MailEntry::sole();
        $this->assertSame([MailEntry::OUT, MailEntry::SOURCE_GAME, $game->id, 'convocation'], [$entry->direction, $entry->source_type, $entry->source_id, $entry->source_key]);
        $this->assertSame('2026-10-20', $entry->entry_date->toDateString());
        // Addressed to the called-up players: the team's label, not the opponent.
        $this->assertSame($game->team->fresh()->labelWith(\App\Support\CategoryLabels::make()), $entry->correspondent);
        $this->assertStringContainsString(UiLang::get('comp.convocation.title', null, 'fr'), $entry->subject);
        $this->assertStringContainsString('Rivals FC', $entry->subject);
        $this->assertStringContainsString('17/10/2026', $entry->subject);
    }

    #[Test]
    public function a_parent_letter_is_one_entry_per_player_branch_and_period(): void
    {
        $player = $this->player($this->category('U15'));
        $this->actingAs($this->userWith(['mail' => ['view', 'add'], 'attendance' => ['view']]));

        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))
            ->assertOk()->assertJsonPath('entry.reference', '2026/01');
        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))
            ->assertJsonPath('created', false);
        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::SEPTEMBER)))
            ->assertJsonPath('entry.reference', '2026/02');

        $october = MailEntry::where('source_key', "player:{$player->id}|branch:0|2026-10-01|2026-10-31")->sole();
        $this->assertSame(MailEntry::SOURCE_ABSENCE_LETTER, $october->source_type);
        $this->assertSame($player->id, $october->source_id);
        // The letter's own recipient and subject (no emergency contact: "parent / guardian of").
        $this->assertSame('Parent / tuteur de '.$player->fullname, $october->correspondent);
        $this->assertSame('Assiduité de '.$player->fullname.' aux entraînements', $october->subject);
    }

    #[Test]
    public function the_first_emergency_contact_is_the_letter_s_correspondent(): void
    {
        $player = $this->player($this->category('U15'));
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Karim Test', 'relationship' => 'father']);
        PlayerEmergencyContact::create(['player_id' => $player->id, 'name' => 'Salma Test', 'relationship' => 'mother']);

        $this->actingAs($this->french())->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))->assertOk();

        $this->assertSame('Karim Test', MailEntry::sole()->correspondent);
    }

    #[Test]
    public function a_sent_purchase_order_registers_to_its_supplier_and_a_draft_cannot(): void
    {
        $order = $this->order();
        $this->actingAs($this->userWith(['mail' => ['view', 'add'], 'purchase_orders' => ['view']]));

        $this->postJson(route('mail.sources.store', $this->source('purchase-order', $order->id)))->assertOk()->assertJsonPath('entry.reference', '2026/01');

        $entry = MailEntry::sole();
        $this->assertSame('Test Sport Shop', $entry->correspondent);
        $this->assertSame('12 rue des Sports', $entry->address);
        $this->assertSame(UiLang::get('pur.title', null, 'fr').' 03/2026', $entry->subject);
        $this->assertSame('order', $entry->source_key);

        $draft = $this->order(PurchaseOrder::DRAFT, 4);
        $this->getJson(route('mail.sources.show', $this->source('purchase-order', $draft->id)))->assertStatus(422);
        $this->postJson(route('mail.sources.store', $this->source('purchase-order', $draft->id)))->assertStatus(422);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function registering_needs_the_mail_add_right_and_the_right_to_print_the_document(): void
    {
        $game = $this->game($this->team());
        // team() already made U15 (firstOrCreate); category() always creates.
        $player = $this->player($this->category('U13'));

        $this->actingAs($this->userWith(['mail' => ['view'], 'games' => ['view']]));
        $this->getJson(route('mail.sources.show', $this->source('game', $game->id)))->assertForbidden();
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))->assertForbidden();

        $this->actingAs($this->userWith(['mail' => ['view', 'add']]));
        $this->postJson(route('mail.sources.store', $this->source('game', $game->id)))->assertForbidden();
        $this->postJson(route('mail.sources.store', $this->source('absence-letter', $player->id, self::OCTOBER)))->assertForbidden();
        $this->postJson(route('mail.sources.store', $this->source('purchase-order', $this->order()->id)))->assertForbidden();

        $this->actingAs($this->french())->postJson('/mail/sources/invoice/1')->assertNotFound();
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function the_convocation_prints_its_reference_once_registered_and_printing_never_registers(): void
    {
        $game = $this->game($this->team());
        $admin = $this->french();

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('games.convocation', $game))->assertOk();
        $this->assertStringNotContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[0]);
        $this->assertSame(0, MailEntry::count());

        $entry = app(MailRegister::class)->register(MailEntry::SOURCE_GAME, $game->id, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $admin);
        $this->get(route('games.convocation', $game))->assertOk();
        $this->assertStringContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[1]);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[1]);

        // A cancelled entry no longer prints.
        app(MailRegister::class)->cancel($entry, $admin);
        $this->get(route('games.convocation', $game))->assertOk();
        $this->assertStringNotContainsString('2026/01', $seen[2]);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function the_parent_letter_prints_the_reference_of_its_own_period_only(): void
    {
        $player = $this->player($this->category('U15'));
        $admin = $this->french();
        app(MailRegister::class)->register(MailEntry::SOURCE_ABSENCE_LETTER, $player->id, "player:{$player->id}|branch:0|2026-10-01|2026-10-31", [
            'correspondent' => 'Parent', 'subject' => 'Assiduité',
        ], $admin);

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('attendance.players.letter', ['player' => $player] + self::OCTOBER))->assertOk();
        $this->assertStringContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[0]);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[0]);

        $this->get(route('attendance.players.letter', ['player' => $player] + self::SEPTEMBER))->assertOk();
        $this->assertStringNotContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[1]);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function the_purchase_order_prints_its_reference(): void
    {
        $order = $this->order();
        $admin = $this->french();
        app(MailRegister::class)->register(MailEntry::SOURCE_PURCHASE_ORDER, $order->id, 'order', ['correspondent' => 'Test Sport Shop', 'subject' => 'Bon'], $admin);

        $seen = $this->spyPdf();
        $this->actingAs($admin)->get(route('purchase-orders.print', $order))->assertOk();

        $this->assertStringContainsString(UiLang::get('mreg.pdf.ref', null, 'fr'), $seen[0]);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen[0]);
        $this->assertStringContainsString('<bdo dir="ltr">03/2026</bdo>', $seen[0]);
    }
}
