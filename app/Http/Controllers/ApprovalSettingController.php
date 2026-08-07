<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApprovalLayerRequest;
use App\Http\Requests\UpdateApprovalLayerRequest;
use App\Models\ApprovalFlow;
use App\Models\ApprovalLayer;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin screen for the configurable approval workflow. Manages one flow per
 * module (idp, appraisal) and its ordered layers. The actual approve/reject
 * runtime that consumes these flows lands in a later phase.
 */
class ApprovalSettingController extends Controller
{
    public function index(): Response
    {
        // Ensure a flow row exists for every module so the UI always has a
        // tab to render, even before the seeder has run in a fresh env.
        foreach (ApprovalFlow::MODULES as $module) {
            ApprovalFlow::firstOrCreate(
                ['module' => $module],
                ['name' => ucfirst($module).' Approval', 'is_active' => true],
            );
        }

        $flows = ApprovalFlow::with('layers')
            ->whereIn('module', ApprovalFlow::MODULES)
            ->orderBy('id')
            ->get();

        $approverNames = $this->resolveApproverNames($flows);

        return Inertia::render('Admin/ApprovalSetting', [
            'flows' => $flows->map(fn (ApprovalFlow $flow) => [
                'id' => $flow->id,
                'module' => $flow->module,
                'name' => $flow->name,
                'description' => $flow->description,
                'is_active' => $flow->is_active,
                'layers' => $flow->layers->map(fn (ApprovalLayer $layer) => [
                    'id' => $layer->id,
                    'sequence' => $layer->sequence,
                    'name' => $layer->name,
                    'approver_type' => $layer->approver_type,
                    'approver_employee_id' => $layer->approver_employee_id,
                    'approver_name' => $layer->approver_type === ApprovalLayer::TYPE_SPECIFIC
                        ? ($approverNames[$layer->approver_employee_id] ?? null)
                        : null,
                    'is_active' => $layer->is_active,
                ])->values(),
            ])->values(),
        ]);
    }

    // --- Flow ---

    public function updateFlow(Request $request, ApprovalFlow $flow): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
        ]);

        $flow->update($data);

        return back()->with('success', 'Approval flow updated successfully.');
    }

    // --- Layers ---

    public function storeLayer(StoreApprovalLayerRequest $request): RedirectResponse
    {
        $data = $request->validated();

        // Append the new layer at the end of its flow.
        $data['sequence'] = (int) ApprovalLayer::where('approval_flow_id', $data['approval_flow_id'])
            ->max('sequence') + 1;

        ApprovalLayer::create($data);

        return back()->with('success', 'Approval layer added successfully.');
    }

    public function updateLayer(UpdateApprovalLayerRequest $request, ApprovalLayer $layer): RedirectResponse
    {
        $layer->update($request->validated());

        return back()->with('success', 'Approval layer updated successfully.');
    }

    public function destroyLayer(ApprovalLayer $layer): RedirectResponse
    {
        $flowId = $layer->approval_flow_id;
        $layer->delete();

        // Close the gap so sequences stay contiguous (1,2,3…).
        $this->resequence($flowId);

        return back()->with('success', 'Approval layer removed successfully.');
    }

    /**
     * Persist a new layer order for a flow. Accepts the full ordered list of
     * this flow's layer ids; anything omitted keeps its place after them.
     */
    public function reorderLayers(Request $request, ApprovalFlow $flow): RedirectResponse
    {
        $data = $request->validate([
            'layer_ids' => ['required', 'array', 'min:1'],
            'layer_ids.*' => ['integer'],
        ]);

        // Only reorder layers that actually belong to this flow.
        $valid = $flow->layers()->pluck('id')->all();
        $ordered = array_values(array_filter($data['layer_ids'], fn ($id) => in_array($id, $valid, true)));

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $index => $id) {
                ApprovalLayer::where('id', $id)->update(['sequence' => $index + 1]);
            }
        });

        return back()->with('success', 'Approval order updated successfully.');
    }

    /**
     * Renumber a flow's layers to a contiguous 1..N in their current order.
     */
    private function resequence(int $flowId): void
    {
        $layers = ApprovalLayer::where('approval_flow_id', $flowId)
            ->orderBy('sequence')
            ->orderBy('id')
            ->pluck('id');

        DB::transaction(function () use ($layers) {
            foreach ($layers as $index => $id) {
                ApprovalLayer::where('id', $id)->update(['sequence' => $index + 1]);
            }
        });
    }

    /**
     * Live employee search for the "specific employee" approver picker.
     */
    public function searchEmployees(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('q', ''));

        $employees = Employee::query()
            ->when($term !== '', function ($query) use ($term) {
                $query->where(function ($sub) use ($term) {
                    $sub->where('employee_id', 'like', "%{$term}%")
                        ->orWhere('fullname', 'like', "%{$term}%")
                        ->orWhere('designation_name', 'like', "%{$term}%");
                });
            })
            ->orderBy('fullname')
            ->limit(30)
            ->get(['employee_id', 'fullname', 'designation_name', 'group_company']);

        return response()->json($employees);
    }

    /**
     * Map specific-approver employee_ids to display names in one query.
     * Guarded so an unreachable kpncorp connection degrades to ids only.
     *
     * @param  Collection<int, ApprovalFlow>  $flows
     * @return array<string, string>
     */
    private function resolveApproverNames($flows): array
    {
        $ids = $flows->flatMap->layers
            ->where('approver_type', ApprovalLayer::TYPE_SPECIFIC)
            ->pluck('approver_employee_id')
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        try {
            return Employee::whereIn('employee_id', $ids)
                ->pluck('fullname', 'employee_id')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
