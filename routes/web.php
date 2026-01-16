<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MdvrController;

Route::get('/', function () {
    return redirect()->route('mdvr.dashboard');
});

// MDVR Dashboard Routes
Route::prefix('mdvr')->name('mdvr.')->group(function () {
    Route::get('/', [MdvrController::class, 'dashboard'])->name('dashboard');
    
    // Devices
    Route::get('/devices', [MdvrController::class, 'devices'])->name('devices');
    Route::get('/devices/{device}', [MdvrController::class, 'deviceShow'])->name('devices.show');
    
    // Map
    Route::get('/map', [MdvrController::class, 'map'])->name('map');
    
    // Monitoring (New)
    Route::get('/monitoring', [MdvrController::class, 'monitoring'])->name('monitoring');
    
    // Alarms
    Route::get('/alarms', [MdvrController::class, 'alarms'])->name('alarms');
    Route::get('/alarms/{alarm}', [MdvrController::class, 'alarmShow'])->name('alarms.show');
    
    // API endpoints
    Route::prefix('api')->name('api.')->group(function () {
        Route::get('/locations', [MdvrController::class, 'apiLocations'])->name('locations');
        Route::get('/stats', [MdvrController::class, 'apiStats'])->name('stats');
        Route::get('/devices/{device}/locations', [MdvrController::class, 'apiDeviceLocations'])->name('device.locations');
    });
});
