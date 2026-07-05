# Player Form Enhancement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enhance the player create/edit form with dependent Algeria wilaya→city pickers (bilingual wilaya labels), `year+NNNNN` sequential-per-year membership IDs, job gated on worker status, exposed `join_year`/`team`/`skill_level` fields, image preview, searchable selects, and a shared `PlayerForm` component.

**Architecture:** One membership-number helper feeds both the backend generator and the form's live preview. `PlayerController` passes the Algeria geo data + a per-year next-sequence map. A single shared `Players/Partials/PlayerForm.vue` (rendered by thin Create/Edit wrappers) holds all fields, the dependent wilaya→city `SearchableSelect`s, the worker-gated Job field, the membership preview, and the image preview.

**Tech Stack:** Laravel 12 (PHP 8.2+), PHPUnit 12 (`php artisan test`), Inertia + Vue 3 (`<script setup>`), Tailwind, vue-i18n. No new dependencies.

## Global Constraints

- Membership ID format: `sprintf('%04d%05d', $year, $seq)` → `YYYYNNNNN` (9 chars, fits `membership_id string(10)`). Sequence is per-join-year, `max existing suffix for that year + 1`, `1` if none. Generated authoritatively on save; client value never trusted on create.
- Stored `state` = wilaya French/Latin `name`; `city` = commune French/Latin name. No schema change.
- Wilaya option label is bilingual: `` `${name} — ${ar_name}` ``. Commune labels are French/Latin (data has no Arabic communes).
- `email` is optional (already `nullable` in both requests); no `required` attribute/asterisk on it.
- Required marker (asterisk) only on `firstname`. Do NOT hard-`required` birthdate or category in the UI (server rules are `nullable`).
- Reuse `@/Components/SearchableSelect.vue` (`modelValue`, `options:[{value,label}]`, `placeholder`, `disabled`; emits `update:modelValue`). No new deps.
- Vue user-facing strings via `t()`; add missing keys to en/fr/ar.

---

## File Structure

- Create `app/Services/Player/MembershipNumber.php` — `nextSequence(int $year): int`, `format(int $year, int $seq): string`.
- Modify `app/Services/Player/RegisterPlayerService.php` — use `MembershipNumber` in `generateMembershipId`.
- Modify `app/Http/Controllers/PlayerController.php` — `algeriaGeo()`, `nextSequenceByYear()`, pass new props from `create()`/`edit()`.
- Create `resources/js/Pages/Players/Partials/PlayerForm.vue` — the whole shared form.
- Modify `resources/js/Pages/Players/Create.vue`, `Edit.vue` — thin wrappers rendering `PlayerForm`.
- Modify `resources/js/i18n/en.json`, `fr.json`, `ar.json` — new keys.
- Test: `tests/Feature/MembershipNumberTest.php`, update `tests/Feature/Services/RegisterPlayerServiceTest.php`, `tests/Feature/PlayerFormPropsTest.php`.

---

### Task 1: Membership number helper + generator

**Files:**
- Create: `app/Services/Player/MembershipNumber.php`
- Modify: `app/Services/Player/RegisterPlayerService.php`
- Test: `tests/Feature/MembershipNumberTest.php`, `tests/Feature/Services/RegisterPlayerServiceTest.php`

