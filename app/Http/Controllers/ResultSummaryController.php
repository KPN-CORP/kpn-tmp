<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreResultSummaryRequest;
use App\Models\ResultSummary;
use App\Services\EmployeeScopeService;
use Illuminate\Http\RedirectResponse;

class ResultSummaryController extends Controller
{
    public function __construct(private readonly EmployeeScopeService $scope)
    {
    }

    public function store(StoreResultSummaryRequest $request): RedirectResponse
    {
        $data = $request->validated();

        abort_unless($this->scope->canView($request->user(), $data['employee_id']), 403);

        ResultSummary::updateOrCreate(
            ['employee_id' => $data['employee_id']],
            $data,
        );

        return back()->with('success', 'Succession summary saved successfully.');
    }
}
