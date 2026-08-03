<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

use App\Http\Controllers\EvolutionWebhookController;

Route::post('/webhooks/evolution/{channel}/{secret}', EvolutionWebhookController::class)
    ->name('webhooks.evolution');

// O login do OnChat e o do painel Filament: mesma sessao web. Este alias existe
// porque o middleware 'auth' redireciona para a rota chamada 'login'.
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

Route::view('/inbox', 'inbox')->middleware('auth')->name('inbox');

Route::get('/media/{message}', App\Http\Controllers\MediaController::class)
    ->middleware('auth')
    ->name('media.show');
