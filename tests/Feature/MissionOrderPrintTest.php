<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\BoardMember;
use App\Models\BoardTerm;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Pdf\PdfService;
use App\Services\Secretariat\MissionOrders;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MissionOrderPrintTest extends TestCase
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

    private function typed(string $name, ?string $function = null): array
    {
        return ['traveler_type' => null, 'traveler_id' => null, 'name' => $name, 'function' => $function];
    }

    private function order(array $extra = []): MissionOrder
    {
        return app(MissionOrders::class)->create(array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => "Stage de formation\nNiveau 1",
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'travelers' => [
                $this->typed('Entraîneur Test', 'Entraîneur principal'),
                $this->typed('Adjoint Test', 'Entraîneur adjoint'),
                $this->typed('Joueur Test', 'Joueur — U15'),
            ],
        ], $extra), User::factory()->admin()->create());
    }

    private function user(string $locale = 'fr'): User
    {
        return User::factory()->admin()->create(['preferred_lng' => $locale]);
    }

    private function member(string $name, string $role): void
    {
        BoardMember::create(['board_term_id' => BoardTerm::current()?->id, 'name' => $name, 'role' => $role, 'status' => 'active']);
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
    public function the_sheets_are_a_real_pdf_in_arabic(): void
    {
        $response = $this->actingAs($this->user('ar'))->get(route('missions.print', $this->order()))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function one_traveler_is_a_real_pdf_in_french(): void
    {
        $order = $this->order();

        $response = $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $order->travelers[1]]))->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    #[Test]
    public function each_traveler_gets_a_sheet_with_every_field_and_both_signatures(): void
    {
        $this->member('Président Test', 'president');
        $this->member('Secrétaire Test', 'secretary');
        $this->member('Trésorier Test', 'treasurer');
        $order = $this->order();
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $order))->assertOk();

        $this->assertFalse($seen['rtl']);
        $this->assertSame('ordre-de-mission-'.$order->id.'.pdf', $seen['filename']);
        $this->assertSame(2, substr_count($seen['html'], '<pagebreak />'));
        $this->assertSame(3, substr_count($seen['html'], UiLang::get('mis.pdf.title', null, 'fr')));
        foreach ([
            'Entraîneur Test', 'Adjoint Test', 'Joueur Test', 'Entraîneur principal', 'Joueur — U15',
            UiLang::get('mis.pdf.name', null, 'fr'), UiLang::get('mis.pdf.function', null, 'fr'),
            UiLang::get('mis.pdf.destination', null, 'fr'), 'Testville',
            UiLang::get('mis.pdf.reason', null, 'fr'), 'Stage de formation</bdo><br /><bdo dir="ltr">Niveau 1',
            UiLang::get('mis.pdf.departure', null, 'fr'), '<bdo dir="ltr">01/12/2026</bdo>',
            UiLang::get('mis.pdf.return', null, 'fr'), '<bdo dir="ltr">03/12/2026</bdo>',
            UiLang::get('mis.pdf.transport', null, 'fr'), 'Bus du club',
            '<bdo dir="ltr">26/11/2026</bdo>',
            UiLang::get('mreg.pdf.secretary', null, 'fr'), UiLang::get('po.pdf.president', null, 'fr'),
            'Président Test', 'Secrétaire Test',
        ] as $text) {
            $this->assertStringContainsString($text, $seen['html'], $text);
        }
        // The treasurer does not sign mission orders; no banner on an active order.
        $this->assertStringNotContainsString('Trésorier Test', $seen['html']);
        $this->assertStringNotContainsString(UiLang::get('mis.pdf.cancelled', null, 'fr'), $seen['html']);
        // Opening the PDF never takes a reference.
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function the_reference_taken_at_the_first_print_shows_on_every_sheet(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $order))->assertOk();

        $this->assertSame(3, substr_count($seen['html'], '<bdo dir="ltr">2026/01</bdo>'));
        $this->assertSame('ordre-de-mission-2026-01.pdf', $seen['filename']);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function one_traveler_prints_alone(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $order->travelers[1]]))->assertOk();

        $this->assertStringContainsString('Adjoint Test', $seen['html']);
        $this->assertStringNotContainsString('Entraîneur Test', $seen['html']);
        $this->assertStringNotContainsString('Joueur Test', $seen['html']);
        $this->assertStringNotContainsString('<pagebreak />', $seen['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen['html']);
        $this->assertSame('ordre-de-mission-2026-01-2.pdf', $seen['filename']);
    }

    #[Test]
    public function a_traveler_of_another_order_is_not_found(): void
    {
        $order = $this->order();
        $other = $this->order();

        $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $other->travelers[0]]))->assertNotFound();
    }

    #[Test]
    public function the_arabic_sheet_reads_right_to_left_and_keeps_latin_lines_left_to_right(): void
    {
        $seen = $this->spyPdf();

        $this->actingAs($this->user('ar'))->get(route('missions.print', $this->order([
            'destination' => 'وهران', 'reason' => "تربص تكويني\nStage FAF 2026",
            'travelers' => [$this->typed('مدرب تجريبي', 'مدرب')],
        ])))->assertOk();

        $this->assertTrue($seen['rtl']);
        $this->assertStringContainsString('<body dir="rtl">', $seen['html']);
        $this->assertStringContainsString('تربص تكويني<br /><bdo dir="ltr">Stage FAF 2026</bdo>', $seen['html']);
        $this->assertStringContainsString('وهران', $seen['html']);
        $this->assertStringContainsString('مدرب تجريبي', $seen['html']);
        $this->assertStringContainsString(UiLang::get('mis.pdf.title', null, 'ar'), $seen['html']);
    }

    #[Test]
    public function a_cancelled_order_prints_with_a_banner_and_keeps_its_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $order = $this->order();
        app(MissionOrders::class)->register($order, $admin);
        app(MissionOrders::class)->cancel($order, $admin);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $order))->assertOk();

        $this->assertStringContainsString(UiLang::get('mis.pdf.cancelled', null, 'fr'), $seen['html']);
        $this->assertStringContainsString('<bdo dir="ltr">2026/01</bdo>', $seen['html']);
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function an_imported_order_prints_its_old_reference(): void
    {
        $legacy = MissionOrder::create([
            'order_date' => '2018-05-12', 'destination' => 'Vieilleville', 'status' => MissionOrder::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE,
        ]);
        $legacy->travelers()->create(['name' => 'Ancien Test', 'function' => 'Entraîneur', 'sort_order' => 1]);
        $seen = $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print', $legacy))->assertOk();

        $this->assertStringContainsString('<bdo dir="ltr">59</bdo>', $seen['html']);
        $this->assertStringContainsString('Ancien Test', $seen['html']);
        $this->assertSame('ordre-de-mission-59.pdf', $seen['filename']);
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function a_view_only_user_prints_and_others_may_not(): void
    {
        $order = $this->order();
        $role = fn (array $permissions) => User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
        $this->spyPdf();

        $this->actingAs($role(['missions' => ['view']]))->get(route('missions.print', $order))->assertOk();
        $this->actingAs($role(['mail' => ['view']]))->get(route('missions.print-traveler', [$order, $order->travelers[0]]))->assertForbidden();
    }

    #[Test]
    public function each_print_is_logged_with_references_and_ids_only(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $this->spyPdf();

        $this->actingAs($this->user('fr'))->get(route('missions.print-traveler', [$order, $order->travelers[2]]))->assertOk();

        $log = ActivityLog::where('action', ActivityAction::MISSION_ORDER_PRINTED)->sole();
        $this->assertSame($order->id, (int) $log->subject_id);
        $this->assertSame(['reference' => '2026/01', 'traveler_id' => $order->travelers[2]->id], $log->properties);
    }
}
