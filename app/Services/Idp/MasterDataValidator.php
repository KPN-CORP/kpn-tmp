<?php

namespace App\Services\Idp;

use App\Enums\MasterDataType;
use App\Services\Idp\Rules\CompetencyMasterRules;
use App\Services\Idp\Rules\ProgramMasterRules;
use App\Services\Idp\Rules\TrainingMasterRules;
use App\Support\FailsValidation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Validation for the one set of endpoints every kind of IDP master writes
 * through.
 *
 * The kinds share a table shape (a bilingual name + description, an optional
 * active flag) but not their constraints, so this class owns what they have in
 * common — the field rules, keyed off the kind — and hands the relational
 * checks to a rule class per kind. Each of those runs in two beats: `prepare()`
 * reshapes the request before the rules see it, `check()` polices what a field
 * rule cannot express once the data is validated.
 */
class MasterDataValidator
{
    use FailsValidation;

    public function __construct(
        private readonly CompetencyMasterRules $competencyRules,
        private readonly ProgramMasterRules $programRules,
        private readonly TrainingMasterRules $trainingRules,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function validate(Request $request, MasterDataType $type, ?Model $master = null): array
    {
        $isProgram = $type === MasterDataType::DevelopmentProgram;
        $isCompetency = $type === MasterDataType::CompetencyName;
        $isTraining = $type === MasterDataType::Training;

        // A program whose development model draws from Master Training carries
        // the training rather than the text. Resolving it up front means the
        // name rules - required, length, uniqueness per model - all police the
        // real value.
        if ($isProgram) {
            $this->programRules->prepare($request);
        }

        // A nested row the user added and never touched is not an error, it is
        // a row they changed their mind about - so it goes before the rules see
        // it. A row with anything at all in it stays and has to name itself.
        if ($isCompetency) {
            $this->competencyRules->prepare($request);
        }

        $data = $request->validate(
            $this->rules($request, $type, $master),
            $this->messages($type),
        );

        if ($isCompetency) {
            $this->competencyRules->check($data, $master);
        }

        // A program reaches its masters through the implementation map, so its
        // competencies have to still be usable and its level + grades have to
        // come from an implementation of those competencies.
        if ($isProgram) {
            $this->programRules->check($data, $master);
        }

        if ($isTraining) {
            $this->trainingRules->check($data, $master);
        }

        return $data;
    }

    /**
     * Reject a wire `type` that names no master kind. Shared by the endpoints
     * that read the kind from the body rather than the path.
     */
    public function resolveType(mixed $value): MasterDataType
    {
        return MasterDataType::tryFrom((string) $value)
            ?? $this->fail('type', 'The selected master data type is invalid.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, MasterDataType $type, ?Model $master): array
    {
        $isProgram = $type === MasterDataType::DevelopmentProgram;
        $isCompetency = $type === MasterDataType::CompetencyName;
        $isTraining = $type === MasterDataType::Training;
        $isCompetencyType = $type === MasterDataType::CompetencyType;

        $uniqueName = Rule::unique($type->table(), 'name_en')->ignore($master?->id);

        // Whether the program's development model says its name comes from the
        // Master Training catalogue - and whether one has actually been picked.
        // `prepare()` has already run, so a training that resolved is on the
        // request and its name has been copied over.
        $needsTraining = $isProgram && $this->programRules->requiresTraining($request);
        $trainingMissing = $needsTraining && $request->input('training_id') === null;

        if ($isProgram) {
            // Programs are named per development model.
            $uniqueName->where('development_model_id', $request->input('development_model_id'));
        }

        // Program "names" are activity descriptions and run long; every other
        // master is a short label.
        $nameLength = 'max:'.($isProgram ? 1000 : 255);

        return [
            'type' => [$master ? 'sometimes' : 'required', 'string', 'in:'.MasterDataType::validationList()],
            // The short identifier a competency and a competency type each
            // carry. The rules — and the unique index behind them — are per
            // table, so the two namespaces are independent; every other kind
            // must not send the field at all.
            'code' => $type->hasCode()
                ? [
                    'required', 'string', 'max:50',
                    Rule::unique($type->table(), 'code')->ignore($master?->id),
                ]
                : ['prohibited'],
            // The name is required, except when it was to come from a training
            // that has not been picked: the missing training is then the single
            // error worth reporting, since the form shows no name field at all.
            'value_en' => [$trainingMissing ? 'nullable' : 'required', 'string', $nameLength, $uniqueName],
            'value_id' => ['nullable', 'string', $nameLength],
            'description_en' => ['nullable', 'string'],
            'description_id' => ['nullable', 'string'],
            // Active/inactive (competencies / trainings / review tools). Absent
            // means active.
            'is_active' => ['nullable', 'boolean'],
            'development_model_id' => ['nullable', 'integer', 'exists:development_models,id'],
            // Set when a program takes its name from the Master Training
            // catalogue; null when the name is typed. Which of the two applies
            // is the development model's call, so a model that declares it
            // makes the training required.
            'training_id' => [$needsTraining ? 'required' : 'nullable', 'integer', 'exists:trainings,id'],
            'competency_type_id' => [
                // Development programs, competencies and trainings are all
                // classified by a competency type; the competency's type is
                // what scopes the proficiency levels it may pin, and a
                // training's type is what scopes the competency it builds.
                $isProgram || $isCompetency || $isTraining ? 'required' : 'nullable',
                'integer', 'exists:competency_types,id',
            ],
            // The single competency a training builds.
            'competency_id' => [
                $isTraining ? 'required' : 'nullable',
                'integer', 'exists:competencies,id',
            ],
            // The rung a program targets, taken from its competency's ladder.
            'proficiency_level_id' => [
                'nullable',
                'integer', 'exists:competency_proficiency_levels,id',
            ],
            // The rungs a training targets, likewise from the ladder of the
            // competency it builds.
            'proficiency_level_ids' => ['nullable', 'array'],
            'proficiency_level_ids.*' => ['integer', 'exists:competency_proficiency_levels,id'],
            'related_programs' => ['nullable', 'array'],
            'related_programs.*' => ['integer', 'exists:development_programs,id'],
            // A competency's sub-competencies, edited inline as rows. An `id`
            // means "update that row"; it is checked against the competency's
            // own rows in the service, so a foreign id simply creates instead.
            'sub_competencies' => ['nullable', 'array'],
            'sub_competencies.*.id' => ['nullable', 'integer'],
            'sub_competencies.*.name_en' => ['required', 'string', 'max:255'],
            'sub_competencies.*.name_id' => ['nullable', 'string', 'max:255'],
            'sub_competencies.*.description_en' => ['nullable', 'string'],
            'sub_competencies.*.description_id' => ['nullable', 'string'],
            // A competency's own proficiency ladder, typed in as rows, each
            // with its own key behaviors. As with the sub-competencies, an
            // `id` means "update that row" and is checked against the
            // competency's own rows in the service. Neither list carries a
            // sequence: both are ordered by row position, which the service
            // reads off the submitted order.
            'proficiency_levels' => ['nullable', 'array'],
            'proficiency_levels.*.id' => ['nullable', 'integer'],
            'proficiency_levels.*.name_en' => ['required', 'string', 'max:255'],
            'proficiency_levels.*.name_id' => ['nullable', 'string', 'max:255'],
            'proficiency_levels.*.description_en' => ['nullable', 'string'],
            'proficiency_levels.*.description_id' => ['nullable', 'string'],
            'proficiency_levels.*.is_active' => ['nullable', 'boolean'],
            'proficiency_levels.*.key_behaviors' => ['nullable', 'array'],
            'proficiency_levels.*.key_behaviors.*.id' => ['nullable', 'integer'],
            'proficiency_levels.*.key_behaviors.*.name_en' => ['required', 'string', 'max:255'],
            'proficiency_levels.*.key_behaviors.*.name_id' => ['nullable', 'string', 'max:255'],
            // A development program develops exactly one competency. It stays
            // a list on the wire because the link is a pivot — a competency
            // reaches many programs, and the competency screen edits that side.
            'related_competencies' => ['nullable', 'array', 'max:1'],
            'related_competencies.*' => ['integer', 'exists:competencies,id'],
            // Free-typed proficiency level for an "Others" program.
            'custom_proficiency_level' => ['nullable', 'string', 'max:255'],
            // Development-program corporate scope: any number of grades, each
            // stored as the raw string.
            'grades' => ['nullable', 'array'],
            'grades.*' => ['string', 'max:255'],
            // Corporate scope, as raw kpncorp business-unit names. A
            // competency type must name at least one unit — it is what says
            // where the type applies. A training's units are optional, but a
            // work location belongs to a unit, so it can't stand alone.
            'business_units' => [
                $isCompetencyType ? 'required' : 'nullable',
                'array', 'required_with:work_locations',
            ],
            'business_units.*' => ['string', 'max:255'],
            'work_locations' => ['nullable', 'array'],
            'work_locations.*' => ['string', 'max:255'],
        ];
    }

    /**
     * The generated messages would read "The sub_competencies.0.name_en field
     * is required", which names the wire path rather than the field. These are
     * shown per row, so they say what is missing.
     *
     * @return array<string, string>
     */
    private function messages(MasterDataType $type): array
    {
        // Both coded kinds share these rules, so the noun follows the kind
        // being saved rather than naming one of them.
        $coded = $type === MasterDataType::CompetencyType ? 'competency type' : 'competency';

        return [
            'code.required' => "A {$coded} needs a code.",
            'code.unique' => "Another {$coded} already uses this code.",
            'code.prohibited' => 'Only a competency or a competency type carries a code.',
            'training_id.required' => 'The selected development model takes its programs from Master Training, so a training must be chosen.',
            'sub_competencies.*.name_en.required' => 'Every sub competency needs an English name.',
            'sub_competencies.*.name_en.max' => 'A sub competency name may not be longer than 255 characters.',
            'sub_competencies.*.name_id.max' => 'A sub competency name may not be longer than 255 characters.',
            'proficiency_levels.*.name_en.required' => 'Every proficiency level needs an English name.',
            'proficiency_levels.*.name_en.max' => 'A proficiency level name may not be longer than 255 characters.',
            'proficiency_levels.*.name_id.max' => 'A proficiency level name may not be longer than 255 characters.',
            'proficiency_levels.*.key_behaviors.*.name_en.required' => 'Every key behavior needs an English name.',
            'proficiency_levels.*.key_behaviors.*.name_en.max' => 'A key behavior name may not be longer than 255 characters.',
            'proficiency_levels.*.key_behaviors.*.name_id.max' => 'A key behavior name may not be longer than 255 characters.',
        ];
    }
}
