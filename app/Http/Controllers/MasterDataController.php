<?php

namespace App\Http\Controllers;

use App\Enums\MasterDataType;
use App\Services\Idp\MasterDataValidator;
use App\Services\IdpMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One set of endpoints for every kind of IDP master.
 *
 * The kind arrives as the `type` field (on create) or path segment (on
 * everything else) and resolves to a {@see MasterDataType}, which picks the
 * table to work on. Validation belongs to {@see MasterDataValidator} and the
 * writes to {@see IdpMasterService}, so what is here is the HTTP shape: resolve
 * the row, delegate, redirect back with a message.
 */
class MasterDataController extends Controller
{
    public function __construct(
        private readonly IdpMasterService $masters,
        private readonly MasterDataValidator $validator,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $type = $this->validator->resolveType($request->input('type'));

        $this->masters->create($type, $this->validator->validate($request, $type));

        return back()->with('success', 'Master data added successfully.');
    }

    public function update(Request $request, MasterDataType $type, int $id): RedirectResponse
    {
        $master = $type->query()->findOrFail($id);

        $this->masters->update(
            $type,
            $master,
            $this->validator->validate($request, $type, $master),
            array_keys($request->all()),
        );

        return back()->with('success', 'Master data updated successfully.');
    }

    /**
     * Activate / deactivate one master from its list screen. Deactivating keeps
     * the row and everything referencing it — it only takes the master out of
     * the pickers for new work.
     */
    public function toggleActive(Request $request, MasterDataType $type, int $id): RedirectResponse
    {
        if (! $type->hasActiveState()) {
            return back()->with('error', 'This master data type cannot be activated or deactivated.');
        }

        $master = $type->query()->findOrFail($id);
        $active = $request->boolean('is_active');

        $this->masters->setActive($type, $master, $active);

        return back()->with(
            'success',
            $active ? 'Master data activated successfully.' : 'Master data deactivated successfully.'
        );
    }

    /**
     * The activate / deactivate trail for one master, newest first. Read from
     * the audit log on disk, not from the database.
     */
    public function statusHistory(MasterDataType $type, int $id): JsonResponse
    {
        return response()->json([
            'history' => $this->masters->statusHistory($type, $id),
        ]);
    }

    public function destroy(MasterDataType $type, int $id): RedirectResponse
    {
        $master = $type->query()->findOrFail($id);

        if ($blocker = $this->masters->deletionBlocker($type, $master)) {
            return back()->with('error', $blocker);
        }

        $this->masters->delete($type, $master);

        return back()->with('success', 'Master data deleted successfully.');
    }
}
