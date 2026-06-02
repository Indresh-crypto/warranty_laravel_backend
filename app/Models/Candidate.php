<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Candidate extends Model
{
    protected $fillable = [
        'name',
        'mobile',
        'status',
        'last_sent_at',
        'send_count',
        'city',
        'location',
        'qualification',
        'experience_level',
        'gender',
        'resume_link',
        'profile_link',
        'current_salary',
        'course',
        'college_name',
        'previous_designation',
        'previous_company_name',
        'data_source',
    ];

    protected $casts = [
        'last_sent_at' => 'datetime',
    ];
}