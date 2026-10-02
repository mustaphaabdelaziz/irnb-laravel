# Attendance calendar speed (Stream B) — plan

Spec: `docs/superpowers/specs/2026-10-02-attendance-and-filters-batch-design.md` (Stream B).

## 1. Generate only when inputs changed
- New table `session_generation_marks` (category_id FK cascade, month `Y-m`, PK both).
  A row means "this category's month was generated from the current inputs".
- `SessionGenerator::forMonth` returns early when the mark exists (one read, no
  transaction). Otherwise it generates as today and inserts the mark in the
  same transaction.
- `SessionGenerator::forRange` (used by `CalendarFeed::generateAll`): one
  schedules query + one marks query for the whole range; only unmarked
  (category, month) pairs are generated, reusing the loaded schedules and
  closures (no per-pair schedule query). Nothing unmarked = no transaction.
- `GenerationMarks::forget*` drops marks; called from:
  - `TrainingSchedule` saved/deleted: old + new category, all months.
  - `ClubClosure` saved/deleted: every category, months of old + new range.
  - `TrainingSession` created/deleted, and updated when `date`, `start_time`,
    `category_id` or `moved_from` changed: every pivot category plus old/new
    primary, months of old/new date and moved_from.
  - Pivot edits (`TrainingSessionController::updateCategories`, `store`):
    old + new category ids for the session's month (pivot sync fires no events).
  - Query-builder deletes (`purgeFuturePlanned`, closure purge) run right
    next to a schedule/closure write that already forgets the affected marks.
  - Category delete: FK cascade.
- Today's semantics kept: a freed slot is regenerated on the next view.

## 2. Index `training_sessions(date)` (additive migration).

## 3. `Vite::prefetch` only when not running in NativePHP.

## 4. Same-view calendar navigation uses Inertia partial reloads (`only`) with
the props that view returns; view switches stay full visits. `categories` and
`attendanceCodes` become lazy so partial reloads skip them.

## 5. `PreseasonProgress::milestones`: one held-sessions query per season
instead of one per category.

## 6. Tests
- Second GET of month/week/agenda/timeline: no INSERT/UPDATE/DELETE/BEGIN, and
  query counts recorded before/after.
- Regeneration after schedule create/update/delete, closure create/delete,
  session move, joint category removal.
- Prefetch on/off by `nativephp-internal.running`.
