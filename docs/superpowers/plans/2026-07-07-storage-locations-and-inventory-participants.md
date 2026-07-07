# Storage Locations & Inventory Participants Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-managed storage-location list (used as the item Location dropdown and to group the inventory count sheet) and let staff attach user/player participants to an inventory session.

**Architecture:** A `storage_locations` lookup table drives a strict Location `<select>` on item forms and the inventory count sheet; `equipment_items.location` stays a string (legacy values preserved). Inventory participants are polymorphic (`User`/`Player`) rows in `inventory_session_participants`, synced from the session page while the session is in progress.

**Tech Stack:** Laravel 13 (PHP 8.3), Inertia + Vue 3, PHPUnit (attribute style, `RefreshDatabase`), SQLite (desktop) / MySQL (web).

## Global Constraints

- Storage locations are admin-managed (name, unique). Settings CRUD routes live in the **admin** middleware group, beside `equipment-categories`.
- `equipment_items.location` stays a nullable string; the dropdown is UI-only (backend still accepts any string, so legacy values persist). No server-side enum.
- Item Location dropdown is **strict** (managed names only); an item's existing non-managed value is kept as a selectable option in the edit modal.
- Participants are polymorphic `User`/`Player`; persisted as full class names (`App\Models\User` / `App\Models\Player`) — this app has no morph map. Sync endpoint replaces the set and is refused (403) unless the session is `in_progress`. Participant routes live in the **general** auth group (`auth,verified,approved`), NOT admin — same group as the other `inventory.*` routes.
- Count sheet groups rows by `expected_location` ("Unassigned" when null).
- Test conventions: `namespace Tests\Feature;`, `use RefreshDatabase;`, `#[Test]`, `extends Tests\TestCase`. Admin routes need `User::factory()->create(['email_verified_at' => now(), 'privileges' => ['admin']])`; general auth routes need `User::factory()->create(['email_verified_at' => now()])`. A test Player needs only `Player::create(['membership_id' => '<unique>', 'firstname' => '<name>'])`. Test command (Windows): `php artisan test --filter=<Name>`.
- Vue changes have no JS test runner: verify with `npm run build`.

---

### Task 1: StorageLocation model + migration + Settings CRUD backend

**Files:**
- Create: `database/migrations/2026_07_07_000002_create_storage_locations_table.php`
- Create: `app/Models/StorageLocation.php`
- Create: `app/Http/Controllers/StorageLocationController.php`
- Modify: `routes/web.php` (import + one route line in the admin group)
- Test: `tests/Feature/StorageLocationTest.php`

