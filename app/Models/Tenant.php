<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $fillable = [
        'nome', 'slug', 'razao_social', 'nome_fantasia', 'documento', 'email', 'telefone',
        'fuso_horario',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
        'natureza_juridica', 'cnae_principal', 'situacao_cadastral', 'porte',
        'data_abertura', 'cnpj_consultado_em',
        'resposta_automatica_ativa', 'resposta_automatica_texto',
    ];

    // data_abertura fica sem cast de proposito: a tela le e escreve o texto
    // 'AAAA-MM-DD' que a Receita devolve, e converter para Carbon aqui faria a
    // volta gravar '... 00:00:00' no campo.
    protected $casts = [
        'resposta_automatica_ativa' => 'boolean',
        'cnpj_consultado_em'        => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }
}
