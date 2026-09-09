<?php

namespace App\Imports;

use App\Enums\MasterDataType;
use App\Imports\Contracts\ReportsImportOutcome;
use App\Imports\Support\SkipsForeignSheets;
use App\Models\BusinessUnit;
use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\Training;
use App\Services\CorporateScopeService;
use App\Services\Idp\Rules\TrainingMasterRules;
use App\Services\IdpMasterService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-import master trainings. One row per training, keyed by header:
 * `name_en`, `name_id`, `description_en`, `description_id`, `competency_type`,
 * `competency`, `proficiency_levels`, `business_units`, `work_locations` and
 * `is_active`.
 *
 * Unlike a competency, a training's three lists — the rungs it targets and the
 * business units / work locations it is offered in — are plain lists of names
 * with no attributes of their own. So they ride in one cell each, separated by
 * `;`, rather than earning a sheet apiece.
 *
 * Two things are worth knowing:
 *
 * - **A training has no code**, so a row is matched to a stored training by its
 *   English name alone. Renaming one is therefore not something this import can
 *   do — a new name simply creates a new training.
 * - **A blank list cell leaves the stored list alone** rather than clearing it,
 *   matching how the competency import treats a sheet it was not given. Which
 *   means a list cannot be emptied from a spreadsheet; that stays the Master
 *   Training screen's job.
 *
 * The scope rules themselves are not re-implemented here: once the names are
 * resolved to ids, {@see TrainingMasterRules} — the very rules the form runs —
 * polices the result, and its validation error becomes this row's message.
 */
class TrainingImport implements ReportsImportOutcome, ToCollection, WithHeadingRow
{
    use SkipsForeignSheets;

    /**
     * What separates the values inside one list cell. The same set the
     * competency-type import accepts, and not the comma, since a corporate unit
     * or site name may well carry one.
     */
    private const SEPARATORS = '/[|;\r\n]+/';

    private int $created = 0;

    private int $updated = 0;

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<string, int> */
    private array $seenNames = [];

    public function __construct(
        private readonly IdpMasterService $masters,
        private readonly TrainingMasterRules $rules,
        private readonly CorporateScopeService $corporate,
    ) {}

    public function collection(Collection $rows): void
    {
        if (! $this->isOurSheet($rows, ['name_en', 'competency', 'competency_type'])) {
            return;
        }

        $types = $this->index(CompetencyType::get(['id', 'code', 'name_en']));
        $competencies = Competency::with('proficiencyLevels')->get();
        $byRef = $this->index($competencies);
        $units = $this->corporate->businessUnits();

        foreach ($rows as $index => $row) {
            $this->writeOne($index + 2, collect($row), $types, $byRef, $competencies, $units);
        }
    }

    public function summary(): string
    {
        $total = $this->created + $this->updated;

        if ($total === 0) {
            return 'No master training was imported.';
        }

        return "Imported {$total} master training(s): {$this->created} created, {$this->updated} updated.";
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->withSheetError($this->errors);
    }

    /**
     * @param  array<string, int>  $types
     * @param  array<string, int>  $byRef
     * @param  Collection<int, Competency>  $competencies
     * @param  list<string>  $units
     */
    private function writeOne(
        int $line,
        Collection $row,
        array $types,
        array $byRef,
        Collection $competencies,
        array $units,
    ): void {
        $nameEn = $this->cell($row, 'name_en');

        if ($nameEn === null) {
            // Blank throughout is a trailing row, not a mistake.
            if ($row->every(fn ($value) => trim((string) $value) === '')) {
                return;
            }

            $this->errors[] = "Row {$line}: name_en is required.";

            return;
        }

        if (mb_strlen($nameEn) > 255) {
            $this->errors[] = "Row {$line}: name_en may not exceed 255 characters.";

            return;
        }

        $nameKey = mb_strtolower($nameEn);

        if (isset($this->seenNames[$nameKey])) {
            $this->errors[] = "Row {$line}: name '{$nameEn}' is already used by row {$this->seenNames[$nameKey]}.";

            return;
        }

        // The name is the only identifier a training has.
        $existing = Training::where('name_en', $nameEn)->first();

        $typeId = $this->resolve($row, 'competency_type', $types, $line, 'competency type');
        $competencyId = $this->resolve($row, 'competency', $byRef, $line, 'competency');

        if ($typeId === null || $competencyId === null) {
            return;
        }

        $competency = $competencies->firstWhere('id', $competencyId);

        $levels = $this->levelIds($row, $competency, $existing, $line);
        $businessUnits = $this->units($row, $units, $existing, $line);

        if ($levels === false || $businessUnits === false) {
            return;
        }

        $locations = $this->list($row, 'work_locations')
            ?? ($existing?->workLocations->pluck('work_location')->all() ?? []);

        if ($locations !== [] && $businessUnits === []) {
            $this->errors[] = "Row {$line}: work_locations needs at least one business_unit.";

            return;
        }

        $data = [
            'value_en' => $nameEn,
            'value_id' => $this->cell($row, 'name_id'),
            'description_en' => $this->cell($row, 'description_en'),
            'description_id' => $this->cell($row, 'description_id'),
            'is_active' => $this->flag($row),
            'competency_type_id' => $typeId,
            'competency_id' => $competencyId,
            'proficiency_level_ids' => $levels,
            'business_units' => $businessUnits,
            'work_locations' => $locations,
        ];

        // The form's own scope rules, run verbatim, so the import can never
        // accept a training the screen would reject.
        try {
            $this->rules->check($data, $existing);
        } catch (ValidationException $e) {
            $this->errors[] = "Row {$line}: ".($e->validator->errors()->first() ?: $e->getMessage());

            return;
        }

        try {
            if ($existing !== null) {
                $this->masters->update(MasterDataType::Training, $existing, $data);
                $this->updated++;
            } else {
                $this->masters->create(MasterDataType::Training, $data);
                $this->created++;
            }
        } catch (\Throwable $e) {
            // One unwritable training must not cost the rest of the file.
            $this->errors[] = "Row {$line}: could not be saved — {$e->getMessage()}";

            return;
        }

        $this->seenNames[$nameKey] = $line;
    }

