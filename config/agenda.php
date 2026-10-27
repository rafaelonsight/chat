<?php

return [
    // Liga a "edicao Agenda", que troca as rotas e o comportamento do app inteiro. Na versao
    // final a Agenda vira modulo por conta (Fase 2 do blueprint); ate la, desligado por padrao
    // o produto e o Virtus Chat completo.
    'edition' => (bool) env('AGENDA_EDITION', false),
    // Polling is the supported mode until an independent Reverb is homologated.
    'realtime_enabled' => false,
    'currency' => env('AGENDA_CURRENCY', 'BRL'),
    'integrations_enabled' => (bool) env('AGENDA_INTEGRATIONS_ENABLED', false),
    'whatsapp_enabled' => (bool) env('AGENDA_WHATSAPP_ENABLED', false),
    'channels_enabled' => (bool) env('AGENDA_CHANNELS_ENABLED', false),
    'evolution_enabled'=>(bool)env('AGENDA_EVOLUTION_ENABLED',false),
    'evolution_url'=>env('AGENDA_EVOLUTION_URL',''),
    'evolution_key'=>env('AGENDA_EVOLUTION_KEY',''),
    'gateway_only' => true,
    'installation_id' => env('AGENDA_INSTALLATION_ID'),
    'interface_locale' => env('AGENDA_INTERFACE_LOCALE', 'pt_BR'),
    'communication_locale' => env('AGENDA_COMMUNICATION_LOCALE', 'pt_BR'),
];
