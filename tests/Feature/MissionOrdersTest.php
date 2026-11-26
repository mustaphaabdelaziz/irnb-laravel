<?php

namespace Tests\Feature;

use App\Exceptions\MissionOrderException;
use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Secretariat\MailRegister;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MissionOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        app()->setLocale('fr');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function missions(): MissionOrders
    {
        return app(MissionOrders::class);
    }

    private function typed(string $name, ?string $function = null): array
    {
        return ['traveler_type' => null, 'traveler_id' => null, 'name' => $name, 'function' => $function];
    }

    private function order(array $extra = []): MissionOrder
    {
        return $this->missions()->create(array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => 'Stage de formation',
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'travelers' => [$this->typed('Entraîneur Test', 'Entraîneur'), $this->typed('Joueur Test', 'Joueur — U15')],
        ], $extra), $this->admin);
    }

    private function legacy(): MissionOrder
    {
        $order = MissionOrder::create([
            'order_date' => '2018-05-12', 'destination' => 'Vieilleville', 'status' => MissionOrder::ACTIVE,
            'is_legacy' => true, 'legacy_reference' => '59', 'legacy_source' => MissionOrder::LEGACY_SOURCE,
        ]);
        $order->travelers()->create(['name' => 'Ancien Test', 'function' => 'Entraîneur', 'sort_order' => 1]);

        return $order;
    }

    private function refusal(callable $step): string
    {
        try {
            $step();
        } catch (MissionOrderException $e) {
            return $e->flashKey;
        }
        $this->fail('The step was not refused.');
    }

    #[Test]
    public function an_order_keeps_its_fields_and_a_copy_of_each_traveler(): void
    {
        $coach = User::factory()->create();
        $order = $this->order(['travelers' => [
            ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Coach Test', 'function' => 'Entraîneur'],
            $this->typed('Chauffeur Test'),
        ]]);

        $this->assertSame(MissionOrder::ACTIVE, $order->status);
        $this->assertSame($this->admin->id, $order->created_by);
        $this->assertSame('Testville', $order->destination);
        $this->assertSame('2026-12-01', $order->departure_date->toDateString());
        $this->assertSame(['Coach Test', 'Chauffeur Test'], $order->travelers->pluck('name')->all());
        $this->assertSame([1, 2], $order->travelers->pluck('sort_order')->all());
        $this->assertSame(['user', null], $order->travelers->pluck('traveler_type')->all());
        $this->assertSame($coach->id, $order->travelers[0]->traveler_id);
        $this->assertNull($order->travelers[1]->function);
        $this->assertNull($this->missions()->reference($order));
    }

    #[Test]
    public function the_same_person_is_listed_once_and_typed_names_always_stay(): void
    {
        $coach = User::factory()->create();
        $row = ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Coach Test', 'function' => 'Entraîneur'];

        $order = $this->order(['travelers' => [$row, $this->typed('Homonyme'), $row, $this->typed('Homonyme')]]);

        $this->assertSame(['Coach Test', 'Homonyme', 'Homonyme'], $order->travelers->pluck('name')->all());
    }

    #[Test]
    public function an_order_needs_a_traveler(): void
    {
        $this->assertSame('flash.mission_no_traveler', $this->refusal(fn () => $this->order(['travelers' => []])));
        $this->assertSame(0, MissionOrder::count());
    }

    #[Test]
    public function an_edit_rewrites_fields_and_travelers_and_bumps_the_lock_version(): void
    {
        $order = $this->missions()->update($this->order(), [
            'order_date' => '2026-11-27', 'destination' => 'Nouvelleville', 'reason' => null,
            'departure_date' => '2026-12-02', 'return_date' => null, 'transport' => 'Train',
            'travelers' => [$this->typed('Seul Test', 'Arbitre')],
        ], $this->admin);

        $this->assertSame('Nouvelleville', $order->destination);
        $this->assertNull($order->return_date);
        $this->assertSame(['Seul Test'], $order->travelers->pluck('name')->all());
        $this->assertSame(1, (int) DB::table('mission_orders')->where('id', $order->id)->value('lock_version'));
    }

    #[Test]
    public function cancelled_and_imported_orders_are_read_only(): void
    {
        $data = ['destination' => 'X', 'travelers' => [$this->typed('X')]];
        $cancelled = $this->missions()->cancel($this->order(), $this->admin);

        $this->assertSame('flash.mission_locked', $this->refusal(fn () => $this->missions()->update($cancelled, $data, $this->admin)));
        $this->assertSame('flash.mission_locked', $this->refusal(fn () => $this->missions()->update($this->legacy(), $data, $this->admin)));
        $this->assertSame('Testville', $cancelled->fresh()->destination);
    }

    #[Test]
    public function the_first_print_takes_one_outgoing_reference_and_reprints_keep_it(): void
    {
        $order = $this->order();

        $first = $this->missions()->register($order, $this->admin);
        $again = $this->missions()->register($order->fresh(), $this->admin);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame($first->id, $again->id);
        $this->assertSame('2026/01', $first->reference);
        $this->assertSame(MailEntry::OUT, $first->direction);
        $this->assertSame([MailEntry::SOURCE_MISSION, $order->id, 'order'], [$first->source_type, $first->source_id, $first->source_key]);
        $this->assertSame('Entraîneur Test, Joueur Test', $first->correspondent);
        $this->assertSame('Ordre de mission — Testville', $first->subject);
        $this->assertSame('2026/01', $this->missions()->reference($order));

        // Still editable after printing; the reference stays.
        $this->missions()->update($order, ['destination' => 'Autreville', 'travelers' => [$this->typed('Seul Test')]], $this->admin);
        $this->assertSame('2026/01', $this->missions()->reference($order->fresh()));
        $this->assertSame(1, MailEntry::count());
    }

    #[Test]
    public function the_correspondent_names_three_travelers_then_counts_the_rest(): void
    {
        $five = array_map(fn ($n) => $this->typed($n), ['Un Test', 'Deux Test', 'Trois Test', 'Quatre Test', 'Cinq Test']);

        $fr = $this->missions()->register($this->order(['travelers' => $five]), $this->admin);
        app()->setLocale('ar');
        $ar = $this->missions()->register($this->order(['travelers' => $five]), $this->admin);

        $this->assertSame('Un Test, Deux Test, Trois Test +2', $fr->correspondent);
        $this->assertSame('Un Test، Deux Test، Trois Test +2', $ar->correspondent);
        $this->assertSame('أمر بمهمة — Testville', $ar->subject);
    }

    #[Test]
    public function cancelled_and_imported_orders_never_take_a_reference(): void
    {
        $cancelled = $this->missions()->cancel($this->order(), $this->admin);
        $legacy = $this->legacy();

        $this->assertSame('flash.mission_cannot_register', $this->refusal(fn () => $this->missions()->register($cancelled, $this->admin)));
        $this->assertSame('flash.mission_cannot_register', $this->refusal(fn () => $this->missions()->register($legacy, $this->admin)));
        $this->assertSame(0, MailEntry::count());
        $this->assertSame('59', $this->missions()->reference($legacy));
    }

    #[Test]
    public function cancelling_cancels_the_mail_entry_and_the_order_keeps_its_reference(): void
    {
        $order = $this->order();
        $this->missions()->register($order, $this->admin);

        $cancelled = $this->missions()->cancel($order, $this->admin);

        $this->assertSame(MissionOrder::CANCELLED, $cancelled->status);
        $this->assertSame($this->admin->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $entry = MailEntry::sole();
        $this->assertSame(MailEntry::CANCELLED, $entry->status);
        $this->assertSame(1, $entry->number);
        $this->assertSame('2026/01', $this->missions()->reference($cancelled));
        // The number stays taken.
        $this->assertSame('2026/02', $this->missions()->register($this->order(), $this->admin)->reference);

        $this->assertSame('flash.mission_cannot_cancel', $this->refusal(fn () => $this->missions()->cancel($cancelled, $this->admin)));
        $this->assertSame('flash.mission_cannot_cancel', $this->refusal(fn () => $this->missions()->cancel($this->legacy(), $this->admin)));
    }

    #[Test]
    public function an_order_never_printed_is_cancelled_alone(): void
    {
        $cancelled = $this->missions()->cancel($this->order(), $this->admin);

        $this->assertSame(MissionOrder::CANCELLED, $cancelled->status);
        $this->assertSame(0, MailEntry::count());
        $this->assertNull($this->missions()->reference($cancelled));
    }

    #[Test]
    public function the_order_and_its_mail_entry_are_cancelled_together_or_not_at_all(): void
    {
        $order = $this->order();
        $entry = $this->missions()->register($order, $this->admin);
        // The mail entry's cancel fails after the order's claim ran.
        DB::listen(function ($query) {
            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'update') && str_contains($sql, 'mail_entries')) {
                throw new RuntimeException('mail register unavailable');
            }
        });

        try {
            $this->missions()->cancel($order, $this->admin);
            $this->fail('The cancel went through.');
        } catch (RuntimeException $e) {
            $this->assertSame('mail register unavailable', $e->getMessage());
        }

        $this->assertSame(MissionOrder::ACTIVE, $order->fresh()->status);
        $this->assertSame(MailEntry::ACTIVE, $entry->fresh()->status);
        $this->assertSame(0, ActivityLog::where('action', ActivityAction::MISSION_ORDER_CANCELLED)->count());
    }

    #[Test]
    public function an_entry_cancelled_in_the_register_lets_the_next_print_take_a_new_reference(): void
    {
        $order = $this->order();
        $entry = $this->missions()->register($order, $this->admin);
        app(MailRegister::class)->cancel($entry, $this->admin);

        $this->assertNull($this->missions()->reference($order));
        $this->assertSame('2026/02', $this->missions()->register($order, $this->admin)->reference);
        $this->assertSame('2026/02', $this->missions()->reference($order));
    }

    #[Test]
    public function cancel_source_cancels_only_an_active_entry(): void
    {
        $register = app(MailRegister::class);
        $this->assertNull($register->cancelSource(MailEntry::SOURCE_MISSION, 99, 'order', $this->admin));

        $entry = $register->register(MailEntry::SOURCE_MISSION, 99, 'order', ['correspondent' => 'X', 'subject' => 'Y'], $this->admin);
        $cancelled = $register->cancelSource(MailEntry::SOURCE_MISSION, 99, 'order', $this->admin);

        $this->assertSame($entry->id, $cancelled?->id);
        $this->assertSame(MailEntry::CANCELLED, $cancelled->status);
        $this->assertNull($register->find(MailEntry::SOURCE_MISSION, 99, 'order'));
        $this->assertNull($register->cancelSource(MailEntry::SOURCE_MISSION, 99, 'order', $this->admin));
    }

    #[Test]
    public function a_page_of_references_costs_one_query(): void
    {
        $printed = $this->order();
        $this->missions()->register($printed, $this->admin);              // 2026/01
        $cancelled = $this->order();
        $this->missions()->register($cancelled, $this->admin);            // 2026/02
        $cancelled = $this->missions()->cancel($cancelled, $this->admin);
        $draft = $this->order();
        $legacy = $this->legacy();
        $orders = [$printed->fresh(), $cancelled, $draft, $legacy];

        DB::enableQueryLog();
        $references = $this->missions()->references($orders);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertSame([$printed->id => '2026/01', $cancelled->id => '2026/02', $draft->id => null, $legacy->id => '59'], $references);
    }

    #[Test]
    public function the_activity_log_keeps_ids_references_and_counts_never_names_or_places(): void
    {
        $order = $this->order();
        $this->missions()->register($order, $this->admin);
        $this->missions()->update($order, ['destination' => 'Autreville', 'travelers' => [$this->typed('Seul Test', 'Arbitre')]], $this->admin);
        $this->missions()->cancel($order->fresh(), $this->admin);

        $created = ActivityLog::where('action', ActivityAction::MISSION_ORDER_CREATED)->sole();
        $this->assertSame($order->id, (int) $created->subject_id);
        $this->assertSame(['travelers' => 2], $created->properties);
        $this->assertSame(['reference' => '2026/01', 'travelers' => 1], ActivityLog::where('action', ActivityAction::MISSION_ORDER_UPDATED)->sole()->properties);
        $this->assertSame(['reference' => '2026/01'], ActivityLog::where('action', ActivityAction::MISSION_ORDER_CANCELLED)->sole()->properties);
        foreach (ActivityLog::all() as $log) {
            foreach (['Testville', 'Autreville', 'Entraîneur Test', 'Seul Test', 'Arbitre', 'Stage'] as $text) {
                $this->assertStringNotContainsString($text, (string) json_encode($log->properties, JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
