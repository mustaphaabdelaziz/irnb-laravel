<?php

namespace Tests\Feature;

use App\Models\MemberJob;
use App\Models\Role;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class JobDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    #[Test]
    public function the_normaliser_ignores_case_accents_spacing_and_arabic_forms(): void
    {
        $this->assertSame(NameNormalizer::key('Ingénieur'), NameNormalizer::key('  INGENIEUR '));
        $this->assertSame(NameNormalizer::key('Chef de projet'), NameNormalizer::key('chef-de-projet'));
        // أ إ آ all normalise to ا, and ة to ه.
        $this->assertSame(NameNormalizer::key('أستاذة'), NameNormalizer::key('استاذه'));
    }

    #[Test]
    public function an_exact_duplicate_is_refused_and_names_the_existing_job(): void
    {
        $existing = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);

        $response = $this->actingAs($this->admin())
            ->post(route('jobs.store'), ['name' => 'INGENIEUR', 'name_fr' => 'INGENIEUR'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, MemberJob::query()->count());
        $this->assertSame($existing->id, MemberJob::query()->firstOrFail()->id);
    }

    #[Test]
    public function the_quick_endpoint_returns_the_created_job_as_json(): void
    {
        $response = $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'Menuisier', 'name_fr' => 'Menuisier'])
            ->assertCreated();

        $response->assertJsonPath('job.name', 'Menuisier');
        $this->assertNotNull($response->json('job.id'));
    }

    #[Test]
    public function the_quick_endpoint_hands_back_the_existing_job_instead_of_a_duplicate(): void
    {
        $existing = MemberJob::create(['name' => 'Menuisier', 'name_fr' => 'Menuisier']);

        $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'menuisier'])
            ->assertStatus(409)
            ->assertJsonPath('duplicate.id', $existing->id);

        $this->assertSame(1, MemberJob::query()->count());
    }

    #[Test]
    public function a_near_duplicate_is_reported_but_still_created(): void
    {
        MemberJob::create(['name' => 'Informaticien', 'name_fr' => 'Informaticien']);

        $response = $this->actingAs($this->admin())
            ->postJson(route('jobs.quick.store'), ['name' => 'Informaticienne'])
            ->assertCreated();

        $this->assertNotEmpty($response->json('similar'));
        $this->assertSame(2, MemberJob::query()->count());
    }

    #[Test]
    public function creating_a_job_quickly_needs_the_right_to_add_one(): void
    {
        $role = Role::factory()->create(['permissions' => ['categories' => ['view']]]);
        $viewer = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($viewer)->postJson(route('jobs.quick.store'), ['name' => 'Menuisier'])->assertForbidden();
    }

    #[Test]
    public function merging_needs_the_right_to_delete_a_job(): void
    {
        // Merging deletes the duplicate job, so it must require at least what
        // jobs.destroy requires (categories.delete) — add/edit is not enough.
        $keep = MemberJob::create(['name' => 'Ingénieur', 'name_fr' => 'Ingénieur']);
        $duplicate = MemberJob::create(['name' => 'ingenieur', 'name_fr' => 'ingenieur']);

        $role = Role::factory()->create(['permissions' => ['categories' => ['add', 'edit']]]);
        $editor = User::factory()->create([
            'privileges' => ['user'], 'approved' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
        ]);

        $this->actingAs($editor)
            ->post(route('jobs.merge', $duplicate), ['into' => $keep->id])
            ->assertForbidden();

        $this->assertNotNull($duplicate->fresh());
    }
}
