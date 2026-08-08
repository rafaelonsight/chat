<?php

return [

    /*
     * Reuniao por video.
     *
     * O teto de gente na sala existe por PROCESSAMENTO, e nao por seguranca: cada camera
     * publicando custa banda e CPU no servidor de midia. Oito cobre a reuniao de equipe e o
     * atendimento com o cliente, que sao os dois casos reais.
     */
    'video' => [
        'max_participantes' => (int) env('VIDEO_MAX_PARTICIPANTES', 8),
    ],

    // Numero que recebe alerta por WhatsApp, em E.164 sem sinais (5584999998888).
    // Vazio = alerta so no log e no /saude.
    'alerta_whatsapp' => env('ONCHAT_ALERTA_WHATSAPP', ''),

    // Nao repetir o mesmo alerta antes disso, para uma queda nao virar enxurrada.
    'alerta_silencio_minutos' => (int) env('ONCHAT_ALERTA_SILENCIO', 30),

    'limites' => [
        // Webhook recebido e nao processado por mais tempo que isso significa que
        // mensagem de cliente esta entrando e ninguem esta vendo.
        'webhook_parado_minutos' => (int) env('ONCHAT_LIMITE_WEBHOOK', 10),
        'fila_acumulada'         => (int) env('ONCHAT_LIMITE_FILA', 200),
        'falhas_por_hora'        => (int) env('ONCHAT_LIMITE_FALHAS', 1),
        'disco_aviso'            => (int) env('ONCHAT_LIMITE_DISCO_AVISO', 85),
        'disco_critico'          => (int) env('ONCHAT_LIMITE_DISCO_CRITICO', 95),
        // 26h e nao 24h: da folga para o horario do timer sem gerar falso alarme.
        'backup_horas'           => (int) env('ONCHAT_LIMITE_BACKUP_HORAS', 26),
    ],
];
