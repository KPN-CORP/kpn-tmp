<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
class FormalEducation extends Model
{
    use HasFactory;
    protected $connection = 'kpncorp';
    protected $table = 'formal_educations'; 
    protected $guarded = ['id'];
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}