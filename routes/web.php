<?php

use Illuminate\Support\Facades\Route;

use App\Models\WDevice;
use App\Mail\WarrantyActivationMail;
use Illuminate\Support\Facades\Mail;

use App\Events\CustomerRegistered;
use App\Models\WCustomer;


use App\Events\WarrantyRegistered;

use App\Http\Controllers\AgreementController;


Route::get('/', function () {
    return view('welcome');
});

Route::get('/check', function () {
    return view('welcome');
});

// routes/web.php
Route::get('/test-mail', function () {
    Mail::raw('Mail system working!', function ($msg) {
        $msg->to('indresh@goelectronix.com')
            ->subject('SMTP Test');
    });

    return 'Mail sent';
});

Route::get('/preview-warranty-mail', function () {
    $device = WDevice::with([
        'customer',
        'product.coverages'
    ])->first();

    return new WarrantyActivationMail($device);
});



Route::get('/send-warranty-mail', function () {
    $device = WDevice::with([
        'customer',
        'product.coverages'
    ])->findOrFail(1); // use real device ID

    Mail::to($device->customer->email)
        ->send(new \App\Mail\WarrantyActivationMail($device));

    return 'Warranty mail sent';
});


Route::get('/test-customer-event', function () {
    $customer = WCustomer::first();

    event(new CustomerRegistered($customer));

    return 'CustomerRegistered event fired';
});


Route::get('/queue-mail-test', function () {

    $device = WDevice::with([
        'customer',
        'product.coverages'
    ])->firstOrFail();

    Mail::to($device->customer->email)
        ->queue(new WarrantyActivationMail($device));

    return 'Mail pushed to queue';
});


Route::get('/resend-warranty-mail/{id}', function ($id) {
    $device = WDevice::with(['customer', 'product.coverages'])->findOrFail($id);

    event(new WarrantyRegistered($device));

    return 'Warranty email re-triggered';
});


Route::get('/test-mail', function () {
    \Mail::raw('Email working fine', function ($m) {
        $m->to('indresh@goelectronix.com')
          ->subject('Test Mail');
    });

    return 'Mail sent';
});


Route::get('/esign/callback', [AgreementController::class, 'esignCallback'])
    ->name('esign.callback');

