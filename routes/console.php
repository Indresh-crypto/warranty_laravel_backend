<?php

use Illuminate\Support\Facades\Schedule;
use App\Jobs\GenerateWarrantyRetailerInvoicesJob;

/*
Schedule::job(new GenerateWarrantyRetailerInvoicesJob)
    ->everyMinute()
    ->withoutOverlapping();
*/