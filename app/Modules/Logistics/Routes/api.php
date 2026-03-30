<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Logistics\Controllers\DriverController;
use App\Modules\Logistics\Controllers\VehicleController;
use App\Modules\Logistics\Controllers\TripRequestController;  // ← ADD THIS

// Fleet routes (drivers & vehicles)
Route::prefix('fleet')->group(function () {

    // Drivers
    Route::get('/drivers', [DriverController::class, 'index']);
    Route::post('/drivers', [DriverController::class, 'store']);
    Route::get('/drivers/available-employees', [DriverController::class, 'availableEmployees']);
    Route::get('/drivers/{driver}', [DriverController::class, 'show']);
    Route::put('/drivers/{driver}', [DriverController::class, 'update']);
    Route::patch('/drivers/{driver}', [DriverController::class, 'update']);
    Route::delete('/drivers/{driver}', [DriverController::class, 'destroy']);

    // Vehicles
    Route::get('/vehicles', [VehicleController::class, 'index']);
    Route::post('/vehicles', [VehicleController::class, 'store']);
    Route::get('/vehicles/{vehicle}', [VehicleController::class, 'show']);
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::patch('/vehicles/{vehicle}', [VehicleController::class, 'update']);
    Route::patch('/vehicles/{vehicle}/gps', [VehicleController::class, 'updateGps']);
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy']);
});

// Logistics routes (trip requests)
Route::prefix('logistics')->group(function () {

    // CRUD
    Route::get('/trip-requests',                          [TripRequestController::class, 'index']);
    Route::post('/trip-requests',                         [TripRequestController::class, 'store']);
    Route::get('/trip-requests/{tripRequest}',            [TripRequestController::class, 'show']);
    Route::put('/trip-requests/{tripRequest}',            [TripRequestController::class, 'update']);
    Route::patch('/trip-requests/{tripRequest}',          [TripRequestController::class, 'update']);
    Route::delete('/trip-requests/{tripRequest}',         [TripRequestController::class, 'destroy']);

    // Workflow transitions
    Route::patch('/trip-requests/{tripRequest}/approve',  [TripRequestController::class, 'approve']);
    Route::patch('/trip-requests/{tripRequest}/reject',   [TripRequestController::class, 'reject']);
    Route::patch('/trip-requests/{tripRequest}/assign',   [TripRequestController::class, 'assign']);
    Route::patch('/trip-requests/{tripRequest}/start',    [TripRequestController::class, 'start']);
    Route::patch('/trip-requests/{tripRequest}/complete', [TripRequestController::class, 'complete']);
    Route::patch('/trip-requests/{tripRequest}/cancel',   [TripRequestController::class, 'cancel']);

    Route::get('/dispatch-batches/available-requests', [DispatchBatchController::class, 'availableRequests']);
Route::get('/dispatch-batches',            [DispatchBatchController::class, 'index']);
Route::post('/dispatch-batches',           [DispatchBatchController::class, 'store']);
Route::get('/dispatch-batches/{dispatchBatch}',    [DispatchBatchController::class, 'show']);
Route::patch('/dispatch-batches/{dispatchBatch}',  [DispatchBatchController::class, 'update']);
Route::patch('/dispatch-batches/{dispatchBatch}/confirm', [DispatchBatchController::class, 'confirm']);
Route::delete('/dispatch-batches/{dispatchBatch}', [DispatchBatchController::class, 'destroy']);

// ── Deliveries ────────────────────────────────────────────────────────────
Route::get('/deliveries',           [DeliveryController::class, 'index']);
Route::get('/deliveries/{delivery}', [DeliveryController::class, 'show']);
Route::patch('/deliveries/{delivery}/start', [DeliveryController::class, 'start']);
Route::patch('/deliveries/{delivery}/stops/{stop}', [DeliveryController::class, 'updateStop']);
});
