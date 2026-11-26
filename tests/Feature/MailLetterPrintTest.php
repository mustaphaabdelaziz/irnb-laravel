<?php

namespace Tests\Feature;

use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MailEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Pdf\BoardSignatories;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MailRegister;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailLetterPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function letter(array $extra = []): MailEntry
    {
        return app(MailRegister::class)->create(array_merge([
            'direction' => MailEntry::OUT, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue de Wilaya',
            'address' => '12 rue des Sports, Testville', 'subject' => 'Engagement de nos équipes',
            'body' => "Monsieur le Président,\nNous vous adressons la liste de nos équipes.",
        ], $extra), User::factory()->admin()->create());
    }

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function member(string $name, string $role, string $status = 'active'): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => $name, 'role' => $role, 'status' => $status]);
    }

    private function spyPdf(): \ArrayObject
    {
        $seen = new \ArrayObject;
        $this->mock(PdfService::class, function (MockInterface $mock) use ($seen) {
            $mock->shouldReceive('stream')->once()->andReturnUsing(function (string $html, string $filename, bool $rtl = true) use ($seen) {
                $seen['html'] = $html;
                $seen['filename'] = $filename;
                $seen['rtl'] = $rtl;

                return response('%PDF-spy', 200, ['Content-Type' => 'application/pdf']);
            });
        });

        return $seen;
    }

    #[Test]
    public function the_letter_is_a_real_pdf_in_arabic(): void
    {
        $response = $this->actingAs($this->user('ar'))->get(route('mail.print', $this->letter()))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function the_french_letter_has_reference_date_recipient_subject_text_and_both_signatures(): void
    {
        $this->member('Président Test', 'president');
        $this->member('Secrétaire Test', 'secretary');
        $this->member('Trésorier Test', 'treasurer');
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('mail.print', $this->letter()))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('courrier-2026-01.pdf', $seen['filename']);
        foreach ([
            UiLang::get('mreg.pdf.ref', null, 'fr'), '<bdo dir="ltr">2026/01</bdo>', '<bdo dir="ltr">26/11/2026</bdo>',
            UiLang::get('mreg.pdf.to', null, 'fr'), 'Test Ligue de Wilaya', '12 rue des Sports, Testville',
            UiLang::get('mreg.pdf.subject', null, 'fr'), 'Engagement de nos équipes',
            'Monsieur le Président,</bdo><br /><bdo dir="ltr">Nous vous adressons la liste de nos équipes.',
            UiLang::get('mreg.pdf.secretary', null, 'fr'), UiLang::get('po.pdf.president', null, 'fr'),
            'Secrétaire Test', 'Président Test',
        ] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        // The treasurer does not sign letters.
        $this->assertStringNotContainsString('Trésorier Test', $seen['html']);
    }

    #[Test]
    public function the_arabic_letter_keeps_latin_lines_left_to_right(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('ar'))->get(route('mail.print', $this->letter([
            'correspondent' => 'الرابطة الولائية', 'body' => "السيد الرئيس،\nRef. FAF 2026",
        ])))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('<body dir="rtl">', $seen['html']);
        $this->assertStringContainsString('السيد الرئيس،<br /><bdo dir="ltr">Ref. FAF 2026</bdo>', $seen['html']);
        $this->assertStringContainsString('الرابطة الولائية', $seen['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen['html']);
    }

    #[Test]
    public function a_role_nobody_holds_prints_a_blank_line_to_sign(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('mail.print', $this->letter()))->assertOk();

        $this->assertStringContainsString(UiLang::get('mreg.pdf.secretary', null, 'fr'), $seen['html']);
        $this->assertSame(['president' => null, 'treasurer' => null, 'secretary' => null], BoardSignatories::names());
    }

    #[Test]
    public function the_signatories_include_the_active_secretary_general_of_the_current_term(): void
    {
        $this->member('Ancien Secrétaire', 'secretary', 'former');
        $this->member('Secrétaire Test', 'secretary');
        $this->member('Président Test', 'president');

        $names = BoardSignatories::names();

        $this->assertSame('Secrétaire Test', $names['secretary']);
        $this->assertSame('Président Test', $names['president']);
        $this->assertNull($names['treasurer']);
    }

    #[Test]
    public function only_an_active_outgoing_letter_with_a_text_prints(): void
    {
        $user = $this->user('fr');
        $register = app(MailRegister::class);
        $incoming = $register->create([
            'direction' => MailEntry::IN, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Direction', 'subject' => 'Invitation',
        ], $user);
        $noText = $this->letter(['body' => null]);
        $cancelled = $register->cancel($this->letter(), $user);

        $this->actingAs($user)->get(route('mail.print', $incoming))->assertNotFound();
        $this->get(route('mail.print', $noText))->assertNotFound();
        $this->get(route('mail.print', $cancelled))->assertNotFound();
    }

    #[Test]
    public function a_view_only_user_prints(): void
    {
        $letter = $this->letter();
        $this->spyPdf();
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => ['mail' => ['view']]])->id,
        ]);

        $this->actingAs($viewer)->get(route('mail.print', $letter))->assertOk();
    }
}