**Interfaces:**
- Produces: `App\Services\Player\MembershipNumber::nextSequence(int $year): int` (max existing 5+-digit suffix for that year + 1; 1 if none), `::format(int $year, int $seq): string` (`YYYYNNNNN`).

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/MembershipNumberTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Services\Player\MembershipNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function format_builds_year_plus_five_digits(): void
    {
        $this->assertSame('202600001', MembershipNumber::format(2026, 1));
        $this->assertSame('202600042', MembershipNumber::format(2026, 42));
    }

    #[Test]
    public function next_sequence_is_one_for_an_empty_year(): void
    {
        $this->assertSame(1, MembershipNumber::nextSequence(2026));
    }

    #[Test]
    public function next_sequence_increments_within_a_year_and_is_independent_per_year(): void
    {
        Player::create(['membership_id' => '202600001', 'firstname' => 'A']);
        Player::create(['membership_id' => '202600002', 'firstname' => 'B']);

        $this->assertSame(3, MembershipNumber::nextSequence(2026));
        $this->assertSame(1, MembershipNumber::nextSequence(2027));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=MembershipNumberTest`
Expected: FAIL (class `MembershipNumber` not found).

- [ ] **Step 3: Create the helper**

Create `app/Services/Player/MembershipNumber.php`:

```php
<?php

namespace App\Services\Player;

use App\Models\Player;

class MembershipNumber
{
    /** Next per-year sequence: highest existing suffix for that year + 1 (1 if none). */
    public static function nextSequence(int $year): int
    {
        $prefix = sprintf('%04d', $year);

        $max = (int) Player::query()
            ->where('membership_id', 'like', $prefix.'%')
            ->selectRaw('MAX(CAST(SUBSTR(membership_id, 5) AS INTEGER)) as m')
            ->value('m');

        return $max + 1;
    }

    /** Format a membership id as YYYYNNNNN (4-digit year + 5-digit zero-padded sequence). */
    public static function format(int $year, int $seq): string
    {
        return sprintf('%04d%05d', $year, $seq);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=MembershipNumberTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Use the helper in `RegisterPlayerService`**

In `app/Services/Player/RegisterPlayerService.php`, add `use App\Services\Player\MembershipNumber;` and replace the `generateMembershipId` method body:

```php
private function generateMembershipId(int $joinYear): string
{
    do {
        $candidate = MembershipNumber::format($joinYear, MembershipNumber::nextSequence($joinYear));
    } while (Player::query()->where('membership_id', $candidate)->exists());

    return $candidate;
}
```

- [ ] **Step 6: Add a format assertion to the existing registration test**

In `tests/Feature/Services/RegisterPlayerServiceTest.php`, inside `it_creates_player_and_assigns_mandatory_subscription_for_matching_category`, after the player is created, add:

```php
        $this->assertSame(
            (int) now()->year.'00001',
            $player->membership_id,
            'first player of the year gets sequence 00001',
        );
```

(The test already passes `join_year => (int) now()->year`.)

- [ ] **Step 7: Run both tests**

Run: `php artisan test --filter=MembershipNumberTest && php artisan test --filter=RegisterPlayerServiceTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Player/MembershipNumber.php app/Services/Player/RegisterPlayerService.php tests/Feature/MembershipNumberTest.php tests/Feature/Services/RegisterPlayerServiceTest.php
git commit -m "feat: year+NNNNN sequential-per-year membership ids"
```

---

### Task 2: PlayerController passes geo data + next-sequence map

**Files:**
- Modify: `app/Http/Controllers/PlayerController.php`
- Test: `tests/Feature/PlayerFormPropsTest.php`

**Interfaces:**
- Consumes: `MembershipNumber::nextSequence`.
- Produces: `create()`/`edit()` Inertia props `wilayas` (`[{id,name,ar_name}]`), `communes` (`{wilaya_id:[name,…]}`), `nextSequenceByYear` (`{year:int}`), `defaultJoinYear` (int). `edit()` also passes `player`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerFormPropsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerFormPropsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['privileges' => ['admin'], 'is_active' => true, 'email_verified_at' => now()]);
    }

    #[Test]
    public function create_page_exposes_geo_and_sequence_props(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Players/Create')
                ->has('wilayas', 58)
                ->has('wilayas.0', fn (AssertableInertia $w) => $w->has('id')->has('name')->has('ar_name'))
                ->has('communes')
                ->where('defaultJoinYear', (int) now()->year)
                ->where('nextSequenceByYear.'.now()->year, 1));
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=PlayerFormPropsTest`
Expected: FAIL (no `wilayas`/`communes`/`defaultJoinYear` props).

- [ ] **Step 3: Add helpers + wire create/edit**

In `app/Http/Controllers/PlayerController.php`, add `use App\Services\Player\MembershipNumber;`, then add two private helpers:

```php
/**
 * Algeria wilayas (bilingual) + communes keyed by wilaya id, from the seed json.
 *
 * @return array{wilayas: array<int, array{id:int,name:string,ar_name:string}>, communes: array<int|string, array<int,string>>}
 */
private function algeriaGeo(): array
{
    $data = json_decode(File::get(database_path('seeders/algeria_wilayas.json')), true);

    $wilayas = collect($data['states'] ?? [])
        ->map(fn ($s) => ['id' => (int) $s['id'], 'name' => $s['name'], 'ar_name' => $s['ar_name']])
        ->values()->all();

    return ['wilayas' => $wilayas, 'communes' => $data['communes'] ?? []];
}

/**
 * Next membership sequence per join year (years present in players + current year).
 *
 * @return array<int, int>
 */
private function nextSequenceByYear(): array
{
    $years = Player::query()->whereNotNull('join_year')->distinct()->pluck('join_year')
        ->push((int) now()->year)->unique();

    $map = [];
    foreach ($years as $year) {
        $map[(int) $year] = MembershipNumber::nextSequence((int) $year);
    }

    return $map;
}
```

Replace the `create()` render props:

```php
public function create(): Response
{
    $geo = $this->algeriaGeo();

    return Inertia::render('Players/Create', [
        'categories' => Category::orderBy('name')->get(),
        'positions' => Position::orderBy('name')->get(),
        'jobs' => MemberJob::orderBy('name')->get(),
        'wilayas' => $geo['wilayas'],
        'communes' => $geo['communes'],
        'nextSequenceByYear' => $this->nextSequenceByYear(),
        'defaultJoinYear' => (int) now()->year,
    ]);
}
```

Replace the `edit()` render props (keep the existing `$player->load(['emergencyContacts'])`):

```php
public function edit(Player $player): Response
{
    $player->load(['emergencyContacts']);
    $geo = $this->algeriaGeo();

    return Inertia::render('Players/Edit', [
        'player' => $player,
        'categories' => Category::orderBy('name')->get(),
        'positions' => Position::orderBy('name')->get(),
        'jobs' => MemberJob::orderBy('name')->get(),
        'wilayas' => $geo['wilayas'],
        'communes' => $geo['communes'],
        'nextSequenceByYear' => $this->nextSequenceByYear(),
        'defaultJoinYear' => (int) now()->year,
    ]);
}
```

Delete the now-unused `getAlgeriaStates()` method.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=PlayerFormPropsTest`
Expected: PASS.

- [ ] **Step 5: Run the player suite (regression)**

Run: `php artisan test --filter=Player`
Expected: PASS (no other test depended on the removed `states` prop shape).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/PlayerController.php tests/Feature/PlayerFormPropsTest.php
git commit -m "feat: pass Algeria geo + next-sequence map to player form"
```

---

### Task 3: Shared `PlayerForm` component + Create/Edit wrappers

**Files:**
- Create: `resources/js/Pages/Players/Partials/PlayerForm.vue`
- Modify: `resources/js/Pages/Players/Create.vue`, `resources/js/Pages/Players/Edit.vue`
- Test: `tests/Feature/PlayerFormPropsTest.php` (add a store test)

**Interfaces:**
- Consumes: props `player` (nullable Object), `categories`, `positions`, `jobs`, `wilayas`, `communes`, `nextSequenceByYear`, `defaultJoinYear`; `@/Components/SearchableSelect.vue`.
- Produces: a form that submits to `players.store` / `players.update` with the existing payload shape plus `join_year`, `team`, `skill_level` (and `archived` on edit).

- [ ] **Step 1: Create `PlayerForm.vue`**

Create `resources/js/Pages/Players/Partials/PlayerForm.vue`:

```vue
<script setup>
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    player: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    positions: { type: Array, default: () => [] },
    jobs: { type: Array, default: () => [] },
    wilayas: { type: Array, default: () => [] },
    communes: { type: Object, default: () => ({}) },
    nextSequenceByYear: { type: Object, default: () => ({}) },
    defaultJoinYear: { type: Number, default: () => new Date().getFullYear() },
});

