<?php

namespace App\Imports;

use App\Enums\MasterDataType;
use App\Imports\Contracts\ReportsImportOutcome;
use App\Imports\Support\SheetBuffer;
use App\Models\Competency;
use App\Models\CompetencyType;
use App\Services\Idp\Rules\CompetencyMasterRules;
use App\Services\IdpMasterService;
use Maatwebsite\Excel\Concerns\SkipsUnknownSheets;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Events\AfterImport;

/**
 * Bulk-import competencies, over the four sheets of the Master Competency
 * template.
 *
 * A competency carries three independent child lists — its sub-competencies,
 * the rungs of its own proficiency ladder, and the key behaviors under each
 * rung — so it cannot be one row. Each list gets a sheet, joined back to the
 * competency by its `code`; a key behavior joins one step further, naming its
 * rung. Nothing can be acted on until the whole workbook is read, which is why
 * the sheets only buffer and the work happens in {@see AfterImport}.
 *
 * Writes go through {@see IdpMasterService} in the wire shape the competency
 * form posts, so an imported competency is built exactly the way a typed one
 * is.
 *
 * Two rules are worth knowing before reading the code:
 *
 * - **Silence never deletes.** A child list is replaced wholesale only when the
 *   file actually carries a row for that competency; a competency listed with
 *   no ladder rows keeps the ladder it has. So a rung can be removed by leaving
 *   it out of a file that still lists its siblings, but a list cannot be
 *   emptied entirely — that is the form's job.
 * - **Child rows are matched to existing rows by name**, and their ids are sent
 *   back. Without that the service would delete and recreate every rung on each
 *   import, and the implementations, trainings and programs pointing at those
 *   rungs would lose their link.
 */
class CompetencyImport implements ReportsImportOutcome, SkipsUnknownSheets, WithEvents, WithMultipleSheets
{
    /**
     * The tabs this importer reads, each listed under every name it has gone
     * by. The template numbers them ("1. Master Competency") so that a reader
     * can tell the sheets that are saved from the reference tabs beside them;
     * the older, unnumbered names are still accepted so a file built from an
     * earlier download keeps working. A name the workbook does not carry is
     * simply skipped ({@see onUnknownSheet()}).
     */
    private const SHEET_COMPETENCY = ['1. Master Competency', 'Competency'];

    private const SHEET_SUB = ['2. Sub Competency', 'Sub Competency'];

    private const SHEET_LEVEL = ['3. Proficiency Level', 'Proficiency Level'];

    private const SHEET_BEHAVIOR = ['4. Key Behavior', 'Key Behavior'];

    private SheetBuffer $competencies;

    private SheetBuffer $subCompetencies;

    private SheetBuffer $levels;

    private SheetBuffer $behaviors;

    private int $created = 0;

    private int $updated = 0;

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, int> */
    private array $seenCodes = [];

    /** @var array<string, int> */
    private array $seenNames = [];

    public function __construct(
        private readonly IdpMasterService $masters,
        private readonly CompetencyMasterRules $rules,
    ) {
        $this->competencies = new SheetBuffer;
        $this->subCompetencies = new SheetBuffer;
        $this->levels = new SheetBuffer;
        $this->behaviors = new SheetBuffer;
    }

    /**
     * @return array<string, SheetBuffer>
     */
    public function sheets(): array
    {
        $map = [];

        foreach ([
            [self::SHEET_COMPETENCY, $this->competencies],
            [self::SHEET_SUB, $this->subCompetencies],
            [self::SHEET_LEVEL, $this->levels],
            [self::SHEET_BEHAVIOR, $this->behaviors],
        ] as [$names, $buffer]) {
            foreach ($names as $name) {
                $map[$name] = $buffer;
            }
        }

        return $map;
    }

    /**
     * A workbook missing one of the child sheets still imports what it has —
     * only the Competency sheet is indispensable, and its absence is reported
     * in {@see write()}.
     */
    public function onUnknownSheet($sheetName): void
    {
        // Nothing to do: the buffer for that sheet simply stays unread, which
        // is exactly what "this list was not part of the upload" means.
    }

    /**
     * @return array<string, callable>
     */
    public function registerEvents(): array
    {
        return [AfterImport::class => fn () => $this->write()];
    }

