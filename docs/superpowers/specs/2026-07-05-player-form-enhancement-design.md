# Player Form Enhancement — Design

Date: 2026-07-05
Status: Approved (decisions confirmed)

## Problem

The player create/edit form has several gaps:
- Wilaya (state) is a flat French-only `<select>`; city is free text — no Algeria-accurate
  list, no Arabic, no wilaya→city dependency.
- Job sits before Status and always shows, even for students.
- Several model fields (`join_year`, `team`, `skill_level`) aren't in the form.
- Membership ID generation uses a 6-digit random suffix, not the club's
  `year + 5-digit` convention.
- `Create.vue` and `Edit.vue` are near-duplicates.
- Baseline UX (required markers, inline validation, image preview) is thin.

## Goal

Wilaya→city dependent bilingual-label pickers, membership ID = `year + NNNNN`
sequential per year, job gated on worker status, email confirmed optional, expose
missing fields, and a UX polish pass — with Create/Edit consolidated into one shared
form component.

## Confirmed decisions

- **City data**: use the existing `communes` (French/Latin) as-is. Wilaya labels are
  bilingual (data has `ar_name`); commune labels are French/Latin (no Arabic in data).
- **Stored value**: canonical French/Latin name for both `state` (wilaya) and `city`
  (commune). No schema change.
- **Enhancements (all)**: searchable wilaya+city, required markers + inline validation,
  image upload preview, expose `join_year`/`team`/`skill_level`.
- **Membership ID**: `{joinYear}{NNNNN}`, 5-digit sequential **per join year**; shown
  read-only in the form with a live preview; generated authoritatively on save.

## Existing building blocks (reuse)

- `resources/js/Components/SearchableSelect.vue` — combobox (`modelValue`,
  `options:[{value,label}]`, `placeholder`, `disabled`; emits `update:modelValue`).
- `database/seeders/algeria_wilayas.json` — `states` (58: `id,name,ar_name,lat,long`)
  and `communes` (`{ wilaya_id: [name,…] }`, 57 wilayas populated).
- `PlayerController@create/edit` currently pass `categories, positions, jobs, states`
  (states via `getAlgeriaStates()` → `pluck('name')`).
- `RegisterPlayerService::generateMembershipId(int $joinYear)` — the only membership-ID
  producer; runs inside `handle()`'s `DB::transaction`.
- `StorePlayerRequest`/`UpdatePlayerRequest` — validation. `email` is already
  `nullable`; `state`/`city` already `nullable|string`.
- `Player` model: `state`, `city`, `join_year`, `team`, `skill_level`, `is_student`,
  `member_job_id`, `membership_id` (`string(10)`, unique).

---

## Design

### A. Backend data wiring (`PlayerController`)

- Replace `getAlgeriaStates()` with a private `algeriaGeo(): array` returning:
  - `wilayas`: `array<int, {id:int, name:string, ar_name:string}>` from `states`.
  - `communes`: `array<int|string, string[]>` — the raw `communes` map keyed by wilaya id.
- `create()` passes `categories, positions, jobs, wilayas, communes,
  nextSequenceByYear, defaultJoinYear`.
- `edit()` passes the same plus `player`.
- `nextSequenceByYear`: `array<int,int>` — for every join year present in `players`
  plus the current year, the next available 5-digit sequence (max suffix + 1; years
  with no members → 1). Powers the form's live ID preview accurately as the user picks a
  year. (With an empty players table, all years → 1.)
- Extract membership-year/sequence logic so both the controller preview and
  `RegisterPlayerService` use one source of truth (see §I).

### B. Wilaya → City pickers (dependent, searchable)

In the shared form (§G):
- **Wilaya**: `SearchableSelect`, `options = wilayas.map(w => ({ value: w.name,
  label: `${w.name} — ${w.ar_name}` }))`, bound to `form.state` (stored = French name).
- **City**: `SearchableSelect`, `options = communesForSelectedWilaya.map(c => ({ value:
  c, label: c }))`, bound to `form.city`.
- Dependency: a computed resolves the selected wilaya's `id` by matching `form.state`
  against `wilayas`; `communesForSelectedWilaya = communes[selectedWilayaId] ?? []`.
  Watch `form.state`: when it changes, reset `form.city = ''`.
- **Legacy/edge tolerance**:
  - If `form.state` (edit prefill) isn't in `wilayas`, inject it as its own option so it
    displays and submits unchanged.
  - If the selected wilaya has no commune list (the one unpopulated wilaya) OR
    `form.city` isn't in the list, the city field falls back to a plain text input so no
    data is lost / entry stays possible.

### C. Job after Status, gated on worker

- Order in the "Role & Position" section: **Status** select first, then **Job**.
- Job field renders only when `form.is_student === false` (worker). Wrap in `v-if`.
- When status switches to student, set `form.member_job_id = ''`.

