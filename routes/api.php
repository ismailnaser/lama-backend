<?php

use App\Http\Controllers\PatientController;
use Illuminate\Support\Facades\Route;

Route::get('/patients', [PatientController::class, 'index']);
Route::get('/patients/count', [PatientController::class, 'count']);
Route::post('/patients', [PatientController::class, 'store']);
Route::patch('/patients/{patient}', [PatientController::class, 'update']);
Route::delete('/patients/{patient}', [PatientController::class, 'destroy']);
Route::get('/patients/pdf', [PatientController::class, 'pdf']);
Route::get('/patients/excel', [PatientController::class, 'excel']);

