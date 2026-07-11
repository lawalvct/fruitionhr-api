<?php

use App\Modules\Reference\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

Route::get('/reference/countries', [LocationController::class, 'countries']);
Route::get('/reference/countries/{country:code}/states', [LocationController::class, 'states']);
