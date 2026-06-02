<?php

use App\Models\ActivityLog;

if (!function_exists('activityLog')) {

    function activityLog(
        $type,
        $action,
        $message = null,
        $payload = [],
        $status = 'success',
        $exception = null,
        $tag = null
    ) {

        /*
        |--------------------------------------------------------------------------
        | PREVENT LOGGING LOOP
        |--------------------------------------------------------------------------
        */
        static $logging = false;

        if ($logging) {
            return;
        }

        $logging = true;

        try {

            /*
            |--------------------------------------------------------------------------
            | LIMIT HUGE PAYLOADS
            |--------------------------------------------------------------------------
            */
            if (

                is_array($payload) ||

                is_object($payload)
            ) {

                $payload = json_decode(
                    json_encode($payload),
                    true
                );
            }

            /*
            |--------------------------------------------------------------------------
            | SAVE LOG
            |--------------------------------------------------------------------------
            */
            ActivityLog::create([

                'type' =>
                    substr((string) $type, 0, 255),

                'tag' =>
                    $tag,

                'action' =>
                    substr((string) $action, 0, 255),

                'user_id' =>
                    auth()->id(),

                'message' =>
                    $message,

                'payload' =>
                    $payload,

                'status' =>
                    $status,

                'exception' =>
                    $exception,

                'ip' =>
                    request()?->ip(),

                'url' =>
                    request()?->fullUrl(),

                'method' =>
                    request()?->method(),
            ]);

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | ONLY FILE LOG HERE
            |--------------------------------------------------------------------------
            */
            \Log::error(

                'Activity Log Failed: ' .

                $e->getMessage()
            );

        } finally {

            $logging = false;
        }
    }
}