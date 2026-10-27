<?php

use App\Http\Middleware\RequireAgendaTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');
Route::redirect('/login', '/admin/login')->name('login');
Route::get('/saude', function () {
    $ok=app(\App\Services\AgendaOperations::class)->health();
    return response()->json(['status'=>$ok?'ok':'degraded'],$ok?200:503)->header('Cache-Control','no-store');
})->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,\Illuminate\Session\Middleware\StartSession::class,\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\View\Middleware\ShareErrorsFromSession::class])->middleware('throttle:30,1');
Route::post('/webhooks/agenda/evolution/{connection}',\App\Http\Controllers\AgendaEvolutionWebhook::class)->whereUuid('connection')->middleware('throttle:600,1');

Route::middleware(['auth', RequireAgendaTenant::class, \App\Http\Middleware\RequireAgendaAccess::class])->group(function () {
    Route::get('/produtos/{product}/foto',\App\Http\Controllers\AgendaProductPhoto::class)->whereNumber('product')->name('agenda.products.photo');
    Route::get('/servicos/{service}/foto',\App\Http\Controllers\AgendaServicePhoto::class)->whereNumber('service')->name('agenda.services.photo');
    Route::post('/sessao/bloquear', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/admin/login');
    })->name('sessao.bloquear');
    Route::get('/sessao/travada', fn () => redirect('/admin/login'))->name('sessao.travada');
    Route::get('/sessao/limpar-navegador', fn () => view('sessao.limpar-navegador', [
        'manterSessao' => [], 'voltarPara' => '/admin',
    ]))->name('sessao.limpar-navegador');
    Route::get('/media/{message}', App\Http\Controllers\MediaController::class)->name('media.show');
    Route::get('/meu-perfil', [App\Http\Controllers\PerfilInicialController::class, 'mostrar'])->name('perfil.inicial');
    Route::post('/meu-perfil', [App\Http\Controllers\PerfilInicialController::class, 'salvar'])->middleware('throttle:20,1')->name('perfil.salvar');
    Route::get('/perfil-foto/{usuario}', [App\Http\Controllers\PerfilInicialController::class, 'foto'])->name('perfil.foto');
    Route::post('/idioma', [App\Http\Controllers\IdiomaController::class, 'atualizar'])->name('idioma.atualizar');
});

// No provider credentials or webhook subscriptions until the isolated test-channel phase.
Route::get('/webhooks/meta/whatsapp', function (Request $request) {
    abort_unless(config('agenda.whatsapp_enabled'), 503);
    return app(App\Http\Controllers\MetaWebhookController::class)->verificar($request);
})->name('webhooks.meta.verificar');
Route::post('/webhooks/meta/whatsapp', function (Request $request) {
    abort_unless(config('agenda.whatsapp_enabled'), 503);
    return app(App\Http\Controllers\MetaWebhookController::class)->receber($request);
})->name('webhooks.meta');
