<?php

if (config('agenda.edition')) {
    require __DIR__.'/agenda-web.php';
    return;
}

use Illuminate\Support\Facades\Route;

Route::get('/internal-media/{id}', function (int $id) {
    $user=auth()->user();
    $message=\App\Models\DirectMessage::withoutGlobalScopes()->where('tenant_id',$user->tenant_id)
        ->where(fn($q)=>$q->where('de_user_id',$user->id)->orWhere('para_user_id',$user->id))->findOrFail($id);
    abort_unless($message->file_path,404);
    $disk=\Illuminate\Support\Facades\Storage::disk('local');
    abort_unless($disk->exists($message->file_path),404);
    if(str_starts_with($message->file_mime??'','audio/') || in_array($message->file_mime,['video/webm','video/mp4'])) {
        return response()->file($disk->path($message->file_path),['Content-Type'=>$message->file_mime,'X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
    return $disk->download($message->file_path,$message->file_name,['X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
})->middleware(['auth',\App\Http\Middleware\SessaoBloqueada::class])->name('internal-media');

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

/*
 * Messenger e Instagram: MESMO app da Meta, mesma logica de URL unica, mas um
 * endpoint separado do WhatsApp porque o formato do payload (objeto "page"/"instagram",
 * array "messaging") e diferente do formato do WhatsApp (objeto "whatsapp_business_account",
 * "changes"/"value"). Registrado no painel da Meta como callback dos dois objetos.
 */
Route::get('/webhooks/meta/social', [\App\Http\Controllers\MetaSocialWebhookController::class, 'verificar'])
    ->name('webhooks.meta.social.verificar');

Route::post('/webhooks/meta/social', [\App\Http\Controllers\MetaSocialWebhookController::class, 'receber'])
    ->name('webhooks.meta.social');

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
    Route::post('/suporte/selecionar', function (\Illuminate\Http\Request $pedido) {
        abort_unless($pedido->user()?->operador && $pedido->user()?->super_admin, 403);
        $data=$pedido->validate(['tenant_id'=>'required|integer|exists:tenants,id']);
        $pedido->session()->put(\App\Support\TenantContext::CHAVE_SESSAO_SUPORTE,(int)$data['tenant_id']);
        return redirect('/admin');
    })->name('suporte.selecionar');

    Route::post('/suporte/sair', function (\Illuminate\Http\Request $pedido) {
        abort_unless($pedido->user()?->operador && $pedido->user()?->super_admin, 403);
        $pedido->session()->forget(\App\Support\TenantContext::CHAVE_SESSAO_SUPORTE);

        return redirect('/gestao/clientes');
    })->name('suporte.sair');

    // Cadastro Incorporado (Embedded Signup): o popup da Meta devolve code + ids por
    // JS, e e o JS quem chama esta rota para trocar isso por um canal pronto.
    Route::get('/canais/meta/resultado', [App\Http\Controllers\MetaEmbeddedSignupController::class, 'resultado'])
        ->name('canais.meta.resultado');

    Route::post('/canais/meta/receber', [App\Http\Controllers\MetaEmbeddedSignupController::class, 'receber'])
        ->name('canais.meta.receber');

    Route::post('/canais/meta/iniciar', [App\Http\Controllers\MetaEmbeddedSignupController::class, 'iniciar'])
        ->name('canais.meta.iniciar');

    Route::post('/canais/meta/conectar-incorporado', [App\Http\Controllers\MetaEmbeddedSignupController::class, 'conectar'])
        ->name('canais.meta.conectar-incorporado');

    // Callback do Cadastro Incorporado hospedado pela Meta: roda dentro do popup, na
    // mesma origem nossa, e volta por redirect do navegador (nao por postMessage).
    Route::get('/canais/meta/callback', [App\Http\Controllers\MetaEmbeddedSignupController::class, 'retorno'])
        ->name('canais.meta.callback');

    // Messenger/Instagram: FB.login() roda na propria pagina (sem popup), entao as
    // duas chamadas (listar Paginas, depois conectar a escolhida) sao so JS->rota,
    // sem problema de sessao compartilhada entre abas.
    Route::post('/canais/meta/social/listar-paginas', [App\Http\Controllers\MetaSocialSignupController::class, 'listarPaginas'])
        ->name('canais.meta.social.listar-paginas');

    Route::post('/canais/meta/social/conectar', [App\Http\Controllers\MetaSocialSignupController::class, 'conectar'])
        ->name('canais.meta.social.conectar');

    // Instagram: login direto (instagram.com), redirect comum na mesma aba — sem
    // popup, sem postMessage. Ver MetaInstagramSignupController.
    Route::get('/canais/instagram/callback', [App\Http\Controllers\MetaInstagramSignupController::class, 'retorno'])
        ->name('canais.instagram.callback');

    // POST e nao GET: bloquear muda estado, e link visitado por engano nao pode
    // trancar a tela de ninguem.
    // Bloquear exige um novo código, sem manter uma alternativa de senha.
    Route::post('/sessao/bloquear', function (\Illuminate\Http\Request $pedido) {
        \Illuminate\Support\Facades\Auth::logout();
        $pedido->session()->invalidate();
        $pedido->session()->regenerateToken();
        return redirect('/admin/login');
    })->name('sessao.bloquear');
    Route::get('/sessao/travada', fn () => redirect('/admin/login'))->name('sessao.travada');
    Route::post('/sessao/destravar', fn () => abort(404))->name('sessao.destravar');

    Route::get('/sessao/limpar-navegador', function () {
        return view('sessao.limpar-navegador', [
            // A trava de sessao fica: quem limpa dados locais nao pediu para destravar.
            'manterSessao' => ['onchat.bloqueada'],
            'voltarPara'   => '/admin',
        ]);
    })->name('sessao.limpar-navegador');
});

/*
 * Licenca sem acesso: mesma razao de ficar fora do painel que a tela de sessao
 * bloqueada — nao pode usar o layout do produto que ela existe para trancar.
 */
Route::middleware('auth')->get('/licenca-bloqueada', function (\Illuminate\Http\Request $pedido) {
    $licenca = $pedido->user()->tenant?->license;

    // Sem licenca, ou licenca valida: nao ha o que mostrar aqui.
    if (! $licenca || $licenca->estaValida()) {
        return redirect('/admin');
    }

    return view('licenca.bloqueada', [
        'status' => $licenca->nomeDoStatus(),
        'motivo' => $licenca->motivo,
    ]);
})->name('licenca.bloqueada');

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

// Fotos de produto e servico do modulo Agenda. Os controllers conferem a conta do registro.
Route::middleware(['auth'])->group(function () {
    Route::get('/agenda/produtos/{product}/foto', App\Http\Controllers\AgendaProductPhoto::class)->whereNumber('product')->name('agenda.products.photo');
    Route::get('/agenda/servicos/{service}/foto', App\Http\Controllers\AgendaServicePhoto::class)->whereNumber('service')->name('agenda.services.photo');
});

/*
 * A proposta que o cliente abre pelo link.
 *
 * PUBLICA E SEM SESSAO, como a sala de video e a pagina de agendamento. O que protege e o token
 * aleatorio: nao ha nada a adivinhar, e proposta em rascunho devolve 404 antes de renderizar.
 */

Route::middleware(['auth', \App\Http\Middleware\SessaoBloqueada::class, \App\Http\Middleware\LicencaValida::class, 'throttle:60,1'])->prefix('browser-push')->group(function () {
    Route::post('/test', [\App\Http\Controllers\BrowserPushController::class, 'test'])->middleware('throttle:5,1');
    Route::get('/key', [\App\Http\Controllers\BrowserPushController::class, 'key']);
    Route::post('/subscriptions', [\App\Http\Controllers\BrowserPushController::class, 'store']);
    Route::delete('/subscriptions', [\App\Http\Controllers\BrowserPushController::class, 'destroy']);
});

Route::middleware('guest')->group(function () {
    Route::post('/acesso/trocar-email', [App\Http\Controllers\AcessoPorCodigoController::class, 'trocarEmail'])->middleware('throttle:10,1')->name('acesso.trocar-email');
    Route::post('/acesso/solicitar', [App\Http\Controllers\AcessoPorCodigoController::class, 'solicitar'])
        ->middleware('throttle:5,1')->name('acesso.solicitar');
    Route::post('/acesso/confirmar', [App\Http\Controllers\AcessoPorCodigoController::class, 'confirmar'])
        ->middleware('throttle:10,1')->name('acesso.confirmar');
});

Route::middleware('auth')->group(function(){
 Route::get('/meu-perfil', [\App\Http\Controllers\PerfilInicialController::class,'mostrar'])->name('perfil.inicial');
 Route::post('/meu-perfil', [\App\Http\Controllers\PerfilInicialController::class,'salvar'])->middleware('throttle:20,1')->name('perfil.salvar');
 Route::get('/perfil-foto/{usuario}', [\App\Http\Controllers\PerfilInicialController::class,'foto'])->name('perfil.foto');
});

Route::post('/idioma', [\App\Http\Controllers\IdiomaController::class, 'atualizar'])->middleware('auth')->name('idioma.atualizar');
