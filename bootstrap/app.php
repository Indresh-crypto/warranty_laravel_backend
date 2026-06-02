<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;

use App\Mail\UniversalErrorMail;
use App\Http\Middleware\CompanyApiAuth;

return Application::configure(basePath: dirname(__DIR__))

    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up'
    )

    ->withMiddleware(function (Middleware $middleware): void {

        $middleware->validateCsrfTokens(except: [
            'api/razorpay/webhook',
            'api/esign/webhook'
        ]);

        $middleware->append(
            \Illuminate\Http\Middleware\HandleCors::class
        );

        $middleware->alias([
            'company.auth' => CompanyApiAuth::class,
        ]);
    })

    ->withSchedule(function ($schedule) {

        $schedule->command('email:inactive-retailers')
            ->dailyAt('23:00');

        $schedule->command('app:notify-due-tasks')
            ->hourly();

        $schedule->command('sales:daily-reminder')
            ->dailyAt('20:00');

        $schedule->command('report:daily-sales')
            ->dailyAt('23:10');

        $schedule->command('report:weekly-sales')
            ->weeklyOn(1, '23:20');

        $schedule->command('report:monthly-sales')
            ->monthlyOn(1, '23:30');

        $schedule->command('report:inactive-retailers')
            ->dailyAt('10:00');

        $schedule->command('report:daily-retailer')
            ->dailyAt('01:00');

        $schedule->command('payouts:generate')
            ->monthlyOn(1, '01:00');

        $schedule->command('wa:pending-activation')
            ->dailyAt('10:00')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('wa:pending-activation')
            ->dailyAt('16:00')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('retailers:update-inactive')
            ->dailyAt('01:00');

    })

    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->report(function (\Throwable $e) {

            if (app()->environment('production')) {

                if (!Cache::has('error_email_lock')) {

                    Cache::put('error_email_lock', true, 300);

                    try {

                        Mail::to('indresh@goelectronix.com')
                            ->send(
                                new UniversalErrorMail(
                                    $e,
                                    request()?->all() ?? []
                                )
                            );

                    } catch (\Throwable $mailError) {

                        logger()->error(
                            'Error sending error alert email: ' .
                            $mailError->getMessage()
                        );
                    }
                }
            }
        });

    })

    ->create();