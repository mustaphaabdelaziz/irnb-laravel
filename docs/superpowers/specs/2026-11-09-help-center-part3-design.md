# Help Center Part 3 — Equipment, Board, Competitions & matches, Social activities

Date: 2026-11-09 · Status: decided with the owner in brainstorming (3 rounds) ·
Builds on `2026-11-08-help-center-design.md` (Part 1) and
`2026-11-08-help-center-part2-design.md` (Part 2, 39 articles, on origin/main).

## Goal

Add four topics (34 task articles) to the in-app manual, same style, tooling and
writing rules as Parts 1–2: Arabic, one task per article, step cards with ringed
and magnified screenshots, one worked example, permission-filtered, offline.
Before each topic's articles, fix the app problems the owner chose below so the
articles describe correct behaviour.

## Decisions (owner, 2026-11-09)

| Topic | Decision |
|---|---|
| Order | Four phases in sequence: **3a Equipment → 3b Board → 3c Competitions → 3d Social**. Social last: the literary report reads board members and match results, so it reuses 3b/3c demo data. |
| Scope | Full list below (9 + 8 + 11 + 6 = 34). Board tasks board and board calendar left out. |
| App problems | Decided one by one; chosen fixes land at the start of their phase, before the articles. |
| Demo images | Generated neutral illustrations (activity photos, opponent logos) written during the shot run into a throwaway storage folder; real media never touched. |
| Push | Nothing pushed until the owner asks; owner reviews wording after each phase. |

## Phase 3a · Equipment

### Fixes (all chosen)

1. «استلام مخزون»: when «تسجيل كمصروف» is unticked, «مصدر الاستلام» is required (تبرع / جرد افتتاحي / تسوية); today an empty choice saves silently as a purchase.
2. «تسجيل كمصروف» ticked with «سعر الوحدة» empty or 0 → validation error asking for the unit price (today: no expense, no message).
3. Arabic texts: conditions (New/Good…) translated in «تقرير المخزون» and stock-take dropdowns; purchase expense description translated (ar/fr/en) instead of "Equipment purchase: N unit(s)".
4. Return form defaults «الحالة» to the unit's condition before it went out (today always «جيد»).
5. Deleting a catalog is refused while any of its units is out (lent/assigned).
6. Stock-take reference: next INV number from the highest used, so deleting a session can't make «بدء الجرد» fail.
7. Equipment and stock-take buttons hidden when the user lacks the right (today shown, then an error page).
8. Renaming a storage place carries the new name to units stored there.
9. Unit import («استيراد» on the catalog page): dialog gets «تسجيل كمصروف» (off by default) + caisse choice, like receive stock; no expense unless ticked.

### Articles · topic `equipment` (icon `equipment`, module `equipment`)

1. `equipment-lists` — «فئات المعدات» (code → serial) and «أماكن التخزين» (module `equipment_categories`).
2. `add-catalog` — add an item, «تتبع كل وحدة على حدة» (locked once stocked), similar-name hint.
3. `receive-stock` — quantity, unit price, branch, expense + caisse or source, serial units for tracked items.
4. `lend-assign` — row «إيجار»: «إعارة» vs «تخصيص», player or outside person, expected duration.
5. `return-equipment` — from the catalog or «المعدات المُخرَجة», partial return, «متأخر».
6. `damage-repair-lost` — «إعادة تصنيف وحدات», repair, lost/found (whole lot → reclassify first).
7. `import-export` — catalog import/export and unit import with the new expense choice.
8. `stock-take` — «الجرد»: start, mark, participants, end (missing → lost, irreversible), PDF (module `inventory`).
9. `inventory-report` — «تقرير المخزون» and the reports centre equipment reports.

## Phase 3b · Board of directors

### Fixes (all chosen)

1. «أعضاء المجلس» and the «الاجتماعات» list open with the «عرض» right (today they need «تعديل»).
2. Sidebar entry «أعضاء المجلس» in the «مجلس إدارة النادي» group.
3. «عهدة جديدة», «تعديل العهدة», «إضافة اجتماع» hidden without the right.
4. «إزالة من اللجنة» asks for confirmation.
5. «إزالة من المكتب» keeps the person's past meeting attendance under their name (minutes and PDFs unchanged).
6. Overview counts, meeting attendance and task assignees use the **current term** only.
7. New: «نسخ أعضاء العهدة السابقة» on an empty term copies the previous term's active members with their roles and committee seats; former («سابق») members are not copied. Shown only while the selected term has no members.
8. Role edit field shows the readable (translated) name; renaming a default role keeps working.
9. Picking someone already on the term changes their role with message «تم تحديث عضو المكتب.» (not «تمت إضافة…»).

### Articles · topic `board` (icon `board`, module `board`)

