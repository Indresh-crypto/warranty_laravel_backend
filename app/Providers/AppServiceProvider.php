<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Auth\Notifications\ResetPassword;

use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Mail;
use App\Mail\UniversalErrorMail;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function ($company, string $token) {
            return env('FRONTEND_RESET_URL') .
                "?token={$token}&email={$company->contact_email}";
        });
        
            Queue::failing(function (JobFailed $event) {

        Mail::to('indresh@goelectronix.com')
            ->send(new UniversalErrorMail(
                $event->exception,
                ['job' => $event->job->resolveName()]
            ));
    });
    }
}
