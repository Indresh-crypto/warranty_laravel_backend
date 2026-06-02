<?php

namespace App\Providers;

use App\Models\WDevice;
use App\Observers\WDeviceObserver;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
|--------------------------------------------------------------------------
| QUEUE EVENTS
|--------------------------------------------------------------------------
*/
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/*
|--------------------------------------------------------------------------
| COMMAND EVENTS
|--------------------------------------------------------------------------
*/
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/*
|--------------------------------------------------------------------------
| MAIL EVENTS
|--------------------------------------------------------------------------
*/
use Illuminate\Mail\Events\MessageSent;

/*
|--------------------------------------------------------------------------
| NOTIFICATION EVENTS
|--------------------------------------------------------------------------
*/
use Illuminate\Notifications\Events\NotificationSent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {

        /*
        |--------------------------------------------------------------------------
        | RESET PASSWORD URL
        |--------------------------------------------------------------------------
        */
        ResetPassword::createUrlUsing(

            function ($company, string $token) {

                return env('FRONTEND_RESET_URL') .

                    "?token={$token}&email={$company->contact_email}";
            }
        );

        /*
        |--------------------------------------------------------------------------
        | OBSERVERS
        |--------------------------------------------------------------------------
        */
        WDevice::observe(WDeviceObserver::class);

        /*
        |--------------------------------------------------------------------------
        | JOB PROCESSING
        |--------------------------------------------------------------------------
        */
        Queue::before(function (

            JobProcessing $event

        ) {

            activityLog(

                'job',

                'processing',

                'Job Started',

                [

                    'queue' =>
                        $event->job->getQueue(),

                    'connection' =>
                        $event->connectionName,
                ],

                'success',

                null,

                class_basename(
                    $event->job->resolveName()
                )
            );
        });

        /*
        |--------------------------------------------------------------------------
        | JOB COMPLETED
        |--------------------------------------------------------------------------
        */
        Queue::after(function (

            JobProcessed $event

        ) {

            activityLog(

                'job',

                'completed',

                'Job Completed',

                [

                    'queue' =>
                        $event->job->getQueue(),

                    'connection' =>
                        $event->connectionName,
                ],

                'success',

                null,

                class_basename(
                    $event->job->resolveName()
                )
            );
        });

        /*
        |--------------------------------------------------------------------------
        | JOB FAILED
        |--------------------------------------------------------------------------
        */
        Queue::failing(function (

            JobFailed $event

        ) {

            activityLog(

                'job',

                'failed',

                $event->exception->getMessage(),

                [

                    'queue' =>
                        $event->job->getQueue(),

                    'connection' =>
                        $event->connectionName,
                ],

                'failed',

                $event->exception->getTraceAsString(),

                class_basename(
                    $event->job->resolveName()
                )
            );
        });

        /*
        |--------------------------------------------------------------------------
        | COMMAND START
        |--------------------------------------------------------------------------
        */
        Event::listen(

            CommandStarting::class,

            function (

                CommandStarting $event

            ) {

                activityLog(

                    'command',

                    'started',

                    'Command Started',

                    [],

                    'success',

                    null,

                    $event->command
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | COMMAND FINISHED
        |--------------------------------------------------------------------------
        */
        Event::listen(

            CommandFinished::class,

            function (

                CommandFinished $event

            ) {

                activityLog(

                    'command',

                    'completed',

                    'Command Completed',

                    [

                        'exit_code' =>
                            $event->exitCode
                    ],

                    'success',

                    null,

                    $event->command
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | MAIL LOGS
        |--------------------------------------------------------------------------
        */
        Event::listen(

            MessageSent::class,

            function (

                MessageSent $event

            ) {

                activityLog(

                    'mail',

                    'sent',

                    'Mail Sent',

                    [],

                    'success',

                    null,

                    'mail'
                );
            }
        );

        /*
        |--------------------------------------------------------------------------
        | NOTIFICATION LOGS
        |--------------------------------------------------------------------------
        */
        Event::listen(

            NotificationSent::class,

            function (

                NotificationSent $event

            ) {

                activityLog(

                    'notification',

                    'sent',

                    'Notification Sent',

                    [],

                    'success',

                    null,

                    class_basename(
                        $event->notification
                    )
                );
            }
        );
    }
}