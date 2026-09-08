<?php

namespace App\Http\Controllers;

use App\Enums\MasterDataType;
use App\Models\Competency;
use App\Models\Training;
use App\Services\CorporateScopeService;
use App\Services\Idp\DevelopmentModelPackageService;
use App\Services\Idp\MasterOptionService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The IDP settings screens: development programs, development models, review
 * tools and master trainings.
 *
 * Read-only. Every one of these pages writes through the shared master-data
 * endpoints ({@see MasterDataController}), and the two that have a form page of
 * their own hand it to that form's controller — so what is left here is prop
 * shaping, delegated in turn to the services that own each payload.
 */
class IdpSettingController extends Controller
{
    public function __construct(
        private readonly MasterOptionService $options,
        private readonly CorporateScopeService $corporate,
        private readonly DevelopmentModelPackageService $packages,
    ) {}

    /**
     * Development programs. The form narrows its pickers through the other
     * masters, so nearly every option list in the app travels with it.
     */
    public function index(): Response
    {
        [$packages, $activePackageId] = $this->packages->listData();

        return Inertia::render('Idp/Settings', [
            'packages' => $packages,
            'activePackageId' => $activePackageId,
            'developmentModels' => $this->packages->models(),
            'competencies' => $this->options->competencies(
                fn (Competency $c) => [
                    'description_en' => $c->description_en,
                    'description_id' => $c->description_id,
                    // The rungs of this competency's own ladder. The program
                    // form narrows them further through the implementation map.
                    'proficiency_level_ids' => $c->proficiencyLevels->pluck('id')->all(),
                    'related_program' => $c->developmentPrograms->pluck('id')->values(),
                    'linked_programs' => $c->developmentPrograms->pluck('name_en')->values(),
                ],
                ['proficiencyLevels', 'developmentPrograms:id,name_en'],
            ),
            'competencyTypes' => $this->options->competencyTypes(),
            'proficiencyLevels' => $this->options->proficiencyLevels(),
            // The master-implementation map drives the program form's
            // proficiency + grade pickers: a program may only target a level
            // some implementation maps its competencies to, and only the
            // grades that mapping covers.
            'implementations' => $this->options->implementationScopes(),
            // A program's name is either typed or taken from Master Training,
            // so the form needs the catalogue to pick from. The active flag
            // rides on the option; the form keeps an inactive training listed
            // only while a program already points at it.
            'trainings' => $this->options->trainings(),
            'grades' => $this->corporate->grades(),
            'developmentPrograms' => $this->options->developmentPrograms(),
        ]);
    }

    /**
     * Development model management (packages + weighted models). Add / edit
     * live on their own page ({@see DevelopmentModelPackageController}).
     */
    public function developmentModel(): Response
    {
        [$packages, $activePackageId] = $this->packages->listData();

        return Inertia::render('Idp/DevelopmentModel', [
            'packages' => $packages,
            'activePackageId' => $activePackageId,
            'developmentModels' => $this->packages->models(),
        ]);
    }

    /**
     * Review tools: a standalone master list (bilingual name).
     */
    public function reviewTools(): Response
    {
        return Inertia::render('Idp/ReviewTools', [
            'reviewTools' => $this->options->options(
                MasterDataType::ReviewTools->query()->orderBy('name_en')->get()
            ),
        ]);
    }

    /**
     * Master Training: a standalone master list of trainings (bilingual name +
     * description) with what each one builds and who it is offered to.
     */
    public function masterTraining(): Response
    {
        $trainings = Training::with(['proficiencyLevels:id', 'businessUnits', 'workLocations'])
            ->orderBy('name_en')
            ->get()
            ->map(fn (Training $t) => $this->options->option($t) + [
                'description_en' => $t->description_en,
                'description_id' => $t->description_id,
                // What the training builds, and who it is offered to. Every
                // part of the scope but the competency is a list.
                'competency_type_id' => $t->competency_type_id,
                'competency_id' => $t->competency_id,
                'proficiency_level_ids' => $t->proficiencyLevels->pluck('id')->all(),
                'business_units' => $t->businessUnits->pluck('business_unit')->all(),
                'work_locations' => $t->workLocations->pluck('work_location')->all(),
            ]);

        $locations = $this->corporate->workLocations();

        return Inertia::render('Idp/MasterTraining', [
            'trainings' => $trainings,
            'competencyTypes' => $this->options->competencyTypes(),
            // Competencies carry their type so the form can cascade
            // (type -> competency); the active flag rides on the option and is
            // what keeps inactive competencies off the list.
            'competencies' => $this->options->competencies(),
            // Every proficiency level in the app, each carrying the competency
            // that owns it — that is what the form scopes the picker by, since
            // a training targets rungs of the competency it builds.
            'proficiencyLevels' => $this->options->proficiencyLevels(),
            'businessUnits' => $locations['businessUnits'],
            'workLocationsByBu' => $locations['byBusinessUnit'],
        ]);
    }
}
