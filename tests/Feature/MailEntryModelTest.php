<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Services\Documents\YearlySequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailEntryModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function entry(array $attributes = []): MailEntry
    {
        return MailEntry::create(array_merge([
            'direction' => MailEntry::OUT, 'year' => 2026, 'number' => 1, 'status' => MailEntry::ACTIVE,
            'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue', 'subject' => 'Engagement',
        ], $attributes));
    }

    #[Test]
    public function the_reference_is_year_slash_number_with_the_incoming_mark_or_the_old_text(): void
    {
        $this->assertSame('2026/03', $this->entry(['number' => 3])->reference);
        $this->assertSame('2026/114', $this->entry(['number' => 114])->reference);

        $incoming = $this->entry(['direction' => MailEntry::IN, 'number' => 1]);
        app()->setLocale('fr');
        $this->assertSame('A-2026/01', $incoming->reference);
        app()->setLocale('en');
        $this->assertSame('IN-2026/01', $incoming->reference);
        app()->setLocale('ar');
        $this->assertSame('و 2026/01', $incoming->reference);

        $this->assertSame('2019/07', $this->entry([
            'number' => null, 'year' => 2019, 'is_legacy' => true, 'legacy_reference' => '2019/07', 'entry_date' => null,
        ])->reference);
    }

    #[Test]
    public function each_direction_counts_from_one_every_year_and_old_rows_never_count(): void
    {
        $this->entry(['number' => 1]);
        $this->entry(['number' => 2]);
        $this->entry(['direction' => MailEntry::IN, 'number' => 1]);
        $this->entry(['number' => null, 'is_legacy' => true, 'legacy_reference' => '2026/40']);

        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('mail_entries', 2026, ['direction' => MailEntry::OUT])));
        $this->assertSame(2, DB::transaction(fn () => YearlySequence::next('mail_entries', 2026, ['direction' => MailEntry::IN])));
        $this->assertSame(1, DB::transaction(fn () => YearlySequence::next('mail_entries', 2027, ['direction' => MailEntry::OUT])));
        // Without a filter the sequence reads the whole year, as payment and purchase orders do.
        $this->assertSame(3, DB::transaction(fn () => YearlySequence::next('mail_entries', 2026)));
    }

    #[Test]
    public function a_number_is_used_once_per_direction_and_year(): void
    {
        $this->entry(['number' => 1]);
        // The same number in the other direction belongs to another sequence.
        $this->entry(['direction' => MailEntry::IN, 'number' => 1]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entry(['number' => 1]);
    }

    #[Test]
    public function only_one_active_entry_holds_a_document_lock(): void
    {
        $lock = MailEntry::lockFor(MailEntry::SOURCE_GAME, 5, 'convocation');
        $this->assertSame('game_convocation|5|convocation', $lock);

        // Cancelled entries of the same document carry no lock: any number of them.
        $this->entry(['number' => 1, 'status' => MailEntry::CANCELLED, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation']);
        $this->entry(['number' => 2, 'status' => MailEntry::CANCELLED, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation']);
        $this->entry(['number' => 3, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation', 'source_lock' => $lock]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->entry(['number' => 4, 'source_type' => MailEntry::SOURCE_GAME, 'source_id' => 5, 'source_key' => 'convocation', 'source_lock' => $lock]);
    }

    #[Test]
    public function the_editable_fields_follow_the_kind_of_entry(): void
    {
        $this->assertSame(['entry_date', 'correspondent', 'address', 'subject', 'body', 'notes'], $this->entry(['number' => 1])->editableFields());
        $this->assertSame(
            ['entry_date', 'correspondent', 'address', 'subject', 'sender_reference', 'received_date', 'notes'],
            $this->entry(['direction' => MailEntry::IN, 'number' => 1])->editableFields(),
        );
        // Made from a print: the document fixes direction, number, date and text.
        $this->assertSame(['correspondent', 'address', 'subject', 'notes'], $this->entry([
            'number' => 2, 'source_type' => MailEntry::SOURCE_PURCHASE_ORDER, 'source_id' => 1, 'source_key' => 'order',
        ])->editableFields());
        $this->assertSame(['notes'], $this->entry(['number' => null, 'is_legacy' => true, 'legacy_reference' => '2019/01'])->editableFields());
        $this->assertSame([], $this->entry(['number' => 3, 'status' => MailEntry::CANCELLED])->editableFields());
    }

    #[Test]
    public function only_an_active_outgoing_entry_with_a_text_prints_as_a_letter(): void
    {
        $this->assertTrue($this->entry(['number' => 1, 'body' => "Monsieur,\nBonjour."])->isPrintable());
        $this->assertFalse($this->entry(['number' => 2, 'body' => '   '])->isPrintable());
        $this->assertFalse($this->entry(['number' => 3, 'body' => 'Texte', 'status' => MailEntry::CANCELLED])->isPrintable());
        $this->assertFalse($this->entry(['direction' => MailEntry::IN, 'number' => 1, 'body' => 'Texte'])->isPrintable());
    }

    #[Test]
    public function the_page_props_carry_the_reference_and_the_scan_flag_but_no_internal_columns(): void
    {
        $array = $this->entry(['number' => 1, 'scan_path' => 'mail/abc.pdf', 'scan_name' => 'lettre.pdf', 'source_lock' => 'x|1|y'])->toArray();

        $this->assertSame('2026/01', $array['reference']);
        $this->assertTrue($array['has_scan']);
        $this->assertSame('lettre.pdf', $array['scan_name']);
        $this->assertArrayNotHasKey('scan_path', $array);
        $this->assertArrayNotHasKey('source_lock', $array);
        $this->assertArrayNotHasKey('lock_version', $array);
        $this->assertFalse($this->entry(['number' => 2])->has_scan);
    }
}
