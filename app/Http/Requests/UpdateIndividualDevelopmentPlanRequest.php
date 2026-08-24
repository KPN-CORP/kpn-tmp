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
     * The plan being edited (route-model bound as {idp}).
     */
    private function plan(): ?IndividualDevelopmentPlan
    {
        $plan = $this->route('idp');

        return $plan instanceof IndividualDevelopmentPlan ? $plan : null;
    }

    protected function targetEmployeeId(): ?string
    {
        return $this->plan()?->employee_id;
    }

    protected function currentModelId(): ?int
    {
        return $this->plan()?->development_model_id;
    }
}
