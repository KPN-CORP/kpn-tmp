<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PerformanceAppraisal extends Model
{
    use HasFactory;

    protected $connection = 'kpncorp';

    protected $table = 'performance_appraisals';

    protected $guarded = ['id'];
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}