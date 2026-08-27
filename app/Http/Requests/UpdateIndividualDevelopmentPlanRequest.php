<?php

namespace App\Http\Requests;

/**
 * Same rules as create, minus employee_id (the plan is resolved from the route).
 */
class UpdateIndividualDevelopmentPlanRequest extends StoreIndividualDevelopmentPlanRequest
{
    public function rules(): array
    {
        return $this->planRules();
    }
}