**Interfaces:**
- Produces: table `storage_locations` (`id`, `name` unique); routes `storage-locations.index/store/update/destroy`; `StorageLocation` model (fillable `name`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/StorageLocationTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StorageLocationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'privileges' => ['admin']]);
    }

    #[Test]
    public function an_admin_can_create_a_storage_location(): void
    {
        $this->actingAs($this->admin())
            ->post(route('storage-locations.store'), ['name' => 'Storage 01'])
            ->assertRedirect();

        $this->assertDatabaseHas('storage_locations', ['name' => 'Storage 01']);
    }

    #[Test]
    public function location_names_must_be_unique(): void
    {
        StorageLocation::create(['name' => 'Storage 01']);

        $this->actingAs($this->admin())
            ->post(route('storage-locations.store'), ['name' => 'Storage 01'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, StorageLocation::where('name', 'Storage 01')->count());
    }

    #[Test]
    public function an_admin_can_rename_and_delete_a_location(): void
    {
        $loc = StorageLocation::create(['name' => 'Storage 01']);

        $this->actingAs($this->admin())
            ->put(route('storage-locations.update', $loc), ['name' => 'Storage 02'])
            ->assertRedirect();
        $this->assertDatabaseHas('storage_locations', ['id' => $loc->id, 'name' => 'Storage 02']);

        $this->actingAs($this->admin())
            ->delete(route('storage-locations.destroy', $loc))
            ->assertRedirect();
        $this->assertDatabaseMissing('storage_locations', ['id' => $loc->id]);
    }

    #[Test]
    public function a_non_admin_cannot_manage_locations(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->post(route('storage-locations.store'), ['name' => 'Storage 01'])
            ->assertForbidden();

        $this->assertDatabaseMissing('storage_locations', ['name' => 'Storage 01']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=StorageLocationTest`
Expected: FAIL — route `storage-locations.store` not defined.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_07_000002_create_storage_locations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_locations');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/StorageLocation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StorageLocation extends Model
{
    protected $fillable = ['name'];
}
```

- [ ] **Step 5: Create the controller**

Create `app/Http/Controllers/StorageLocationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\StorageLocation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorageLocationController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/StorageLocations', [
            'locations' => StorageLocation::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:storage_locations,name'],
        ]);

        StorageLocation::create($data);

        return back()->with('success', 'Storage location created successfully.');
    }

    public function update(Request $request, StorageLocation $storageLocation): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:storage_locations,name,'.$storageLocation->id],
        ]);

        $storageLocation->update($data);

        return back()->with('success', 'Storage location updated successfully.');
    }

    public function destroy(StorageLocation $storageLocation): RedirectResponse
    {
        $storageLocation->delete();

        return back()->with('success', 'Storage location deleted successfully.');
    }
}
```

- [ ] **Step 6: Register the routes**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\StorageLocationController;
```

And in the **admin** middleware group, in the "Settings - lookup tables" block (right after the `equipment-categories` resource line), add:

```php
        Route::resource('storage-locations', StorageLocationController::class)->except(['show', 'create', 'edit']);
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --filter=StorageLocationTest`
Expected: PASS (4 tests)

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_07_000002_create_storage_locations_table.php app/Models/StorageLocation.php app/Http/Controllers/StorageLocationController.php routes/web.php tests/Feature/StorageLocationTest.php
git commit -m "feat: managed storage locations (model + settings CRUD)"
```

---

### Task 2: Storage Locations Settings page + nav

**Files:**
- Create: `resources/js/Pages/Settings/StorageLocations.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (nav entry, near line 89)
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`

**Interfaces:**
- Consumes: `storage-locations.*` routes (Task 1), `locations` prop.

- [ ] **Step 1: Create the Vue page (mirrors EquipmentCategories.vue)**

Create `resources/js/Pages/Settings/StorageLocations.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ConfirmModal from '@/Components/ConfirmModal.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import { ref } from 'vue';

const { t } = useI18n();

defineProps({ locations: Array });

const editingId = ref(null);
const deleteId = ref(null);

const form = useForm({ name: '' });
const editForm = useForm({ name: '' });

function addLocation() {
    form.post(route('storage-locations.store'), { onSuccess: () => form.reset() });
}
function startEdit(loc) {
    editingId.value = loc.id;
    editForm.name = loc.name;
}
function saveEdit(id) {
    editForm.put(route('storage-locations.update', id), { onSuccess: () => { editingId.value = null; } });
}
function destroy() {
    router.delete(route('storage-locations.destroy', deleteId.value), { onSuccess: () => { deleteId.value = null; } });
}
</script>

<template>
    <Head :title="t('storage_locations')" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('storage_locations') }}</h1>
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <form @submit.prevent="addLocation" class="flex items-end gap-3 rounded-2xl bg-white dark:bg-slate-900 p-5 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <div class="flex-1">
                    <label class="text-sm font-medium text-slate-700 dark:text-slate-200">{{ t('name') }}</label>
                    <TextInput v-model="form.name" class="mt-1 w-full" placeholder="Storage 01" required />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>
                <PrimaryButton :disabled="form.processing">{{ t('add') }}</PrimaryButton>
            </form>

            <div class="overflow-hidden rounded-2xl bg-white dark:bg-slate-900 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
                <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-800">
                    <thead class="bg-slate-50 dark:bg-slate-950">
                        <tr>
                            <th class="px-4 py-3 text-start text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('name') }}</th>
                            <th class="px-4 py-3 text-end text-xs font-semibold uppercase text-slate-500 dark:text-slate-400">{{ t('actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        <tr v-for="loc in locations" :key="loc.id">
                            <td class="px-4 py-3">
                                <TextInput v-if="editingId === loc.id" v-model="editForm.name" class="w-full" />
                                <span v-else class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ loc.name }}</span>
                            </td>
                            <td class="px-4 py-3 text-end">
                                <div v-if="editingId === loc.id" class="flex justify-end gap-2">
                                    <button @click="saveEdit(loc.id)" class="text-sm text-primary-600 hover:text-primary-800">{{ t('save') }}</button>
                                    <button @click="editingId = null" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('cancel') }}</button>
                                </div>
                                <div v-else class="flex justify-end gap-2">
                                    <button @click="startEdit(loc)" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">{{ t('edit') }}</button>
                                    <button @click="deleteId = loc.id" class="text-sm text-rose-500 hover:text-rose-700">{{ t('delete') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!locations?.length">
                            <td colspan="2" class="px-4 py-8 text-center text-sm text-slate-500 dark:text-slate-400">{{ t('no_results') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <ConfirmModal :show="!!deleteId" :message="t('are_you_sure')" @confirm="destroy" @cancel="deleteId = null" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Add the nav entry**

In `resources/js/Layouts/AuthenticatedLayout.vue`, right after the `equipment_categories` nav item (line 89), add:

```js
                { label: t('storage_locations'), href: '/storage-locations', icon: 'equipment', prefix: '/storage-locations' },
```

- [ ] **Step 3: Add i18n keys**

In `resources/js/i18n/en.json`, `fr.json`, `ar.json`, add (skip a file that already has the key):
- en: `"storage_locations": "Storage Locations"`, `"storage_location": "Storage Location"`
- fr: `"storage_locations": "Emplacements de stockage"`, `"storage_location": "Emplacement de stockage"`
- ar: `"storage_locations": "أماكن التخزين"`, `"storage_location": "مكان التخزين"`

- [ ] **Step 4: Build the frontend**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 5: Verify JSON validity**

Run: `node -e "['en','fr','ar'].forEach(l=>JSON.parse(require('fs').readFileSync('resources/js/i18n/'+l+'.json')))"`
Expected: no output / no error.

- [ ] **Step 6: Manual verification (deferred to controller — no browser)**

Confirm the Settings → Storage Locations page adds/edits/deletes locations.

- [ ] **Step 7: Commit**

```bash
git add resources/js/Pages/Settings/StorageLocations.vue resources/js/Layouts/AuthenticatedLayout.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: storage locations settings page + nav"
```

---

### Task 3: Item Location dropdown

**Files:**
- Modify: `app/Http/Controllers/EquipmentCatalogController.php` (`show`, lines 44-55)
- Modify: `resources/js/Pages/Equipment/Catalog/Show.vue`
- Test: `tests/Feature/EquipmentItemManagementTest.php` (extend)

**Interfaces:**
- Consumes: `StorageLocation` (Task 1).
- Produces: `Equipment/Catalog/Show` receives a `storageLocations` prop (array of names).

- [ ] **Step 1: Write the failing test (append to `tests/Feature/EquipmentItemManagementTest.php`)**

Add these imports if not present:

```php
use App\Models\StorageLocation;
use Inertia\Testing\AssertableInertia;
```

Add this method:

```php
    #[Test]
    public function the_catalog_page_exposes_storage_locations(): void
    {
        StorageLocation::create(['name' => 'Storage 01']);
        StorageLocation::create(['name' => 'Storage 02']);
        $catalog = $this->catalog();

        $this->actingAs($this->user())
            ->get(route('equipment.catalogs.show', $catalog))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Equipment/Catalog/Show')
                ->where('storageLocations', ['Storage 01', 'Storage 02']));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EquipmentItemManagementTest`
Expected: FAIL — `storageLocations` prop not present.

- [ ] **Step 3: Pass `storageLocations` from `show`**

In `app/Http/Controllers/EquipmentCatalogController.php`, add the import:

```php
use App\Models\StorageLocation;
```

In the `show` method's `Inertia::render('Equipment/Catalog/Show', [...])` array, add:

```php
            'storageLocations' => StorageLocation::orderBy('name')->pluck('name'),
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EquipmentItemManagementTest`
Expected: PASS.

- [ ] **Step 5: Use the prop and replace the Location inputs with selects**

In `resources/js/Pages/Equipment/Catalog/Show.vue`:

Add `storageLocations` to `defineProps`:

```js
const props = defineProps({
    catalog: Object,
    availableCount: Number,
    storageLocations: { type: Array, default: () => [] },
});
```

Add a computed that includes an item's legacy location value (for the edit modal), after the `editItemForm` block:

```js
const editLocationOptions = computed(() => {
    const opts = [...props.storageLocations];
    const current = editItemForm.location;
    if (current && !opts.includes(current)) opts.unshift(current);
    return opts;
});
```

Import `computed` alongside the existing Vue imports:

```js
import { ref, watch, computed } from 'vue';
```

In the **add-item** modal, replace the location `TextInput` (currently `<TextInput v-model="addItemForm.location" ... />`) with a select. If the add modal has no location field yet, add this block after the notes field:

```html
                        <div>
                            <InputLabel :value="t('location')" />
                            <select v-model="addItemForm.location" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">—</option>
                                <option v-for="loc in storageLocations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>
```

In the **edit-item** modal, replace the location `TextInput` (`<TextInput v-model="editItemForm.location" ... />`) with:

```html
                        <div>
                            <InputLabel :value="t('location')" />
                            <select v-model="editItemForm.location" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                <option value="">—</option>
                                <option v-for="loc in editLocationOptions" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
                        </div>
```

- [ ] **Step 6: Build the frontend**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 7: Manual verification (deferred — no browser)**

Confirm the add/edit item Location field is now a dropdown of storage locations, and editing an item whose location isn't managed keeps its value.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/EquipmentCatalogController.php resources/js/Pages/Equipment/Catalog/Show.vue tests/Feature/EquipmentItemManagementTest.php
git commit -m "feat: item location as storage-location dropdown"
```

---

### Task 4: Inventory participants backend

**Files:**
- Create: `database/migrations/2026_07_07_000003_create_inventory_session_participants_table.php`
- Create: `app/Models/InventorySessionParticipant.php`
- Modify: `app/Models/InventorySession.php` (add `participants` relation)
- Modify: `app/Http/Controllers/InventoryController.php` (`show` props + `participants` action)
- Modify: `routes/web.php` (participants route in the auth group)
- Test: `tests/Feature/InventoryParticipantsTest.php`

**Interfaces:**
- Consumes: `StorageLocation` (Task 1).
- Produces: route `inventory.participants` (POST `/equipment/stocktake/{session}/participants`); `InventorySession::participants()` HasMany; `Inventory/Session` receives `storageLocations`, `users`, `players`, and `session.participants`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/InventoryParticipantsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\InventorySession;
use App\Models\Player;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::factory()->create(['email_verified_at' => now()]);
    }

    private function session(string $status = 'in_progress'): InventorySession
    {
        return InventorySession::create([
            'reference' => 'INV-T-'.uniqid(),
            'type' => 'ad_hoc',
            'session_date' => '2026-05-01',
            'status' => $status,
        ]);
    }

    #[Test]
    public function it_syncs_a_mixed_set_of_user_and_player_participants(): void
    {
        $session = $this->session();
        $staff = User::factory()->create();
        $player = Player::create(['membership_id' => 'M-0001', 'firstname' => 'Ali']);

        $this->actingAs($this->user())
            ->post(route('inventory.participants', $session), [
                'participants' => [
                    ['type' => 'User', 'id' => $staff->id],
                    ['type' => 'Player', 'id' => $player->id],
                ],
            ])->assertRedirect();

        $this->assertSame(2, $session->participants()->count());
        $this->assertDatabaseHas('inventory_session_participants', [
            'inventory_session_id' => $session->id,
            'participant_type' => User::class,
            'participant_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('inventory_session_participants', [
            'inventory_session_id' => $session->id,
            'participant_type' => Player::class,
            'participant_id' => $player->id,
        ]);
    }

    #[Test]
    public function reposting_replaces_the_participant_set(): void
    {
        $session = $this->session();
        $a = User::factory()->create();
        $b = User::factory()->create();

        $this->actingAs($this->user())->post(route('inventory.participants', $session), [
            'participants' => [['type' => 'User', 'id' => $a->id], ['type' => 'User', 'id' => $b->id]],
        ]);
        $this->assertSame(2, $session->participants()->count());

        $this->actingAs($this->user())->post(route('inventory.participants', $session), [
            'participants' => [['type' => 'User', 'id' => $a->id]],
        ]);
        $this->assertSame(1, $session->fresh()->participants()->count());
    }

    #[Test]
    public function participants_cannot_be_set_on_a_completed_session(): void
    {
        $session = $this->session('completed');
        $staff = User::factory()->create();

        $this->actingAs($this->user())
            ->post(route('inventory.participants', $session), [
                'participants' => [['type' => 'User', 'id' => $staff->id]],
            ])->assertForbidden();

        $this->assertSame(0, $session->participants()->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=InventoryParticipantsTest`
Expected: FAIL — route `inventory.participants` not defined.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_07_000003_create_inventory_session_participants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_session_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_session_id')->constrained('inventory_sessions')->cascadeOnDelete();
            $table->morphs('participant');
            $table->timestamps();

            $table->unique(
                ['inventory_session_id', 'participant_type', 'participant_id'],
                'inv_session_participant_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_session_participants');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/InventorySessionParticipant.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class InventorySessionParticipant extends Model
{
    protected $fillable = ['inventory_session_id', 'participant_type', 'participant_id'];

    public function participant(): MorphTo
    {
        return $this->morphTo();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InventorySession::class, 'inventory_session_id');
    }
}
```

- [ ] **Step 5: Add the `participants` relation to `InventorySession`**

In `app/Models/InventorySession.php`, add (with `use Illuminate\Database\Eloquent\Relations\HasMany;` at the top if not present):

```php
    public function participants(): HasMany
    {
        return $this->hasMany(InventorySessionParticipant::class);
    }
```

- [ ] **Step 6: Add the `participants` action + extend `show`**

In `app/Http/Controllers/InventoryController.php`, add these imports:

```php
use App\Models\Player;
use App\Models\StorageLocation;
use App\Models\User;
```

Replace the `show` method body with:

```php
    public function show(InventorySession $session): Response
    {
        $session->load(['items.item.catalog:id,name,category', 'conductedBy:id,name', 'participants.participant']);

        return Inertia::render('Inventory/Session', [
            'session' => $session,
            'conditions' => self::CONDITIONS,
            'storageLocations' => StorageLocation::orderBy('name')->pluck('name'),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'players' => Player::orderBy('lastname')->orderBy('firstname')->get(['id', 'firstname', 'lastname']),
        ]);
    }
```

Add the `participants` action (after `counts`):

```php
    public function participants(Request $request, InventorySession $session): RedirectResponse
    {
        abort_if($session->status !== 'in_progress', 403, 'This inventory is already closed.');

        $data = $request->validate([
            'participants' => ['present', 'array'],
            'participants.*.type' => ['required', Rule::in(['User', 'Player'])],
            'participants.*.id' => ['required', 'integer'],
        ]);

        DB::transaction(function () use ($session, $data) {
            $session->participants()->delete();

            $seen = [];
            foreach ($data['participants'] as $p) {
                $class = $p['type'] === 'User' ? User::class : Player::class;
                $key = $class.':'.$p['id'];
                if (isset($seen[$key]) || ! $class::whereKey($p['id'])->exists()) {
                    continue;
                }
                $seen[$key] = true;
                $session->participants()->create([
                    'participant_type' => $class,
                    'participant_id' => $p['id'],
                ]);
            }
        });

        return back()->with('success', 'Participants updated.');
    }
```

- [ ] **Step 7: Register the route**

In `routes/web.php`, right after the `inventory.complete` route (line ~122), in the same (auth) group, add:

```php
    Route::post('/equipment/stocktake/{session}/participants', [InventoryController::class, 'participants'])->name('inventory.participants');
```

- [ ] **Step 8: Run test to verify it passes**

Run: `php artisan test --filter=InventoryParticipantsTest`
Expected: PASS (3 tests)

- [ ] **Step 9: Run the equipment/inventory suite (no regressions)**

Run: `php artisan test --filter=Inventory`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add database/migrations/2026_07_07_000003_create_inventory_session_participants_table.php app/Models/InventorySessionParticipant.php app/Models/InventorySession.php app/Http/Controllers/InventoryController.php routes/web.php tests/Feature/InventoryParticipantsTest.php
git commit -m "feat: inventory session participants (backend + sync endpoint)"
```

---

### Task 5: Inventory Session page — location grouping, actual-location dropdown, participants panel

**Files:**
- Modify: `resources/js/Pages/Inventory/Session.vue`
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`

**Interfaces:**
- Consumes: `storageLocations`, `users`, `players`, `session.participants` (Task 4); `inventory.participants` route.

- [ ] **Step 1: Add the new props**

In `resources/js/Pages/Inventory/Session.vue`, extend `defineProps`:

```js
const props = defineProps({
    session: { type: Object, required: true },
    conditions: { type: Array, default: () => [] },
    storageLocations: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    players: { type: Array, default: () => [] },
});
```

- [ ] **Step 2: Group the count sheet by location instead of catalog**

Replace the existing `groups` computed (grouping by catalog name) with grouping by `expected_location`:

```js
const groups = computed(() => {
    const map = {};
    for (const line of props.session.items) {
        const key = line.expected_location || t('unassigned');
        (map[key] ||= []).push(line);
    }
    // Sort location keys alphabetically, keeping "Unassigned" last.
    return Object.fromEntries(
        Object.entries(map).sort(([a], [b]) => {
            if (a === t('unassigned')) return 1;
            if (b === t('unassigned')) return -1;
            return a.localeCompare(b);
        })
    );
});
```

In the count-sheet `<section v-for="(lines, catalog) in groups">`, rename the loop variable to `location` and show a count, and add the catalog name to each line's subtitle. Change the section header line to:

```html
                <section v-for="(lines, location) in groups" :key="location" class="card overflow-hidden">
                    <p class="border-b border-slate-100 px-5 py-2.5 text-sm font-bold text-slate-700 dark:border-slate-800 dark:text-slate-200">{{ location }} <span class="font-normal text-slate-400">({{ lines.length }})</span></p>
```

And change each line's subtitle `<p class="text-xs text-slate-400">` to include the catalog name:

```html
                                <p class="text-xs text-slate-400">{{ line.item?.catalog?.name }} · {{ t('expected') }}: {{ line.expected_condition }}<span v-if="line.expected_location"> · {{ line.expected_location }}</span></p>
```

- [ ] **Step 3: Make `actual_location` a dropdown**

Replace the `actual_location` `<input>` in the count row with a select:

```html
                            <select v-model="state[line.id].actual_location" :disabled="!state[line.id].found" class="w-32 rounded-lg border-slate-200 bg-white py-1 text-xs disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800">
                                <option value="">{{ t('location') }}</option>
                                <option v-for="loc in storageLocations" :key="loc" :value="loc">{{ loc }}</option>
                            </select>
```

- [ ] **Step 4: Add participants state + save handler**

In the `<script setup>`, after the existing state setup, add:

```js
import SearchableSelect from '@/Components/SearchableSelect.vue';

const participantType = ref('User');
const participantPick = ref('');
const participants = ref(
    (props.session.participants || []).map((p) => ({
        type: p.participant_type?.includes('Player') ? 'Player' : 'User',
        id: p.participant_id,
        label: p.participant_type?.includes('Player')
            ? `${p.participant?.firstname ?? ''} ${p.participant?.lastname ?? ''}`.trim()
            : (p.participant?.name ?? `#${p.participant_id}`),
    }))
);

