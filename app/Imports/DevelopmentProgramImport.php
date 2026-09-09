<?php

namespace App\Imports;

use App\Enums\MasterDataType;
use App\Imports\Contracts\ReportsImportOutcome;
use App\Imports\Support\SkipsForeignSheets;
use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\DevelopmentModel;
use App\Models\DevelopmentProgram;
use App\Models\Training;
use App\Services\Idp\Rules\ProgramMasterRules;
use App\Services\IdpMasterService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Bulk-import development programs — the "Master Development" screen. One row
 * per program, keyed by header: `model_package`, `development_model`,
 * `competency_type`, `competency`, `name_en`, `name_id`, `description_en`,
 * `description_id`, `training`, `proficiency_level`, `custom_proficiency_level`
 * and `grades`.
 *
 * This is the most tightly scoped of the master imports, because a program is
 * defined by what it sits between:
 *
 * - **A program is named per development model**, so the model is what a row is
 *   matched within — and since two packages may each hold a model of the same
 *   name, `model_package` is what tells them apart.
 * - **Where the name comes from is the model's decision, not the program's.** A
 *   model flagged `uses_master_training` names its programs from the Master
 *   Training catalogue: such a row must give a `training`, and its `name_en` is
 *   ignored in favour of the training's. Any other model types the name by hand.
 * - **The proficiency level and grades come from the master implementation
 *   map**, not from the competency directly — a program can only target what has
 *   actually been rolled out.
 *
 * None of those last rules are re-implemented here. {@see ProgramMasterRules} —
 * the object the master endpoints validate through — does both halves of the
 * job: `prepare()` resolves the name source, `check()` polices the scope, and
 * its validation error becomes this row's message.
 */
class DevelopmentProgramImport implements ReportsImportOutcome, ToCollection, WithHeadingRow
{
    use SkipsForeignSheets;

    /** What separates the values inside the `grades` cell. */
    private const SEPARATORS = '/[|;\r\n]+/';

    private int $created = 0;

    private int $updated = 0;

    /** @var array<int, string> */
    private array $errors = [];

    /**
     * Names already claimed in this run, keyed by model — a program's name is
     * unique within its development model, not globally.
     *
     * @var array<string, int>
     */
    private array $seen = [];

    public function __construct(
        private readonly IdpMasterService $masters,
        private readonly ProgramMasterRules $rules,
    ) {}

    public function collection(Collection $rows): void
    {
        if (! $this->isOurSheet($rows, ['development_model', 'competency_type', 'name_en'])) {
            return;
        }

        $models = DevelopmentModel::with('developmentModelPackage')->get();
        $types = $this->index(CompetencyType::get(['id', 'code', 'name_en']));
        $competencies = Competency::with('proficiencyLevels')->get();

        foreach ($rows as $index => $row) {
            $this->writeOne($index + 2, collect($row), $models, $types, $competencies);
        }
    }

    public function summary(): string
    {
        $total = $this->created + $this->updated;

        if ($total === 0) {
            return 'No development program was imported.';
        }

        return "Imported {$total} development program(s): {$this->created} created, {$this->updated} updated.";
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->withSheetError($this->errors);
    }

    /**
     * @param  Collection<int, DevelopmentModel>  $models
     * @param  array<string, int>  $types
     * @param  Collection<int, Competency>  $competencies
     */
    private function writeOne(
        int $line,
        Collection $row,
        Collection $models,
        array $types,
        Collection $competencies,
    ): void {
        if ($row->every(fn ($value) => trim((string) $value) === '')) {
            // A trailing blank row is not a mistake worth reporting.
            return;
        }

        $model = $this->model($row, $models, $line);

        if ($model === null) {
            return;
        }

        $typeId = $this->resolve($row, 'competency_type', $types, $line, 'competency type');

        if ($typeId === null) {
            return;
        }

        $competency = $this->competency($row, $competencies, $line);

        if ($competency === null) {
            return;
        }

        // The model decides where the name comes from, so the training has to
        // be resolved before the name can be judged.
        $trainingId = null;

        if ($model->uses_master_training) {
            $name = $this->cell($row, 'training');

            if ($name === null) {
                $this->errors[] = "Row {$line}: '{$model->name_en}' takes its programs from Master Training, "
                    .'so a training is required.';

                return;
            }

            $training = Training::where('name_en', $name)->first();

            if ($training === null) {
                $this->errors[] = "Row {$line}: training '{$name}' matches no master training.";

                return;
            }

            $trainingId = $training->id;
        }

        $level = $this->level($row, $competency, $line);

        if ($level === false) {
            return;
        }

        // `prepare()` is what copies a training's name and description onto the
        // payload — or nulls the training when the model types its own names.
        // Running it here rather than repeating it keeps the import and the
        // form on one rule.
        $request = Request::create('', 'POST', [
            'development_model_id' => $model->id,
            'training_id' => $trainingId,
            'value_en' => $this->cell($row, 'name_en'),
            'value_id' => $this->cell($row, 'name_id'),
            'description_en' => $this->cell($row, 'description_en'),
            'description_id' => $this->cell($row, 'description_id'),
        ]);

        $this->rules->prepare($request);

        $nameEn = $this->trimmed($request->input('value_en'));

        if ($nameEn === null) {
            $this->errors[] = "Row {$line}: name_en is required.";

            return;
        }

        if (mb_strlen($nameEn) > 1000) {
            $this->errors[] = "Row {$line}: name_en may not exceed 1000 characters.";

            return;
        }

        // A program's name is unique within its development model.
        $key = $model->id.'|'.mb_strtolower($nameEn);

        if (isset($this->seen[$key])) {
            $this->errors[] = "Row {$line}: '{$nameEn}' is already used by row {$this->seen[$key]} "
                ."under '{$model->name_en}'.";

            return;
        }

        // Under a master-training model the program's identity is the training
        // it points at, not the name — the name is only a copy of the
        // training's. Matching on it first is what lets a renamed training be
        // carried onto the program it named, rather than spawning a second one.
        $existing = $trainingId === null
            ? null
            : DevelopmentProgram::where('development_model_id', $model->id)
                ->where('training_id', $trainingId)
                ->first();

        $existing ??= DevelopmentProgram::where('development_model_id', $model->id)
            ->where('name_en', $nameEn)
            ->first();

        $data = [
            'value_en' => $nameEn,
            'value_id' => $this->trimmed($request->input('value_id')),
            'description_en' => $this->trimmed($request->input('description_en')),
            'description_id' => $this->trimmed($request->input('description_id')),
            'development_model_id' => $model->id,
            'competency_type_id' => $typeId,
            'training_id' => $request->input('training_id'),
            'related_competencies' => [$competency->id],
            'proficiency_level_id' => $level,
            'custom_proficiency_level' => $this->cell($row, 'custom_proficiency_level'),
            'grades' => $this->grades($row, $existing),
        ];

        // The form's own scope rules, run verbatim, so the import can never
        // accept a program the screen would reject.
        try {
            $this->rules->check($data, $existing);
        } catch (ValidationException $e) {
            $this->errors[] = "Row {$line}: ".($e->validator->errors()->first() ?: $e->getMessage());

            return;
        }

        try {
            if ($existing !== null) {
                $this->masters->update(MasterDataType::DevelopmentProgram, $existing, $data);
                $this->updated++;
            } else {
                $this->masters->create(MasterDataType::DevelopmentProgram, $data);
                $this->created++;
            }
        } catch (\Throwable $e) {
            // One unwritable program must not cost the rest of the file.
            $this->errors[] = "Row {$line}: could not be saved — {$e->getMessage()}";

            return;
        }

        $this->seen[$key] = $line;
    }

