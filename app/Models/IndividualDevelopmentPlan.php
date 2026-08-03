<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IndividualDevelopmentPlan extends Model
{
    /** @use HasFactory<\Database\Factories\IndividualDevelopmentPlanFactory> */
    use HasFactory;

    protected $connection = 'mysql';
    protected $table = 'individual_development_plans';

    protected $fillable = [
        'employee_id',
        'development_model_id',
        'competency_type',
        'competency_name',
        'review_tools',
        'development_program',
        'expected_outcome',
        'time_frame_start',
        'time_frame_end',
        'realization_date',
        'result_evidence',
    ];

    protected $casts = [
        'time_frame_start' => 'date',
        'time_frame_end' => 'date',
        'realization_date' => 'date',
    ];

    public function developmentModel(): BelongsTo
    {
        return $this->belongsTo(DevelopmentModel::class, 'development_model_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