### D. Email optional (confirm)

- `StorePlayerRequest`: `email` stays `['nullable','email','max:255']` (already is).
- `UpdatePlayerRequest`: confirm/ensure `email` is `nullable` (not `required`).
- Form: no `required` attribute and no required-asterisk on the email field.

### E. Expose model fields

Add to the shared form (and `form.transform` payload) in a sensible section:
- `join_year`: number input, defaults to `defaultJoinYear` (current year). Drives the
  membership-ID preview.
- `team`: text input.
- `skill_level`: select 1–10 (validation is `min:1 max:10`).
Validation already covers all three; no request change needed.

### F. UX / UI polish

- **Required markers**: an asterisk on required fields. Required set aligned to actual
  server rules: `firstname` required. `category_id` kept visually required (registration
  assigns subscriptions by category) but remains `nullable` server-side — mark with a
  softer "recommended" hint rather than a hard asterisk to avoid client/server drift.
  Do NOT hard-require `birthdate` in the UI (server rule is `nullable`) — the current
  form's `required` on birthdate/category is a mismatch this fixes.
- **Inline validation**: keep `InputError` under every field; add short helper hints
  where useful (e.g. membership ID "auto-generated").
- **Image upload**: replace the bare file input with a small uploader that shows a
  thumbnail preview (`URL.createObjectURL`) after selection and supports drag-and-drop;
  a "remove" affordance clears the selection. Revoke the object URL on change/unmount.
- **Layout**: keep the existing card sections + responsive grid; tighten spacing,
  consistent focus rings, logical field order, section subtitles.

### G. Refactor — shared `PlayerForm`

- Extract `resources/js/Pages/Players/Partials/PlayerForm.vue` holding the whole form
  (state, computeds, watchers, submit), taking props `player` (nullable), `categories`,
  `positions`, `jobs`, `wilayas`, `communes`, `nextSequenceByYear`, `defaultJoinYear`.
- `Create.vue` and `Edit.vue` become thin wrappers (Head + header + `<PlayerForm>`),
  mirroring the `TransactionForm` split already in the codebase.
- `submit()` posts to `players.store` (create) or `players.update` with `_method:'put'`
  (edit), `forceFormData:true`. Preserve the existing `phones`/`emergency_contacts`
  array transforms.

### H. i18n

Add missing keys to `en.json`, `fr.json`, `ar.json` (skip any that already exist):
`wilaya`, `search_wilaya`, `search_city`, `join_year`, `team`, `skill_level`,
`membership_id`, `auto_generated`, `remove`, `drag_drop_image`. (`city`, `state`,
`job`, `status`, `student`, `worker`, `email` already exist.)

### I. Membership ID = `year + NNNNN`

- **Format**: `sprintf('%04d%05d', $joinYear, $sequence)` → 9 chars (e.g. `202600001`).
  Fits `membership_id string(10)`.
- **Sequence**: highest existing suffix among players whose `membership_id` starts with
  that 4-digit year, `+ 1`; `1` if none. Implemented as a small helper (e.g.
  `App\Services\Player\MembershipNumber::nextSequence(int $year): int` and
  `format(int $year, int $seq): string`), used by BOTH the controller preview and
  `RegisterPlayerService`.
- **Generation**: `RegisterPlayerService::generateMembershipId($joinYear)` becomes
  `MembershipNumber::format($joinYear, nextSequence($joinYear))`, inside the existing
  `DB::transaction`; keep a retry-on-duplicate loop (recompute sequence) to survive a
  race on the unique index. Client-submitted membership IDs are never trusted on create.
- **Form preview**: read-only field showing `${join_year}-${pad5(nextSequenceByYear[join_year] ?? 1)}`
  (dash is display-only; stored value has no dash). Updates as `join_year` changes.
  Edit shows the player's existing `membership_id` read-only (never regenerated).

## Data & compatibility notes

- No schema change: `state`/`city` remain nullable strings; `membership_id` stays
  `string(10)` (9-char values fit).
- Existing players (post-cleanup there are 0) keep their current IDs; only new players
  use the new format. Legacy state/city free-text values still display/submit via the
  edge-tolerance in §B.

## Testing

- Feature: `MembershipNumber::nextSequence` returns 1 for an empty year, and max+1 when
  players exist for that year; `format` zero-pads to `YYYYNNNNN`.
- Feature: registering two students in the same join year yields `…00001` then `…00002`.
- Feature: storing a player persists `state`/`city` as the French names sent.
- Feature: `email` omitted → player still created (already true; lock it with a test).
- Component/manual: wilaya change resets city and repopulates options; worker shows Job,
  student hides+clears it; image preview renders; searchable selects filter.

## Out of scope (confirmed)

- Arabic commune names / dataset enrichment.
- Bilingual storage / schema changes for state/city.
- Map/geolocation, address lines beyond wilaya+city.
