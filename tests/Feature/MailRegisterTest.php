<?php

namespace Tests\Feature;

use App\Exceptions\MailEntryException;
use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Secretariat\MailRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailRegisterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function register(): MailRegister
    {
        return app(MailRegister::class);
    }

    private function outgoing(array $extra = [], ?UploadedFile $scan = null): MailEntry
    {
        return $this->register()->create(array_merge([
            'direction' => MailEntry::OUT, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue',
            'address' => '1 rue Test', 'subject' => 'Engagement saison', 'body' => "Monsieur,\nBonjour.", 'notes' => null,
        ], $extra), $this->admin, $scan);
    }

    private function legacy(): MailEntry
    {
        return MailEntry::create([
            'direction' => MailEntry::OUT, 'year' => 2019, 'status' => MailEntry::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '2019/04', 'legacy_source' => 'corresponds', 'correspondent' => 'Ancien destinataire',
        ]);
    }

    #[Test]
    public function created_entries_are_numbered_per_direction_and_year_of_their_date(): void
    {
        $this->assertSame('2026/01', $this->outgoing()->reference);
        $this->assertSame('2026/02', $this->outgoing()->reference);
        $this->assertSame('2025/01', $this->outgoing(['entry_date' => '2025-12-30'])->reference);

        app()->setLocale('fr');
        $in = $this->register()->create([
            'direction' => MailEntry::IN, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Direction',
            'subject' => 'Invitation', 'sender_reference' => 'DJS/45', 'received_date' => '2026-11-25',
            // Not an incoming field: dropped.
            'body' => 'Ignored',
        ], $this->admin);

        $this->assertSame('A-2026/01', $in->reference);
        $this->assertNull($in->body);
        $this->assertSame('DJS/45', $in->sender_reference);
        $this->assertSame('2026-11-25', $in->received_date->toDateString());
        $this->assertSame(MailEntry::ACTIVE, $in->status);
        $this->assertSame($this->admin->id, $in->created_by);
    }

    #[Test]
    public function an_outgoing_entry_drops_the_incoming_fields(): void
    {
        $entry = $this->outgoing(['sender_reference' => 'X/1', 'received_date' => '2026-11-20']);

        $this->assertNull($entry->sender_reference);
        $this->assertNull($entry->received_date);
        $this->assertSame("Monsieur,\nBonjour.", $entry->body);
    }

    #[Test]
    public function a_scan_goes_to_the_private_disk_and_a_new_one_replaces_it(): void
    {
        $entry = $this->outgoing([], UploadedFile::fake()->create('lettre.pdf', 50, 'application/pdf'));
        $first = $entry->scan_path;

        $this->assertTrue($entry->has_scan);
        $this->assertSame('lettre.pdf', $entry->scan_name);
        $this->assertStringStartsWith('mail/', $first);
        Storage::disk('local')->assertExists($first);

        $entry = $this->register()->update($entry, ['notes' => 'Scannée'], $this->admin, UploadedFile::fake()->create('signee.pdf', 50, 'application/pdf'));

        $this->assertSame('signee.pdf', $entry->scan_name);
        $this->assertSame('Scannée', $entry->notes);
        Storage::disk('local')->assertExists($entry->scan_path);
        Storage::disk('local')->assertMissing($first);
    }

    #[Test]
    public function a_refused_save_leaves_no_uploaded_file_behind(): void
    {
        $entry = $this->outgoing();
        // Someone else cancels it while this page still shows it active: the claim refuses.
        $this->register()->cancel($entry->fresh(), $this->admin);

        try {
            $this->register()->update($entry, ['notes' => 'x'], $this->admin, UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf'));
            $this->fail('A cancelled entry took a scan.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_locked', $e->flashKey);
        }

        $this->assertSame([], Storage::disk('local')->allFiles('mail'));
        $this->assertNull($entry->fresh()->notes);
    }

    #[Test]
    public function an_update_changes_only_what_the_entry_may_still_change(): void
    {
        $entry = $this->register()->update($this->outgoing(), [
            'correspondent' => 'Test Ligue Régionale', 'subject' => 'Engagement', 'body' => 'Nouveau texte',
            'entry_date' => '2026-11-20', 'direction' => MailEntry::IN,
        ], $this->admin);

        $this->assertSame('Test Ligue Régionale', $entry->correspondent);
        $this->assertSame('Nouveau texte', $entry->body);
        $this->assertSame('2026-11-20', $entry->entry_date->toDateString());
        $this->assertSame(MailEntry::OUT, $entry->direction);
        $this->assertSame('2026/01', $entry->reference);

        // Made from a print: correspondent, address, subject and notes only.
        $printed = $this->register()->register(MailEntry::SOURCE_GAME, 7, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);
        $printed = $this->register()->update($printed, [
            'correspondent' => 'Ligue Test', 'body' => 'Texte', 'entry_date' => '2026-11-01', 'notes' => 'Envoyée par mail',
        ], $this->admin);

        $this->assertSame('Ligue Test', $printed->correspondent);
        $this->assertNull($printed->body);
        $this->assertSame('2026-11-26', $printed->entry_date->toDateString());
        $this->assertSame('Envoyée par mail', $printed->notes);

        // Imported: notes only.
        $old = $this->register()->update($this->legacy(), ['correspondent' => 'Changé', 'notes' => 'Original classé'], $this->admin);

        $this->assertSame('Ancien destinataire', $old->correspondent);
        $this->assertSame('Original classé', $old->notes);
    }

    #[Test]
    public function the_date_stays_in_the_year_of_the_number(): void
    {
        $entry = $this->outgoing();

        try {
            $this->register()->update($entry, ['entry_date' => '2027-01-02'], $this->admin);
            $this->fail('The date left its number\'s year.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_year_locked', $e->flashKey);
        }
        $this->assertSame('2026-11-26', $entry->fresh()->entry_date->toDateString());
    }

    #[Test]
    public function a_cancelled_entry_keeps_its_number_and_is_read_only(): void
    {
        $entry = $this->register()->cancel($this->outgoing(), $this->admin);

        $this->assertSame(MailEntry::CANCELLED, $entry->status);
        $this->assertSame($this->admin->id, $entry->cancelled_by);
        $this->assertNotNull($entry->cancelled_at);
        $this->assertSame('2026/01', $entry->reference);
        $this->assertSame('2026/02', $this->outgoing()->reference);

        try {
            $this->register()->update($entry, ['notes' => 'x'], $this->admin);
            $this->fail('A cancelled entry was edited.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_locked', $e->flashKey);
        }
        try {
            $this->register()->cancel($entry, $this->admin);
            $this->fail('An entry was cancelled twice.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_cannot_cancel', $e->flashKey);
        }
    }

    #[Test]
    public function an_imported_entry_cannot_be_cancelled(): void
    {
        $old = $this->legacy();

        try {
            $this->register()->cancel($old, $this->admin);
            $this->fail('An imported entry was cancelled.');
        } catch (MailEntryException $e) {
            $this->assertSame('flash.mail_cannot_cancel', $e->flashKey);
        }
        $this->assertSame(MailEntry::ACTIVE, $old->fresh()->status);
    }

    #[Test]
    public function a_print_takes_one_outgoing_number_and_reprints_reuse_it(): void
    {
        $first = $this->register()->register(MailEntry::SOURCE_PURCHASE_ORDER, 3, 'order', [
            'correspondent' => 'Test Sport Shop', 'address' => '12 rue des Sports', 'subject' => 'Bon de commande 03/2026',
        ], $this->admin);
        $again = $this->register()->register(MailEntry::SOURCE_PURCHASE_ORDER, 3, 'order', ['correspondent' => 'Other'], $this->admin);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('2026/01', $first->reference);
        $this->assertSame(MailEntry::OUT, $first->direction);
        $this->assertSame('2026-11-26', $first->entry_date->toDateString());
        $this->assertSame('Test Sport Shop', $first->correspondent);
        $this->assertSame('12 rue des Sports', $first->address);
        $this->assertSame('Bon de commande 03/2026', $first->subject);
        $this->assertSame([MailEntry::SOURCE_PURCHASE_ORDER, 3, 'order'], [$first->source_type, $first->source_id, $first->source_key]);
        $this->assertSame(1, MailEntry::count());
        // Another document is another entry; the outgoing sequence is shared with typed letters.
        $this->assertSame('2026/02', $this->register()->register(MailEntry::SOURCE_PURCHASE_ORDER, 4, 'order', ['correspondent' => 'X', 'subject' => 'Y'], $this->admin)->reference);
        $this->assertSame('2026/03', $this->outgoing()->reference);
    }

    #[Test]
    public function find_only_looks_up_and_a_cancelled_entry_frees_its_document(): void
    {
        $this->assertNull($this->register()->find(MailEntry::SOURCE_GAME, 9, 'convocation'));
        $this->assertSame(0, MailEntry::count());

        $first = $this->register()->register(MailEntry::SOURCE_GAME, 9, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);
        $this->assertSame($first->id, $this->register()->find(MailEntry::SOURCE_GAME, 9, 'convocation')?->id);

        $this->register()->cancel($first, $this->admin);
        $this->assertNull($this->register()->find(MailEntry::SOURCE_GAME, 9, 'convocation'));

        $second = $this->register()->register(MailEntry::SOURCE_GAME, 9, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('2026/02', $second->reference);
        $this->assertSame(2, MailEntry::where('source_type', MailEntry::SOURCE_GAME)->where('source_id', 9)->count());
    }

    #[Test]
    public function blank_defaults_still_give_a_readable_entry(): void
    {
        $entry = $this->register()->register(MailEntry::SOURCE_GAME, 1, 'convocation', ['correspondent' => '  ', 'subject' => null], $this->admin);

        $this->assertSame('—', $entry->correspondent);
        $this->assertSame('—', $entry->subject);
        $this->assertNull($entry->address);
    }

    #[Test]
    public function the_activity_log_keeps_references_never_names_or_text(): void
    {
        $entry = $this->outgoing();
        $this->register()->update($entry, ['notes' => 'Texte libre'], $this->admin);
        $this->register()->cancel($entry, $this->admin);
        $this->register()->register(MailEntry::SOURCE_GAME, 9, 'convocation', ['correspondent' => 'Rivals FC', 'subject' => 'Convocation'], $this->admin);

        $created = ActivityLog::where('action', ActivityAction::MAIL_ENTRY_CREATED)->sole();
        $this->assertSame($entry->id, (int) $created->subject_id);
        $this->assertSame(['reference' => '2026/01', 'direction' => 'out'], $created->properties);
        $this->assertSame(['reference' => '2026/01', 'direction' => 'out'], ActivityLog::where('action', ActivityAction::MAIL_ENTRY_UPDATED)->sole()->properties);
        $this->assertSame(['reference' => '2026/01', 'direction' => 'out'], ActivityLog::where('action', ActivityAction::MAIL_ENTRY_CANCELLED)->sole()->properties);
        $this->assertSame(
            ['reference' => '2026/02', 'direction' => 'out', 'source_type' => 'game_convocation', 'source_id' => 9],
            ActivityLog::where('action', ActivityAction::MAIL_ENTRY_REGISTERED)->sole()->properties,
        );
        foreach (ActivityLog::all() as $log) {
            foreach (['Test Ligue', '1 rue Test', 'Engagement', 'Texte libre', 'Rivals FC', 'Convocation'] as $text) {
                $this->assertStringNotContainsString($text, (string) json_encode($log->properties, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
