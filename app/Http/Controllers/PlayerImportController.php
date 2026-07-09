<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MemberJob;
use App\Models\Position;
use App\Services\Player\RegisterPlayerService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PlayerImportController extends Controller
{
    /**
     * Ordered columns shared by the downloadable template and the importer.
     * Each entry: [field key, Arabic header, example value].
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    private const COLUMNS = [
        ['firstname', 'الاسم', 'محمد'],
        ['lastname', 'اللقب', 'بن علي'],
        ['father', 'اسم الأب', 'أحمد'],
        ['grandfather', 'اسم الجد', 'عمر'],
        ['nickname', 'الكنية', ''],
        ['birthdate', 'تاريخ الميلاد (YYYY-MM-DD)', '2008-05-20'],
        ['gender', 'الجنس (Male/Female)', 'Male'],
        ['phone', 'الهاتف', '0550000000'],
        ['email', 'البريد الإلكتروني', ''],
        ['city', 'المدينة', 'الجزائر'],
        ['state', 'الولاية', 'الجزائر'],
        ['category', 'الفئة', 'Senior'],
        ['position', 'المركز (الاختصار أو الاسم)', 'GK'],
        ['job', 'المهنة', 'طالب'],
        ['status', 'الحالة (student/worker)', 'worker'],
        ['skill_level', 'المستوى (1-10)', '5'],
        ['blood_group', 'فصيلة الدم', 'O+'],
        ['medical_conditions', 'الحالات الصحية', ''],
        ['join_year', 'سنة الانضمام', ''],
    ];

    public function template(): StreamedResponse
    {
        $headers = array_map(fn ($column) => $column[1], self::COLUMNS);
        $example = array_map(fn ($column) => $column[2], self::COLUMNS);

        return Csv::download('players-import-template.csv', $headers, [$example]);
    }

    public function store(Request $request, RegisterPlayerService $service): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        try {
            $rows = Csv::readRows($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        // Drop the header row.
        array_shift($rows);

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
        $jobs = MemberJob::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id]);

        $imported = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // human-friendly spreadsheet row number
            $data = $this->mapRow($row);

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
                    'gender' => in_array($data['gender'], ['Male', 'Female'], true) ? $data['gender'] : 'Male',
                    'phones' => $data['phone'] ? [$data['phone']] : [],
                    'email' => $data['email'] ?: null,
                    'city' => $data['city'] ?: 'Unknown',
                    'state' => $data['state'] ?: 'Unknown',
                    'category_id' => $categories[mb_strtolower((string) $data['category'])] ?? null,
                    'position_id' => $this->resolvePosition($positions, $data['position']),
                    'member_job_id' => $jobs[mb_strtolower((string) $data['job'])] ?? null,
                    // Default to worker: only an explicit "student" cell marks a student.
                    'is_student' => mb_strtolower((string) $data['status']) === 'student',
                    'skill_level' => $this->clampSkill($data['skill_level']),
                    'health_blood_group_rhesus' => $data['blood_group'] ?: null,
                    'health_medical_conditions' => $data['medical_conditions'] ?: null,
                    'join_year' => is_numeric($data['join_year']) ? (int) $data['join_year'] : now()->year,
                ];

                $service->handle($attributes, $request->user()?->id);
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
     * @return array<string, string|null>
     */
    private function mapRow(array $row): array
    {
        $data = [];
        foreach (self::COLUMNS as $index => [$key]) {
            $value = $row[$index] ?? null;
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
