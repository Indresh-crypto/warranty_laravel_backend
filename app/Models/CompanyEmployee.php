<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyEmployee extends Model
{
    protected $table = 'company_employee';

    protected $fillable = [
        'password',
        'company_id',
        'first_name',
        'middle_name',
        'last_name',

        'personal_phone',
        'official_phone',
        'official_email',
        'employee_id',

        'type_of_user',
        'position',
        'reports_to',

        'categories',
        'handle',

        'state',
        'district',
        'pincodes',
        'employee_type',
        'location_mode',
        'photo_url',
        'password_changed_at'
    ];

    protected $casts = [
        'categories' => 'array',
        'handle'     => 'array',
        'pincodes'   => 'array'
    ];
    
     public function company()
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    // 🔹 Employee reports to another employee (Manager)
    public function manager()
    {
        return $this->belongsTo(self::class, 'reports_to');
    }

    // 🔹 Employee has many subordinates
    public function subordinates()
    {
        return $this->hasMany(self::class, 'reports_to');
    }

    // 🔹 Employee categories (if stored in pivot table)
    public function employeeCategories()
    {
        return $this->hasMany(CompanyEmployeeCategory::class, 'employee_id');
    }

    // 🔹 Employee handled locations / areas
    public function employeeHandles()
    {
        return $this->hasMany(CompanyEmployeeHandle::class, 'employee_id');
    }

    // 🔹 Accessor: Full name
    public function getFullNameAttribute()
    {
        return trim(
            $this->first_name . ' ' .
            ($this->middle_name ? $this->middle_name . ' ' : '') .
            $this->last_name
        );
    }
}