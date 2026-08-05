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

/*
 * Sessao: bloquear a tela e limpar os dados locais do navegador.
 *
 * Fora do painel do Filament de proposito: a tela de bloqueio nao pode usar o layout
 * do painel, senao mostraria por tras justamente o que ela existe para esconder.
 */
Route::middleware('auth')->group(function () {
    // POST e nao GET: bloquear muda estado, e link visitado por engano nao pode
    // trancar a tela de ninguem.
    Route::post('/sessao/bloquear', function (\Illuminate\Http\Request $pedido) {
        $pedido->session()->put(\App\Http\Middleware\SessaoBloqueada::CHAVE, true);

        return redirect()->route('sessao.travada');
    })->name('sessao.bloquear');

    Route::get('/sessao/travada', function (\Illuminate\Http\Request $pedido) {
        // Sessao destravada nao tem o que fazer nesta tela.
        if (! $pedido->session()->get(\App\Http\Middleware\SessaoBloqueada::CHAVE)) {
            return redirect('/admin');
        }

        $usuario = $pedido->user();

        return view('sessao.travada', [
            'nome'     => $usuario->name,
            'iniciais' => \Illuminate\Support\Str::of($usuario->name)
                ->explode(' ')
                ->filter()
                ->take(2)
                ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))
                ->implode(''),
        ]);
    })->name('sessao.travada');

    // throttle: tela de bloqueio que aceita tentativa infinita de senha e pior que
    // nenhuma — daria para adivinhar a senha em paz na maquina de quem saiu.
    Route::post('/sessao/destravar', function (\Illuminate\Http\Request $pedido) {
        $pedido->validate(['senha' => 'required|string']);

        if (! \Illuminate\Support\Facades\Hash::check($pedido->input('senha'), $pedido->user()->password)) {
            return back()->withErrors(['senha' => 'Senha incorreta.']);
        }

        $pedido->session()->forget(\App\Http\Middleware\SessaoBloqueada::CHAVE);

        return redirect()->intended('/admin');
    })->middleware('throttle:5,1')->name('sessao.destravar');

    Route::get('/sessao/limpar-navegador', function () {
        return view('sessao.limpar-navegador', [
            // A trava de sessao fica: quem limpa dados locais nao pediu para destravar.
            'manterSessao' => ['onchat.bloqueada'],
            'voltarPara'   => '/admin',
        ]);
    })->name('sessao.limpar-navegador');
});
