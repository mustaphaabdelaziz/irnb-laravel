<?php

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DocumentTypeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Licence fédérale',
            'name_ar' => 'الإجازة الفدرالية',
            'name_fr' => 'Licence fédérale',
            'name_en' => 'Federation licence',
            'is_required' => true,
            'validity' => 'season',
            'max_age' => '',
            'is_active' => true,
            'sort_order' => 80,
            ...$overrides,
        ];
    }

    private function recordFor(DocumentType $type, array $attributes = []): PlayerDocument
    {
        $player = Player::create(['membership_id' => '202600601', 'firstname' => 'Ali', 'lastname' => 'Kaci']);

        return PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $type->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            ...$attributes,
        ]);
    }

    #[Test]
    public function the_page_lists_every_type_with_its_usage(): void
    {
        $this->recordFor($this->type('birth_certificate'));

        $page = $this->actingAs($this->admin())->get(route('document-types.index'))
            ->assertOk()->viewData('page');
        $props = $page['props'];

        $this->assertSame('Settings/DocumentTypes', $page['component']);
        $this->assertCount(7, $props['documentTypes']);
        $this->assertSame('birth_certificate', $props['documentTypes'][0]['code']);
        $this->assertSame(1, $props['documentTypes'][0]['player_documents_count']);
        $this->assertArrayHasKey('localized_name', $props['documentTypes'][0]);
    }

    #[Test]
    public function a_type_can_be_created_with_its_rules_and_gets_a_stable_code(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['max_age' => 15]))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.document_type_created');

        $type = DocumentType::where('name', 'Licence fédérale')->firstOrFail();
        $this->assertSame('federation-licence', $type->code);
        $this->assertTrue($type->is_required);
        $this->assertSame('season', $type->validity);
        $this->assertSame(15, $type->max_age);
        $this->assertSame(80, $type->sort_order);

        app()->setLocale('ar');
        $this->assertSame('الإجازة الفدرالية', $type->localized_name);
    }

    #[Test]
    public function a_second_type_with_the_same_slug_gets_a_suffixed_code(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload());
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['name' => 'Licence fédérale (bis)']));

        $this->assertSame(['federation-licence', 'federation-licence-2'], DocumentType::where('code', 'like', 'federation-licence%')->orderBy('id')->pluck('code')->all());
    }

    #[Test]
    public function bad_rules_are_rejected(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['validity' => 'forever']))
            ->assertSessionHasErrors('validity');

        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['max_age' => 0]))
            ->assertSessionHasErrors('max_age');

        $this->actingAs($this->admin())->post(route('document-types.store'), $this->payload(['name' => 'Photo']))
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function renaming_or_changing_rules_never_touches_existing_records_or_the_code(): void
    {
        $type = $this->type('medical_certificate');
        $record = $this->recordFor($type, ['valid_until' => '2027-08-31']);

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload([
            'name' => 'Certificat médical annuel',
            'validity' => 'date',
            'max_age' => 30,
            'code' => 'hacked',
        ]))->assertRedirect()->assertSessionHas('success', 'flash.document_type_updated');

        $type->refresh();
        $this->assertSame('medical_certificate', $type->code);
        $this->assertSame('Certificat médical annuel', $type->name);
        $this->assertSame(30, $type->max_age);
        $this->assertSame('2027-08-31', $record->fresh()->valid_until->toDateString());
        $this->assertSame($type->id, $record->fresh()->document_type_id);
    }

    #[Test]
    public function clearing_the_age_limit_makes_a_type_apply_to_every_age(): void
    {
        $type = $this->type('parental_authorization');

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload([
            'name' => $type->name,
            'max_age' => '',
        ]));

        $this->assertNull($type->fresh()->max_age);
    }

    #[Test]
    public function a_type_can_be_deactivated_and_reactivated(): void
    {
        $type = $this->type('school_certificate');

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'is_active' => false]));
        $this->assertFalse($type->fresh()->is_active);

        $this->actingAs($this->admin())->put(route('document-types.update', $type), $this->payload(['name' => $type->name, 'is_active' => true]));
        $this->assertTrue($type->fresh()->is_active);
    }

    #[Test]
    public function an_unused_type_can_be_deleted(): void
    {
        $type = $this->type('residence_certificate');

        $this->actingAs($this->admin())->delete(route('document-types.destroy', $type))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.document_type_deleted');

        $this->assertNull($type->fresh());
    }

    #[Test]
    public function a_type_in_use_cannot_be_deleted(): void
    {
        $type = $this->type('birth_certificate');
        $this->recordFor($type);

        $this->actingAs($this->admin())->delete(route('document-types.destroy', $type))
            ->assertRedirect()
            ->assertSessionHas('error', 'flash.document_type_in_use');

        $this->assertNotNull($type->fresh());
    }

    #[Test]
    public function the_page_needs_the_lookup_module(): void
    {
        $coach = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'coach', 'name' => ['en' => 'Coach'], 'permissions' => ['players' => Role::ACTIONS, 'documents' => Role::ACTIONS]])->id,
        ]);

        $this->actingAs($coach)->get(route('document-types.index'))->assertForbidden();
        $this->actingAs($coach)->post(route('document-types.store'), $this->payload())->assertForbidden();
    }
}
