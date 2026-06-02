<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskChatConversation extends Model
{

    protected $fillable = [
        'name',
        'is_group',
        'created_by',
        'team_id',
        'last_message',
        'last_message_type',
        'last_sender_id'
        
    ];

    public function participants()
    {
        return $this->hasMany(TaskChatParticipant::class,'conversation_id');
    }

    public function messages()
    {
        return $this->hasMany(TaskChatMessage::class,'conversation_id');
    }

}