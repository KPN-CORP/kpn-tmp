<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Employee extends Model
{
    use HasFactory;

    protected $connection = 'kpncorp';
    protected $table = 'employees';
    protected $guarded = ['id'];

    protected $casts = [
        'language_ability' => 'array',
        'date_of_joining' => 'date',
        'date_of_birth' => 'date',
    ];

    /**
     * Trimmed column set safe to expose to the frontend.
     */
    public function scopePublicData($query)
    {
        return $query->select(
            'id', 'employee_id', 'fullname', 'gender', 'email', 'group_company',
            'designation_code', 'designation_name', 'job_level', 'company_name',
            'contribution_level_code', 'work_area_code', 'office_area',
            'manager_l1_id', 'manager_l2_id', 'employee_type', 'unit',
            'personal_email', 'date_of_birth', 'nationality', 'marital_status',
            'homebase', 'permanent_city'
        );
    }

    // --- Structural (kpncorp) relations ---

    public function subordinatesL1(): HasMany
    {
        return $this->hasMany(self::class, 'manager_l1_id', 'employee_id');
    }

    public function subordinatesL2(): HasMany
    {
        return $this->hasMany(self::class, 'manager_l2_id', 'employee_id');
    }

    public function formalEducations(): HasMany
    {
        return $this->hasMany(FormalEducation::class, 'employee_id', 'employee_id');
    }

    public function workExperiences(): HasMany
    {
        return $this->hasMany(WorkExperience::class, 'employee_id', 'employee_id');
    }

    public function trainingCertifications(): HasMany
    {
        return $this->hasMany(TrainingCertification::class, 'employee_id', 'employee_id');
    }

    public function movementTransactions(): HasMany
    {
        return $this->hasMany(MovementTransaction::class, 'employee_id', 'employee_id');
    }

    public function promotionTransactions(): HasMany
    {
        return $this->hasMany(PromotionTransaction::class, 'employee_id', 'employee_id');
    }

    public function performanceAppraisals(): HasMany
    {
        return $this->hasMany(PerformanceAppraisal::class, 'employee_id', 'employee_id');
    }

    // --- App-owned (mysql) relations: hop connections explicitly ---

    public function user(): HasOne
    {
        return $this->setConnection('mysql')
            ->hasOne(User::class, 'employee_id', 'employee_id');
    }

    public function developmentPlans(): HasMany
    {
        return $this->setConnection('mysql')
            ->hasMany(IndividualDevelopmentPlan::class, 'employee_id', 'employee_id');
    }

    public function competencyAssessments(): HasMany
    {
        return $this->setConnection('mysql')
            ->hasMany(CompetencyAssessment::class, 'employee_id', 'employee_id');
    }

    public function resultSummary(): HasOne
    {
        return $this->setConnection('mysql')
            ->hasOne(ResultSummary::class, 'employee_id', 'employee_id');
    }
}