const participantOptions = computed(() => {
    const list = participantType.value === 'User'
        ? props.users.map((u) => ({ value: u.id, label: u.name }))
        : props.players.map((pl) => ({ value: pl.id, label: `${pl.firstname} ${pl.lastname ?? ''}`.trim() }));
    const taken = new Set(participants.value.filter((p) => p.type === participantType.value).map((p) => p.id));
    return list.filter((o) => !taken.has(o.value));
});

function addParticipant() {
    if (!participantPick.value) return;
    const opt = participantOptions.value.find((o) => String(o.value) === String(participantPick.value));
    if (!opt) return;
    participants.value.push({ type: participantType.value, id: opt.value, label: opt.label });
    participantPick.value = '';
}
function removeParticipant(idx) {
    participants.value.splice(idx, 1);
}
function saveParticipants() {
    router.post(route('inventory.participants', props.session.id), {
        participants: participants.value.map((p) => ({ type: p.type, id: p.id })),
    }, { preserveScroll: true });
}
```

Add `ref` and `computed` to the existing `vue` import if not already present:

```js
import { reactive, computed, ref } from 'vue';
```

- [ ] **Step 5: Add the participants panel to the template**

Add this card at the top of the `<div class="space-y-6">` (before the Summary grid), so it shows for both open and completed sessions:

```html
            <!-- Participants -->
            <div class="card p-5">
                <p class="mb-3 text-sm font-bold text-slate-700 dark:text-slate-200">{{ t('participants') }}</p>
                <div class="flex flex-wrap gap-2">
                    <span v-for="(p, idx) in participants" :key="p.type + p.id" class="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1 text-sm text-slate-700 dark:bg-slate-800 dark:text-slate-200">
                        {{ p.label }}
                        <button v-if="isOpen" @click="removeParticipant(idx)" class="text-slate-400 hover:text-rose-500">×</button>
                    </span>
                    <span v-if="!participants.length" class="text-sm text-slate-400">{{ t('no_results') }}</span>
                </div>
                <div v-if="isOpen" class="mt-4 flex flex-wrap items-end gap-2">
                    <select v-model="participantType" class="rounded-lg border-slate-200 bg-white py-1.5 text-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="User">{{ t('staff') }}</option>
                        <option value="Player">{{ t('member') }}</option>
                    </select>
                    <div class="min-w-[12rem] flex-1">
                        <SearchableSelect v-model="participantPick" :options="participantOptions" :placeholder="t('add_participant')" />
                    </div>
                    <button @click="addParticipant" class="rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-slate-600 ring-1 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-800">{{ t('add') }}</button>
                    <button @click="saveParticipants" class="rounded-xl bg-slate-900 px-3 py-1.5 text-sm font-bold text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900">{{ t('save') }}</button>
                </div>
            </div>
