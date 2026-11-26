<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivitySubjectLink;
use App\Services\Secretariat\MailRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MailEntryHttpTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-26 10:00:00');
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

    private function payload(array $extra = []): array
    {
        return array_merge([
            'direction' => 'out', 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue', 'address' => '1 rue Test',
            'subject' => 'Engagement saison', 'body' => "Monsieur,\nBonjour.", 'notes' => '',
        ], $extra);
    }

    private function entry(array $extra = [], ?UploadedFile $scan = null): MailEntry
    {
        return app(MailRegister::class)->create($this->payload($extra), User::factory()->admin()->create(), $scan);
    }

    #[Test]
    public function an_editor_registers_a_letter_with_its_scan_and_lands_on_it(): void
    {
        $user = $this->userWith(['mail' => ['view', 'add']]);

        $response = $this->actingAs($user)->post(route('mail.store'), $this->payload([
            'scan' => UploadedFile::fake()->create('lettre.pdf', 80, 'application/pdf'),
        ]));

        $entry = MailEntry::sole();
        $response->assertRedirect(route('mail.show', $entry))->assertSessionHas('success', 'flash.mail_created');
        $this->assertSame('2026/01', $entry->reference);
        $this->assertSame("Monsieur,\nBonjour.", $entry->body);
        $this->assertNull($entry->notes);
        $this->assertSame('lettre.pdf', $entry->scan_name);
        Storage::disk('local')->assertExists($entry->scan_path);
    }

    #[Test]
    public function the_form_is_validated(): void
    {
        $user = $this->userWith(['mail' => ['view', 'add']]);

        $this->actingAs($user)->post(route('mail.store'), $this->payload([
            'direction' => 'sideways', 'correspondent' => '', 'subject' => '', 'entry_date' => '2026-12-01',
            'scan' => UploadedFile::fake()->create('setup.exe', 10, 'application/x-msdownload'),
        ]))->assertSessionHasErrors(['direction', 'correspondent', 'subject', 'entry_date', 'scan']);

        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function each_action_needs_its_right(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->userWith(['mail' => ['view']]));
        $this->get(route('mail.index'))->assertOk();
        $this->get(route('mail.show', $entry))->assertOk();
        $this->get(route('mail.create'))->assertForbidden();
        $this->post(route('mail.store'), $this->payload())->assertForbidden();
        $this->get(route('mail.edit', $entry))->assertForbidden();
        $this->put(route('mail.update', $entry), $this->payload())->assertForbidden();
        $this->post(route('mail.cancel', $entry))->assertForbidden();

        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('mail.index'))->assertForbidden();
        // Entries are never deleted: there is no destroy route.
        $this->assertFalse(Route::has('mail.destroy'));
    }

    #[Test]
    public function cancelling_needs_the_delete_right_and_keeps_the_number(): void
    {
        $entry = $this->entry();

        $this->actingAs($this->userWith(['mail' => ['view', 'edit']]))->post(route('mail.cancel', $entry))->assertForbidden();

        $this->actingAs($this->userWith(['mail' => ['view', 'delete']]))->from(route('mail.show', $entry))
            ->post(route('mail.cancel', $entry))
            ->assertRedirect(route('mail.show', $entry))->assertSessionHas('success', 'flash.mail_cancelled');

        $this->assertSame(MailEntry::CANCELLED, $entry->fresh()->status);
        $this->assertSame('2026/01', $entry->fresh()->reference);
        // A second cancel is a refusal shown as a flash error.
        $this->post(route('mail.cancel', $entry))->assertSessionHas('error', 'flash.mail_cannot_cancel');
    }

    #[Test]
    public function an_update_keeps_direction_and_number_and_replaces_the_scan(): void
    {
        $entry = $this->entry([], UploadedFile::fake()->create('brouillon.pdf', 80, 'application/pdf'));
        $first = $entry->scan_path;

        $this->actingAs($this->userWith(['mail' => ['view', 'edit']]))->post(route('mail.update', $entry), $this->payload([
            '_method' => 'put', 'direction' => 'in', 'subject' => 'Engagement corrigé',
            'scan' => UploadedFile::fake()->create('signee.pdf', 80, 'application/pdf'),
        ]))->assertRedirect(route('mail.show', $entry))->assertSessionHas('success', 'flash.mail_updated');

        $entry->refresh();
        $this->assertSame('Engagement corrigé', $entry->subject);
        $this->assertSame(MailEntry::OUT, $entry->direction);
        $this->assertSame('2026/01', $entry->reference);
        $this->assertSame('signee.pdf', $entry->scan_name);
        Storage::disk('local')->assertMissing($first);
    }

    #[Test]
    public function an_imported_entry_only_takes_notes_and_a_scan(): void
    {
        $old = MailEntry::create([
            'direction' => 'out', 'year' => 2019, 'status' => 'active',
            'is_legacy' => true, 'legacy_reference' => '2019/04', 'legacy_source' => 'corresponds', 'correspondent' => 'Ancien',
        ]);

        // The required fields of a new entry are not asked of it.
        $this->actingAs($this->userWith(['mail' => ['view', 'edit']]))->post(route('mail.update', $old), [
            '_method' => 'put', 'correspondent' => '', 'notes' => 'Original aux archives',
            'scan' => UploadedFile::fake()->create('scan.jpg', 30, 'image/jpeg'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('mail.show', $old));

        $old->refresh();
        $this->assertSame('Ancien', $old->correspondent);
        $this->assertSame('Original aux archives', $old->notes);
        $this->assertTrue($old->has_scan);
    }

    #[Test]
    public function a_cancelled_entry_has_no_edit_page(): void
    {
        $entry = $this->entry();
        app(MailRegister::class)->cancel($entry, User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.edit', $entry))
            ->assertRedirect(route('mail.show', $entry))->assertSessionHas('error', 'flash.mail_locked');
    }

    #[Test]
    public function the_edit_page_lists_what_the_entry_may_still_change(): void
    {
        $entry = app(MailRegister::class)->register(MailEntry::SOURCE_PURCHASE_ORDER, 1, 'order', [
            'correspondent' => 'Test Sport Shop', 'subject' => 'Bon de commande',
        ], User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.edit', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.id', $entry->id)
                ->where('editable', ['correspondent', 'address', 'subject', 'notes'])
                ->has('correspondents.out', 1));
    }

    #[Test]
    public function the_scan_is_served_behind_the_view_right_only(): void
    {
        $entry = $this->entry([], UploadedFile::fake()->create('lettre.pdf', 80, 'application/pdf'));

        $this->get(route('mail.scan', $entry))->assertRedirect(route('login'));
        $this->actingAs($this->userWith(['players' => ['view']]))->get(route('mail.scan', $entry))->assertForbidden();

        $response = $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('mail.scan', $entry))->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));

        $this->get(route('mail.scan', $this->entry()))->assertNotFound();
    }

    #[Test]
    public function the_register_lists_one_direction_filters_by_year_and_finds_a_reference(): void
    {
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $register = app(MailRegister::class);
        $register->create($this->payload(['correspondent' => 'Test Ligue']), $admin);                              // 2026/01
        $register->create($this->payload(['correspondent' => 'Test Mairie', 'subject' => 'Salle']), $admin);       // 2026/02
        $register->create($this->payload(['entry_date' => '2025-06-01', 'correspondent' => 'Test Wilaya']), $admin); // 2025/01
        $register->create(['direction' => 'in', 'entry_date' => '2026-11-26', 'correspondent' => 'Test Direction', 'subject' => 'Invitation'], $admin);

        $this->actingAs($admin)->get(route('mail.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.direction', 'out')
                ->has('entries.data', 3)
                ->where('entries.data.0.reference', '2026/02')
                ->where('years', [2026, 2025]));
        $this->get(route('mail.index', ['direction' => 'in']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.reference', 'A-2026/01'));
        $this->get(route('mail.index', ['year' => 2025]))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.correspondent', 'Test Wilaya'));
        $this->get(route('mail.index', ['q' => 'Mairie']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.reference', '2026/02'));
        // "2026/1" is entry 1 of 2026; the incoming mark may be typed too.
        $this->get(route('mail.index', ['q' => '2026/1']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1)->where('entries.data.0.correspondent', 'Test Ligue'));
        $this->get(route('mail.index', ['direction' => 'in', 'q' => 'A-2026/01']))
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    #[Test]
    public function the_form_suggests_earlier_correspondents_same_direction_first(): void
    {
        $admin = User::factory()->admin()->create();
        $register = app(MailRegister::class);
        $register->create($this->payload(['correspondent' => 'Zeta Ligue']), $admin);
        $register->create($this->payload(['correspondent' => 'Zeta Ligue']), $admin);
        $register->create(['direction' => 'in', 'entry_date' => '2026-11-26', 'correspondent' => 'Alpha Mairie', 'subject' => 'Invitation'], $admin);

        $this->actingAs($admin)->get(route('mail.create', ['direction' => 'in']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('direction', 'in')
                ->where('correspondents.out', ['Zeta Ligue', 'Alpha Mairie'])
                ->where('correspondents.in', ['Alpha Mairie', 'Zeta Ligue']));
    }

    #[Test]
    public function the_entry_page_names_and_links_its_document_only_for_who_may_view_it(): void
    {
        $game = $this->game($this->team());
        $entry = app(MailRegister::class)->register(MailEntry::SOURCE_GAME, $game->id, 'convocation', [
            'correspondent' => 'Rivals FC', 'subject' => 'Convocation',
        ], User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entry.reference', '2026/01')
                ->where('source.type', 'game_convocation')
                ->where('source.label', 'Rivals FC · 17/10/2026')
                ->where('source.url', route('games.show', $game))
                ->where('can.edit', true)->where('can.cancel', true)->where('can.print', false));

        $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.label', '#'.$game->id)->where('source.url', null)
                ->where('can.edit', false)->where('can.cancel', false));
        $this->get(route('mail.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.source_type', 'game_convocation')
                ->where('entries.data.0.source_url', null));
    }

    #[Test]
    public function the_activity_pages_link_an_entry_for_mail_viewers_only(): void
    {
        $entry = $this->entry();
        $log = ActivityLog::where('action', ActivityAction::MAIL_ENTRY_CREATED)->sole();

        $this->assertSame(route('mail.show', $entry), ActivitySubjectLink::for($log, User::factory()->admin()->create())['url']);

        $neutral = ActivitySubjectLink::for($log, $this->userWith(['players' => ['view']]));
        $this->assertNull($neutral['url']);
        $this->assertTrue($neutral['neutral']);
    }
}
