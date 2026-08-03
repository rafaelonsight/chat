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

// Saude para monitor externo. Deliberadamente publica e magra: diz o que caiu
// pelo nome, sem detalhe interno. Um monitor de fora e a unica coisa que funciona
// quando a maquina inteira para — alerta que sai daqui de dentro, nao sai.
Route::get('/saude', function () {
    $diagnostico = app(App\Services\Diagnostico::class);
    $problemas = $diagnostico->verificar();

    $criticos = array_values(array_filter($problemas, fn ($p) => $p['nivel'] === App\Services\Diagnostico::CRITICO));
    $avisos = array_values(array_filter($problemas, fn ($p) => $p['nivel'] === App\Services\Diagnostico::AVISO));

    return response()->json([
        'ok'      => $criticos === [],
        'falhas'  => array_column($criticos, 'chave'),
        'avisos'  => array_column($avisos, 'chave'),
    ], $criticos === [] ? 200 : 503);
})->name('saude');
