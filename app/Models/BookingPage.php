<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A pagina publica onde o cliente escolhe o horario.
 */
class BookingPage extends Model
{
    use BelongsToTenant;

    public const DIAS = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];

    protected $fillable = [
        'tenant_id', 'user_id', 'channel_id', 'slug', 'titulo', 'descricao', 'local',
        'duracao_min', 'intervalo_min', 'antecedencia_horas', 'janela_dias', 'limite_dia',
        'disponibilidade', 'ativa', 'por_video',
    ];

    protected $casts = [
        'disponibilidade'    => 'array',
        'ativa'              => 'boolean',
        'por_video'          => 'boolean',
        'duracao_min'        => 'integer',
        'intervalo_min'      => 'integer',
        'antecedencia_horas' => 'integer',
        'janela_dias'        => 'integer',
        'limite_dia'         => 'integer',
    ];

    protected $attributes = [
        'duracao_min'        => 30,
        'intervalo_min'      => 0,
        'antecedencia_horas' => 2,
        'janela_dias'        => 30,
        'ativa'              => true,
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * As faixas de um dia da semana, ja em ordem.
     *
     * Domingo e 0, como no Carbon: um a mais ou um a menos aqui desloca a semana inteira em
     * silencio, e o erro so aparece quando alguem reclama que o link ofereceu sabado.
     */
    public function faixasDe(int $diaDaSemana): array
    {
        $faixas = collect($this->disponibilidade ?? [])
            ->filter(fn ($f) => (int) ($f['dia'] ?? -1) === $diaDaSemana)
            ->filter(fn ($f) => ! empty($f['de']) && ! empty($f['ate']) && $f['de'] < $f['ate'])
            ->sortBy('de')
            ->values();

        return $faixas->all();
    }

    public function temDisponibilidade(): bool
    {
        foreach (range(0, 6) as $dia) {
            if ($this->faixasDe($dia) !== []) {
                return true;
            }
        }

        return false;
    }

    public function url(): string
    {
        return route('reservar', $this->slug);
    }

    /**
     * Um slug que ninguem precisa inventar.
     *
     * Sai do titulo porque link legivel da confianca — /agendar/visita-tecnica diz o que e
     * antes de a pessoa clicar, e /agendar/x7f2 nao diz nada. O sufixo entra so quando
     * precisa, e "precisa" e o banco inteiro, nao a conta: a URL nao tem tenant dentro.
     */
    public static function slugLivre(string $base): string
    {
        $raiz = Str::slug($base) ?: 'agenda';
        $raiz = Str::limit($raiz, 44, '');
        $slug = $raiz;
        $n = 1;

        while (static::withoutGlobalScope('tenant')->where('slug', $slug)->exists()) {
            $slug = $raiz.'-'.(++$n);
        }

        return $slug;
    }

    /** Comercial: de segunda a sexta, das 9 as 12 e das 13 as 18. */
    public static function horarioComercial(): array
    {
        $faixas = [];

        foreach (range(1, 5) as $dia) {
            $faixas[] = ['dia' => $dia, 'de' => '09:00', 'ate' => '12:00'];
            $faixas[] = ['dia' => $dia, 'de' => '13:00', 'ate' => '18:00'];
        }

        return $faixas;
    }
}