```

Note: confirm `SearchableSelect` accepts a `placeholder` prop; if it does not, drop the `:placeholder` binding (the component still works without it).

- [ ] **Step 6: Add i18n keys**

In `resources/js/i18n/en.json`, `fr.json`, `ar.json`, add (skip existing):
- en: `"participants": "Participants"`, `"add_participant": "Add participant"`, `"staff": "Staff"`, `"member": "Member"`, `"unassigned": "Unassigned"`
- fr: `"participants": "Participants"`, `"add_participant": "Ajouter un participant"`, `"staff": "Personnel"`, `"member": "Membre"`, `"unassigned": "Non attribué"`
- ar: `"participants": "المشاركون"`, `"add_participant": "إضافة مشارك"`, `"staff": "الطاقم"`, `"member": "عضو"`, `"unassigned": "غير محدد"`

- [ ] **Step 7: Build the frontend**

Run: `npm run build`
Expected: builds with no errors.

- [ ] **Step 8: Verify JSON validity**

Run: `node -e "['en','fr','ar'].forEach(l=>JSON.parse(require('fs').readFileSync('resources/js/i18n/'+l+'.json')))"`
Expected: no output / no error.

- [ ] **Step 9: Manual verification (deferred — no browser)**

Confirm: the count sheet groups by location; `actual_location` is a dropdown; a participants panel lets you add staff/members and save; completed sessions show participants read-only.

- [ ] **Step 10: Commit**

```bash
git add resources/js/Pages/Inventory/Session.vue resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: inventory session location grouping + participants panel"
```

---

### Task 6: Participants in the PDF report

**Files:**
- Modify: `app/Http/Controllers/ReportController.php` (`inventoryReport`, load participants)
- Modify: `resources/views/pdf/inventory-report.blade.php`

**Interfaces:**
- Consumes: `InventorySession::participants` (Task 4).

- [ ] **Step 1: Eager-load participants in the report controller**

In `app/Http/Controllers/ReportController.php`, in `inventoryReport` (line ~97), ensure the session loads participants. Find the existing `$session->load([...])` (or add one) and include `'participants.participant'`. If the method has no `load`, add right after the signature:

```php
        $session->load(['items.item.catalog:id,name', 'conductedBy:id,name', 'participants.participant']);
