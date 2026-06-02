<?php

namespace App\Jobs;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tokens;
    protected $title;
    protected $body;
    protected $data;

    public function __construct($tokens, $title, $body, $data = [])
    {
        $this->tokens = $tokens;
        $this->title = $title;
        $this->body = $body;
        $this->data = $data;
    }

    public function handle()
    {
        $factory = (new Factory)
            ->withServiceAccount(storage_path('app/firebase/serviceAccount.json'));

        $messaging = $factory->createMessaging();

        $notification = Notification::create($this->title, $this->body);

        foreach ($this->tokens as $token) {

            $message = CloudMessage::new()
                ->toToken($token)
                ->withNotification($notification)
                ->withData($this->data);

            $messaging->send($message);
        }
    }
}