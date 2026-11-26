<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\MailEntry;
use App\Models\MissionOrder;
use App\Models\Player;
use App\Models\Role;
use App\Models\User;
use App\Services\Activity\ActivityAction;
use App\Services\Activity\ActivitySubjectLink;
use App\Services\Secretariat\MissionOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class MissionOrderHttpTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

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

    private function userWith(array $permissions): User
    {
        return User::factory()->create([
            'privileges' => ['user'], 'preferred_lng' => 'fr',
            'role_id' => Role::factory()->create(['permissions' => $permissions])->id,
        ]);
    }

    private function typed(string $name, ?string $function = null): array
    {
        return ['traveler_type' => null, 'traveler_id' => null, 'name' => $name, 'function' => $function];
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'order_date' => '2026-11-26', 'destination' => 'Testville', 'reason' => 'Stage de formation',
            'departure_date' => '2026-12-01', 'return_date' => '2026-12-03', 'transport' => 'Bus du club',
            'travelers' => [$this->typed('Entraîneur Test', 'Entraîneur')],
        ], $extra);
    }

    private function order(array $extra = []): MissionOrder
    {
        return app(MissionOrders::class)->create($this->payload($extra), User::factory()->admin()->create());
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

    #[Test]
    public function an_editor_writes_an_order_with_a_coach_and_a_typed_traveler(): void
    {
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $user = $this->userWith(['missions' => ['view', 'add']]);

        $response = $this->actingAs($user)->post(route('missions.store'), $this->payload(['travelers' => [
            ['traveler_type' => 'user', 'traveler_id' => $coach->id, 'name' => 'Karim Coach', 'function' => 'Entraîneur'],
            $this->typed('Chauffeur Test', 'Chauffeur'),
        ]]));

        $order = MissionOrder::sole();
        $response->assertRedirect(route('missions.show', $order))->assertSessionHas('success', 'flash.mission_created');
        $this->assertSame('Testville', $order->destination);
        $this->assertSame(['Karim Coach', 'Chauffeur Test'], $order->travelers()->pluck('name')->all());
        $this->assertSame($user->id, $order->created_by);
        // Writing an order takes no reference: only a print does.
        $this->assertSame(0, MailEntry::count());
    }

    #[Test]
    public function the_form_is_validated(): void
    {
        $user = $this->userWith(['missions' => ['view', 'add']]);

        $this->actingAs($user)->post(route('missions.store'), $this->payload([
            'destination' => '', 'departure_date' => '2026-12-05', 'return_date' => '2026-12-01', 'travelers' => [],
        ]))->assertSessionHasErrors(['destination', 'return_date', 'travelers']);

        $this->post(route('missions.store'), $this->payload(['travelers' => [
            ['traveler_type' => 'robot', 'traveler_id' => 1, 'name' => 'X', 'function' => null],
            $this->typed(''),
        ]]))->assertSessionHasErrors(['travelers.0.traveler_type', 'travelers.1.name']);

        // The existence check runs once the rows are well formed.
        $this->post(route('missions.store'), $this->payload(['travelers' => [
            $this->typed('Connu'),
            ['traveler_type' => 'player', 'traveler_id' => 99999, 'name' => 'Inconnu', 'function' => null],
        ]]))->assertSessionHasErrors(['travelers.1.traveler_id']);

        $this->assertSame(0, MissionOrder::count());
    }

    #[Test]
    public function each_action_needs_its_right(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith(['missions' => ['view']]));
        $this->get(route('missions.index'))->assertOk();
        $this->get(route('missions.show', $order))->assertOk();
        $this->get(route('missions.create'))->assertForbidden();
        $this->post(route('missions.store'), $this->payload())->assertForbidden();
        $this->get(route('missions.edit', $order))->assertForbidden();
        $this->put(route('missions.update', $order), $this->payload())->assertForbidden();
        $this->post(route('missions.cancel', $order))->assertForbidden();
        $this->postJson(route('missions.register', $order))->assertForbidden();

        $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('missions.index'))->assertForbidden();
        // Orders are never deleted: there is no destroy route.
        $this->assertFalse(Route::has('missions.destroy'));
    }

    #[Test]
    public function an_update_rewrites_the_order_and_its_travelers(): void
    {
        $order = $this->order();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]))
            ->put(route('missions.update', $order), $this->payload(['destination' => 'Autreville', 'travelers' => [$this->typed('Arbitre Test', 'Arbitre')]]))
            ->assertRedirect(route('missions.show', $order))->assertSessionHas('success', 'flash.mission_updated');

        $order->refresh();
        $this->assertSame('Autreville', $order->destination);
        $this->assertSame(['Arbitre Test'], $order->travelers()->pluck('name')->all());
    }

    #[Test]
    public function cancelled_and_imported_orders_have_no_edit_page(): void
    {
        $admin = User::factory()->admin()->create();
        $cancelled = app(MissionOrders::class)->cancel($this->order(), $admin);
        $legacy = $this->legacy();

        $this->actingAs($admin)->get(route('missions.edit', $cancelled))
            ->assertRedirect(route('missions.show', $cancelled))->assertSessionHas('error', 'flash.mission_locked');
        $this->get(route('missions.edit', $legacy))
            ->assertRedirect(route('missions.show', $legacy))->assertSessionHas('error', 'flash.mission_locked');
        $this->put(route('missions.update', $legacy), $this->payload())->assertSessionHas('error', 'flash.mission_locked');
    }

    #[Test]
    public function cancelling_needs_the_delete_right_and_cancels_the_mail_entry_too(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]))->post(route('missions.cancel', $order))->assertForbidden();

        $this->actingAs($this->userWith(['missions' => ['view', 'delete']]))->from(route('missions.show', $order))
            ->post(route('missions.cancel', $order))
            ->assertRedirect(route('missions.show', $order))->assertSessionHas('success', 'flash.mission_cancelled');

        $this->assertSame(MissionOrder::CANCELLED, $order->fresh()->status);
        $this->assertSame(MailEntry::CANCELLED, MailEntry::sole()->status);
        $this->assertSame('2026/01', app(MissionOrders::class)->reference($order->fresh()));
        $this->post(route('missions.cancel', $order))->assertSessionHas('error', 'flash.mission_cannot_cancel');
    }

    #[Test]
    public function the_print_buttons_take_the_reference_once_with_the_edit_right(): void
    {
        $order = $this->order(['travelers' => [$this->typed('Entraîneur Test'), $this->typed('Joueur Test')]]);

        $this->actingAs($this->userWith(['missions' => ['view', 'add']]))->postJson(route('missions.register', $order))->assertForbidden();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]));
        $this->postJson(route('missions.register', $order))->assertOk()
            ->assertJson(['reference' => '2026/01', 'created' => true]);
        $this->postJson(route('missions.register', $order))->assertOk()
            ->assertJson(['reference' => '2026/01', 'created' => false]);

        $entry = MailEntry::sole();
        $this->assertSame('Entraîneur Test, Joueur Test', $entry->correspondent);
        $this->assertSame('Ordre de mission — Testville', $entry->subject);

        app(MissionOrders::class)->cancel($order, User::factory()->admin()->create());
        $this->postJson(route('missions.register', $order))->assertStatus(422)
            ->assertJsonPath('message', 'flash.mission_cannot_register');
        $this->postJson(route('missions.register', $this->legacy()))->assertStatus(422);
    }

    #[Test]
    public function the_list_filters_by_year_and_status_and_finds_a_place_a_traveler_or_a_reference(): void
    {
        $admin = User::factory()->admin()->create(['preferred_lng' => 'fr']);
        $missions = app(MissionOrders::class);
        $printed = $missions->create($this->payload(['destination' => 'Testville']), $admin);
        $missions->create($this->payload([
            'destination' => 'Autreville', 'order_date' => '2025-06-01',
            'travelers' => [$this->typed('Voyageur Unique'), $this->typed('B'), $this->typed('C'), $this->typed('D')],
        ]), $admin);
        $cancelled = $missions->create($this->payload(['destination' => 'Troisville']), $admin);
        $missions->cancel($cancelled, $admin);
        $missions->register($printed, $admin);                                                     // 2026/01
        $this->legacy();

        $this->actingAs($admin)->get(route('missions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders.data', 4)
                ->where('orders.data.0.destination', 'Troisville')
                ->where('orders.data.1.reference', '2026/01')
                ->where('orders.data.2.travelers', 'Voyageur Unique, B, C')
                ->where('orders.data.2.travelers_count', 4)
                ->where('orders.data.3.reference', '59')
                ->where('years', [2026, 2025, 2018])
                ->where('can.create', true)->where('can.register', true));
        $this->get(route('missions.index', ['year' => 2025]))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Autreville'));
        $this->get(route('missions.index', ['status' => 'cancelled']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Troisville'));
        $this->get(route('missions.index', ['q' => 'Autre']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1));
        $this->get(route('missions.index', ['q' => 'Voyageur Unique']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Autreville'));
        // "2026/1" is mail entry 1 of 2026: the order printed under it.
        $this->get(route('missions.index', ['q' => '2026/1']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Testville'));
        $this->get(route('missions.index', ['q' => '59']))
            ->assertInertia(fn (Assert $page) => $page->has('orders.data', 1)->where('orders.data.0.destination', 'Vieilleville'));
    }

    #[Test]
    public function the_order_page_shows_its_reference_and_what_the_viewer_may_do(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());

        $this->actingAs(User::factory()->admin()->create())->get(route('missions.show', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('order.id', $order->id)
                ->has('order.travelers', 1)
                ->where('order.travelers.0.name', 'Entraîneur Test')
                ->where('reference', '2026/01')
                ->where('can.edit', true)->where('can.cancel', true)->where('can.register', true));

        $this->actingAs($this->userWith(['missions' => ['view']]))->get(route('missions.show', $this->legacy()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reference', '59')
                ->where('can.edit', false)->where('can.cancel', false)->where('can.register', false));
    }

    #[Test]
    public function the_form_pages_carry_the_pickers(): void
    {
        $order = $this->order();

        $this->actingAs(User::factory()->admin()->create())->get(route('missions.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('users')->has('boardMembers')->has('players')->has('teams'));
        $this->get(route('missions.edit', $order))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('order.id', $order->id)->has('order.travelers', 1)->has('users'));

        $this->actingAs($this->userWith(['missions' => ['view', 'add']]))->get(route('missions.create'))
            ->assertInertia(fn (Assert $page) => $page->where('players', null)->where('teams', null));
    }

    #[Test]
    public function adding_a_team_needs_the_team_and_its_players(): void
    {
        $u15 = $this->category('U15');
        $team = $this->team($u15);
        $player = $this->player($u15);
        $this->onRoster($team, 2026, [$player]);
        $coach = User::factory()->create(['firstname' => 'Karim', 'lastname' => 'Coach']);
        $this->assignCoach($team, $coach, 2026);

        $this->actingAs($this->userWith(['missions' => ['view', 'add']]))->getJson(route('missions.team-members', $team))->assertForbidden();
        $this->actingAs($this->userWith(['missions' => ['view'], 'teams' => ['view'], 'players' => ['view']]))
            ->getJson(route('missions.team-members', $team))->assertForbidden();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit'], 'teams' => ['view'], 'players' => ['view']]))
            ->getJson(route('missions.team-members', $team))
            ->assertOk()
            ->assertJsonCount(2, 'travelers')
            ->assertJsonPath('travelers.0.traveler_id', $coach->id)
            ->assertJsonPath('travelers.1.traveler_type', 'player')
            ->assertJsonPath('travelers.1.traveler_id', $player->id);
    }

    #[Test]
    public function the_mail_register_names_and_links_a_mission_order_for_who_may_view_it(): void
    {
        $order = $this->order();
        app(MissionOrders::class)->register($order, User::factory()->admin()->create());
        $entry = MailEntry::sole();

        $this->actingAs(User::factory()->admin()->create())->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page
                ->where('source.type', 'mission_order')
                ->where('source.label', 'Testville · 26/11/2026')
                ->where('source.url', route('missions.show', $order)));
        $this->get(route('mail.index'))
            ->assertInertia(fn (Assert $page) => $page->where('entries.data.0.source_url', route('missions.show', $order)));

        $this->actingAs($this->userWith(['mail' => ['view']]))->get(route('mail.show', $entry))
            ->assertInertia(fn (Assert $page) => $page->where('source.label', '#'.$order->id)->where('source.url', null));
    }

    #[Test]
    public function the_activity_pages_link_an_order_for_mission_viewers_only(): void
    {
        $order = $this->order();
        $log = ActivityLog::where('action', ActivityAction::MISSION_ORDER_CREATED)->sole();

        $this->assertSame(route('missions.show', $order), ActivitySubjectLink::for($log, User::factory()->admin()->create())['url']);

        $neutral = ActivitySubjectLink::for($log, $this->userWith(['players' => ['view']]));
        $this->assertNull($neutral['url']);
        $this->assertTrue($neutral['neutral']);
    }

    #[Test]
    public function a_traveler_whose_person_was_deleted_stays_as_a_typed_name_on_edit(): void
    {
        $player = Player::create(['membership_id' => 'T000001', 'firstname' => 'Test', 'lastname' => 'Joueur']);
        $order = $this->order(['travelers' => [
            ['traveler_type' => 'player', 'traveler_id' => $player->id, 'name' => 'Test Joueur', 'function' => 'Joueur'],
        ]]);
        DB::table('players')->where('id', $player->id)->delete();

        $this->actingAs($this->userWith(['missions' => ['view', 'edit']]))
            ->put(route('missions.update', $order), $this->payload(['travelers' => [
                ['traveler_type' => 'player', 'traveler_id' => $player->id, 'name' => 'Test Joueur', 'function' => 'Joueur'],
            ]]))
            ->assertRedirect(route('missions.show', $order))->assertSessionHasNoErrors();

        $traveler = $order->travelers()->sole();
        $this->assertNull($traveler->traveler_type);
        $this->assertNull($traveler->traveler_id);
        $this->assertSame('Test Joueur', $traveler->name);
        $this->assertSame('Joueur', $traveler->function);
    }

    #[Test]
    public function a_traveler_keyed_by_a_word_is_a_validation_error_not_a_crash(): void
    {
        $user = $this->userWith(['missions' => ['view', 'add']]);

        $this->actingAs($user)->post(route('missions.store'), $this->payload(['travelers' => [
            'a' => ['traveler_type' => 'player', 'traveler_id' => 99999, 'name' => 'Inconnu', 'function' => null],
        ]]))->assertSessionHasErrors(['travelers.a.traveler_id']);

        $this->assertSame(0, MissionOrder::count());
    }
}