    /**
     * The rungs this training targets, as ids — resolved by name against the
     * chosen competency's own ladder, which is where a level comes from now.
     * False when a name matches nothing (the row's error is recorded).
     *
     * @return array<int, int>|false
     */
    private function levelIds(Collection $row, ?Competency $competency, ?Training $existing, int $line): array|false
    {
        $names = $this->list($row, 'proficiency_levels');

        if ($names === null) {
            return $existing?->proficiencyLevels->pluck('id')->all() ?? [];
        }

        $ladder = collect($competency?->proficiencyLevels ?? [])
            ->keyBy(fn ($level) => mb_strtolower(trim((string) $level->name_en)));

        $ids = [];
        $unknown = [];

        foreach ($names as $name) {
            $level = $ladder->get(mb_strtolower($name));

            if ($level === null) {
                $unknown[] = $name;

                continue;
            }

            $ids[] = $level->id;
        }

        if ($unknown !== []) {
            $this->errors[] = "Row {$line}: '".implode("', '", $unknown)."' "
                .(count($unknown) === 1 ? 'is not a proficiency level' : 'are not proficiency levels')
                ." of '".($competency?->name_en ?? '?')."'.";

            return false;
        }

        return array_values(array_unique($ids));
    }

    /**
     * The business units this training is offered in, folded onto the corporate
     * master. False when one of them names no unit at all.
     *
     * @param  list<string>  $master
     * @return list<string>|false
     */
    private function units(Collection $row, array $master, ?Training $existing, int $line): array|false
    {
        $names = $this->list($row, 'business_units');

        if ($names === null) {
            return $existing?->businessUnits->pluck('business_unit')->all() ?? [];
        }

        $units = [];
        $unknown = [];

        foreach ($names as $name) {
            // With the corporate master unreachable there is nothing to check
            // against, so the value is taken as typed rather than rejected.
            $resolved = $master === [] ? $name : BusinessUnit::resolveName($name, $master);

            if ($resolved === null) {
                $unknown[] = $name;

                continue;
            }

            $units[] = $resolved;
        }

        if ($unknown !== []) {
            $this->errors[] = "Row {$line}: unknown business unit(s): ".implode(', ', $unknown).'.';

            return false;
        }

        return array_values(array_unique($units));
    }

    /**
     * Look a master up by the two things a row may name it with — its code, or
     * its English name. Reports and returns null when the cell is empty or
     * matches nothing.
     *
     * @param  array<string, int>  $index
     */
    private function resolve(Collection $row, string $key, array $index, int $line, string $label): ?int
    {
        $value = $this->cell($row, $key);

        if ($value === null) {
            $this->errors[] = "Row {$line}: {$key} is required.";

            return null;
        }

        $id = $index[mb_strtolower($value)] ?? null;

        if ($id === null) {
            $this->errors[] = "Row {$line}: {$key} '{$value}' matches no {$label} "
                .'(give its code or its English name).';
        }

        return $id;
    }

    /**
     * Masters by the two things a row may name them with, lower-cased since
     * neither is matched by case.
     *
     * @param  Collection<int, Model>  $models
     * @return array<string, int>
     */
    private function index(Collection $models): array
    {
        $index = [];

        foreach ($models as $model) {
            if (trim((string) $model->code) !== '') {
                $index[mb_strtolower(trim((string) $model->code))] = $model->id;
            }

            $index[mb_strtolower(trim((string) $model->name_en))] = $model->id;
        }

        return $index;
    }

    /**
     * One list cell, split and cleaned — or null when the cell is blank, which
     * means "leave the stored list alone" rather than "empty it".
     *
     * @return list<string>|null
     */
    private function list(Collection $row, string $key): ?array
    {
        $raw = $this->cell($row, $key);

        if ($raw === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split(self::SEPARATORS, $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ))));
    }

    /**
     * A yes/no cell. Anything unrecognised — a blank one included — means
     * active, matching the form, where a new row arrives switched on.
     */
    private function flag(Collection $row): bool
    {
        $value = $this->cell($row, 'is_active');

        if ($value === null) {
            return true;
        }

        return ! in_array(mb_strtolower($value), ['0', 'no', 'false', 'n', 'inactive', 'tidak'], true);
    }

    private function cell(Collection $row, string $key): ?string
    {
        $value = trim((string) $row->get($key));

        return $value === '' ? null : $value;
    }
}
