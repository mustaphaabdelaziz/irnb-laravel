<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerStatusSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function a_status_can_be_created_with_its_translations(): void
    {
        $this->actingAs($this->admin())->post(route('player-statuses.store'), [
            'name' => 'مصاب',
            'name_ar' => 'مصاب',
            'name_fr' => 'Blessé',
            'name_en' => 'Injured',
        ])->assertRedirect();

        $status = PlayerStatus::where('name', 'مصاب')->first();
        $this->assertNotNull($status);

        app()->setLocale('fr');
        $this->assertSame('Blessé', $status->localized_name);
    }

    #[Test]
    public function a_duplicate_name_is_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('player-statuses.store'), [
            'name' => 'منخرط',
        ])->assertSessionHasErrors('name');
    }

    #[Test]
    public function a_status_can_be_renamed(): void
    {
        $status = PlayerStatus::where('name', 'معاقب')->first();

        $this->actingAs($this->admin())->put(route('player-statuses.update', $status), [
            'name' => 'معاقب',
            'name_fr' => 'Suspendu pour faute',
        ])->assertRedirect();

        $this->assertSame('Suspendu pour faute', $status->fresh()->name_fr);
    }

    #[Test]
    public function an_unused_status_can_be_deleted(): void
    {
        $status = PlayerStatus::where('name', 'معاقب')->first();

        $this->actingAs($this->admin())->delete(route('player-statuses.destroy', $status))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.player_status_deleted');

        $this->assertNull($status->fresh());
    }

    #[Test]
    public function a_status_in_use_cannot_be_deleted(): void
    {
        $status = PlayerStatus::where('name', 'معتزل')->first();

        Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => '202600020', 'join_year' => 2026,
            'status_id' => $status->id,
        ]);

        // The FK is nullOnDelete, so without this guard deleting the status
        // would silently blank the status of every player using it.
        $this->actingAs($this->admin())->delete(route('player-statuses.destroy', $status))
            ->assertRedirect()
            ->assertSessionHas('error', 'flash.player_status_in_use');

        $this->assertNotNull($status->fresh());
    }

    #[Test]
    public function the_settings_page_reports_how_many_players_use_each_status(): void
    {
        $status = PlayerStatus::where('name', 'متوقف')->first();

        Player::create([
            'firstname' => 'Ali', 'lastname' => 'B',
            'membership_id' => '202600021', 'join_year' => 2026,
            'status_id' => $status->id,
        ]);

        $props = $this->actingAs($this->admin())->get(route('player-statuses.index'))
            ->assertOk()->viewData('page')['props'];

        $row = collect($props['playerStatuses'])->firstWhere('id', $status->id);
        $this->assertSame(1, $row['players_count']);
    }
}
