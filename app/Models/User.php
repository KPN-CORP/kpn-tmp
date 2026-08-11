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
    use HasFactory, HasRoles, Notifiable;

    /**
     * Baseline self-service role assigned to every provisioned user. It carries
     * the Individual Contributor + People Manager Data Access permissions so
     * deny-by-default access never locks a user out of their own record.
     */
    public const BASELINE_ROLE = 'Employee (Self-Service)';

    protected $connection = 'kpncorp';

    protected $table = 'users';

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
