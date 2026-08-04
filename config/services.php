<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],


    'evolution' => [
        'url' => env('EVOLUTION_BASE_URL', 'http://127.0.0.1:8081'),
        'key' => env('EVOLUTION_API_KEY'),
    ],


    'transcricao' => [
        // whisper.cpp como servico local: o audio do cliente nunca sai do
        // servidor, o que resolve o lado LGPD sem trabalho de compliance.
        'ativa'        => (bool) env('TRANSCRICAO_ATIVA', true),
        'url'          => env('TRANSCRICAO_URL', 'http://127.0.0.1:9090'),
        // Audio de 8 minutos travaria um nucleo por minutos. Recusar e dizer e
        // melhor que degradar a maquina em silencio.
        'max_segundos' => (int) env('TRANSCRICAO_MAX_SEGUNDOS', 300),
        'vocabulario'  => env('TRANSCRICAO_VOCABULARIO', 'Provedor de internet. ONU, OLT, roteador, PPPoE, sinal, fibra, boleto, segunda via, plano, velocidade, instalacao.'),
    ],


    'cnpj' => [
        // BrasilAPI serve a base publica da Receita Federal sem chave nem
        // cadastro. Trocavel por env caso o limite por IP aperte: qualquer
        // servico com o mesmo formato de resposta serve.
        'url'         => env('CNPJ_API_URL', 'https://brasilapi.com.br/api/cnpj/v1'),
        // Curto de proposito: e o usuario esperando na tela de cadastro.
        'timeout'     => (int) env('CNPJ_API_TIMEOUT', 12),
        // Dado cadastral da Receita muda em meses, nao em horas.
        'cache_horas' => (int) env('CNPJ_CACHE_HORAS', 24),
    ],


    'cep' => [
        // ViaCEP: publica, sem chave. Atencao ao consumir direto — CEP que nao
        // existe volta com status 200 e {"erro":"true"} no corpo.
        'url'         => env('CEP_API_URL', 'https://viacep.com.br/ws'),
        'timeout'     => (int) env('CEP_API_TIMEOUT', 8),
        // Rua nao muda de bairro: cache longo, ao contrario do CNPJ.
        'cache_horas' => (int) env('CEP_CACHE_HORAS', 720),
    ],

];
