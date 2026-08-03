<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class WorkExperience extends Model
{
    use HasFactory;
    protected $connection = 'kpncorp';
    // protected $table = 'work_experiences'; 
    protected $table = 'experience'; 
    protected $guarded = ['id'];
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}