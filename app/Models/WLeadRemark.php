<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WLeadRemark extends Model
{
    protected $table = 'w_lead_remarks';

    protected $fillable = [
        'lead_id',
        'remark',
        'followup_date',
        'follow_up_by',
        'created_by',
        'status'
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function lead()
    {
        return $this->belongsTo(WLead::class, 'lead_id');
    }

    public function followupUser()
    {
        return $this->belongsTo(CompanyEmployee::class, 'follow_up_by');
    }

    public function createdUser()
    {
        return $this->belongsTo(CompanyEmployee::class, 'created_by');
    }
}