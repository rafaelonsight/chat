<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Channel extends Model
{
    use BelongsToTenant;

    /** Baileys pela Evolution: numero comum, sem regra de janela e COM grupo. */
    public const EVOLUTION = 'evolution';

    /** API oficial da Meta: janela de 24h, template aprovado, e SEM grupo. */
    public const META_CLOUD = 'meta_cloud';

    /** O chat que fica no site do cliente. Sem numero, sem provedor. */
    public const SITE = 'site';

    public const TIPOS = [
        self::EVOLUTION  => 'WhatsApp via Evolution (não oficial)',
        self::META_CLOUD => 'WhatsApp oficial (Meta Cloud API)',
        self::SITE       => 'Chat no site',
    ];

    protected $fillable = [
        'tenant_id', 'tipo', 'nome', 'instance_name',
        'webhook_secret', 'telefone_e164', 'status', 'conectado_em', 'ultimo_erro',
        'site_key', 'site_dominio', 'site_saudacao',
        'meta_phone_number_id', 'meta_waba_id', 'meta_token', 'meta_business_id',
    ];

    protected $casts = [
        'conectado_em' => 'datetime',
        // encrypted: o token da Meta nao fica legivel no banco. Quem tira um dump para
        // depurar, ou quem consegue ler uma tabela, nao sai com credencial de cliente na
        // mao. A chave e a APP_KEY, que nao mora no banco — sem ela o dump nao serve.
        'meta_token'   => 'encrypted',
    ];

    /** Como se chama um canal que ainda nao tem nome. */
    public const NOME_PROVISORIO = 'WhatsApp';

    /**
     * Um nome para o canal nascer com, ja que ninguem vai digitar um.
     *
     * Existe porque pedir "nome do canal" antes do QR Code e pedir uma decisao que a pessoa
     * ainda nao tem como tomar: ela veio conectar um numero, e o nome bom para ele so aparece
     * DEPOIS — normalmente e o proprio numero.
     */
    public static function nomeProvisorio(): string
    {
        $usados = static::query()->pluck('nome')->all();

        if (! in_array(self::NOME_PROVISORIO, $usados, true)) {
            return self::NOME_PROVISORIO;
        }

        for ($i = 2; $i < 100; $i++) {
            $tentativa = self::NOME_PROVISORIO.' '.$i;

            if (! in_array($tentativa, $usados, true)) {
                return $tentativa;
            }
        }

        return self::NOME_PROVISORIO.' '.uniqid();
    }

    /** Ainda esta com o nome que o sistema deu? Se sim, da para trocar sozinho pelo numero. */
    public function temNomeProvisorio(): bool
    {
        return (bool) preg_match('/^'.preg_quote(self::NOME_PROVISORIO, '/').'( \d+)?$/', (string) $this->nome);
    }

    protected static function booted(): void
    {
        static::creating(function (Channel $c) {
            // A Evolution nao assina o payload do webhook: a autenticidade vem
            // deste segredo embutido na URL.
            $c->webhook_secret ??= Str::random(48);
        });

        static::created(function (Channel $c) {
            if (! $c->instance_name) {
                $c->forceFill(['instance_name' => "t{$c->tenant_id}-c{$c->id}"])->saveQuietly();
            }
        });
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function webhookUrl(): string
    {
        return url("/webhooks/evolution/{$this->id}/{$this->webhook_secret}");
    }

    /**
     * Este canal e limitado pela janela de 24 horas?
     *
     * Pergunta ao TIPO e nao ao sistema: no Baileys a regra nao existe, e avisar o
     * atendente de um limite que nao vale ali seria inventar restricao — ele
     * aprenderia a ignorar o aviso, inclusive quando fosse verdade.
     */
    public function exigeJanela(): bool
    {
        return $this->tipo === self::META_CLOUD;
    }

    public function ehSite(): bool
    {
        return $this->tipo === self::SITE;
    }

    /**
     * O trecho que a pessoa cola no site dela.
     *
     * Uma linha, e nada de iframe: iframe nao consegue se posicionar sozinho por cima da
     * pagina, e obrigaria quem instala a mexer em CSS que ele nao conhece.
     */
    public function trechoDoSite(): string
    {
        return '<script src="'.url('/widget.js').'" data-chave="'.$this->site_key.'" defer></script>';
    }

    /**
     * Grupo de WhatsApp NAO existe na API oficial.
     *
     * E o motivo de o hibrido continuar necessario: quem usa grupo de bairro vai
     * manter os dois canais lado a lado.
     */
    public function permiteGrupo(): bool
    {
        return $this->tipo === self::EVOLUTION;
    }

    public function rotuloTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? (string) $this->tipo;
    }

    /**
     * Uma cor estavel para este canal, para o olho separar as conversas sem ler.
     *
     * Derivada do ID e nao guardada: cor de canal nao e informacao que alguem queira
     * escolher, e uma coluna a mais seria uma coluna a mais para manter. O resto vira da
     * mesma logica do avatar do contato — mesmo id, mesma cor, sempre.
     */
    /**
     * De qual PLATAFORMA este canal fala.
     *
     * Nao e a mesma coisa que o tipo. O tipo diz por onde nos conectamos (Evolution por QR ou
     * a API oficial da Meta); a plataforma diz o que o CLIENTE usou para escrever. Hoje os
     * dois tipos sao WhatsApp, e por isso o icone sai igual em todas as conversas — o que
     * separa um numero do outro e o nome do canal, nao o icone.
     *
     * Quando Instagram e Messenger entrarem, o icone muda sozinho aqui e aparece nos dois
     * lugares que o usam.
     */
    public function plataforma(): string
    {
        return match ($this->tipo) {
            'instagram' => 'instagram',
            'messenger' => 'messenger',
            default     => 'whatsapp',
        };
    }

    /**
     * O que aparece ao parar o mouse em cima: qual canal, e qual numero.
     *
     * O numero e a parte que importa quando ha tres canais na mesma plataforma — "RP" nao
     * lembra nada a quem entrou ontem na equipe; "+55 41 9…" lembra.
     */
    public function rotulo(): string
    {
        $numero = $this->telefone_e164
            ? \App\Support\PhoneNumber::discavel($this->telefone_e164)
            : null;

        return $this->nome.($numero ? ' · '.$numero : ' · número ainda não confirmado');
    }

    public function cor(): string
    {
        $cores = [
            'bg-sky-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500',
            'bg-teal-500', 'bg-indigo-500', 'bg-lime-600', 'bg-fuchsia-500',
        ];

        return $cores[$this->id % count($cores)];
    }

    /** Nome curto para caber na lista. O nome inteiro fica no title. */
    public function nomeCurto(int $limite = 14): string
    {
        return \Illuminate\Support\Str::limit((string) $this->nome, $limite, '…');
    }

    public function conectado(): bool
    {
        return $this->status === 'open';
    }
}
