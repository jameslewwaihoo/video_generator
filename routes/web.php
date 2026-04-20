<?php

use App\Http\Controllers\VideoGeneratorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', [VideoGeneratorController::class, 'index']);
Route::post('/assets/videos', [VideoGeneratorController::class, 'uploadVideo']);
Route::post('/assets/music', [VideoGeneratorController::class, 'uploadMusic']);
Route::post('/generate', [VideoGeneratorController::class, 'generate']);
Route::post('/jobs/{videoJob}/retry', [VideoGeneratorController::class, 'retry']);
Route::get('/generated/{file}/watch', [VideoGeneratorController::class, 'watch']);
Route::delete('/generated/{file}', [VideoGeneratorController::class, 'destroyGenerated']);
Route::get('/generated/{file}', [VideoGeneratorController::class, 'download']);
