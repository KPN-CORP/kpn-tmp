<?php

namespace App\Imports;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentPlanMaster;
use App\Models\IndividualDevelopmentPlan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Imports Individual Development Plans for a SINGLE employee (the "Upload
 * Development Plan" action on the IDP manage page). Each row adds a new plan.
 *
 * Rows are validated against the IDP master data:
 *  - development_model resolves by name or percentage
 *  - review_tools must exist in the master list (when given)
 *  - for a Soft Competency, competency_name + development_program must be a
 *    valid, linked combination in the master data
 *
 * Valid rows are created; invalid rows are skipped and reported via errors().
 */
class SingleEmployeeDevelopmentPlanImport implements ToCollection, WithHeadingRow
{
    /** development-model key (lowercase name / percentage) => id */
    private array $modelsMap = [];

    /** lowercase competency value => canonical value */
    private array $canonicalCompetency = [];

    /** lowercase competency value => id */
    private array $competencyMap = [];

    /** "{modelId}_{lower program}" => program id */
    private array $programMap = [];

    /** competency id => string[] of linked program ids */
    private array $relationsMap = [];

    /** lowercase review-tool values */
    private array $validReviewTools = [];

    private int $imported = 0;

    /** @var array<int, string> */
    private array $errors = [];

    public function __construct(private readonly string $employeeId)
    {
        foreach (DevelopmentModel::all() as $model) {
            $this->modelsMap[strtolower(trim($model->name))] = $model->id;
            $this->modelsMap[(string) $model->percentage] = $model->id;
            $this->modelsMap[$model->percentage.'%'] = $model->id;
        }

        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')->get();
        foreach ($competencies as $competency) {
            $key = strtolower(trim($competency->value));
            $this->competencyMap[$key] = $competency->id;
            $this->canonicalCompetency[$key] = $competency->value;
            $this->relationsMap[$competency->id] = array_map('strval', $competency->related_program ?? []);
        }

        foreach (DevelopmentPlanMaster::where('type', 'development_program')->get() as $program) {
            $key = $program->development_model_id.'_'.strtolower(trim($program->value));
            $this->programMap[$key] = (string) $program->id;
        }

        $this->validReviewTools = DevelopmentPlanMaster::where('type', 'review_tools')
            ->pluck('value')
            ->map(fn ($value) => strtolower(trim($value)))
            ->all();
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 heading row, +1 for 1-based display

            $data = $this->normalize($row);

            // Skip fully-empty rows.
            if ($this->isEmpty($data)) {
                continue;
            }

            $error = $this->validate($data, $line);
            if ($error !== null) {
                $this->errors[] = $error;

                continue;
            }

            $modelId = $this->resolveModelId($data['development_model']);
            $competencyName = $data['competency_name'];
            if ($data['competency_type'] === 'Soft Competency') {
                $competencyName = $this->canonicalCompetency[strtolower($competencyName)] ?? $competencyName;
            }

            IndividualDevelopmentPlan::create([
                'employee_id' => $this->employeeId,
                'development_model_id' => $modelId,
                'competency_type' => $data['competency_type'],
                'competency_name' => $competencyName,
                'development_program' => $data['development_program'],
                'review_tools' => $data['review_tools'],
                'expected_outcome' => $data['expected_outcome'],
                'time_frame_start' => $data['time_frame_start'],
                'time_frame_end' => $data['time_frame_end'],
                'realization_date' => $data['realization_date'],
                'result_evidence' => $data['result_evidence'],
            ]);

            $this->imported++;
        }
    }

    public function imported(): int
    {
        return $this->imported;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, string|null>
     */
    private function normalize($row): array
    {
        $get = fn (string $key) => trim((string) ($row->get($key) ?? ''));

        return [
            'development_model' => $get('development_model'),
            'competency_type' => $get('competency_type'),
            'competency_name' => $get('competency_name'),
            'development_program' => $get('development_program'),
            'review_tools' => $get('review_tools') ?: null,
            'expected_outcome' => $get('expected_outcome') ?: null,
            'time_frame_start' => $this->parseDate($row->get('time_frame_start')),
            'time_frame_end' => $this->parseDate($row->get('time_frame_end')),
            'realization_date' => $this->parseDate($row->get('realization_date')),
            'result_evidence' => $get('result_evidence') ?: null,
        ];
    }

    private function isEmpty(array $data): bool
    {
        return $data['development_model'] === ''
            && $data['competency_type'] === ''
            && $data['competency_name'] === ''
            && $data['development_program'] === '';
    }

    /**
     * Returns a human-readable error string, or null when the row is valid.
     */
    private function validate(array $data, int $line): ?string
    {
        if (! in_array($data['competency_type'], ['Soft Competency', 'Technical Competency'], true)) {
            return "Row {$line}: competency_type must be 'Soft Competency' or 'Technical Competency'.";
        }

        if ($data['development_model'] === '' || $this->resolveModelId($data['development_model']) === null) {
            return "Row {$line}: development_model '{$data['development_model']}' not found.";
        }

        if ($data['competency_name'] === '') {
            return "Row {$line}: competency_name is required.";
        }

        if ($data['development_program'] === '') {
            return "Row {$line}: development_program is required.";
        }

        if ($data['review_tools'] !== null && ! in_array(strtolower($data['review_tools']), $this->validReviewTools, true)) {
            return "Row {$line}: review_tools '{$data['review_tools']}' is not in the master data.";
        }

        if (! $data['time_frame_start']) {
            return "Row {$line}: time_frame_start is required (use DD-MM-YYYY).";
        }

        if ($data['time_frame_end'] && $data['time_frame_end'] < $data['time_frame_start']) {
            return "Row {$line}: time_frame_end cannot be before time_frame_start.";
        }

        if ($data['realization_date']) {
            if ($data['realization_date'] < $data['time_frame_start']) {
                return "Row {$line}: realization_date cannot be before time_frame_start.";
            }
            if ($data['realization_date'] > now()->format('Y-m-d')) {
                return "Row {$line}: realization_date cannot be a future date.";
            }
            if ($data['result_evidence'] === null) {
                return "Row {$line}: result_evidence is required when realization_date is set.";
            }
        }

        if ($data['expected_outcome'] !== null && mb_strlen($data['expected_outcome']) > 500) {
            return "Row {$line}: expected_outcome must not exceed 500 characters.";
        }

        // Soft Competency: name + program must be a valid, linked combination.
        if ($data['competency_type'] === 'Soft Competency') {
            $competencyKey = strtolower($data['competency_name']);
            if (! isset($this->competencyMap[$competencyKey])) {
                return "Row {$line}: competency_name '{$data['competency_name']}' not found in master data.";
            }

            $modelId = $this->resolveModelId($data['development_model']);
            $programId = $this->programMap[$modelId.'_'.strtolower($data['development_program'])] ?? null;
            if ($programId === null) {
                return "Row {$line}: development_program '{$data['development_program']}' is not valid for the selected model.";
            }

            $allowed = $this->relationsMap[$this->competencyMap[$competencyKey]] ?? [];
            if (! in_array($programId, $allowed, true)) {
                return "Row {$line}: '{$data['development_program']}' is not linked to competency '{$data['competency_name']}'.";
            }
        }

        return null;
    }

    private function resolveModelId(string $value): ?int
    {
        if ($value === '') {
            return null;
        }

        $key = strtolower(trim($value));
        if (isset($this->modelsMap[$key])) {
            return $this->modelsMap[$key];
        }

        // Fall back to a leading number, e.g. "70% - On The Job".
        if (preg_match('/^\d+/', $value, $matches) && isset($this->modelsMap[$matches[0]])) {
            return $this->modelsMap[$matches[0]];
        }

        return null;
    }

    /**
     * Accepts DD-MM-YYYY strings, Excel serial numbers, or "-"/empty (=> null).
     * Returns an ISO yyyy-mm-dd string or null.
     */
    private function parseDate($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '-') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $value)) {
            try {
                return Carbon::createFromFormat('d-m-Y', $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
