<?php

use App\Http\Controllers\Internal\IngestJvLinkEventsController;
use App\Http\Controllers\Internal\IngestSchedulesController;
use App\Http\Controllers\Internal\ReportJvLinkBackfillController;
use App\Http\Middleware\AuthenticateJvLink;
use Illuminate\Support\Facades\Route;

Route::post('/internal/v1/jvlink/schedules', IngestSchedulesController::class)
    ->middleware(AuthenticateJvLink::class);
Route::post('/internal/v1/jvlink/events', IngestJvLinkEventsController::class)
    ->middleware(AuthenticateJvLink::class);
Route::post('/internal/v1/jvlink/backfills', ReportJvLinkBackfillController::class)
    ->middleware(AuthenticateJvLink::class);
