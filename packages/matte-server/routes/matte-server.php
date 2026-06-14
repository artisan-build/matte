<?php

declare(strict_types=1);

use ArtisanBuild\MatteServer\Http\Controllers\RemoveController;
use Illuminate\Support\Facades\Route;

Route::post('v1/remove', [RemoveController::class, 'store'])->name('matte.remove');
Route::get('v1/jobs/{jobId}', [RemoveController::class, 'show'])->name('matte.jobs.show');
Route::get('v1/jobs/{jobId}/result', [RemoveController::class, 'result'])->name('matte.jobs.result');
