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

    public function teams(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_user')
            ->withPivot('papel')
            ->withTimestamps();
    }

    /** @return array<int, int> */
    public function equipeIds(): array
    {
        return $this->teams()->pluck('teams.id')->all();
    }
}
