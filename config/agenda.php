<?php

return [
    'edition' => true,
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
