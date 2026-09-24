<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentTypeModelTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_24_100002_create_document_types.php';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    #[Test]
    public function the_seven_default_types_are_seeded_in_order(): void
    {
        $this->assertSame([
            'birth_certificate', 'photo', 'medical_certificate', 'parental_authorization',
            'id_card_copy', 'residence_certificate', 'school_certificate',
        ], DocumentType::ordered()->pluck('code')->all());
    }

    #[Test]
    public function the_defaults_carry_the_spec_rules(): void
    {
        $rules = DocumentType::ordered()->get()
            ->mapWithKeys(fn (DocumentType $t) => [$t->code => [$t->is_required, $t->validity, $t->max_age, $t->is_active]])
            ->all();

        $this->assertSame([
            'birth_certificate' => [true, 'none', null, true],
            'photo' => [true, 'none', null, true],
            'medical_certificate' => [true, 'season', null, true],
            'parental_authorization' => [true, 'season', 17, true],
            'id_card_copy' => [false, 'date', null, true],
            'residence_certificate' => [false, 'none', null, true],
            'school_certificate' => [false, 'season', null, true],
        ], $rules);
    }

    #[Test]
    public function a_type_is_named_in_the_page_language(): void
    {
        $type = $this->type('medical_certificate');

        app()->setLocale('fr');
        $this->assertSame('Certificat médical', $type->localized_name);

        app()->setLocale('ar');
        $this->assertSame('شهادة طبية', $type->localized_name);

        app()->setLocale('en');
        $this->assertSame('Medical certificate', $type->localized_name);
    }

    #[Test]
    public function only_the_photo_type_is_the_photo(): void
    {
        $this->assertTrue($this->type('photo')->isPhoto());
        $this->assertFalse($this->type('birth_certificate')->isPhoto());
    }

    #[Test]
    public function a_season_document_is_valid_until_the_end_of_its_season(): void
    {
        $type = $this->type('medical_certificate');

        // Seasons start in September unless the club says otherwise.
        $this->assertSame('2027-08-31', $type->validUntilFor('2026-10-05'));
        $this->assertSame('2026-08-31', $type->validUntilFor('2026-08-31'));
        $this->assertSame('2027-08-31', $type->validUntilFor('2026-09-01'));
    }

    #[Test]
    public function a_date_document_is_valid_until_the_entered_date_and_a_plain_one_never_expires(): void
    {
        $this->assertSame('2030-01-31', $this->type('id_card_copy')->validUntilFor('2026-10-05', '2030-01-31'));
        $this->assertNull($this->type('id_card_copy')->validUntilFor('2026-10-05'));
        $this->assertNull($this->type('birth_certificate')->validUntilFor('2026-10-05', '2030-01-31'));
    }

    #[Test]
    public function an_age_limited_type_applies_up_to_and_including_its_age(): void
    {
        Carbon::setTestNow('2026-09-23');
        $type = $this->type('parental_authorization'); // max_age 17

        $this->assertSame('2008-09-24', $type->earliestApplicableBirthdate()->toDateString());

        // 17 today (turns 18 tomorrow): still asked.
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => '2008-09-24'])));
        // 18 today: no longer asked.
        $this->assertFalse($type->appliesTo(new Player(['birthdate' => '2008-09-23'])));
        // A young child.
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => '2016-01-01'])));
        // Owner decision: no birth date means "not limited".
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => null])));
    }

    #[Test]
    public function a_type_without_an_age_limit_applies_to_everyone(): void
    {
        $type = $this->type('medical_certificate');

        $this->assertNull($type->earliestApplicableBirthdate());
        $this->assertTrue($type->appliesTo(new Player(['birthdate' => '1950-01-01'])));
    }

    #[Test]
    public function the_migration_can_run_again_after_a_partial_failure(): void
    {
        $migration = require database_path(self::MIGRATION);

        // Desktop: SQLite DDL is not rolled back, so a re-run meets its own table
        // and its own rows. Neither may throw or duplicate.
        $migration->up();

        $this->assertSame(7, DocumentType::count());
    }

    #[Test]
    public function the_seed_never_overwrites_an_edited_type(): void
    {
        $this->type('school_certificate')->update(['is_required' => true, 'name_fr' => 'Scolarité']);

        (require database_path(self::MIGRATION))->up();

        $type = $this->type('school_certificate');
        $this->assertTrue($type->is_required);
        $this->assertSame('Scolarité', $type->name_fr);
    }
}
