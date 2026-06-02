<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;


class TaskChatParticipant extends Model
{

    protected $fillable = [
        'conversation_id',	'user_id',	'last_read_at'
    ];

}
