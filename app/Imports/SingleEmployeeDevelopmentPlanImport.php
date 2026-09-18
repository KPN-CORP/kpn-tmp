<?php

namespace App\Imports;

use App\Imports\Support\SkipsForeignSheets;
use App\Models\CompetencyType;
use App\Models\DevelopmentModel;
use App\Models\IndividualDevelopmentPlan;
use App\Models\ReviewTool;
use App\Services\Idp\Rules\PlanMasterRules;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Imports Individual Development Plans for a SINGLE employee (the "Upload
 * Development Plan" action on the IDP manage page). Each row adds a new plan.
 *
 * It is a PLANNING import: a row carries what the plan drawer carries and
 * nothing more. The realization date and the result evidence belong to stage 3
 * and are filed per program through the result drawer, so that a result always
 * passes the approval gate — a spreadsheet cannot slip one past it.
 *
 * Rows are validated by **`PlanMasterRules`**, the very object the plan form
 * validates through, so the import cannot accept a plan the screen would reject
 * and the two can never drift apart. Only the shape checks (required, dates,
 * lengths) are done here.
 *
 * Everything is scoped to ONE development-model cycle: `development_model` is
 * resolved against that package's models only, so a row can never land in
 * another cycle.
 */
class SingleEmployeeDevelopmentPlanImport implements ToCollection, WithHeadingRow
{
    use SkipsForeignSheets;

    /**
     * What identifies the sheet that is actually imported. `time_frame_start`
     * is the discriminator: the "Ref - Valid Combinations" tab carries the same
     * four naming columns, so matching on those alone would read the reference
     * rows as plans.
     */
    private const OUR_HEADINGS = ['development_model', 'competency_type', 'time_frame_start'];

    /** development-model key (lowercase name / percentage) => id, this cycle only */
    private array $modelsMap = [];

    /** lowercase competency-type name => canonical name */
    private array $competencyTypes = [];

    /** lowercase review-tool names */
    private array $validReviewTools = [];

    private int $imported = 0;

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * @param  list<int>  $modelIds  the models of the cycle being imported into
     */
    public function __construct(
        private readonly string $employeeId,
        array $modelIds,
        private readonly PlanMasterRules $rules = new PlanMasterRules,
    ) {
        foreach (DevelopmentModel::whereIn('id', $modelIds ?: [0])->get() as $model) {
            $this->modelsMap[strtolower(trim($model->name))] = $model->id;
            $this->modelsMap[(string) $model->percentage] = $model->id;
            $this->modelsMap[$model->percentage.'%'] = $model->id;
        }

        // Every configured type, not a hard-coded pair: the catch-all "Others"
        // and anything the client adds later are just as valid.
        foreach (CompetencyType::get(['id', 'name_en']) as $type) {
            $this->competencyTypes[strtolower(trim($type->name_en))] = $type->name_en;
        }

        $this->validReviewTools = ReviewTool::pluck('name_en')
            ->map(fn ($value) => strtolower(trim($value)))
            ->all();
    }

    public function collection(Collection $rows): void
    {
        // A single-sheet importer is handed EVERY sheet in the workbook, and
        // this template carries reference tabs.
        if (! $this->isOurSheet($rows, self::OUR_HEADINGS)) {
            return;
        }

        foreach ($rows as $index => $row) {
            $line = $index + 2; // +1 heading row, +1 for 1-based display

            $data = $this->normalize($row);

            // Skip fully-empty rows.
            if ($this->isEmpty($data)) {
                continue;
            }

            if ($error = $this->validateShape($data, $line)) {
                $this->errors[] = $error;

                continue;
            }

            $attributes = [
                'employee_id' => $this->employeeId,
                'development_model_id' => $this->resolveModelId($data['development_model']),
                // Store the master's own spelling, not whatever case was typed.
                'competency_type' => $this->competencyTypes[strtolower($data['competency_type'])] ?? $data['competency_type'],
                'competency_name' => $data['competency_name'],
                'development_program' => $data['development_program'],
                'review_tools' => $data['review_tools'],
                'expected_outcome' => $data['expected_outcome'],
                'time_frame_start' => $data['time_frame_start'],
                'time_frame_end' => $data['time_frame_end'],
            ];

            // The screen's own cascade, run verbatim.
            if ($cascade = $this->rules->check($attributes)) {
                $this->errors[] = "Row {$line}: ".reset($cascade);

                continue;
            }

            IndividualDevelopmentPlan::create($attributes);

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
        // Says so when no sheet matched at all, rather than reading as an empty
        // file when it is really a mis-built one.
        return $this->withSheetError($this->errors);
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
     * The shape checks the cascade does not do: required fields, dates and
     * lengths. Returns a human-readable error, or null when the row is well
     * formed. Whether the values FIT the master data is PlanMasterRules' call.
     */
    private function validateShape(array $data, int $line): ?string
    {
        if ($data['competency_type'] === '') {
            return "Row {$line}: competency_type is required.";
        }

        if (! isset($this->competencyTypes[strtolower($data['competency_type'])])) {
            $known = implode(', ', array_values($this->competencyTypes));

            return "Row {$line}: competency_type '{$data['competency_type']}' is not a configured competency type (have: {$known}).";
        }

        if ($data['development_model'] === '' || $this->resolveModelId($data['development_model']) === null) {
            return "Row {$line}: development_model '{$data['development_model']}' is not a model of the cycle being imported into.";
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

        if ($data['expected_outcome'] !== null && mb_strlen($data['expected_outcome']) > 500) {
            return "Row {$line}: expected_outcome must not exceed 500 characters.";
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