const isEdit = !!props.player;
const p = props.player || {};

const form = useForm({
    firstname: p.firstname || '',
    lastname: p.lastname || '',
    father: p.father || '',
    grandfather: p.grandfather || '',
    nickname: p.nickname || '',
    birthdate: p.birthdate ? String(p.birthdate).split('T')[0] : '',
    gender: p.gender || 'Male',
    health_blood_group_rhesus: p.health_blood_group_rhesus || '',
    phone: p.phones?.[0] || '',
    email: p.email || '',
    state: p.state || '',
    city: p.city || '',
    is_student: p.is_student ?? true,
    category_id: p.category_id || '',
    position_id: p.position_id || '',
    member_job_id: p.member_job_id || '',
    join_year: p.join_year || props.defaultJoinYear,
    team: p.team || '',
    skill_level: p.skill_level || '',
    picture: null,
    health_medical_conditions: p.health_medical_conditions || '',
    emergency_contact_name: p.emergency_contacts?.[0]?.name || '',
    emergency_contact_phone: p.emergency_contacts?.[0]?.phones?.[0] || '',
    emergency_contact_relationship: p.emergency_contacts?.[0]?.relationship || '',
    archived: p.archived || false,
});

// --- Membership id preview ---
const pad5 = (n) => String(n).padStart(5, '0');
const membershipPreview = computed(() => {
    if (isEdit) return p.membership_id || '';
    const seq = props.nextSequenceByYear[form.join_year] ?? 1;
    return `${form.join_year}-${pad5(seq)}`;
});

