<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Meeting extends Model
{
    use BelongsToTenant;

    public const EM_ANDAMENTO = 'em_andamento';

    public const ENCERRADA = 'encerrada';

    /**
     * Validade do link do convidado, contada de quando a reuniao comecou.
     *
     * Encerrar e acao de quem convidou, e quem convida esquece. Sem um teto, a reuniao de
     * ontem continua em andamento e o link que circulou no grupo de WhatsApp segue abrindo
     * sala para qualquer um, sem prazo. Doze horas cobrem o expediente inteiro, incluindo quem
     * entra atrasado, sem deixar o link valer no dia seguinte.
     */
    public const HORAS_ATE_EXPIRAR = 12;

    protected $fillable = [
        'tenant_id', 'criada_por', 'contact_id', 'conversation_id', 'appointment_id',
        'sala', 'token_convidado', 'titulo', 'status', 'max_participantes',
        'comecou_em', 'encerrada_em',
    ];

    protected $casts = [
        'comecou_em'        => 'datetime',
        'encerrada_em'      => 'datetime',
        'max_participantes' => 'integer',
    ];

    protected $attributes = ['status' => self::EM_ANDAMENTO];

    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function scopeAbertas(Builder $q): Builder
    {
        return $q->where('status', self::EM_ANDAMENTO);
    }

    public function aberta(): bool
    {
        return $this->status === self::EM_ANDAMENTO;
    }

    /** Marcada para depois: a sala existe, mas a hora dela ainda nao chegou. */
    public function agendada(): bool
    {
        return $this->comecou_em->isFuture();
    }

    /**
     * A janela dela e AGORA: aberta, dentro da validade, e a hora ja chegou.
     *
     * E a unica que pode ter alguem esperando dentro — e por isso a unica que o numero do menu
     * conta. Reuniao de semana que vem acendendo a insignia por sete dias e insignia que se
     * aprende a ignorar.
     */
    public function acontecendo(): bool
    {
        return $this->podeEntrar() && ! $this->agendada();
    }

    /**
     * O link vencido nao depende de alguem ter lembrado de encerrar.
     *
     * Separado de "encerrada" de proposito: para quem recebeu o link, "expirou" e "quem te
     * convidou encerrou" pedem providencias diferentes.
     */
    public function expirada(): bool
    {
        return $this->comecou_em->addHours(self::HORAS_ATE_EXPIRAR)->isPast();
    }

    public function podeEntrar(): bool
    {
        return $this->aberta() && ! $this->expirada();
    }

    public function url(): string
    {
        return route('sala', $this->token_convidado);
    }

    public function encerrar(): void
    {
        if (! $this->aberta()) {
            return;
        }

        $this->update(['status' => self::ENCERRADA, 'encerrada_em' => now()]);
    }

    /**
     * Abre uma sala.
     *
     * O nome da sala e o token nascem aqui, e nao em quem chama: sao as duas coisas que
     * precisam ser aleatorias e unicas no banco inteiro, e deixar isso a cargo de cada tela e
     * garantir que um dia uma delas invente um nome previsivel.
     */
    public static function abrir(array $dados = []): self
    {
        return static::create($dados + [
            'sala'             => 'sala_'.Str::lower(Str::random(18)),
            'token_convidado'  => Str::random(32),
            'comecou_em'       => now(),
            'max_participantes' => (int) config('onchat.video.max_participantes', 8),
        ]);
    }
}