```

(If a `load` already exists, add `'participants.participant'` to its array rather than duplicating.)

- [ ] **Step 2: Render participants in the blade**

In `resources/views/pdf/inventory-report.blade.php`, right after the closing `</div>` of the `.sub` block (line 38), add:

```blade
    @if ($session->participants->isNotEmpty())
        <div class="sub">
            {{ __('Participants') }}:
            @foreach ($session->participants as $p)
                {{ $p->participant instanceof \App\Models\Player
                    ? trim(($p->participant->firstname ?? '').' '.($p->participant->lastname ?? ''))
                    : ($p->participant->name ?? '') }}@if (! $loop->last), @endif
            @endforeach
        </div>
    @endif
```

- [ ] **Step 3: Smoke-test the report renders**

Run: `php artisan test --filter=Inventory`
Expected: PASS (no regression; existing inventory tests still green).

- [ ] **Step 4: Manual verification (deferred — no browser)**

Open a session's report PDF and confirm the participants line appears when participants are set.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/views/pdf/inventory-report.blade.php
git commit -m "feat: show inventory participants on the PDF report"
```

---

## Final verification

- [ ] `php artisan test` — full suite green.
- [ ] `npm run build` — succeeds.
- [ ] Manual end-to-end: add storage locations in Settings → pick one as an item's location → start an inventory (count sheet grouped by location, actual-location dropdown) → add staff/member participants → complete → participants show on the report.

## Notes / risks

- `equipment_items.location` remains a free string; the dropdown only constrains new UI input. Legacy/Arabic location strings stay valid and are preserved in the edit modal via `editLocationOptions`.
- Participant morph types are stored as full class names (`App\Models\User` / `App\Models\Player`); the frontend distinguishes them by substring (`includes('Player')`). This is consistent with the app having no morph map.
- Deleting a storage location does not touch items that already reference its name (it's a string, not a FK) — the name simply disappears from the dropdown.
- The count sheet's grouping changed from catalog to location; the catalog name is preserved per-line in the subtitle so no information is lost.
