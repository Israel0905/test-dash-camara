<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/mdvr-live', function () {
    return view('mdvr_live');
});
