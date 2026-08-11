<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Equipe e fila de atendimento, nao rotulo de pessoa. E o destino do roteamento:
// o chatbot vai setar conversation.team_id e deixar o atendente nulo, fazendo a
// conversa cair em Novos daquela equipe.
class Team extends Model
{
    use BelongsToTenant;

    /**
     * A equipe que existe em toda licenca.
     *
     * E o endereco da FILA DE ENTRADA. Desde que time virou permissao — quem esta num time nao
     * ve conversa sem time —, a conversa que acabou de chegar precisava de um dono desde o
     * primeiro segundo, senao ela nasceria invisivel para todo mundo que nao e administrador.
     * Ficar sem esta equipe e ficar sem porta de entrada.
     */
    public const TRIAGEM = 'Triagem';

    /*
     * O NOME E SO O NOME. Quem manda e a coluna 'padrao': a constante acima serve para batizar a
     * equipe quando a conta nasce, e nada mais. Renomear a equipe nao quebra nada — foi
     * exatamente esse o defeito que a marca veio consertar.
     */

    public const ATENDENTE = 'atendente';

    public const SUPERVISOR = 'supervisor';

    /**
     * A Triagem desta conta, ou null se alguem a apagou.
     *
     * DEVOLVE null EM VEZ DE CRIAR NA HORA, de proposito: criar equipe dentro do caminho de
     * gravar uma mensagem faria a entrada de mensagem depender de uma escrita a mais, e uma
     * falha ali derrubaria o recebimento — trocar "conversa sem time" por "mensagem perdida"
     * seria um pessimo negocio. Quem chama trata o null seguindo sem time.
     *
     * SEM MEMORIA ESTATICA, e essa linha foi aprendida errando.
     *
     * Eu tinha guardado o resultado num array estatico "para nao repetir a consulta numa
     * rajada". Duas coisas quebraram: nos testes os ids de conta se repetem entre casos, e a
     * memoria devolvia a equipe de um caso anterior — ja apagada. A conversa nascia apontando
     * para uma equipe orfa, e ficava invisivel para todo mundo. Cinquenta testes cairam.
     *
     * E o mesmo valeria em producao onde mais doi: um worker do Horizon vive horas, e guardaria
     * a equipe velha depois de qualquer renomeacao ou exclusao.
     *
     * A consulta e por indice (tenant_id, nome) e acontece na CRIACAO de conversa, nao a cada
     * mensagem. Economia errada e a que troca uma consulta barata por uma resposta velha.
     */
    public static function padraoDe(int $tenantId): ?self
    {
        return static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('padrao', true)
            ->first();
    }

    /**
     * A equipe padrao NAO SE APAGA.
     *
     * Pedido do Rafael, e a razao e concreta: ela e o endereco da fila de entrada. Sem ela,
     * conversa nova nasce sem equipe — e desde que equipe virou permissao, sem equipe quer dizer
     * invisivel para todo atendente que nao seja administrador. Apagar essa equipe nao quebra
     * uma tela: apaga a porta de entrada do atendimento.
     *
     * A GUARDA VIVE NO MODELO, e nao so no botao da tela: a exclusao tambem acontece por
     * console, por seeder e por codigo futuro. Regra que mora no botao e regra que o proximo
     * caminho ignora.
     */
    protected static function booted(): void
    {
        /*
         * DESATIVAR A PADRAO QUEBRA IGUAL A APAGAR, e por isso a guarda cobre as duas.
         *
         * Equipe desativada sai dos filtros e da lista de transferencia — mas a conversa nova
         * continua caindo nela, porque a busca da padrao nao olha 'ativa' (e nao deve: a fila de
         * entrada precisa existir mesmo que alguem a esconda). O resultado seria o pior dos dois
         * mundos: mensagem entrando num lugar que ninguem consegue abrir.
         */
        static::updating(function (self $equipe) {
            if ($equipe->padrao && $equipe->isDirty('ativa') && ! $equipe->ativa) {
                throw new \App\Exceptions\EquipePadraoProtegida(
                    'A equipe "'.$equipe->nome.'" e a padrao da conta: ela recebe as conversas '
                    .'novas e nao pode ser desativada. Renomear pode.'
                );
            }
        });

        static::deleting(function (self $equipe) {
            if ($equipe->padrao) {
                throw new \App\Exceptions\EquipePadraoProtegida(
                    'A equipe "'.$equipe->nome.'" e a padrao da conta: ela recebe as conversas '
                    .'novas e nao pode ser excluida. Marque outra como padrao antes, se precisar.'
                );
            }
        });
    }

    protected $fillable = ['tenant_id', 'nome', 'descricao', 'cor', 'ativa'];

    protected $casts = ['ativa' => 'boolean'];

    protected $attributes = ['ativa' => true];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('papel')
            ->withTimestamps();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeAtivas(Builder $query): Builder
    {
        return $query->where('ativa', true);
    }

    public function supervisores(): BelongsToMany
    {
        return $this->users()->wherePivot('papel', self::SUPERVISOR);
    }
}
