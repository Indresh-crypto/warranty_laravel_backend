<?php

namespace App\Events;



use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Broadcasting\Channel;
use Illuminate\Queue\SerializesModels;

class TaskConversationUpdatedEvent implements ShouldBroadcastNow
{
    use SerializesModels;

    public $conversation;

    public function __construct($conversation)
    {
        $this->conversation = $conversation;
    }
    public function broadcastOn()
    {
        return new Channel('user.' . $this->conversation['user_id']);
    }

    public function broadcastAs()
    {
        return 'conversation.updated';
    }
     public function broadcastWith()
    {
        return $this->conversation;
    }
}