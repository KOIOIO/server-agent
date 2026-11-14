<?php

use App\Http\Controllers\TaskController;
use Illuminate\Support\Facades\Route;

Route::get('/tasks', [TaskController::class, 'index']);
Route::post('/tasks', [TaskController::class, 'store'])->middleware('throttle:10,1');
Route::get('/tasks/{id}', [TaskController::class, 'show']);
Route::post('/tasks/{id}/feedback', [TaskController::class, 'feedback'])->middleware('throttle:10,1');
Route::get('/tasks/{id}/revisions/{number}/artifacts/{format}', [TaskController::class, 'artifact']);
