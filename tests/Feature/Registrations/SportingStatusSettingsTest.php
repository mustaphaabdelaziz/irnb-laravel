<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\SportingStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SportingStatusSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]));
    }

    #[Test]
    public function an_admin_manages_the_list(): void
    {
        $this->post(route('sporting-statuses.store'), ['name' => 'Competing', 'name_ar' => 'منافس', 'is_active' => true])
            ->assertSessionHasNoErrors();
        $status = SportingStatus::firstOrFail();

        $this->put(route('sporting-statuses.update', $status), ['name' => 'Recreational', 'is_active' => false])
            ->assertSessionHasNoErrors();
        $this->assertSame('Recreational', $status->fresh()->name);

        $this->delete(route('sporting-statuses.destroy', $status))->assertSessionHasNoErrors();
        $this->assertSame(0, SportingStatus::count());
    }

    #[Test]
    public function one_in_use_cannot_be_deleted(): void
    {
        $status = SportingStatus::create(['name' => 'Competing']);
        $branch = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $player = Player::create(['firstname' => 'A', 'membership_id' => '201700001']);
        $player->registrations()->create(['branch_id' => $branch->id, 'category_id' => null, 'sporting_status_id' => $status->id]);

        $this->delete(route('sporting-statuses.destroy', $status))->assertSessionHas('error', 'flash.sporting_status_in_use');
        $this->assertNotNull($status->fresh());
    }
}
