<?php

namespace App\Http\Controllers;

use App\Services\CorporateScopeService;
use App\Services\Idp\MasterOptionService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Data -> Competency Type.
 *
 * The types live on their own screen: they are edited on their own, and the
 * competency screen only *reads* them (to scope its type filter and its
 * proficiency levels). Writes go through the shared master-data endpoints
 * ({@see MasterDataController}) with `type=competency_type`.
 */
class CompetencyTypeController extends Controller
{
    public function __construct(
        private readonly MasterOptionService $options,
        private readonly CorporateScopeService $corporate,
    ) {}

    public function index(): Response
    {
        return Inertia::render('MasterData/CompetencyType', [
            'competencyTypes' => $this->options->competencyTypes(),
            // The corporate business-unit master — the only source for what
            // units exist (see App\Models\BusinessUnit).
            'businessUnits' => $this->corporate->businessUnits(),
        ]);
    }
}
