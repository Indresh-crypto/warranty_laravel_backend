<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaskTeam extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        'name',
        'manager_id',
        'status',
        'deleted_at'
    ];


    public function manager()
    {
        return $this->belongsTo(TaskUser::class, 'manager_id');
    }

    public function members()
    {
        return $this->belongsToMany(
            TaskUser::class,
            'task_team_members',
            'team_id',
            'user_id'
        );
    }
}