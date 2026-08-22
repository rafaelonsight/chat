<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Channels\ChannelResource;
use App\Models\Channel;
use App\Services\Canais\MetaEmbeddedSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe o que o popup do Cadastro Incorporado devolveu (code + ids da WABA/numero) e
 * troca isso por um canal pronto, sem ninguem copiar Phone Number ID ou token a mao.
 *
 * So chega aqui depois que o JS da tela ja capturou os tres valores: o "code" vem do
 * callback do FB.login, e o waba_id/phone_number_id vem do evento de sessao que o
 * proprio popup manda por postMessage — a v3 do Cadastro Incorporado nao devolve esses
 * dois dentro do "code".
 */
class MetaEmbeddedSignupController extends Controller
{
    public function __invoke(Request $request, MetaEmbeddedSignup $signup): JsonResponse
    {
        // Mesmo portao da tela de Canais: quem nao pode ver Canais nao pode criar um.
        abort_unless((bool) auth()->user()?->admin, 403);

        $dados = $request->validate([
            'code'            => ['required', 'string'],
            'waba_id'         => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'business_id'     => ['nullable', 'string'],
        ]);

        $r = $signup->conectar($dados['code'], $dados['waba_id'], $dados['phone_number_id']);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'erro' => $r['erro']], 422);
        }

        $canal = Channel::create([
            'tenant_id'            => auth()->user()->tenant_id,
            'tipo'                 => Channel::META_CLOUD,
            'nome'                 => $r['nome'] ?: Channel::nomeProvisorio(),
            'meta_phone_number_id' => $dados['phone_number_id'],
            'meta_waba_id'         => $dados['waba_id'],
            'meta_business_id'     => $dados['business_id'] ?? null,
            'meta_token'           => $r['token'],
            'telefone_e164'        => $r['numero'],
            'status'               => 'open',
            'conectado_em'         => now(),
        ]);

        return response()->json([
            'ok'       => true,
            'redirect' => ChannelResource::getUrl('edit', ['record' => $canal]),
        ]);
    }
}
