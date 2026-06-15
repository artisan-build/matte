<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Matte',
    'status' => 'ok',
]))->name('home');
