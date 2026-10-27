<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    /**
     * Toda licenca nasce com a equipe Triagem.
     *
     * AQUI E NAO NO FORMULARIO DE CADASTRO: licenca tambem nasce por seeder, por comando e por
     * teste, e equipe que depende de alguem lembrar de marcar uma caixinha e equipe que vai
     * faltar justamente na conta criada com pressa. Faltando ela, toda conversa nova chega sem
     * time — e desde que time virou permissao, sem time quer dizer invisivel para quem nao e
     * administrador.
     */
    protected static function booted(): void
    {
        static::created(function (Tenant $conta) {
            // tenant_id explicito: o escopo global preenche pelo contexto, e ao criar uma conta
            // o contexto ainda e de outra (ou de nenhuma).
            $padrao = Team::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $conta->id, 'nome' => Team::TRIAGEM],
                [
                    'descricao' => 'Fila de entrada: conversa nova cai aqui ate ser direcionada.',
                    'ativa'     => true,
                ],
            );

            /*
             * forceFill porque 'padrao' esta FORA do fillable — e essa e a protecao.
             *
             * A marca decide onde cai TODA conversa nova e quais equipes nao podem ser
             * apagadas. Campo com esse peso nao pode ser setado por um formulario que um dia
             * receba um campo a mais sem ninguem notar. Mesmo tratamento que o 'operador' do
             * usuario tem aqui.
             *
             * (E foi ela que me pegou: passei 'padrao' => true dentro do firstOrCreate, o
             * Eloquent descartou calado, e oito testes falharam apontando para outro lugar. Era
             * a sexta vez que essa armadilha me pegou neste projeto.)
             */
            if (! $padrao->padrao) {
                $padrao->forceFill(['padrao' => true])->save();
            }

            /*
             * Toda conta nova ganha uma licenca em trial. AQUI, e nao no formulario de
             * cadastro de revenda, pelo mesmo motivo da equipe Triagem: licenca que depende
             * de alguem marcar uma caixinha e licenca que vai faltar justamente na conta
             * criada com pressa. Sem ela, a conta nao aparece no painel de revenda —
             * ausente nao e a mesma coisa que ativo, e 'cadeia sem licenca' um dia vira
             * conta que ninguem lembra que e cliente.
             *
             * Trial sem prazo: o middleware LicencaValida aceita trial sem vence_em como
             * valido, ate a revenda definir uma data.
             */
            if (config('agenda.edition')) return;
            License::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $conta->id],
                [
                    'status'    => License::TRIAL,
                    'inicia_em' => now(),
                    'vence_em'  => now()->addDays(14),
                ],
            );
        });
    }

    protected $fillable = [
        'email_resumos', 'nome', 'slug', 'razao_social', 'nome_fantasia', 'documento', 'email', 'telefone',
        'fuso_horario',
        'cep', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'uf',
        'natureza_juridica', 'cnae_principal', 'situacao_cadastral', 'porte',
        'data_abertura', 'cnpj_consultado_em',
        'resposta_automatica_ativa', 'resposta_automatica_texto',
        'assinatura_ativa',
        'pesquisa_ativa', 'pesquisa_texto', 'pesquisa_obrigatoria',
        'trial_monitorado',
    ];

    // data_abertura fica sem cast de proposito: a tela le e escreve o texto
    // 'AAAA-MM-DD' que a Receita devolve, e converter para Carbon aqui faria a
    // volta gravar '...' 00:00:00' no campo.
    // Sem isto, uma conta recem-criada responde NULL a ->assinatura_ativa: o padrao existe no
    // BANCO, e o objeto em memoria so o conheceria depois de um refresh. Mesma aresta que o
    // 'operador' do User teve, e pelo mesmo motivo.
    protected $attributes = [
        'assinatura_ativa' => false,
        'pesquisa_ativa'   => false,
    ];

    protected $hidden = ['ia_config'];

    protected $casts = [
        'ia_config' => 'encrypted:array',
        'alertas_resposta' => 'array',
        'resposta_automatica_ativa' => 'boolean',
        'assinatura_ativa'          => 'boolean',
        'pesquisa_ativa'            => 'boolean',
        'pesquisa_obrigatoria' => 'boolean',
        'trial_monitorado'           => 'boolean',
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

    public function license(): HasOne
    {
        return $this->hasOne(License::class);
    }
}
