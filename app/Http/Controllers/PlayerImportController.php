<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MemberJob;
use App\Models\Position;
use App\Services\Player\RegisterPlayerService;
use App\Support\Export;
use App\Support\Import\ImportColumns;
use App\Support\NameNormalizer;
use App\Support\Spreadsheet;
use App\Support\UiLang;
use App\Support\WilayaMatcher;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class PlayerImportController extends Controller
{
    /**
     * Ordered columns shared by the downloadable template and the importer.
     * Columns are found by header (ar / fr / en, or `legacy`: the old Arabic
     * template's header), else by this position — so keep the order.
     * `example` is the template's example cell; an i18n key when `localized`.
     *
     * @var list<array{key:string, label:string, hint?:string, legacy:list<string>, example:string, localized?:bool}>
     */
    private const COLUMNS = [
        ['key' => 'firstname', 'label' => 'col.firstname', 'legacy' => ['الاسم'], 'example' => 'محمد'],
        ['key' => 'lastname', 'label' => 'col.lastname', 'legacy' => ['اللقب'], 'example' => 'بن علي'],
        ['key' => 'father', 'label' => 'col.father', 'legacy' => ['اسم الأب'], 'example' => 'أحمد'],
        ['key' => 'grandfather', 'label' => 'col.grandfather', 'legacy' => ['اسم الجد'], 'example' => 'عمر'],
        ['key' => 'nickname', 'label' => 'col.nickname', 'legacy' => ['الكنية'], 'example' => ''],
        ['key' => 'birthdate', 'label' => 'col.birthdate', 'hint' => 'col.hint.date', 'legacy' => ['تاريخ الميلاد (YYYY-MM-DD)'], 'example' => '2008-05-20'],
        ['key' => 'gender', 'label' => 'col.gender', 'legacy' => ['الجنس (Male/Female)'], 'example' => 'male', 'localized' => true],
        ['key' => 'phone', 'label' => 'col.phone', 'legacy' => ['الهاتف'], 'example' => '0550000000'],
        ['key' => 'email', 'label' => 'col.email', 'legacy' => ['البريد الإلكتروني'], 'example' => ''],
        ['key' => 'city', 'label' => 'col.city', 'legacy' => ['المدينة'], 'example' => 'الجزائر'],
        ['key' => 'state', 'label' => 'col.state', 'legacy' => ['الولاية'], 'example' => 'الجزائر'],
        ['key' => 'category', 'label' => 'col.category', 'legacy' => ['الفئة'], 'example' => 'Senior'],
        ['key' => 'position', 'label' => 'col.position', 'hint' => 'col.hint.abbr_or_name', 'legacy' => ['المركز (الاختصار أو الاسم)'], 'example' => 'GK'],
        ['key' => 'job', 'label' => 'col.job', 'legacy' => ['المهنة'], 'example' => 'Ingénieur'], // a seeded job (MemberJobSeeder), consistent with "worker"
        ['key' => 'status', 'label' => 'col.player_type', 'legacy' => ['الحالة (student/worker)'], 'example' => 'worker', 'localized' => true],
        ['key' => 'skill_level', 'label' => 'col.skill_level', 'hint' => 'col.hint.one_to_ten', 'legacy' => ['المستوى (1-10)'], 'example' => '5'],
        ['key' => 'blood_group', 'label' => 'col.blood_group', 'legacy' => ['فصيلة الدم'], 'example' => 'O+'],
        ['key' => 'medical_conditions', 'label' => 'col.medical_conditions', 'legacy' => ['الحالات الصحية'], 'example' => ''],
        ['key' => 'join_year', 'label' => 'col.join_year', 'legacy' => ['سنة الانضمام'], 'example' => ''],
        // Appended last on purpose: older files simply have no cell here.
        ['key' => 'wilaya', 'label' => 'col.wilaya', 'hint' => 'col.hint.code_or_name', 'legacy' => ['الولاية (الرمز أو الاسم)'], 'example' => '47'],
        // Appended last: older files have no cell here.
        ['key' => 'other_positions', 'label' => 'col.other_positions', 'hint' => 'col.hint.comma_separated', 'legacy' => ['مراكز أخرى (مفصولة بفاصلة)'], 'example' => 'WG, LB'],
    ];

    /** Stored gender code => UI label key. */
    private const GENDERS = ['Male' => 'male', 'Female' => 'female'];

    /** Student / worker cell => UI label key. */
    private const STATUSES = ['student' => 'student', 'worker' => 'worker'];

    public function template(Request $request): SymfonyResponse
    {
        $example = array_map(
            fn (array $c) => ($c['localized'] ?? false) ? UiLang::get($c['example']) : $c['example'],
            self::COLUMNS,
        );

        return Export::download(Export::format($request), 'players-import-template',
            (new ImportColumns(self::COLUMNS))->headers(), [$example]);
    }

    public function store(Request $request, RegisterPlayerService $service): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        try {
            $rows = Spreadsheet::readRows($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        // Skip an export's title/spacer rows and the header row itself.
        [$headerRow, $map] = (new ImportColumns(self::COLUMNS))->locate($rows);
        $rows = array_values(array_slice($rows, $headerRow + 1));

        // Match a category by any of its names (base + per-locale), case-insensitively.
        $categories = [];
        foreach (Category::all(['id', 'name', 'name_ar', 'name_fr', 'name_en']) as $category) {
            foreach ([$category->name, $category->name_ar, $category->name_fr, $category->name_en] as $variant) {
                if ($variant !== null && $variant !== '') {
                    $categories[mb_strtolower(trim($variant))] = $category->id;
                }
            }
        }
        $positions = Position::all();

        // Match a job by any of its names (base + per-locale), spelling-insensitively.
        $jobs = [];
        foreach (MemberJob::query()->get() as $job) {
            foreach ([$job->name, $job->name_ar, $job->name_fr, $job->name_en] as $value) {
                $key = NameNormalizer::key($value);
                if ($key !== '') {
                    $jobs[$key] = $job->id;
                }
            }
        }

        $wilayas = WilayaMatcher::lookup();

        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $headerRow + $i + 2; // the real spreadsheet row number
            $data = $this->mapRow($row, $map);

            if ($data['firstname'] === null || $data['firstname'] === '') {
                continue; // blank line
            }

            try {
                $attributes = [
                    'firstname' => $data['firstname'],
                    'lastname' => $data['lastname'],
                    'father' => $data['father'],
                    'grandfather' => $data['grandfather'],
                    'nickname' => $data['nickname'],
                    'birthdate' => $this->parseDate($data['birthdate']),
                    'gender' => ImportColumns::value($data['gender'], self::GENDERS) ?? 'Male',
                    'phones' => $data['phone'] ? [$data['phone']] : [],
                    'email' => $data['email'] ?: null,
                    'city' => $data['city'] ?: 'Unknown',
                    'state' => $data['state'] ?: 'Unknown',
                    'category_id' => $categories[mb_strtolower((string) $data['category'])] ?? null,
                    'position_id' => $this->resolvePosition($positions, $data['position']),
                    // Older 19-column files (and anyone who still fills the template's
                    // legacy "state" column) have no "wilaya" cell at all — fall back
                    // to "state" so those rows still resolve a wilaya_id. When both are
                    // given, the newer "wilaya" cell wins.
                    'wilaya_id' => $wilayas[WilayaMatcher::normalise((string) ($data['wilaya'] ?: $data['state']))] ?? null,
                    'member_job_id' => $jobs[NameNormalizer::key($data['job'] ?? null)] ?? null,
                    // Default to worker: only an explicit "student" cell (any language) marks a student.
                    'is_student' => ImportColumns::value($data['status'], self::STATUSES) === 'student',
                    'skill_level' => $this->clampSkill($data['skill_level']),
                    'health_blood_group_rhesus' => $data['blood_group'] ?: null,
                    'health_medical_conditions' => $data['medical_conditions'] ?: null,
                    'join_year' => is_numeric($data['join_year']) ? (int) $data['join_year'] : now()->year,
                ];

                $player = $service->handle($attributes, $request->user()?->id);

                $others = collect(explode(',', (string) ($data['other_positions'] ?? '')))
                    ->map(fn ($value) => $this->resolvePosition($positions, trim($value)))
                    ->filter()
                    ->reject(fn ($id) => (int) $id === (int) ($attributes['position_id'] ?? 0))
                    ->unique()
                    ->values()
                    ->all();

                if ($others !== []) {
                    $player->otherPositions()->sync($others);
                }

                $imported++;
            } catch (Throwable $e) {
                $errors[] = __('Row :line: :message', ['line' => $line, 'message' => $e->getMessage()]);
            }
        }

        $message = __(':count players imported successfully.', ['count' => $imported]);

        if ($errors !== []) {
            return back()
                ->with('success', $message)
                ->with('error', implode("\n", array_slice($errors, 0, 10)));
        }

        return back()->with('success', $message);
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $map  column key => cell index (a column may be absent)
     * @return array<string, string|null>
     */
    private function mapRow(array $row, array $map): array
    {
        $data = [];
        foreach (self::COLUMNS as ['key' => $key]) {
            $value = $row[$map[$key] ?? -1] ?? null;
            $data[$key] = is_string($value) ? trim($value) : ($value === null ? null : trim((string) $value));
        }

        return $data;
    }

    private function resolvePosition($positions, ?string $value): ?int
    {
        if (! $value) {
            return null;
        }

        $needle = mb_strtolower(trim($value));

        $match = $positions->first(fn (Position $p) => mb_strtolower((string) $p->abbreviation) === $needle
            || mb_strtolower((string) $p->name) === $needle);

        return $match?->id;
    }

    private function clampSkill(?string $value): int
    {
        $skill = is_numeric($value) ? (int) $value : 5;

        return max(1, min(10, $skill));
    }

    private function parseDate(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
