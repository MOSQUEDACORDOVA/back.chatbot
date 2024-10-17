<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

//Este es para enviar recordatorios a los clientes 
//con CheckInsuranceExpiry
//Schedule::command('insurance:check-expiry')->daily(); 