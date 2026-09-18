<?php

namespace App\Services\Idp\Rules;

use App\Models\Competency;
use App\Models\CompetencyType;
use App\Models\DevelopmentProgram;
use App\Models\IndividualDevelopmentPlan;
use App\Models\ReviewTool;

/**
 * The cross-master cascade an IDP plan row has to satisfy — the server mirror of
 * what the plan drawer's pickers offer:
 *
 *  - the competency type must be one of the `competency_types` masters;
 *  - that type must hold an ACTIVE competency reaching a development program
 *    filed under the chosen development model;
 *  - the competency name and the review tool must be ACTIVE masters;
 *  - the competency must belong to the chosen type, and must reach a program
 *    under the chosen development model;
 *  - the development program must be one linked to the chosen competency, and
 *    must be filed under both the chosen development model and the chosen type.
 *
 * The catch-all "Others" type gets exactly the same treatment as any other: it
 * picks its competency from the master data too.
 *
 * Each check exempts the value the plan ALREADY stores, and each is skipped when
 * the submitted name matches no master at all — legacy plans hold free text, and
 * those must stay editable.
 *
 * It lives here rather than inside the form request so the plan form and the
 * Excel import are policed by one set of rules and cannot drift apart, the same
 * way the master importers reuse their screens' rule objects.
 */
