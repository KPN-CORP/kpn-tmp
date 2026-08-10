<?php

namespace App\Services;

use App\Models\DevelopmentModel;
use App\Models\DevelopmentPlanMaster;
use App\Models\IndividualDevelopmentPlan;

/**
 * Assembles the data the IDP "manage" screen needs: the development models, an
 * employee's plans grouped by model, the master-driven dropdown options, and
 * the competency→programs map that drives the soft-competency cascade.
 */
class IdpService
{
    public function manageData(string $employeeId): array
    {
        $models = DevelopmentModel::orderByDesc('percentage')->orderBy('name')->get();

        $plans = IndividualDevelopmentPlan::where('employee_id', $employeeId)
            ->orderByDesc('id')
            ->get()
            ->groupBy('development_model_id');

        $programs = DevelopmentPlanMaster::where('type', 'development_program')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'development_model_id']);

        $programsById = $programs->keyBy('id');

        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id', 'related_program']);

        $reviewTools = DevelopmentPlanMaster::where('type', 'review_tools')
            ->orderBy('value')
            ->get(['id', 'value', 'value_en', 'value_id']);

        // Shape a master row into a localizable option (canonical value stays the
        // stored/matched key; value_en/value_id drive the display label).
        $option = fn ($m) => [
            'value' => $m->value,
            'value_en' => $m->value_en,
            'value_id' => $m->value_id,
        ];

        // competency value (lower-cased) => [{ value, value_en, value_id, model_id }]
        $competencyMap = [];
        foreach ($competencies as $competency) {
            $linked = collect($competency->related_program ?? [])
                ->map(fn ($id) => $programsById->get($id))
                ->filter()
                ->map(fn ($p) => [
                    'value' => $p->value,
                    'value_en' => $p->value_en,
                    'value_id' => $p->value_id,
                    'model_id' => $p->development_model_id,
                ])
                ->values();

            if ($linked->isNotEmpty()) {
                $competencyMap[strtolower(trim($competency->value))] = $linked;
            }
        }

        return [
            'developmentModels' => $models->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'percentage' => $m->percentage,
                'description_en' => $m->description_en,
                'description_id' => $m->description_id,
                'plans' => ($plans->get($m->id) ?? collect())->values(),
            ]),
            'options' => [
                'competencyNames' => $competencies->map($option)->values(),
                'developmentPrograms' => $programs->map($option)->unique('value')->values(),
                'reviewTools' => $reviewTools->map($option)->values(),
            ],
            'competencyMap' => $competencyMap,
        ];
    }
}
