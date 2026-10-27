<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['tenant_id', 'admin', 'name', 'email', 'password', 'cor_chat', 'perfil', 'ver_notas_internas', 'consultar_anexos', 'apelido', 'telefone', 'cargo', 'foto_perfil', 'agenda_products_manage', 'agenda_finance_view', 'agenda_finance_manage'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements \Illuminate\Contracts\Translation\HasLocalePreference, FilamentUser, \Filament\Models\Contracts\HasAvatar, \Filament\Models\Contracts\HasName
{
    public function save(array $options=[])
    {
        $tenant=$this->tenant_id ?? \App\Support\TenantContext::get();
        if(!$this->exists && $tenant && config('agenda.edition') && \Illuminate\Support\Facades\Schema::hasTable('agenda_plan_entitlements')) {
            return \Illuminate\Support\Facades\DB::transaction(function()use($tenant,$options){
                \Illuminate\Support\Facades\DB::table('tenants')->where('id',$tenant)->lockForUpdate()->first();
                app(\App\Services\AgendaPlan::class)->assertSeat((int)$tenant);
                return parent::save($options);
            });
        }
        return parent::save($options);
    }
    public function preferredLocale(): string {return config('agenda.edition') ? \App\Support\Idiomas::interface($this) : \App\Support\Idiomas::valido(Tenant::find($this->tenant_id)?->idioma_comunicacoes);}
    public const PERFIS = ['admin'=>'Admin','supervisor'=>'Supervisor','atendente'=>'Atendente','auditor'=>'Auditor','profissional'=>'Profissional'];
    public function isAgendaProfessional(): bool {return config('agenda.edition') && $this->perfil==='profissional' && !$this->admin;}
    public function agendaProfessional(): ?AgendaProfessional {
        return AgendaProfessional::withoutGlobalScopes()->where('tenant_id',$this->tenant_id)->where('user_id',$this->id)->first();
    }
    public function canEnterAgenda(): bool {
        return $this->tenant_id && $this->agenda_access_status==='ativo' && (!$this->isAgendaProfessional() || $this->agendaProfessional()!==null);
    }
    public function canAgendaProducts(): bool {return $this->canEnterAgenda() && !$this->ehAuditor() && ($this->admin || $this->perfil==='admin' || $this->agenda_products_manage);}
    public function canAgendaFinance(bool $write=false): bool {return $this->canEnterAgenda() && (!$write || !$this->ehAuditor()) && ($this->admin || $this->perfil==='admin' || $this->agenda_finance_manage || (!$write && $this->agenda_finance_view));}
    public function canDefineAgendaPassword(): bool {
        return $this->tenant_id && $this->agenda_access_status!=='suspenso' && (!$this->isAgendaProfessional() || $this->agendaProfessional()!==null);
    }
    public function ehAuditor(): bool { return $this->perfil === 'auditor'; }
    public function gerenciaUsuarios(): bool { return $this->operador || $this->admin || $this->perfil === 'admin'; }
    public function consultaRelatorios(): bool { return $this->admin || in_array($this->perfil,['admin','supervisor','auditor'],true); }
    public function consultaAnexos(): bool { return ! $this->ehAuditor() || $this->consultar_anexos; }
    public function consultaNotas(): bool { return ! $this->ehAuditor() || $this->ver_notas_internas; }
    protected static function booted(): void {
        static::saving(function(User $u){
            if ($u->isDirty('perfil')) { $u->admin = $u->perfil === 'admin'; }
            elseif ($u->isDirty('admin')) { $u->perfil = $u->admin ? 'admin' : 'atendente'; }
            if(config('agenda.edition') && $u->exists && ($u->getOriginal('admin') || $u->getOriginal('perfil')==='admin') && $u->getOriginal('agenda_access_status')==='ativo' && ((!$u->admin && $u->perfil!=='admin') || $u->agenda_access_status!=='ativo')) {
                $others=static::withoutGlobalScopes()->where('tenant_id',$u->tenant_id)->whereKeyNot($u->id)->where('agenda_access_status','ativo')->where(fn($q)=>$q->where('admin',true)->orWhere('perfil','admin'))->count();
                if(!$others)throw \Illuminate\Validation\ValidationException::withMessages(['data.perfil'=>__('O estabelecimento precisa manter um administrador ativo.')]);
            }
        });
    }
    public const PALETA_CHAT = [
        'roxo' => '#6338F6',
        'violeta' => '#7C3AED',
        'lavanda' => '#A78BFA',
        'indigo' => '#4338CA',
        'azul' => '#2563EB',
        'celeste' => '#0284C7',
        'ciano' => '#0891B2',
        'turquesa' => '#0D9488',
        'verde' => '#059669',
        'floresta' => '#15803D',
        'lima' => '#84CC16',
        'amarelo' => '#FBBF24',
        'ambar' => '#D97706',
        'laranja' => '#EA580C',
        'coral' => '#F97373',
        'vermelho' => '#DC2626',
        'rosa' => '#DB2777',
        'fucsia' => '#C026D3',
        'cinza' => '#475569',
        'grafite' => '#1F2937',
    ];
    public const CORES_CHAT = [
        'roxo' => 'Roxo',
        'violeta' => 'Violeta',
        'lavanda' => 'Lavanda',
        'indigo' => 'Indigo',
        'azul' => 'Azul',
        'celeste' => 'Celeste',
        'ciano' => 'Ciano',
        'turquesa' => 'Turquesa',
        'verde' => 'Verde',
        'floresta' => 'Floresta',
        'lima' => 'Lima',
        'amarelo' => 'Amarelo',
        'ambar' => 'Ambar',
        'laranja' => 'Laranja',
        'coral' => 'Coral',
        'vermelho' => 'Vermelho',
        'rosa' => 'Rosa',
        'fucsia' => 'Fucsia',
        'cinza' => 'Cinza',
        'grafite' => 'Grafite',
    ];
    public function classesChat(): string {return 'vc-user-color-'.(isset(self::PALETA_CHAT[$this->cor_chat])?$this->cor_chat:'roxo');}
    public function classeEtiquetaChat(): string {return $this->classesChat();}
    public function nomePublico(): string {return trim((string)$this->apelido) ?: $this->primeiroNome();}
    public function getFilamentName(): string {return $this->nomePublico();}
    public function getFilamentAvatarUrl(): ?string {return $this->foto_perfil ? route('perfil.foto',['usuario'=>$this->id]) : null;}
    /**
     * So o primeiro nome.
     *
     * Usado no canal de presenca: cada campo que vai para la e um campo que todo mundo com a
     * mesma conversa aberta consegue ler. Nome inteiro nao acrescenta nada num aviso de uma
     * linha.
     */
    public function primeiroNome(): string
    {
        return explode(' ', trim((string) $this->name))[0] ?? '';
    }

    use BelongsToTenant;
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Sem isto, um usuario recem-criado responde NULL a ->operador em vez de false: o valor
    // padrao existe no BANCO, e o objeto em memoria so o conheceria depois de um refresh.
    // (bool) null da false e nada quebraria hoje, mas "a coluna nao aceita nulo e o objeto
    // devolve nulo" e o tipo de discrepancia que so aparece no dia em que alguem compara com
    // === false.
    protected $attributes = [
        'operador' => false,
        'super_admin' => false,
        'agenda_access_status' => 'ativo',
        'agenda_access_version' => 1,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'perfil_preenchido_em' => 'datetime',
            'admin' => 'boolean',
            'ver_notas_internas' => 'boolean',
            'consultar_anexos' => 'boolean',
            'super_admin' => 'boolean',
            // 'operador' NAO entra no #[Fillable]: e a chave da casa. Fora do
            // preenchimento em massa, so o comando onchat:operador concede.
            'operador' => 'boolean',
            'password' => 'hashed',
            'agenda_access_version' => 'integer',
        ];
    }

    // Fora do ambiente "local" o Filament exige que o model diga
    // explicitamente quem pode entrar no painel.
    //
    // O painel 'revenda' e o bastidor do fornecedor (cadastro de tenant, licenca):
    // administrador de cliente que entrasse ali veria o nome e o CNPJ de todos os
    // outros clientes. O painel do produto ('admin') continua aberto para todo
    // mundo — o controle fino dele e por pagina, nao por painel.
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'revenda') {
            return (bool) $this->operador;
        }

        return config('agenda.edition') ? ($panel->getId() === 'admin' && $this->canEnterAgenda()) : true;
    }

    /** @var array<int, int>|null */
    protected ?array $canaisMemo = null;

    /** @var array<int, int>|null */
    protected ?array $equipesMemo = null;

    public function teams(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('papel')
            ->withTimestamps();
    }

    /**
     * Os canais que esta pessoa pode ver.
     *
     * Para quem nao e Super Admin, vazio significa nenhum canal liberado.
     */
    public function canais(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_user')->withTimestamps();
    }

    /**
     * @return array<int, int>
     *
     * withoutGlobalScope(Acesso) NAO E DETALHE: o Channel carrega o escopo de acesso, que
     * pergunta ao usuario quais canais ele pode ver — que e exatamente este metodo. Sem tirar o
     * escopo aqui, a pergunta se responde chamando a si mesma e a requisicao morre em recursao.
     *
     * A memoria e pelo mesmo motivo em outra escala: o escopo roda em toda consulta de conversa,
     * e sem isto cada tela viraria dezenas de idas ao banco para a mesma resposta.
     */
    public function canalIds(): array
    {
        return $this->canaisMemo ??= $this->canais()
            ->withoutGlobalScope(\App\Models\Scopes\Acesso::class)
            ->pluck('channels.id')
            ->all();
    }

    /** @return array<int, int> */
    public function equipeIds(): array
    {
        return $this->equipesMemo ??= $this->teams()->pluck('teams.id')->all();
    }

    /**
     * Quem enxerga o sistema inteiro, sem restricao de canal nem de time.
     *
     * Somente o Super Admin ignora a matriz de acesso. Admin e operador tambem precisam receber
     * canais explicitamente: papel funcional nao e permissao para ler conversas.
     */
    public function veTudo(): bool
    {
        return (bool) $this->super_admin;
    }

    /**
     * Esta pessoa consegue abrir esta conversa?
     *
     * ESPELHA O ESCOPO Acesso de proposito, e isso e uma duplicacao consciente: o escopo sabe
     * FILTRAR uma consulta, e aqui a pergunta e ao contrario — dada uma conversa, quem pode
     * ve-la. Fazer isso em SQL sairia num emaranhado de NOT EXISTS para responder algo que se
     * le em cinco linhas.
     *
     * Como duplicacao de regra e como as duas versoes se afastam calados, existe um teste que
     * compara as duas: para cada usuario e cada conversa, o que este metodo diz tem de bater com
     * o que o escopo devolve.
     */
    public function podeVer(\App\Models\Conversation $conversa): bool
    {
        if ($this->veTudo()) {
            return true;
        }

        if (($canais = $this->canalIds()) === [] || ! in_array($conversa->channel_id, $canais, true)) {
            return false;
        }

        if (($times = $this->equipeIds()) !== []) {
            // Restrito a time NAO ve conversa sem time: decisao do Rafael, a triagem e do
            // chatbot ou de quem esta no time Triagem.
            return $conversa->team_id !== null && in_array($conversa->team_id, $times, true);
        }

        return true;
    }

    /**
     * Quem pode ser chamado numa nota desta conversa.
     *
     * SO QUEM CONSEGUE ABRIR A CONVERSA. Oferecer alguem que nao tem acesso seria armar uma
     * decepcao: a pessoa recebe o aviso, clica, e cai num "conversa nao encontrada" — e agora
     * sao dois problemas, o dela e a confianca no aviso.
     *
     * Filtra em PHP e nao no banco porque a equipe aqui tem dezenas de pessoas, nao milhares, e
     * a regra vive num lugar so (podeVer acima). Clareza vale mais que uma consulta esperta.
     *
     * @return \Illuminate\Support\Collection<int, static>
     */
    public static function quePodemVer(\App\Models\Conversation $conversa): \Illuminate\Support\Collection
    {
        return static::where('tenant_id', $conversa->tenant_id)
            ->orderBy('name')
            ->get()
            ->filter(fn (self $u) => $u->podeVer($conversa))
            ->values();
    }

    /** Se ela foi restringida a algo — o que a tela precisa saber para explicar uma lista vazia. */
    public function temAcessoRestrito(): bool
    {
        return ! $this->veTudo();
    }
    public function limparCacheCanais(): void { $this->canaisMemo = null; }
}