1. `roles-committees` — «أدوار المكتب» (levels) and «اللجان» lists (module `board_roles`; committees part notes `board_committees`).
2. `terms` — create, switch, set current, copy previous members.
3. `add-member` — member form and clicking organigram boxes; identity comes live from the player.
4. `committee-seats` — head (1), deputies (2), members.
5. `former-remove-member` — «سابق» for someone who left; «إزالة من المكتب» for mistakes.
6. `schedule-meeting` — title, type, date, place, agenda; calendar shortcut.
7. `meeting-minutes` — attendance, minutes, decisions, minutes file, action items, PDF.
8. `cancel-delete-meeting` — cancel (stays, read-only) vs delete (irreversible).

## Phase 3c · Competitions & matches

### Fixes (all chosen)

1. Rules-tab hint replaced: bans are computed from match reports (drop «في تحديث قادم»).
2. «المنافسون» / «الملاعب والقاعات»: add/edit/delete hidden without the right.
3. Teams «حذف» hidden for a coach limited to own teams.
4. Changing a played match to a status other than «لُعبت» asks for confirmation that the score will be erased.

### Articles · topic `competitions` (icon `trophy`, module `games`)

1. `opponents-venues` — opponents with logo, venues (module `opponents`).
2. `create-team` — sport, category, name, format (module `teams`).
3. `roster-coaches` — roster + shirt numbers, copy last season, coaches (module `teams`).
4. `create-competition` — competition + squad (module `competitions`).
5. `schedule-match` — match form, training cancelled that day (ticked by default).
6. `call-up` — call-up, warning badges, limit, convocation PDF.
7. `lineup` — formation, drag/place, captain, goalkeeper, bench, line-up PDF.
8. `match-report` — score, goals, cards, substitutions, ratings (staff-only), report PDF.
9. `edit-cancel-match` — status, postpone/cancel, delete.
10. `suspensions` — rules, automatic bans, manual ban (module `competitions`).
11. `statistics` — stats page, filters, export; team/competition tabs, player card.

## Phase 3d · Social activities

### Fixes (all chosen)

1. «تقرير النشاط» hint says it prints when «إضافة التفاصيل» is ticked in the literary report.
2. «تقرير جديد» by a user with add but not edit: report created, list reloads with the success message (no error page).

### Articles · topic `social` (icon `social`, module `activities`)

1. `activity-types` — types are report chapters; order «#» (module `activity_types`).
2. `record-activity` — the activity form, participants, cost «للعلم فقط».
3. `photos-files` — photos, «صورة التقرير», captions, private files.
4. `find-edit-activity` — filters, edit, delete (irreversible, erases photos/files).
5. `create-literary-report` — title and period (start date rule) (module `literary_reports`).
6. `export-literary-report` — sections, details, signatures, texts, save, PDF; chapters follow the exporter's rights (module `literary_reports`).

## Framework and tooling

- `topics.php`: add `equipment`, `board`, `competitions`, `social` as each phase lands; titles `help.topic.<slug>` in ar/fr/en. Icons must exist in the help icon set (plan checks; fall back to an existing one).
- Sidebar nav `data-help` targets for the new groups (map in `Components/Sidebar/SidebarSection.vue`), plus `data-help` attributes on the pages (attribute only).
- Demo seeders, one per phase, called from `HelpDemoSeeder::run()`, each starting with `HelpDemoSeeder::assertThrowawayDatabase()`, writing through the app's services: `HelpDemoEquipmentSeeder` (`EquipmentStockService`, `EquipmentLifecycleService`, stock-take as `InventoryController` does; equipment received without expense so `HelpDemoFinanceSeeder` totals stay), `HelpDemoBoardSeeder` (reuse the migration's current term; members from demo players), `HelpDemoCompetitionsSeeder` (link football presets and positions to the Football branch; `TeamRoster`, `GameMinutes::refresh`, `SuspensionEngine::recompute`), `HelpDemoSocialSeeder` (activities, photos, one literary report).
- Throwaway media: `scripts/help-shots.mjs` points the app's storage at a throwaway folder for the run (mechanism verified in the 3c/3d plan), and the seeders generate the illustrations there. Real `storage/app` is never written.
- A seeder test per phase pins the numbers articles quote.

## Writing rules

As Part 2, plus the owner rules from 2d: buttons named by icon + hover name,
no colour words for buttons («الملوّن» only where colour is the point), one
action per step, every clicked element ringed (including sidebar clicks), exact
labels from `ar.json` (copy «العتــاد» with its tatweel).

## Definition of done (per phase)

Fixes covered by tests; `php artisan help:check`; `php artisan test --filter=Help`;
full suite in a worktree without cached config; `node --test tests/js/*.mjs`;
`npm run i18n:check`; `npm run build`; shots generated light + dark and viewed;
per-task and whole-phase reviews clean; merged to local main; owner wording
review. Production is PostgreSQL: no seeder or sweep ever runs there.

## Risks

- Fixes change behaviour (board attendance, term copy, import expense): each gets a feature test; MySQL/PostgreSQL-safe SQL only.
- Shots that write (stock-take end, cancel meeting) must undo themselves in an `after` hook or come last.
- Storage swap must also leave real sessions/caches alone; verify before seeding images.
- Month-dependent data (seasons start in September): date demo data relative to the seed day.
