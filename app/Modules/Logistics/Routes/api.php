<?php

use Illuminate\Support\Facades\Route;
use App\Modules\Logistics\Controllers\DriverController;
use App\Modules\Logistics\Controllers\VehicleController;
use App\Modules\Logistics\Controllers\TripRequestController;
use App\Modules\Logistics\Controllers\DispatchBatchController;
use App\Modules\Logistics\Controllers\DeliveryController;
use App\Modules\Logistics\Controllers\DriverDeliveryController;
use App\Modules\Logistics\Controllers\MaintenanceController;
use App\Modules\Logistics\Controllers\InspectionController;
use App\Constants\Permissions;

// ── Fleet (drivers & vehicles) ────────────────────────────────────────────────
Route::prefix('fleet')->group(function () {

    // Drivers
    Route::get('/drivers',                      [DriverController::class, 'index']);
    Route::post('/drivers',                     [DriverController::class, 'store']);
    Route::get('/drivers/available-employees',  [DriverController::class, 'availableEmployees']);
    Route::get('/drivers/{driver}',             [DriverController::class, 'show']);
    Route::put('/drivers/{driver}',             [DriverController::class, 'update']);
    Route::patch('/drivers/{driver}',           [DriverController::class, 'update']);
    Route::delete('/drivers/{driver}',          [DriverController::class, 'destroy']);

    // Vehicles
    Route::get('/vehicles',                     [VehicleController::class, 'index']);
    Route::post('/vehicles',                    [VehicleController::class, 'store']);
    Route::get('/vehicles/{vehicle}',           [VehicleController::class, 'show']);
    Route::put('/vehicles/{vehicle}',           [VehicleController::class, 'update']);
    Route::patch('/vehicles/{vehicle}',         [VehicleController::class, 'update']);
    Route::patch('/vehicles/{vehicle}/gps',     [VehicleController::class, 'updateGps']);
    Route::delete('/vehicles/{vehicle}',        [VehicleController::class, 'destroy']);
});

