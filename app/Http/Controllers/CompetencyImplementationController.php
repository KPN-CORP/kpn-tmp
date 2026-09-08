<?php

namespace App\Http\Controllers;

use App\Models\Competency;
use App\Models\CompetencyImplementation;
use App\Services\CorporateScopeService;
use App\Services\Idp\ImplementationService;
use App\Services\Idp\ImplementationValidator;
use App\Services\Idp\MasterOptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Implementation: maps a single competency (with one or more of its
 * proficiency levels) to a corporate org scope — grades plus a dynamic
 * business-unit hierarchy (business unit -> job family / function -> position).
 *
 * The page lives under the Master Data menu; the write endpoints stayed under
 * /idp-setting/implementations, where they have always been.
 */
class CompetencyImplementationController extends Controller
{
    public function __construct(
        private readonly ImplementationService $implementations,
        private readonly ImplementationValidator $validator,
        private readonly MasterOptionService $options,
        private readonly CorporateScopeService $corporate,
    ) {}

    public function index(): Response
    {
        $implementations = CompetencyImplementation::with(['proficiencyLevels:id', 'grades', 'businessUnits'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (CompetencyImplementation $i) => [
                'id' => $i->id,
                'is_active' => $i->is_active,
                'competency_type_id' => $i->competency_type_id,
                'competency_id' => $i->competency_id,
                'proficiency_level_ids' => $i->proficiencyLevels->pluck('id')->all(),
                'grades' => $i->grades->pluck('grade')->values(),
                'business_units' => $i->businessUnits->pluck('business_unit')->values(),
                'job_family' => $i->job_family,
                'function_name' => $i->function_name,
                'position' => $i->position,
            ]);

        $hierarchy = $this->corporate->orgHierarchy();

        return Inertia::render('MasterData/MasterImplementation', [
            'implementations' => $implementations,
            'competencyTypes' => $this->options->competencyTypes(),
            // Competencies carry their type + their own proficiency ladder so
            // the form can cascade (type -> competency -> proficiency levels).
            'competencies' => $this->options->competencies(
                fn (Competency $c) => [
                    'proficiency_level_ids' => $c->proficiencyLevels->pluck('id')->all(),
                ],
                ['proficiencyLevels'],
            ),
            'proficiencyLevels' => $this->options->proficiencyLevels(),
            'grades' => $this->corporate->grades(),
            // Dynamic org-scope hierarchy (all guarded reads off kpncorp).
            'businessUnits' => $hierarchy['businessUnits'],
            'jobFamiliesByBu' => $hierarchy['jobFamiliesByBu'],
            'functionsByBu' => $hierarchy['functionsByBu'],
            'positionsByBuFunction' => $hierarchy['positionsByBuFunction'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->implementations->create($this->validator->validate($request), Auth::user());

        return back()->with('success', 'Master implementation added successfully.');
    }

    public function update(Request $request, CompetencyImplementation $implementation): RedirectResponse
    {
        $this->implementations->update(
            $implementation,
            $this->validator->validate($request, $implementation),
            Auth::user(),
        );

        return back()->with('success', 'Master implementation updated successfully.');
    }

    /**
     * Activate / deactivate one mapping from the list screen. Deactivating
     * keeps the row — it only stops the mapping applying from now on.
     */
    public function toggleActive(Request $request, CompetencyImplementation $implementation): RedirectResponse
    {
        $active = $request->boolean('is_active');

        $this->implementations->setActive($implementation, $active, Auth::user());

        return back()->with(
            'success',
            $active
                ? 'Master implementation activated successfully.'
                : 'Master implementation deactivated successfully.'
        );
    }

    /**
     * The activate / deactivate trail for one mapping, newest first.
     */
    public function statusHistory(CompetencyImplementation $implementation): JsonResponse
    {
        return response()->json([
            'history' => $this->implementations->statusHistory($implementation),
        ]);
    }

    public function destroy(CompetencyImplementation $implementation): RedirectResponse
    {
        $implementation->delete();

        return back()->with('success', 'Master implementation deleted successfully.');
    }
}
