<?php

use App\Http\Controllers\Web\TestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

if (app()->environment('local')) {
    Route::get('/test-register', [TestController::class, 'showRegisterForm'])->name('test.register');
}
Route::view('/admin/organizations', 'admin.organizations');