    public function summary(): string
    {
        $total = $this->created + $this->updated;

        if ($total === 0) {
            return 'No competency was imported.';
        }

        return "Imported {$total} competency(ies): {$this->created} created, {$this->updated} updated.";
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Write the whole workbook, once every sheet has been read.
     */
    private function write(): void
    {
        if (! $this->competencies->wasRead()) {
            $this->errors[] = "The workbook has no '".self::SHEET_COMPETENCY[0]."' sheet. Start from the template.";

            return;
        }

        $types = $this->competencyTypeIndex();
        $subs = $this->groupByCompetency($this->subCompetencies, self::SHEET_SUB[0]);
        $ladders = $this->groupByCompetency($this->levels, self::SHEET_LEVEL[0]);
        $behaviors = $this->groupByCompetency($this->behaviors, self::SHEET_BEHAVIOR[0]);

        $known = [];

        foreach ($this->competencies->rows() as $row) {
            $code = $this->cell($row['cells'], 'code');

            if ($code !== null) {
                $known[mb_strtolower($code)] = true;
            }

            $this->writeOne($row, $types, $subs, $ladders, $behaviors);
        }

        // A child row pointing at a competency the workbook never lists has
        // nowhere to go, and saying so beats letting it vanish silently.
        foreach ([$subs, $ladders, $behaviors] as $group) {
            foreach ($group as $key => $rows) {
                if (isset($known[$key])) {
                    continue;
                }

                $first = $rows[0];
                $this->errors[] = "{$first['sheet']} row {$first['line']}: competency_code '{$first['code']}' "
                    ."is not listed on the '".self::SHEET_COMPETENCY[0]."' sheet.";
            }
        }
    }

    /**
     * @param  array{line: int, cells: array<string, mixed>}  $row
     * @param  array<string, int>  $types
     * @param  array<string, list<array<string, mixed>>>  $subs
     * @param  array<string, list<array<string, mixed>>>  $ladders
     * @param  array<string, list<array<string, mixed>>>  $behaviors
     */
    private function writeOne(array $row, array $types, array $subs, array $ladders, array $behaviors): void
    {
        $line = $row['line'];
        $cells = $row['cells'];

        $code = $this->cell($cells, 'code');
        $nameEn = $this->cell($cells, 'name_en');

        if ($code === null && $nameEn === null) {
            // A trailing blank row is not a mistake worth reporting.
            return;
        }

        if ($code === null || $nameEn === null) {
            $this->errors[] = "Row {$line}: missing code or name_en.";

            return;
        }

        if (mb_strlen($code) > 50 || mb_strlen($nameEn) > 255) {
            $this->errors[] = "Row {$line}: code may not exceed 50 characters, name_en 255.";

            return;
        }

        // Compared case-insensitively, matching the unique indexes behind them.
        $codeKey = mb_strtolower($code);
        $nameKey = mb_strtolower($nameEn);

        if (isset($this->seenCodes[$codeKey])) {
            $this->errors[] = "Row {$line}: code '{$code}' is already used by row {$this->seenCodes[$codeKey]}.";

            return;
        }

        if (isset($this->seenNames[$nameKey])) {
            $this->errors[] = "Row {$line}: name '{$nameEn}' is already used by row {$this->seenNames[$nameKey]}.";

            return;
        }

        $rawType = $this->cell($cells, 'competency_type');

        if ($rawType === null) {
            $this->errors[] = "Row {$line}: competency_type is required.";

            return;
        }

        $typeId = $types[mb_strtolower($rawType)] ?? null;

        if ($typeId === null) {
            $this->errors[] = "Row {$line}: competency_type '{$rawType}' matches no competency type "
                .'(give its code or its English name).';

            return;
        }

        // Code first, so importing over a competency that predates the column
        // is what gives it one instead of creating a second by that name.
        $existing = Competency::where('code', $code)->first()
            ?? Competency::where('name_en', $nameEn)->first();

        if ($clash = $this->clash($existing, 'code', $code)) {
            $this->errors[] = "Row {$line}: code '{$code}' already belongs to '{$clash}'.";

            return;
        }

        if ($clash = $this->clash($existing, 'name_en', $nameEn)) {
            $this->errors[] = "Row {$line}: name '{$nameEn}' already belongs to the competency coded '{$clash}'.";

            return;
        }

        $data = [
            'code' => $code,
            'competency_type_id' => $typeId,
            'value_en' => $nameEn,
            'value_id' => $this->cell($cells, 'name_id'),
            'description_en' => $this->cell($cells, 'description_en'),
            'description_id' => $this->cell($cells, 'description_id'),
            'is_active' => $this->flag($cells, 'is_active'),
        ];

        // Silence never deletes: a list is only replaced when the file carries
        // a row for it.
        if (isset($subs[$codeKey])) {
            $data['sub_competencies'] = $this->subRows($subs[$codeKey], $existing);
        }

        if (isset($ladders[$codeKey])) {
            $ladder = $this->ladderRows($codeKey, $ladders[$codeKey], $behaviors, $existing, $line);

            if ($ladder === null) {
                return;
            }

            $data['proficiency_levels'] = $ladder;
        } elseif (isset($behaviors[$codeKey])) {
            $this->errors[] = "Row {$line}: '{$code}' has key behaviors but no proficiency level rows — "
                .'a ladder is imported whole, rungs and behaviors together.';

            return;
        }

        try {
            if ($existing !== null) {
                // The keys this caller actually sent. Passing them is what stops
                // the service wiping the lists this workbook says nothing about
                // — the development-program links among them, which this import
                // never manages.
                $this->masters->update(MasterDataType::CompetencyName, $existing, $data, array_keys($data));
                $this->updated++;
            } else {
                $this->masters->create(MasterDataType::CompetencyName, $data);
                $this->created++;
            }
        } catch (\Throwable $e) {
            // One unwritable competency must not cost the rest of the file.
            $this->errors[] = "Row {$line}: could not be saved — {$e->getMessage()}";

            return;
        }

        $this->seenCodes[$codeKey] = $line;
        $this->seenNames[$nameKey] = $line;
    }

    /**
     * The sub-competency rows for one competency, carrying the id of the stored
     * row of the same name so an existing part is updated rather than replaced.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function subRows(array $rows, ?Competency $existing): array
    {
        $stored = $existing === null
            ? collect()
            : $existing->subCompetencies->keyBy(fn ($sub) => mb_strtolower(trim((string) $sub->name_en)));

        $out = [];

        foreach ($rows as $row) {
            $name = $this->cell($row['cells'], 'name_en');

            if ($name === null) {
                $this->errors[] = "{$row['sheet']} row {$row['line']}: name_en is required.";

                continue;
            }

            $out[] = [
                'id' => $stored->get(mb_strtolower($name))?->id,
                'name_en' => $name,
                'name_id' => $this->cell($row['cells'], 'name_id'),
                'description_en' => $this->cell($row['cells'], 'description_en'),
                'description_id' => $this->cell($row['cells'], 'description_id'),
            ];
        }

        return $out;
    }

    /**
     * One competency's whole ladder — the rungs in sheet order, each with the
     * key behaviors filed under it — or null when the ladder cannot be built,
     * in which case the competency's error has been recorded and it is skipped
     * rather than half-written.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<array<string, mixed>>>  $behaviors
     * @return list<array<string, mixed>>|null
     */
    private function ladderRows(
        string $codeKey,
        array $rows,
        array $behaviors,
        ?Competency $existing,
        int $line,
    ): ?array {
        $stored = $existing === null
            ? collect()
            : $existing->proficiencyLevels()->with('keyBehaviors')->get()
                ->keyBy(fn ($level) => mb_strtolower(trim((string) $level->name_en)));

        // Key behaviors, bucketed by the rung they name.
        $byRung = [];
        foreach ($behaviors[$codeKey] ?? [] as $row) {
            $rung = $this->cell($row['cells'], 'proficiency_level');

            if ($rung === null) {
                $this->errors[] = "{$row['sheet']} row {$row['line']}: proficiency_level is required.";

                continue;
            }

            $byRung[mb_strtolower($rung)][] = $row;
        }

        $out = [];
        $kept = [];

        foreach ($rows as $row) {
            $name = $this->cell($row['cells'], 'name_en');

            if ($name === null) {
                $this->errors[] = "{$row['sheet']} row {$row['line']}: name_en is required.";

                continue;
            }

            $key = mb_strtolower($name);
            $level = $stored->get($key);

            if ($level !== null) {
                $kept[] = $level->id;
            }

            $out[] = [
                'id' => $level?->id,
                'name_en' => $name,
                'name_id' => $this->cell($row['cells'], 'name_id'),
                'description_en' => $this->cell($row['cells'], 'description_en'),
                'description_id' => $this->cell($row['cells'], 'description_id'),
                'is_active' => $this->flag($row['cells'], 'is_active'),
                'key_behaviors' => $this->behaviorRows($byRung[$key] ?? [], $level),
            ];

            unset($byRung[$key]);
        }

        // Whatever is left named a rung this competency's ladder does not have.
        foreach ($byRung as $rows) {
            $first = $rows[0];
            $this->errors[] = "{$first['sheet']} row {$first['line']}: proficiency_level "
                ."'".$this->cell($first['cells'], 'proficiency_level')."' is not one of "
                ."'{$first['code']}'s proficiency level rows.";
        }

        // The service deletes the rungs the list leaves out, and a rung another
        // screen points at must not go that way.
        if ($existing !== null && ($blocker = $this->rules->removalBlocker($existing, $kept))) {
            $this->errors[] = "Row {$line}: {$blocker}";

            return null;
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function behaviorRows(array $rows, mixed $level): array
    {
        $stored = $level === null
            ? collect()
            : $level->keyBehaviors->keyBy(fn ($behavior) => mb_strtolower(trim((string) $behavior->name_en)));

        $out = [];

        foreach ($rows as $row) {
            $name = $this->cell($row['cells'], 'name_en');

            if ($name === null) {
                $this->errors[] = "{$row['sheet']} row {$row['line']}: name_en is required.";

                continue;
            }

            $out[] = [
                'id' => $stored->get(mb_strtolower($name))?->id,
                'name_en' => $name,
                'name_id' => $this->cell($row['cells'], 'name_id'),
            ];
        }

        return $out;
    }

    /**
     * A child sheet's rows, bucketed by the competency code they name, in sheet
     * order. A row naming no competency at all is reported here rather than
     * carried around as a bucket nothing can match.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function groupByCompetency(SheetBuffer $buffer, string $sheet): array
    {
        $grouped = [];

        foreach ($buffer->rows() as $row) {
            $code = $this->cell($row['cells'], 'competency_code');

            if ($code === null) {
                // Blank throughout means a trailing row, not a mistake.
                if (collect($row['cells'])->every(fn ($v) => trim((string) $v) === '')) {
                    continue;
                }

                $this->errors[] = "{$sheet} row {$row['line']}: competency_code is required.";

                continue;
            }

            $grouped[mb_strtolower($code)][] = $row + ['sheet' => $sheet, 'code' => $code];
        }

        return $grouped;
    }

    /**
     * Competency types by the two things a row may name them with: their code,
     * and their English name. Lower-cased, since neither is matched by case.
     *
     * @return array<string, int>
     */
    private function competencyTypeIndex(): array
    {
        $index = [];

        foreach (CompetencyType::get(['id', 'code', 'name_en']) as $type) {
            if ($type->code !== null && trim((string) $type->code) !== '') {
                $index[mb_strtolower(trim((string) $type->code))] = $type->id;
            }

            $index[mb_strtolower(trim((string) $type->name_en))] = $type->id;
        }

        return $index;
    }

    /**
     * The other competency already holding this value, named for the error
     * message, or null when the value is free.
     */
    private function clash(?Competency $existing, string $column, string $value): ?string
    {
        $other = Competency::where($column, $value)
            ->when($existing !== null, fn ($query) => $query->whereKeyNot($existing->id))
            ->first();

        if ($other === null) {
            return null;
        }

        return $column === 'code' ? $other->name_en : (string) ($other->code ?? '—');
    }

    /**
     * A yes/no cell. Anything unrecognised — a blank one included — means
     * active, matching the form, where a new row arrives switched on.
     *
     * @param  array<string, mixed>  $cells
     */
    private function flag(array $cells, string $key): bool
    {
        $value = $this->cell($cells, $key);

        if ($value === null) {
            return true;
        }

        return ! in_array(mb_strtolower($value), ['0', 'no', 'false', 'n', 'inactive', 'tidak'], true);
    }

    /**
     * @param  array<string, mixed>  $cells
     */
    private function cell(array $cells, string $key): ?string
    {
        $value = trim((string) ($cells[$key] ?? ''));

        return $value === '' ? null : $value;
    }
}
