<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use BelongsToTenant;

    public const COMPROMISSO = 'compromisso';

    public const LEMBRETE = 'lembrete';

    public const TIPOS = [
        self::COMPROMISSO => 'Compromisso — hora marcada, a equipe vê',
        self::LEMBRETE    => 'Lembrete — só você vê',
    ];

    // O booking_page_id entra aqui junto com a coluna, e nao depois. Campo fora do fillable
    // e descartado em SILENCIO pelo create(): a reserva gravava sem vinculo com a pagina, o
    // teto por dia parava de contar e o indice unico da vaga nem chegava a valer. Quinta vez
    // que esta armadilha custa uma rodada esta semana.
    protected $fillable = [
        'tenant_id', 'user_id', 'criado_por', 'contact_id', 'conversation_id', 'booking_page_id',
        'tipo', 'titulo', 'descricao', 'comeca_em', 'duracao_min', 'concluido_em',
    ];

    protected $casts = [
        'comeca_em'    => 'datetime',
        'concluido_em' => 'datetime',
        'duracao_min'  => 'integer',
    ];

    protected $attributes = ['tipo' => self::COMPROMISSO];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** De qual link de agendamento veio, quando veio de um. */
    public function bookingPage(): BelongsTo
    {
        return $this->belongsTo(BookingPage::class);
    }

    /**
     * A sala de video deste compromisso, quando ele e por video.
     *
     * O vinculo mora na reuniao e nao aqui: uma sala sabe de que compromisso ela e, e ha sala
     * que nao e de compromisso nenhum — a que o atendente abre no meio da conversa.
     */
    public function meeting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Meeting::class)->where('status', Meeting::EM_ANDAMENTO);
    }

    public function ehPorVideo(): bool
    {
        return $this->meeting !== null;
    }

    public function guests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AppointmentGuest::class)->orderBy('nome');
    }

    /** Marcado pelo proprio cliente, e nao pela equipe. */
    public function veioDoLink(): bool
    {
        return $this->booking_page_id !== null;
    }

    /**
     * O que ESTA pessoa pode ver.
     *
     * Compromisso e da equipe: quem atende o telefone precisa saber que o colega vai la as 14h.
     * Lembrete e de quem escreveu — lembrete alheio na tela de todo mundo vira ruido, e ruido
     * faz a agenda inteira ser ignorada.
     */
    public function scopeVisivelPara(Builder $q, ?User $quem): Builder
    {
        if (! $quem) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function (Builder $w) use ($quem) {
            $w->where('tipo', self::COMPROMISSO)
                ->orWhere('user_id', $quem->id);
        });
    }

    public function scopePendentes(Builder $q): Builder
    {
        return $q->whereNull('concluido_em');
    }

    public function concluido(): bool
    {
        return $this->concluido_em !== null;
    }

    /** Passou da hora e ninguem marcou como feito. */
    public function atrasado(): bool
    {
        return ! $this->concluido() && $this->comeca_em->isPast();
    }

    public function ehLembrete(): bool
    {
        return $this->tipo === self::LEMBRETE;
    }
}