// --- Wilaya -> city dependency ---
const wilayaOptions = computed(() => {
    const opts = props.wilayas.map((w) => ({ value: w.name, label: `${w.name} — ${w.ar_name}` }));
    if (form.state && !opts.some((o) => o.value === form.state)) opts.unshift({ value: form.state, label: form.state });
    return opts;
});
const selectedWilayaId = computed(() => props.wilayas.find((w) => w.name === form.state)?.id ?? null);
const cityList = computed(() => (selectedWilayaId.value != null ? (props.communes[selectedWilayaId.value] || []) : []));
const hasCityList = computed(() => cityList.value.length > 0);
const cityOptions = computed(() => {
    const opts = cityList.value.map((c) => ({ value: c, label: c }));
    if (form.city && !opts.some((o) => o.value === form.city)) opts.unshift({ value: form.city, label: form.city });
    return opts;
});
// reset city when wilaya changes (fires only on change, not initial mount)
watch(() => form.state, () => { form.city = ''; });

// --- Job gated on worker ---
watch(() => form.is_student, (student) => { if (student) form.member_job_id = ''; });

// --- Image preview ---
const previewUrl = ref(null);
function setPicture(file) {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    form.picture = file || null;
    previewUrl.value = file ? URL.createObjectURL(file) : null;
}
function onFileChange(e) { setPicture(e.target.files?.[0]); }
function onDrop(e) { setPicture(e.dataTransfer.files?.[0]); }
function clearPicture() { setPicture(null); }
const shownImage = computed(() => previewUrl.value || (isEdit ? p.picture_url : null));
onBeforeUnmount(() => { if (previewUrl.value) URL.revokeObjectURL(previewUrl.value); });

function submit() {
    form.transform((data) => ({
        ...(isEdit ? { _method: 'put' } : {}),
        firstname: data.firstname,
        lastname: data.lastname,
        father: data.father,
        grandfather: data.grandfather,
        nickname: data.nickname,
        birthdate: data.birthdate || null,
        gender: data.gender,
        health_blood_group_rhesus: data.health_blood_group_rhesus || null,
        phones: data.phone ? [data.phone] : [],
        email: data.email || null,
        state: data.state || null,
        city: data.city || null,
        is_student: data.is_student,
        category_id: data.category_id || null,
        position_id: data.position_id || null,
        member_job_id: data.member_job_id || null,
        join_year: data.join_year || null,
        team: data.team || null,
        skill_level: data.skill_level || null,
        picture: data.picture,
        health_medical_conditions: data.health_medical_conditions || null,
        emergency_contacts: data.emergency_contact_name ? [{
            name: data.emergency_contact_name,
            relationship: data.emergency_contact_relationship || null,
            phones: data.emergency_contact_phone ? [data.emergency_contact_phone] : [],
        }] : [],
        ...(isEdit ? { archived: data.archived } : {}),
    })).post(isEdit ? route('players.update', p.id) : route('players.store'), { forceFormData: true });
}