// ── Logistics ─────────────────────────────────────────────────────────────────
Route::prefix('logistics')->group(function () {

    // Trip Requests
    Route::get('/trip-requests',                          [TripRequestController::class, 'index']);
    Route::post('/trip-requests',                         [TripRequestController::class, 'store']);
    Route::get('/trip-requests/{tripRequest}',            [TripRequestController::class, 'show']);
    Route::put('/trip-requests/{tripRequest}',            [TripRequestController::class, 'update']);
    Route::patch('/trip-requests/{tripRequest}',          [TripRequestController::class, 'update']);
    Route::delete('/trip-requests/{tripRequest}',         [TripRequestController::class, 'destroy']);
    Route::patch('/trip-requests/{tripRequest}/approve',  [TripRequestController::class, 'approve']);
    Route::patch('/trip-requests/{tripRequest}/reject',   [TripRequestController::class, 'reject']);
    Route::patch('/trip-requests/{tripRequest}/assign',   [TripRequestController::class, 'assign']);
    Route::patch('/trip-requests/{tripRequest}/start',    [TripRequestController::class, 'start']);
    Route::patch('/trip-requests/{tripRequest}/complete', [TripRequestController::class, 'complete']);
    Route::patch('/trip-requests/{tripRequest}/cancel',   [TripRequestController::class, 'cancel']);

    // Dispatch Board
    Route::get('/dispatch-batches/available-requests',        [DispatchBatchController::class, 'availableRequests']);
    Route::get('/dispatch-batches',                           [DispatchBatchController::class, 'index']);
    Route::post('/dispatch-batches',                          [DispatchBatchController::class, 'store']);
    Route::get('/dispatch-batches/{dispatchBatch}',           [DispatchBatchController::class, 'show']);
    Route::patch('/dispatch-batches/{dispatchBatch}',         [DispatchBatchController::class, 'update']);
    Route::patch('/dispatch-batches/{dispatchBatch}/confirm', [DispatchBatchController::class, 'confirm']);
    Route::delete('/dispatch-batches/{dispatchBatch}',        [DispatchBatchController::class, 'destroy']);

    // Deliveries
    Route::get('/deliveries',                           [DeliveryController::class, 'index']);
    Route::get('/deliveries/{delivery}',                [DeliveryController::class, 'show']);
    Route::middleware('permission:' . Permissions::LOGISTICS_DELIVERIES_MANAGE)->group(function () {
        Route::patch('/deliveries/{delivery}/start',        [DeliveryController::class, 'start']);
        Route::patch('/deliveries/{delivery}/cancel',       [DeliveryController::class, 'cancel']);
        Route::patch('/deliveries/{delivery}/edit',         [DeliveryController::class, 'edit']);
        Route::patch('/deliveries/{delivery}/stops/{stop}', [DeliveryController::class, 'updateStop']);
    });

    // Driver App (Flutter)
    Route::prefix('driver')->group(function () {
        Route::get('/my-delivery',                                [DriverDeliveryController::class, 'myDelivery']);
        Route::patch('/deliveries/{delivery}/start',              [DeliveryController::class, 'start']);
        Route::patch('/deliveries/{delivery}/cancel',             [DeliveryController::class, 'cancel']);
        Route::patch('/deliveries/{delivery}/stops/{stop}',       [DeliveryController::class, 'updateStop']);
        Route::patch('/delivery/{delivery}/stop/{stop}/arrived',  [DriverDeliveryController::class, 'arrived']);
        Route::patch('/delivery/{delivery}/stop/{stop}/delivered',[DriverDeliveryController::class, 'delivered']);
        Route::patch('/delivery/{delivery}/stop/{stop}/failed',   [DriverDeliveryController::class, 'failed']);
        Route::post('/delivery/{delivery}/location',              [DriverDeliveryController::class, 'updateLocation']);
    });

    // Analytics
    Route::get('/analytics', [\App\Modules\Logistics\Controllers\LogisticsAnalyticsController::class, 'index']);

    // System GPS & Active Trips (Vue web app)
    Route::get('/active-trips', [DriverDeliveryController::class, 'activeTrips']);
    Route::get('/gps-tracking', [DriverDeliveryController::class, 'gpsTracking']);
    Route::get('/delivery-history', [DriverDeliveryController::class, 'deliveryHistory']);

    // Maintenance
    Route::get('/maintenance/stats',                    [MaintenanceController::class, 'stats']);
    Route::get('/maintenance/vehicles-in-maintenance',  [MaintenanceController::class, 'vehiclesInMaintenance']);
    Route::get('/maintenance',                          [MaintenanceController::class, 'index']);
    Route::post('/maintenance',                         [MaintenanceController::class, 'store']);
    Route::get('/maintenance/{maintenance}',            [MaintenanceController::class, 'show']);
    Route::patch('/maintenance/{maintenance}',          [MaintenanceController::class, 'update']);
    Route::patch('/maintenance/{maintenance}/submit',   [MaintenanceController::class, 'submit']);
    Route::patch('/maintenance/{maintenance}/cost',     [MaintenanceController::class, 'addCost']);
    Route::patch('/maintenance/{maintenance}/approve',  [MaintenanceController::class, 'approve']);
    Route::patch('/maintenance/{maintenance}/complete', [MaintenanceController::class, 'complete']);
    Route::patch('/maintenance/{maintenance}/reject',   [MaintenanceController::class, 'reject']);
    Route::delete('/maintenance/{maintenance}',         [MaintenanceController::class, 'destroy']);

    // Inspections
    Route::get('/inspections/checklist-items',       [InspectionController::class, 'checklistItems']);
    Route::get('/inspections/stats',                 [InspectionController::class, 'stats']);
    Route::get('/inspections',                       [InspectionController::class, 'index']);
    Route::post('/inspections',                      [InspectionController::class, 'store']);
    Route::get('/inspections/{inspection}',          [InspectionController::class, 'show']);
    Route::patch('/inspections/{inspection}',        [InspectionController::class, 'update']);
    Route::patch('/inspections/{inspection}/submit', [InspectionController::class, 'submit']);
    Route::patch('/inspections/{inspection}/review', [InspectionController::class, 'review']);
    Route::delete('/inspections/{inspection}',       [InspectionController::class, 'destroy']);
});
