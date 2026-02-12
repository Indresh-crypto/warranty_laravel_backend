<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use App\Mail\UniversalErrorMail;

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

    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->report(function (\Throwable $e) {

            if (app()->environment('production')) {

                if (!Cache::has('error_email_lock')) {

                    Cache::put('error_email_lock', true, 300);

                    try {

                        Mail::to('indresh@goelectronix.com')
                            ->send(new UniversalErrorMail(
                                $e,
                                request()?->all() ?? []
                            ));

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