const cancelHref = computed(() => (isEdit ? route('players.show', p.id) : route('players.index')));
</script>

<template>
    <form @submit.prevent="submit" class="space-y-6">
        <!-- Basic info -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('basic_info') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <InputLabel :value="t('firstname') + ' *'" />
                    <TextInput v-model="form.firstname" class="mt-1 w-full" required />
                    <InputError :message="form.errors.firstname" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('lastname')" />
                    <TextInput v-model="form.lastname" class="mt-1 w-full" />
                    <InputError :message="form.errors.lastname" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('nickname')" />
                    <TextInput v-model="form.nickname" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('father')" />
                    <TextInput v-model="form.father" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('grandfather')" />
                    <TextInput v-model="form.grandfather" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('date_of_birth')" />
                    <TextInput v-model="form.birthdate" type="date" class="mt-1 w-full" />
                    <InputError :message="form.errors.birthdate" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('gender')" />
                    <select v-model="form.gender" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="Male">{{ t('male') }}</option>
                        <option value="Female">{{ t('female') }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('blood_group')" />
                    <select v-model="form.health_blood_group_rhesus" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="bg in ['A+','A-','B+','B-','AB+','AB-','O+','O-']" :key="bg" :value="bg">{{ bg }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('phone')" />
                    <TextInput v-model="form.phone" type="tel" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('email')" />
                    <TextInput v-model="form.email" type="email" class="mt-1 w-full" />
                    <InputError :message="form.errors.email" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('state')" />
                    <SearchableSelect v-model="form.state" :options="wilayaOptions" :placeholder="t('search_wilaya')" />
                    <InputError :message="form.errors.state" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('city')" />
                    <SearchableSelect v-if="hasCityList" v-model="form.city" :options="cityOptions" :placeholder="t('search_city')" />
                    <TextInput v-else v-model="form.city" class="mt-1 w-full" />
                    <InputError :message="form.errors.city" class="mt-1" />
                </div>
            </div>
        </div>

        <!-- Club details -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('role_and_position') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <InputLabel :value="t('membership_id')" />
                    <TextInput :model-value="membershipPreview" class="mt-1 w-full bg-slate-50 dark:bg-slate-950" readonly disabled />
                    <p class="mt-1 text-xs text-slate-400">{{ t('auto_generated') }}</p>
                </div>
                <div>
                    <InputLabel :value="t('join_year')" />
                    <TextInput v-model="form.join_year" type="number" min="1900" :max="defaultJoinYear + 1" class="mt-1 w-full" />
                    <InputError :message="form.errors.join_year" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('category')" />
                    <select v-model="form.category_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">{{ t('select_category') }}</option>
                        <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                    </select>
                    <InputError :message="form.errors.category_id" class="mt-1" />
                </div>
                <div>
                    <InputLabel :value="t('position')" />
                    <select v-model="form.position_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ pos.abbreviation }} - {{ pos.name }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('team')" />
                    <TextInput v-model="form.team" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('skill_level')" />
                    <select v-model="form.skill_level" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="n in 10" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel :value="t('status')" />
                    <select :value="form.is_student ? 'student' : 'worker'" @change="form.is_student = $event.target.value === 'student'" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="student">{{ t('student') }}</option>
                        <option value="worker">{{ t('worker') }}</option>
                    </select>
                </div>
                <div v-if="!form.is_student">
                    <InputLabel :value="t('job')" />
                    <select v-model="form.member_job_id" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">-</option>
                        <option v-for="job in jobs" :key="job.id" :value="job.id">{{ job.name }}</option>
                    </select>
                </div>
                <div v-if="isEdit" class="flex items-center gap-2 pt-6">
                    <input type="checkbox" v-model="form.archived" id="archived" class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500" />
                    <label for="archived" class="text-sm text-slate-700 dark:text-slate-200">{{ t('archived') }}</label>
                </div>
            </div>
        </div>

        <!-- Health & emergency -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('health_information') }}</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <InputLabel :value="t('medical_conditions')" />
                    <textarea v-model="form.health_medical_conditions" rows="2" class="mt-1 w-full rounded-lg border-slate-300 dark:border-slate-700 shadow-sm focus:border-primary-500 focus:ring-primary-500" />
                </div>
                <div>
                    <InputLabel :value="t('emergency_contact') + ' - ' + t('name')" />
                    <TextInput v-model="form.emergency_contact_name" class="mt-1 w-full" />
                </div>
                <div>
                    <InputLabel :value="t('emergency_contact') + ' - ' + t('phone')" />
                    <TextInput v-model="form.emergency_contact_phone" type="tel" class="mt-1 w-full" />
                </div>
            </div>
        </div>

        <!-- Picture -->
        <div class="rounded-2xl bg-white dark:bg-slate-900 p-6 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <h2 class="mb-4 text-base font-semibold text-slate-900 dark:text-slate-100">{{ t('picture') }}</h2>
            <div class="flex items-center gap-4">
                <img v-if="shownImage" :src="shownImage" alt="" class="h-24 w-24 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                <div v-else class="flex h-24 w-24 items-center justify-center rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-400 text-xs">{{ t('picture') }}</div>
                <div class="flex-1" @drop.prevent="onDrop" @dragover.prevent>
                    <label class="flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-slate-300 dark:border-slate-700 px-4 py-4 text-center hover:border-primary-400">
                        <span class="text-sm text-slate-500 dark:text-slate-400">{{ t('drag_drop_image') }}</span>
                        <input type="file" accept="image/*" class="hidden" @change="onFileChange" />
                    </label>
                    <button v-if="previewUrl" type="button" @click="clearPicture" class="mt-2 text-xs text-rose-500 hover:text-rose-700">{{ t('remove') }}</button>
                    <InputError :message="form.errors.picture" class="mt-1" />
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3">
            <Link :href="cancelHref"><SecondaryButton type="button">{{ t('cancel') }}</SecondaryButton></Link>
            <PrimaryButton :disabled="form.processing">{{ isEdit ? t('save_changes') : t('save') }}</PrimaryButton>
        </div>
    </form>
