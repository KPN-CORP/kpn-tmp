<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class TrainingCertification extends Model
{
    use HasFactory;
    protected $connection = 'kpncorp';
    // protected $table = 'trainings_certifications'; 
    // revised database
    //protected $table = 'trainings_certifications'; 
    protected $table = 'certifications'; 
    protected $guarded = ['id'];
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}