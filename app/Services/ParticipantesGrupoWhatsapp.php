<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;

class ParticipantesGrupoWhatsapp
{
    public function __construct(private readonly EvolutionService $evolution) {}

    /** @return array<int, array{jid:string,participant_jid:string,nome:string,telefone:string,foto:?string}> */
    public function listar(Conversation $conversa): array
    {
        $conversa->loadMissing(['channel', 'contact']);

        if (! $conversa->contact?->eGrupo() || $conversa->channel?->tipo !== Channel::EVOLUTION) {
            return [];
        }

        $chave = "grupo-participantes:{$conversa->channel_id}:{$conversa->contact->jid}";

        try {
            return Cache::remember($chave, now()->addMinutes(5), fn () => $this->daEvolution($conversa));
        } catch (\Throwable) {
            return $this->doHistorico($conversa);
        }
    }

    private function daEvolution(Conversation $conversa): array
    {
        $dados = $this->evolution->groupParticipants(
            (string) $conversa->channel->instance_name,
            (string) $conversa->contact->jid,
        );

        $lista = array_is_list($dados) ? $dados : ($dados['participants'] ?? []);
        $nomesHistoricos = Message::query()
            ->where('conversation_id', $conversa->id)
            ->whereNotNull('remetente_jid')
            ->pluck('remetente_nome', 'remetente_jid');
        $telefoneDoCanal = preg_replace('/\D+/', '', (string) $conversa->channel->telefone_e164);

        return collect($lista)->map(function ($p) use ($nomesHistoricos, $telefoneDoCanal) {
            $participantJid = (string) data_get($p, 'id', data_get($p, 'jid', ''));
            $phoneJid = (string) data_get($p, 'phoneNumber', '');
            $telefone = preg_replace('/\D+/', '', explode('@', $phoneJid)[0]);

            // A Evolution 2.3 devolve `id` como LID. LID identifica o participante nas
            // chaves de mensagens, mas NAO e um telefone mencionavel: sendText transformaria
            // seus digitos em @s.whatsapp.net e o WhatsApp mostraria apenas texto sem avisar.
            if ($participantJid === '' || $phoneJid === '' || $telefone === '' || $telefone === $telefoneDoCanal) {
                return null;
            }

            $nome = trim((string) (data_get($p, 'name') ?: $nomesHistoricos->get($participantJid) ?: $telefone));

            return [
                'jid'             => $phoneJid,
                'participant_jid' => $participantJid,
                'nome'            => $nome,
                'telefone'        => $telefone,
                'foto'            => data_get($p, 'imgUrl'),
            ];
        })->filter()->unique('jid')->values()->all();
    }

    private function doHistorico(Conversation $conversa): array
    {
        return Message::query()
            ->where('conversation_id', $conversa->id)
            ->where('direcao', 'in')
            ->whereNotNull('remetente_jid')
            ->latest('id')
            ->get(['remetente_jid', 'remetente_nome'])
            ->unique('remetente_jid')
            ->map(function (Message $m) {
                $jid = (string) $m->remetente_jid;
                $telefone = preg_replace('/\D+/', '', explode('@', $jid)[0]);

                return [
                    'jid'      => $jid,
                    'nome'     => trim((string) ($m->remetente_nome ?: $telefone)),
                    'telefone' => $telefone,
                    'foto'     => null,
                ];
            })->values()->all();
    }
}
