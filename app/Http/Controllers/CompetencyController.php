<?php

namespace App\Http\Controllers;

use App\Enums\MasterDataType;
use App\Models\Competency;
use App\Models\CompetencyProficiencyLevel;
use App\Services\Idp\MasterDataValidator;
use App\Services\Idp\MasterOptionService;
use App\Services\IdpMasterService;
use App\Services\MasterStatusAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Data -> Competency: the list, and the add/edit form that outgrew a
 * drawer (a type, a bilingual name + description, any number of
 * sub-competencies, a proficiency ladder with key behaviors under each rung,
 * and the active flag).
 *
 * Competency types travel along read-only: they name the type column, drive the
 * type filter, and scope what the form may pin.
 *
 * The write endpoints are its own rather than the shared master ones only so a
 * successful save can land back on the *list* — the shared endpoints `back()`,
 * which from a form page means the form page. They run the same validator and
 * the same service; the list still uses the shared endpoints for delete and the
 * active toggle.
 */
class CompetencyController extends Controller
{
    private const TYPE = MasterDataType::CompetencyName;

    public function __construct(
        private readonly IdpMasterService $masters,
        private readonly MasterDataValidator $validator,
        private readonly MasterOptionService $options,
        private readonly MasterStatusAudit $audit,
    ) {}

    public function index(): Response
    {
        $competencies = Competency::with(['proficiencyLevels.keyBehaviors', 'subCompetencies'])
            ->orderBy('name_en')
            ->get()
            ->map(fn (Competency $c) => $this->options->competencyPayload($c));

        return Inertia::render('MasterData/Competency', [
            'competencies' => $competencies,
            'competencyTypes' => $this->options->competencyTypes(),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(int $id): Response
    {
        return $this->form($this->find($id));
    }

    public function store(Request $request): RedirectResponse
    {
        // The route fixes the kind, so the form does not post it.
        $request->merge(['type' => self::TYPE->value]);

        $this->masters->create(self::TYPE, $this->validator->validate($request, self::TYPE));

        return redirect()->route('master_data.competency')
            ->with('success', 'Competency added successfully.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $request->merge(['type' => self::TYPE->value]);

        $competency = self::TYPE->query()->findOrFail($id);

        $this->masters->update(
            self::TYPE,
            $competency,
            $this->validator->validate($request, self::TYPE, $competency),
            array_keys($request->all()),
        );

        return redirect()->route('master_data.competency')
            ->with('success', 'Competency updated successfully.');
    }

    /**
     * The activate / deactivate trail for one rung of a competency's ladder,
     * newest first. Read from the audit log on disk, like every other status
     * history here.
     */
    public function levelStatusHistory(CompetencyProficiencyLevel $level): JsonResponse
    {
        return response()->json([
            'history' => $this->audit->for(MasterStatusAudit::COMPETENCY_LEVEL, $level->id),
        ]);
    }

    private function form(?Competency $competency): Response
    {
        return Inertia::render('MasterData/CompetencyForm', [
            // null when adding. The payload is the same shape the list ships,
            // so both screens read one interface.
            'competency' => $competency === null ? null : $this->options->competencyPayload($competency),
            'competencyTypes' => $this->options->competencyTypes(),
        ]);
    }

    private function find(int $id): Competency
    {
        return Competency::with(['proficiencyLevels.keyBehaviors', 'subCompetencies'])->findOrFail($id);
    }
}
