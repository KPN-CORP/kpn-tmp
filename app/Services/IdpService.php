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
            ->get(['id', 'value', 'development_model_id']);

        $programsById = $programs->keyBy('id');

        // competency value (lower-cased) => [{ value, model_id }]
        $competencyMap = [];
        $competencies = DevelopmentPlanMaster::where('type', 'competency_name')
            ->whereNotNull('related_program')
            ->get();

        foreach ($competencies as $competency) {
            $linked = collect($competency->related_program ?? [])
                ->map(fn ($id) => $programsById->get($id))
                ->filter()
                ->map(fn ($p) => [
                    'value' => $p->value,
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
                'competencyNames' => DevelopmentPlanMaster::where('type', 'competency_name')
                    ->orderBy('value')->pluck('value')->unique()->values(),
                'developmentPrograms' => $programs->pluck('value')->unique()->sort()->values(),
                'reviewTools' => DevelopmentPlanMaster::where('type', 'review_tools')
                    ->orderBy('value')->pluck('value')->unique()->values(),
            ],
            'competencyMap' => $competencyMap,
        ];
    }
}
