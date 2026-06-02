<?php
namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class TaskNewChatEvent implements ShouldBroadcastNow
{
    public $conversationId;
    public $receiverId;

    public function __construct($conversationId, $receiverId)
    {
        $this->conversationId = $conversationId;
        $this->receiverId = $receiverId;
    }

    public function broadcastOn()
    {
        return new Channel('user.' . $this->receiverId);
    }

    public function broadcastAs()
    {
        return 'new.chat';
    }

    public function broadcastWith()
    {
        return [
            'conversation_id' => $this->conversationId
        ];
    }
}