    /**
     * The development model a row is filed under. Model names are only unique
     * inside their package, so a name matching several models needs
     * `model_package` to tell them apart.
     *
     * @param  Collection<int, DevelopmentModel>  $models
     */
    private function model(Collection $row, Collection $models, int $line): ?DevelopmentModel
    {
        $name = $this->cell($row, 'development_model');

        if ($name === null) {
            $this->errors[] = "Row {$line}: development_model is required.";

            return null;
        }

        $matches = $models->filter(
            fn (DevelopmentModel $m) => mb_strtolower(trim((string) $m->name_en)) === mb_strtolower($name)
        );

        if ($package = $this->cell($row, 'model_package')) {
            $matches = $matches->filter(
                fn (DevelopmentModel $m) => mb_strtolower(trim((string) $m->developmentModelPackage?->name)) === mb_strtolower($package)
            );
        }

        if ($matches->isEmpty()) {
            $this->errors[] = "Row {$line}: development_model '{$name}'"
                .($package ? " in package '{$package}'" : '').' matches no development model.';

            return null;
        }

        if ($matches->count() > 1) {
            $packages = $matches->map(fn (DevelopmentModel $m) => $m->developmentModelPackage?->name ?? '?')->implode(', ');
            $this->errors[] = "Row {$line}: development_model '{$name}' exists in more than one package "
                ."({$packages}) — name the package in model_package.";

            return null;
        }

        return $matches->first();
    }

    /**
     * The single competency this program develops.
     *
     * @param  Collection<int, Competency>  $competencies
     */
    private function competency(Collection $row, Collection $competencies, int $line): ?Competency
    {
        $value = $this->cell($row, 'competency');

        if ($value === null) {
            $this->errors[] = "Row {$line}: competency is required.";

            return null;
        }

        $needle = mb_strtolower($value);

        $competency = $competencies->first(fn (Competency $c) => mb_strtolower(trim((string) $c->code)) === $needle)
            ?? $competencies->first(fn (Competency $c) => mb_strtolower(trim((string) $c->name_en)) === $needle);

        if ($competency === null) {
            $this->errors[] = "Row {$line}: competency '{$value}' matches no competency "
                .'(give its code or its English name).';
        }

        return $competency;
    }

    /**
     * The rung this program targets, as an id — resolved by name against the
     * chosen competency's own ladder. Null when the cell is blank, false when
     * the name matches nothing (the row's error is recorded).
     *
     * Whether that rung is actually *implemented* is not judged here: that is
     * {@see ProgramMasterRules::check()}'s call, and its message says so.
     */
    private function level(Collection $row, Competency $competency, int $line): int|false|null
    {
        $name = $this->cell($row, 'proficiency_level');

        if ($name === null) {
            return null;
        }

        $level = $competency->proficiencyLevels
            ->first(fn ($l) => mb_strtolower(trim((string) $l->name_en)) === mb_strtolower($name));

        if ($level === null) {
            $this->errors[] = "Row {$line}: '{$name}' is not a proficiency level of '{$competency->name_en}'.";

            return false;
        }

        return $level->id;
    }

    /**
     * The grades this program is scoped to. A blank cell keeps whatever the
     * program already has — silence never deletes, as everywhere else here —
     * and on a new program means "every grade".
     *
     * @return list<string>
     */
    private function grades(Collection $row, ?DevelopmentProgram $existing): array
    {
        $raw = $this->cell($row, 'grades');

        if ($raw === null) {
            return $existing?->grades->pluck('grade')->all() ?? [];
        }

        return array_values(array_unique(array_filter(array_map(
            'trim',
            preg_split(self::SEPARATORS, $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        ))));
    }

    /**
     * Look a master up by its code or its English name.
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

    private function cell(Collection $row, string $key): ?string
    {
        return $this->trimmed($row->get($key));
    }

    private function trimmed(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
