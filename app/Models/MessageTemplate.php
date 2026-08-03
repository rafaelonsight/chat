<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'titulo', 'atalho', 'corpo', 'ativo'];

    protected $casts = ['ativo' => 'boolean'];

    protected $attributes = ['ativo' => true];

    public function scopeAtivos(Builder $query): Builder
    {
        return $query->where('ativo', true);
    }

    // Marcadores resolvidos na hora de usar, nao na hora de salvar: o mesmo
    // modelo serve para qualquer conversa.
    public function renderizar(?Conversation $conversa, ?User $usuario): string
    {
        return str_replace(
            ['{{nome}}', '{{telefone}}', '{{atendente}}'],
            [
                $conversa?->contact?->nomeExibicao() ?? '',
                $conversa?->contact?->telefone_e164 ?? '',
                $usuario?->name ?? '',
            ],
            (string) $this->corpo,
        );
    }

    public const MARCADORES = [
        '{{nome}}'      => 'nome do contato',
        '{{telefone}}'  => 'telefone do contato',
        '{{atendente}}' => 'seu nome',
    ];
}
