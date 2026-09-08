<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDevelopmentModelPackageRequest;
use App\Http\Requests\UpdateDevelopmentModelPackageRequest;
use App\Models\DevelopmentModelPackage;
use App\Services\Idp\DevelopmentModelPackageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Development-model packages: period-scoped bundles of weighted models.
 *
 * A package and its models are one form on one page — the models only mean
 * anything as a set (they have to total 100%), so they are created, re-weighted
 * and removed together. The list itself lives on
 * {@see IdpSettingController::developmentModel()}.
 */
class DevelopmentModelPackageController extends Controller
{
    public function __construct(private readonly DevelopmentModelPackageService $packages) {}

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(DevelopmentModelPackage $developmentModelPackage): Response
    {
        return $this->form($developmentModelPackage->load('developmentModels'));
    }

    public function store(StoreDevelopmentModelPackageRequest $request): RedirectResponse
    {
        $this->packages->create($request->validated());

        return redirect()->route('idp.setting.development_model')
            ->with('success', 'Package added successfully.');
    }

    public function update(
        UpdateDevelopmentModelPackageRequest $request,
        DevelopmentModelPackage $developmentModelPackage,
    ): RedirectResponse {
        $this->packages->update($developmentModelPackage, $request->validated());

        return redirect()->route('idp.setting.development_model')
            ->with('success', 'Package updated successfully.');
    }

    public function destroy(DevelopmentModelPackage $developmentModelPackage): RedirectResponse
    {
        if ($blocker = $this->packages->deletionBlocker($developmentModelPackage)) {
            return back()->with('error', $blocker);
        }

        $this->packages->delete($developmentModelPackage);

        return back()->with('success', 'Package deleted successfully.');
    }

    private function form(?DevelopmentModelPackage $package): Response
    {
        return Inertia::render('Idp/DevelopmentModelForm', [
            // null when adding.
            'package' => $package === null ? null : $this->packages->payload($package),
        ]);
    }
}
