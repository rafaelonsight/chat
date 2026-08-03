<?php

namespace App\Support;

use App\Models\Conversation;
use App\Models\User;

// Um lugar so para os marcadores: modelo de mensagem e resposta automatica usam
// a mesma sintaxe. Duas implementacoes divergiriam na primeira mudanca.
class Marcadores
{
    public const DISPONIVEIS = [
        '{{nome}}'             => 'nome do contato',
        '{{telefone}}'         => 'telefone do contato',
        '{{atendente}}'        => 'seu nome',
        '{{proxima_abertura}}' => 'quando o atendimento volta',
    ];

    public static function aplicar(
        string $texto,
        ?Conversation $conversa = null,
        ?User $usuario = null,
        ?string $proximaAbertura = null,
    ): string {
        return str_replace(
            ['{{nome}}', '{{telefone}}', '{{atendente}}', '{{proxima_abertura}}'],
            [
                $conversa?->contact?->nomeExibicao() ?? '',
                $conversa?->contact?->telefone_e164 ?? '',
                $usuario?->name ?? '',
                $proximaAbertura ?? 'em breve',
            ],
            $texto,
        );
    }
}
