<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Access\Roles;
use App\Traits\HasBranchScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Employee extends Model implements HasMedia
{
    use Audited, BelongsToCompany, HasFactory, HasBranchScope, InteractsWithMedia;

    protected string $auditLog = 'employee';

    protected $fillable = [
        'branch_id',
        'department_id',
        'designation_id',
        'user_id',
        'employee_code',
        'biometric_emp_code',
        'first_name',
        'last_name',
        'email',
        'phone',
        'date_of_birth',
        'gender',
        'date_of_joining',
        'employment_type',
        'reporting_manager_id',
        'status',
        'personal_email',
        'marital_status',
        'blood_group',
        'nationality',
        'national_id',
        'tax_id',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'country',
        'postal_code',
        'emergency_contact_name',
        'emergency_contact_phone',
        'emergency_contact_relation',
        'bank_name',
        'bank_branch',
        'bank_account_number',
        'bank_ifsc_code',
        'payment_method',
        'probation_end_date',
        'date_of_leaving',
        'notice_period_days',
        'work_location',
        'notes',
        'weekly_off_days',
        'uan',
        'pf_number',
        'esi_number',
        'pf_opted_out',
        'pt_exempt',
        'tax_regime',
        'declared_deductions',
    ];

    protected $appends = ['avatar_url', 'full_name'];

    /**
     * Encrypted at rest and masked in API responses unless the viewer may
     * see them (EmployeePolicy::viewSensitive).
     */
    public const SENSITIVE = ['national_id', 'tax_id', 'bank_account_number'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'date_of_joining' => 'date',
            'probation_end_date' => 'date',
            'date_of_leaving' => 'date',
            'national_id' => 'encrypted',
            'tax_id' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'weekly_off_days' => 'array',
            'pf_opted_out' => 'boolean',
            'pt_exempt' => 'boolean',
            'declared_deductions' => 'decimal:2',
        ];
    }

    /** Replace sensitive values with masked forms (e.g. XXXXXXXX1234) for display. */
    public function maskSensitive(): static
    {
        foreach (self::SENSITIVE as $field) {
            $value = $this->getAttribute($field);

            if ($value !== null && $value !== '') {
                $plain = preg_replace('/\s+/', '', (string) $value);
                $this->setAttribute($field, str_repeat('X', max(0, strlen($plain) - 4)) . substr($plain, -4));
            }
        }

        $this->setAttribute('sensitive_masked', true);

        return $this;
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $media = $this->getFirstMedia('avatar');

        return $media?->getUrl();
    }

    public function registerMediaCollections(): void
    {
        // Private disk: served only through the authorized download endpoint.
        $this->addMediaCollection('documents')
            ->useDisk('local')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]);

        $this->addMediaCollection('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/gif']);
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Employees $user may see, per their data scope. Branch confinement for
     * branch-scoped users is already applied by BranchScope; this adds the
     * team / self narrowing on top.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match ($user->dataScope()) {
            Roles::SCOPE_COMPANY, Roles::SCOPE_BRANCH => $query,
            Roles::SCOPE_TEAM => $query->whereIn('employees.id', $user->teamEmployeeIds() ?: [0]),
            default => $query->where('employees.id', $user->employee_id ?? 0),
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    public function reportingManager()
    {
        return $this->belongsTo(Employee::class, 'reporting_manager_id');
    }

    public function directReports()
    {
        return $this->hasMany(Employee::class, 'reporting_manager_id');
    }

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function payslips()
    {
        return $this->hasMany(Payslip::class);
    }
}