</template>
```

- [ ] **Step 2: Rewrite `Create.vue` as a thin wrapper**

Replace `resources/js/Pages/Players/Create.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlayerForm from '@/Pages/Players/Partials/PlayerForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
defineProps({
    categories: Array,
    positions: Array,
    jobs: Array,
    wilayas: { type: Array, default: () => [] },
    communes: { type: Object, default: () => ({}) },
    nextSequenceByYear: { type: Object, default: () => ({}) },
    defaultJoinYear: { type: Number, default: () => new Date().getFullYear() },
});
</script>

<template>
    <Head :title="t('new_player')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('players.index')" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('new_player') }}</h1>
            </div>
        </template>
        <PlayerForm :categories="categories" :positions="positions" :jobs="jobs" :wilayas="wilayas" :communes="communes" :next-sequence-by-year="nextSequenceByYear" :default-join-year="defaultJoinYear" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Rewrite `Edit.vue` as a thin wrapper**

Replace `resources/js/Pages/Players/Edit.vue`:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PlayerForm from '@/Pages/Players/Partials/PlayerForm.vue';
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const props = defineProps({
    player: Object,
    categories: Array,
    positions: Array,
    jobs: Array,
    wilayas: { type: Array, default: () => [] },
    communes: { type: Object, default: () => ({}) },
    nextSequenceByYear: { type: Object, default: () => ({}) },
    defaultJoinYear: { type: Number, default: () => new Date().getFullYear() },
});
</script>

<template>
    <Head :title="t('edit_player')" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('players.show', player.id)" class="text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </Link>
                <h1 class="text-xl font-bold text-slate-900 dark:text-slate-100">{{ t('edit_player') }}</h1>
            </div>
        </template>
        <PlayerForm :player="player" :categories="categories" :positions="positions" :jobs="jobs" :wilayas="wilayas" :communes="communes" :next-sequence-by-year="nextSequenceByYear" :default-join-year="defaultJoinYear" />
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 4: Add a store test (state/city + new fields persist)**

Append to `tests/Feature/PlayerFormPropsTest.php`:

