<?php

namespace Tests\Feature;

use App\Models\EquipmentCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentCategoryCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_derives_a_four_char_uppercase_code_from_the_name(): void
    {
        $this->assertSame('BALL', EquipmentCategory::deriveCode('Balls'));
        $this->assertSame('GOAL', EquipmentCategory::deriveCode('Goals & Nets'));
        $this->assertSame('APPA', EquipmentCategory::deriveCode('Apparel'));
    }

    #[Test]
    public function it_falls_back_to_cat_when_no_alphanumerics_remain(): void
    {
        $this->assertSame('CAT', EquipmentCategory::deriveCode('—— ——'));
    }

    #[Test]
    public function it_handles_names_shorter_than_four_chars(): void
    {
        $this->assertSame('AX', EquipmentCategory::deriveCode('Ax'));
    }

    #[Test]
    public function creating_a_category_stores_an_uppercased_code(): void
    {
        $user = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($user)->post(route('equipment-categories.store'), [
            'name' => 'Cones',
            'code' => 'con',
        ])->assertRedirect();

        $this->assertDatabaseHas('equipment_categories', ['name' => 'Cones', 'code' => 'CON']);
    }

    #[Test]
    public function creating_a_category_without_a_code_derives_one(): void
    {
        $user = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($user)->post(route('equipment-categories.store'), [
            'name' => 'Whistles',
        ])->assertRedirect();

        $this->assertDatabaseHas('equipment_categories', ['name' => 'Whistles', 'code' => 'WHIS']);
    }

    #[Test]
    public function creating_a_category_with_a_non_ascii_code_fails_validation(): void
    {
        $user = User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);

        $this->actingAs($user)->post(route('equipment-categories.store'), [
            'name' => 'Shields',
            'code' => 'كرة',
        ])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('equipment_categories', ['name' => 'Shields']);
    }
}
