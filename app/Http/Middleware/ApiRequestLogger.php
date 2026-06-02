<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestLogger
{
    /**
     * Handle an incoming request.
     */
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        /*
        |--------------------------------------------------------------------------
        | SKIP HEALTH / STATIC
        |--------------------------------------------------------------------------
        */
        if (

            $request->is('up') ||

            $request->is('_debugbar/*')
        ) {

            return $next($request);
        }

        /*
        |--------------------------------------------------------------------------
        | START TIME
        |--------------------------------------------------------------------------
        */
        $start = microtime(true);

        try {

            /*
            |--------------------------------------------------------------------------
            | RESPONSE
            |--------------------------------------------------------------------------
            */
            $response = $next($request);

            /*
            |--------------------------------------------------------------------------
            | EXECUTION TIME
            |--------------------------------------------------------------------------
            */
            $executionTime = round(
                microtime(true) - $start,
                3
            );

            /*
            |--------------------------------------------------------------------------
            | REMOVE SENSITIVE DATA
            |--------------------------------------------------------------------------
            */
            $params = $request->except([

                'password',
                'password_confirmation',
                'token',
                'access_token',
                'refresh_token',
                'file',
                'image'
            ]);

            /*
            |--------------------------------------------------------------------------
            | ACTIVITY LOG
            |--------------------------------------------------------------------------
            */
            activityLog(

                'api-request',

                $request->path(),

                'API Request Success',

                [

                    'method' => $request->method(),

                    'url' => $request->fullUrl(),

                    'params' => $params,

                    'ip' => $request->ip(),

                    'user_agent' => $request->userAgent(),

                    'status_code' =>
                        $response->getStatusCode(),

                    'execution_time' =>
                        $executionTime . ' sec',
                ],

                'success'
            );

            return $response;

        } catch (\Throwable $e) {

            /*
            |--------------------------------------------------------------------------
            | FAILED LOG
            |--------------------------------------------------------------------------
            */
            activityLog(

                'api-request',

                $request->path(),

                $e->getMessage(),

                [

                    'method' => $request->method(),

                    'url' => $request->fullUrl(),

                    'params' => $request->except([

                        'password',
                        'password_confirmation',
                        'token',
                        'access_token'
                    ]),
                ],

                'failed',

                $e->getTraceAsString()
            );

            throw $e;
        }
    }
}