<?php

use Illuminate\Support\Facades\Route;
use App\Modules\ProcurementStores\Controllers\ProcurementStoresController;

Route::get('/test', [ProcurementStoresController::class, 'test']);