```php
    #[Test]
    public function storing_a_player_persists_geo_and_club_fields(): void
    {
        $this->actingAs($this->admin())
            ->post(route('players.store'), [
                'firstname' => 'Yacine',
                'state' => 'Adrar',
                'city' => 'Reggane',
                'is_student' => false,
                'join_year' => 2026,
                'team' => 'A',
                'skill_level' => 7,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('players', [
            'firstname' => 'Yacine', 'state' => 'Adrar', 'city' => 'Reggane',
            'join_year' => 2026, 'team' => 'A', 'skill_level' => 7, 'is_student' => false,
        ]);
    }
```

- [ ] **Step 5: Build + tests**

Run: `npm run build && php artisan test --filter=PlayerFormPropsTest`
Expected: build passes; tests PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Pages/Players/Partials/PlayerForm.vue resources/js/Pages/Players/Create.vue resources/js/Pages/Players/Edit.vue tests/Feature/PlayerFormPropsTest.php
git commit -m "feat: shared PlayerForm with wilaya/city pickers, worker-gated job, membership preview, image preview"
```

---

### Task 4: i18n keys

**Files:**
- Modify: `resources/js/i18n/en.json`, `fr.json`, `ar.json`

**Interfaces:**
- Produces: keys used by `PlayerForm` exist in all three locales.

- [ ] **Step 1: Add missing keys**

First sweep the new component for keys and check which already exist:
`grep -oE "t\('[a-z_]+'\)" resources/js/Pages/Players/Partials/PlayerForm.vue | sort -u`

For each key NOT already present in `en.json`, add it to all three files (do not duplicate existing keys — e.g. `city`, `state`, `job`, `status`, `student`, `worker`, `email`, `category`, `position`, `picture`, `cancel`, `save`, `save_changes`, `archived`, `name` already exist). The new ones to add (English shown):

```
"wilaya": "Wilaya",
"search_wilaya": "Search wilaya…",
"search_city": "Search city…",
"join_year": "Join year",
"team": "Team",
"skill_level": "Skill level",
"membership_id": "Membership ID",
"auto_generated": "Auto-generated",
"remove": "Remove",
"drag_drop_image": "Drop an image here or click to upload"
```

French (`fr.json`): `"wilaya": "Wilaya"`, `"search_wilaya": "Rechercher une wilaya…"`, `"search_city": "Rechercher une commune…"`, `"join_year": "Année d'adhésion"`, `"team": "Équipe"`, `"skill_level": "Niveau"`, `"membership_id": "N° d'adhérent"`, `"auto_generated": "Généré automatiquement"`, `"remove": "Retirer"`, `"drag_drop_image": "Déposez une image ou cliquez pour téléverser"`.

Arabic (`ar.json`): `"wilaya": "الولاية"`, `"search_wilaya": "ابحث عن ولاية…"`, `"search_city": "ابحث عن بلدية…"`, `"join_year": "سنة الانخراط"`, `"team": "الفريق"`, `"skill_level": "المستوى"`, `"membership_id": "رقم العضوية"`, `"auto_generated": "يُنشأ تلقائيًا"`, `"remove": "إزالة"`, `"drag_drop_image": "أفلت صورة هنا أو انقر للتحميل"`.

Skip any key that a locale file already contains. Validate JSON parses (`php -r "json_decode(file_get_contents('resources/js/i18n/en.json'),true) ?: exit(1);"` per file).

- [ ] **Step 2: Build + full suite**

Run: `npm run build && php artisan test`
Expected: build passes; suite green.

- [ ] **Step 3: Commit**

```bash
git add resources/js/i18n/en.json resources/js/i18n/fr.json resources/js/i18n/ar.json
git commit -m "feat: i18n keys for player form enhancement"
```

---

## Manual verification (after all tasks)

Run the app and verify:
1. New player: wilaya dropdown shows `Name — Arabic`; picking a wilaya populates the city dropdown with that wilaya's communes; changing wilaya clears the city.
2. Membership preview shows `2026-00001` and updates when join year changes.
3. Status=worker reveals Job; switching to student hides + clears it.
4. Email left blank → player saves.
5. Image: selecting/dropping a file shows a thumbnail; Remove clears it.
6. `join_year`, `team`, `skill_level` save; Edit prefills everything incl. existing membership ID (read-only) and existing photo.
