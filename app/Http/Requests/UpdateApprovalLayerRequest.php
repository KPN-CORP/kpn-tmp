<?php

namespace App\Http\Requests;

/**
 * Same rules as creating a layer, minus the flow reference (a layer never
 * moves between flows).
 */
class UpdateApprovalLayerRequest extends StoreApprovalLayerRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['approval_flow_id']);

        return $rules;
    }
}
