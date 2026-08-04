<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Employee
 */
class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'employee_id' => $this->employee_id,
            'fullname' => $this->fullname,
            'email' => $this->email,
            'gender' => $this->gender,
            'group_company' => $this->group_company,
            'company_name' => $this->company_name,
            'designation_code' => $this->designation_code,
            'designation_name' => $this->designation_name,
            'job_level' => $this->job_level,
            'employee_type' => $this->employee_type,
            'unit' => $this->unit,
            'office_area' => $this->office_area,
            'manager_l1_id' => $this->manager_l1_id,
            'manager_l2_id' => $this->manager_l2_id,
            'date_of_joining' => optional($this->date_of_joining)->toDateString(),
        ];
    }
}
