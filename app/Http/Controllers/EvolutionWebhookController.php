<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessEvolutionWebhook;
use App\Models\Channel;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Grava o payload cru e devolve 200 na hora. Processar dentro do request faria
// o gateway tomar timeout e comecar a reentregar em cascata.
class EvolutionWebhookController extends Controller
{
    public function __invoke(Request $request, string $channel, string $secret): JsonResponse
    {
        $canal = Channel::withoutGlobalScope('tenant')->find($channel);

        abort_unless($canal && hash_equals($canal->webhook_secret, $secret), 404);

        $evento = WebhookEvent::create([
            'tenant_id'   => $canal->tenant_id,
            'channel_id'  => $canal->id,
            'evento'      => $request->input('event'),
            'payload'     => $request->all(),
            'recebido_em' => now(),
        ]);

        ProcessEvolutionWebhook::dispatch($evento->id);

        return response()->json(['ok' => true]);
    }
}
