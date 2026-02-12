<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Logistics\Controllers\LogisticsController;

Route::get('/dashboard', [LogisticsController::class, 'dashboard']);
Route::get('/deliveries', [LogisticsController::class, 'deliveries']);
Route::get('/drivers', [LogisticsController::class, 'drivers']);
Route::get('/fleet', [LogisticsController::class, 'fleet']);
Route::get('/routes', [LogisticsController::class, 'routes']);
Route::get('/tracking', [LogisticsController::class, 'tracking']);
