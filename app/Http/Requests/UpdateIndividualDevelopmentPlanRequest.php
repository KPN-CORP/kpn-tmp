<?php

namespace App\Http\Requests;

use App\Models\IndividualDevelopmentPlan;

/**
 * Same rules as create, minus employee_id (the plan is resolved from the route).
 */
class UpdateIndividualDevelopmentPlanRequest extends StoreIndividualDevelopmentPlanRequest
{
    public function rules(): array
    {
        return $this->planRules();
    }

    /**
     * The plan under edit, so the cross-master checks can exempt the values it
     * already stores.
     */
    protected function currentPlan(): ?IndividualDevelopmentPlan
    {
        $plan = $this->route('idp');

        return $plan instanceof IndividualDevelopmentPlan ? $plan : null;
    }
}
