<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasRoles, HasFactory, Notifiable;

    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Corporate employee record for this account (lives on the kpncorp
     * connection; matched by the shared employee_id).
     */
    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Whether this user sits above anyone in the reporting structure.
     */
    public function isManager(): bool
    {
        if (! $this->employee_id) {
            return false;
        }

        return Employee::where('manager_l1_id', $this->employee_id)
            ->orWhere('manager_l2_id', $this->employee_id)
            ->exists();
    }
}
