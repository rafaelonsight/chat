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

#[Fillable(['tenant_id', 'admin', 'name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
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
            'admin' => 'boolean',
            // 'operador' NAO entra no #[Fillable]: e a chave da casa. Fora do
            // preenchimento em massa, so o comando onchat:operador concede.
            'operador' => 'boolean',
            'password' => 'hashed',
        ];
    }

    // Fora do ambiente "local" o Filament exige que o model diga
    // explicitamente quem pode entrar no painel.
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
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
     * Sem nenhum vinculado, ela ve TODOS — ver o escopo Acesso. Vazio aqui quer dizer "nao foi
     * restringida", e nao "nao pode nada": o contrario trancaria para fora todo usuario que
     * existia antes desta tabela.
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
     * Administrador configura canais e usuarios: restringir o que ele ve seria pedir que ele
     * configure no escuro. Operador e o dono do produto, acima de tudo.
     */
    public function veTudo(): bool
    {
        return (bool) ($this->admin || $this->operador);
    }

    /** Se ela foi restringida a algo — o que a tela precisa saber para explicar uma lista vazia. */
    public function temAcessoRestrito(): bool
    {
        return ! $this->veTudo() && ($this->canalIds() !== [] || $this->equipeIds() !== []);
    }
}
