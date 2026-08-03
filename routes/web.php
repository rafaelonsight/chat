<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\EvolutionWebhookController;

Route::post('/webhooks/evolution/{channel}/{secret}', EvolutionWebhookController::class)
    ->name('webhooks.evolution');

// O login do OnChat e o do painel Filament: mesma sessao web. Este alias existe
// porque o middleware 'auth' redireciona para a rota chamada 'login'.
Route::get('/login', fn () => redirect('/admin/login'))->name('login');

Route::get('/media/{message}', App\Http\Controllers\MediaController::class)
    ->middleware('auth')
    ->name('media.show');

// A raiz e a porta de entrada: sem sessao o Filament mostra o login, com sessao
// cai direto no Atendimento.
Route::redirect('/', '/admin');

// O inbox virou pagina do painel; a rota antiga continua valendo.
Route::redirect('/inbox', '/admin');
