<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Rotas sem sessao, e portanto sem CSRF para proteger.
         *
         * webhooks: a Evolution e a Meta nao mandam token; a autenticidade vem do segredo na
         * URL e da assinatura do corpo.
         *
         * chat-do-site: quem chama e o navegador de um visitante em OUTRO dominio. Nao existe
         * sessao nossa ali para alguem sequestrar — o token do visitante so autoriza falar na
         * conversa dele mesmo, e o que segura abuso e o teto por IP no controlador.
         *
         * Declarado AQUI e nao com withoutMiddleware na rota: o Laravel 11 renomeou a classe
         * do CSRF, entao a remocao por nome de classe passa batida sem erro nenhum — o
         * sintoma foi um 419 que nao aparecia em log algum.
         */
        $middleware->validateCsrfTokens(except: ['webhooks/*', 'chat-do-site/*']);
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
