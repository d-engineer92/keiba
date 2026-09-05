<?php

use App\Http\Controllers\Internal\IngestSchedulesController;
use App\Http\Middleware\AuthenticateJvLink;
use Illuminate\Support\Facades\Route;

Route::post('/internal/v1/jvlink/schedules', IngestSchedulesController::class)
    ->middleware(AuthenticateJvLink::class);
