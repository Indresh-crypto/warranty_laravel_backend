<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskTeamMember extends Model
{
    protected $table = 'task_team_members';

    protected $fillable = [
        'team_id',
        'user_id'
    ];
}