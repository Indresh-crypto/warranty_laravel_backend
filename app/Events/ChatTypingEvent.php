<?php
namespace App\Events;
class ChatTypingEvent implements ShouldBroadcastNow
{
    public $conversationId;
    public $userId;

    public function __construct($conversationId,$userId)
    {
        $this->conversationId=$conversationId;
        $this->userId=$userId;
    }

    public function broadcastOn()
    {
        return new Channel('chat.'.$this->conversationId);
    }

    public function broadcastAs()
    {
        return 'chat.typing';
    }
}