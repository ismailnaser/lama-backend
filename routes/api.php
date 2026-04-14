<?php

use App\Http\Controllers\PatientController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserAdminController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth.token')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/patients', [PatientController::class, 'index']);
    Route::get('/patients/count', [PatientController::class, 'count']);
    Route::post('/patients', [PatientController::class, 'store']);
    Route::patch('/patients/{patient}', [PatientController::class, 'update']);
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy']);
    Route::get('/patients/{patient}/audits', [PatientController::class, 'audits']);
    Route::get('/patients/pdf', [PatientController::class, 'pdf']);
    Route::get('/patients/excel', [PatientController::class, 'excel']);

    Route::middleware('admin')->group(function () {
        Route::get('/users', [UserAdminController::class, 'index']);
        Route::post('/users', [UserAdminController::class, 'store']);
    });
});

