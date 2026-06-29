<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\MemberJob;
use App\Models\Position;
use App\Services\Player\RegisterPlayerService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
        ['status', 'الحالة (student/worker)', 'student'],
        ['skill_level', 'المستوى (1-10)', '5'],
        ['blood_group', 'فصيلة الدم', 'O+'],
        ['medical_conditions', 'الحالات الصحية', ''],
        ['join_year', 'سنة الانضمام', ''],
    ];

    public function template(): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Players');
        $sheet->setRightToLeft(true);

        foreach (self::COLUMNS as $index => [$key, $header, $example]) {
            $column = chr(65 + $index); // A, B, C ...
            $sheet->setCellValue($column.'1', $header);
            $sheet->setCellValueExplicit($column.'2', $example, DataType::TYPE_STRING);
            $sheet->getColumnDimension($column)->setWidth(20);
        }

        $headerStyle = $sheet->getStyle('A1:'.chr(65 + count(self::COLUMNS) - 1).'1');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('02A85C');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $filename = 'players-import-template.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function store(Request $request, RegisterPlayerService $service): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ]);

        try {
            $rows = IOFactory::load($request->file('file')->getRealPath())
                ->getActiveSheet()
                ->toArray(null, true, true, false);
        } catch (Throwable $e) {
            return back()->with('error', __('Could not read the file. Please use the provided template.'));
        }

        // Drop the header row.
        array_shift($rows);

        $categories = Category::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id]);
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
                    'is_student' => mb_strtolower((string) $data['status']) !== 'worker',
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
            if (is_numeric($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