class PlanMasterRules
{
    /**
     * @param  array<string, mixed>  $data  competency_type, competency_name,
     *                                      development_program, review_tools,
     *                                      development_model_id
     * @param  IndividualDevelopmentPlan|null  $current  the row being edited, whose
     *                                                   stored values are exempt
     * @return array<string, string> field => message; empty when the row fits
     */
    public function check(array $data, ?IndividualDevelopmentPlan $current = null): array
    {
        $type = trim((string) ($data['competency_type'] ?? ''));
        $name = trim((string) ($data['competency_name'] ?? ''));
        $program = trim((string) ($data['development_program'] ?? ''));
        $reviewTool = trim((string) ($data['review_tools'] ?? ''));

        $modelId = $data['development_model_id'] ?? null;
        $modelId = is_numeric($modelId) ? (int) $modelId : null;

        $errors = [];

        // --- the competency type must be one of the masters ---
        $types = CompetencyType::get(['id', 'name_en']);
        $chosenType = $types->first(fn (CompetencyType $ct) => strcasecmp(trim((string) $ct->name_en), $type) === 0);

        if ($type !== '' && ! $chosenType && $type !== trim((string) $current?->competency_type)) {
            return ['competency_type' => 'The selected competency type is not one of the configured competency types.'];
        }

        // A competency is always filed under a type (the master form requires
        // one), so the type scopes it strictly — an untyped competency is legacy
        // data and belongs under no type at all.
        $matchesType = fn (?string $masterType) => $masterType !== null
            && trim($masterType) !== ''
            && strcasecmp(trim($masterType), $type) === 0;

        // Development programs go the other way: an untyped program is global.
        // They are narrowed primarily by the competency they build, and treating
        // a missing type as "none" would reject everything.
        $fitsType = fn (?string $masterType) => $masterType === null
            || trim($masterType) === ''
            || strcasecmp(trim($masterType), $type) === 0;

        // A program is filed under one development model (the 70-20-10 split)
        // and the plan is being added under one, so only that model's programs
        // may fill it. A program with no model is legacy data and, like an
        // untyped master, counts as global.
        $fitsModel = fn (?int $programModel) => $programModel === null
            || $modelId === null
            || $programModel === $modelId;

        // A program the plan could actually pick: filed under this plan's
        // development model, and under this plan's competency type.
        $selectable = fn (DevelopmentProgram $p) => $fitsModel($p->development_model_id)
            && $fitsType($p->competencyType?->name_en);

        // A competency reaches a program this plan could pick — or has no linked
        // programs at all, which makes it global (the program picker then offers
        // the model's whole catalogue).
        $reachesModel = function (Competency $c) use ($selectable) {
            $links = $c->developmentPrograms;

            return $links->isEmpty() || $links->contains($selectable);
        };

        // --- the type must reach a competency usable under this model ---
        // The type picker is narrowed by the development model too: a type
        // holding no competency that reaches one of this model's programs
        // dead-ends on the very next field, so it is not offered at all.
        if ($chosenType && $type !== trim((string) $current?->competency_type)) {
            $typeCompetencies = Competency::with([
                'developmentPrograms:id,name_en,development_model_id,competency_type_id',
                'developmentPrograms.competencyType:id,name_en',
            ])
                ->where('competency_type_id', $chosenType->id)
                ->active()
                ->get(['id', 'name_en', 'competency_type_id']);

            if (! $typeCompetencies->contains($reachesModel)) {
                return ['competency_type' => 'The selected competency type has no competency with a development program filed under the chosen development model.'];
            }
        }

        // --- review tool must be active ---
        if ($reviewTool !== '' && $reviewTool !== trim((string) $current?->review_tools)) {
            $tools = ReviewTool::where('name_en', $reviewTool)->get();

            if ($tools->isNotEmpty() && $tools->every(fn (ReviewTool $t) => ! $t->isActive())) {
                $errors['review_tools'] = 'The selected review tool is inactive. Please choose an active one.';
            }
        }

        $competencies = $name === ''
            ? collect()
            : Competency::with([
                'competencyType:id,name_en',
                'developmentPrograms:id,name_en,development_model_id,competency_type_id',
                'developmentPrograms.competencyType:id,name_en',
            ])
                ->where('name_en', $name)
                ->get();

        // --- competency name must be active ---
        if ($competencies->isNotEmpty()
            && $competencies->every(fn (Competency $c) => ! $c->isActive())
            && $name !== trim((string) $current?->competency_name)
        ) {
            $errors['competency_name'] = 'The selected competency name is inactive. Please choose an active one.';
        }

        if ($competencies->isEmpty() || $type === '') {
            return $errors;
        }

        // --- competency must belong to the chosen type ---
        $typed = $competencies->filter(fn (Competency $c) => $matchesType($c->competencyType?->name_en));

        if ($typed->isEmpty() && $name !== trim((string) $current?->competency_name)) {
            $errors['competency_name'] = 'The selected competency name does not belong to the chosen competency type.';

            return $errors;
        }

        // --- the competency must reach a program under the chosen model ---
        // A competency reaches its programs through the master link, and a plan
        // is filed under exactly one development model, so a competency whose
        // linked programs all sit under other models cannot be developed here —
        // which is why the picker leaves it off the list. A competency with no
        // links at all is global, so it is exempt.
        if ($name !== trim((string) $current?->competency_name)) {
            $links = $typed->flatMap(fn (Competency $c) => $c->developmentPrograms);

            if ($links->isNotEmpty() && $links->every(fn (DevelopmentProgram $p) => ! $selectable($p))) {
                $errors['competency_name'] = 'The selected competency name has no development program filed under the chosen development model.';

                return $errors;
            }
        }

        // --- program must fit the model, the competency, and the type ---
        if ($program === '' || $program === trim((string) $current?->development_program)) {
            return $errors;
        }

        $linked = ($typed->isNotEmpty() ? $typed : $competencies)
            ->flatMap(fn (Competency $c) => $c->developmentPrograms);

        // A competency with no links at all falls back to the whole catalogue
        // (still narrowed to the model and the type), which is exactly what the
        // picker offers.
        $candidates = $linked->isNotEmpty()
            ? $linked
            : DevelopmentProgram::with('competencyType:id,name_en')
                ->where('name_en', $program)
                ->get(['id', 'name_en', 'development_model_id', 'competency_type_id']);

        // A name matching no program at all is free text on a legacy plan, so
        // there is nothing to police here.
        if ($candidates->isEmpty()) {
            return $errors;
        }

        $allowed = $candidates
            ->filter($selectable)
            ->pluck('name_en')
            ->map(fn ($n) => trim((string) $n))
            ->all();

        if (! in_array($program, $allowed, true)) {
            $errors['development_program'] = 'The selected development program is not valid for the chosen development model, competency name and type.';
        }

        return $errors;
    }
}
