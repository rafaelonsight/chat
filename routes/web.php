<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\EvolutionWebhookController;

Route::post('/webhooks/evolution/{channel}/{secret}', EvolutionWebhookController::class)
    ->name('webhooks.evolution');

/*
 * WhatsApp oficial. URL UNICA para todos os canais: a Meta chama sempre o mesmo endereco
 * e diz de qual numero se trata no corpo, entao o canal e descoberto do payload.
 *
 * A autenticidade vem da ASSINATURA do corpo (X-Hub-Signature-256), nao de segredo na
 * URL — segredo em URL aparece em log de servidor, em proxy e em print de tela.
 */
Route::get('/webhooks/meta/whatsapp', [\App\Http\Controllers\MetaWebhookController::class, 'verificar'])
    ->name('webhooks.meta.verificar');

Route::post('/webhooks/meta/whatsapp', [\App\Http\Controllers\MetaWebhookController::class, 'receber'])
    ->name('webhooks.meta');

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

/*
 * A pagina publica de reserva.
 *
 * SEM AUTENTICACAO e sem tenant na URL: quem abre e o cliente do cliente, que nao tem conta
 * aqui e nem deveria precisar de uma para marcar meia hora. A conta sai do slug, que por isso
 * e unico no banco inteiro.
 */
Route::get('/agendar/{slug}', App\Livewire\Publico\Reservar::class)->name('reservar');

/*
 * A sala de video.
 *
 * SEM AUTENTICACAO na rota, e um endereco so para os dois lados: o link e a credencial, e quem
 * o recebeu pelo WhatsApp entra sem ter conta aqui. Quem tiver sessao da mesma conta e
 * reconhecido dentro da tela e vira anfitriao — mas quem cumpre isso e o token do servidor de
 * midia, nao a rota.
 */
Route::get('/sala/{token}', App\Livewire\Video\Sala::class)->name('sala');

/*
 * O chat que mora no site do cliente.
 *
 * PUBLICO E SEM SESSAO: quem chama e o navegador de um visitante que acabou de entrar num
 * site qualquer. A conta sai da chave do canal, que viaja no HTML de quem instalou o widget, e
 * nunca do corpo da requisicao.
 *
 * Sem CSRF porque nao ha sessao para proteger: o token do visitante nao autoriza nada alem de
 * falar na propria conversa dele, e o que segura abuso e o teto por IP dentro do controlador.
 */
Route::prefix('chat-do-site/{chave}')
    ->name('chat-do-site.')
    ->group(function () {
        Route::post('/abrir', [App\Http\Controllers\ChatDoSiteController::class, 'abrir'])->name('abrir');
        Route::post('/mandar', [App\Http\Controllers\ChatDoSiteController::class, 'mandar'])->name('mandar');
        Route::get('/mensagens', [App\Http\Controllers\ChatDoSiteController::class, 'mensagens'])->name('mensagens');
    });

// O widget em si. Servido pelo app para quem instala colar UMA linha, e para a correcao de
// amanha chegar sozinha em todos os sites que ja instalaram.
Route::get('/widget.js', App\Http\Controllers\WidgetDoSiteController::class)->name('widget');

/*
 * A proposta que o cliente abre pelo link.
 *
 * PUBLICA E SEM SESSAO, como a sala de video e a pagina de agendamento. O que protege e o token
 * aleatorio: nao ha nada a adivinhar, e proposta em rascunho devolve 404 antes de renderizar.
 */
Route::get('/proposta/{token}', App\Livewire\Publico\Proposta::class)->name('proposta');
