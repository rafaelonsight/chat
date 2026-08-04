<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToTenant;

    /**
     * Paleta fechada de 24 cores, nao cor livre. Duas razoes: o contraste do
     * texto continua sendo garantia nossa, e a tela fica coerente mesmo com
     * trinta etiquetas. Vinte e quatro cabe em duas fileiras de doze no
     * seletor, o que da variedade sem virar um degrade indistinguivel.
     *
     * A ordem aqui e a ordem da tela: neutros primeiro, depois girando o
     * circulo cromatico. Nao reordene por gosto — o seletor le esta ordem.
     */
    public const PALETA = [
        // Neutros
        'cinza'     => ['label' => 'Cinza',     'grupo' => 'Neutros'],
        'grafite'   => ['label' => 'Grafite',   'grupo' => 'Neutros'],
        'pedra'     => ['label' => 'Pedra',     'grupo' => 'Neutros'],

        // Vermelhos
        'vermelho'  => ['label' => 'Vermelho',  'grupo' => 'Quentes'],
        'vinho'     => ['label' => 'Vinho',     'grupo' => 'Quentes'],
        'coral'     => ['label' => 'Coral',     'grupo' => 'Quentes'],

        // Laranjas e amarelos
        'laranja'   => ['label' => 'Laranja',   'grupo' => 'Quentes'],
        'ambar'     => ['label' => 'Âmbar',     'grupo' => 'Quentes'],
        'amarelo'   => ['label' => 'Amarelo',   'grupo' => 'Quentes'],
        'marrom'    => ['label' => 'Marrom',    'grupo' => 'Quentes'],

        // Verdes
        'limao'     => ['label' => 'Limão',     'grupo' => 'Verdes'],
        'verde'     => ['label' => 'Verde',     'grupo' => 'Verdes'],
        'esmeralda' => ['label' => 'Esmeralda', 'grupo' => 'Verdes'],
        'turquesa'  => ['label' => 'Turquesa',  'grupo' => 'Verdes'],
        'musgo'     => ['label' => 'Musgo',     'grupo' => 'Verdes'],

        // Azuis
        'ciano'     => ['label' => 'Ciano',     'grupo' => 'Frios'],
        'celeste'   => ['label' => 'Celeste',   'grupo' => 'Frios'],
        'azul'      => ['label' => 'Azul',      'grupo' => 'Frios'],
        'marinho'   => ['label' => 'Marinho',   'grupo' => 'Frios'],
        'indigo'    => ['label' => 'Índigo',    'grupo' => 'Frios'],

        // Roxos e rosas
        'violeta'   => ['label' => 'Violeta',   'grupo' => 'Roxos'],
        'purpura'   => ['label' => 'Púrpura',   'grupo' => 'Roxos'],
        'magenta'   => ['label' => 'Magenta',   'grupo' => 'Roxos'],
        'rosa'      => ['label' => 'Rosa',      'grupo' => 'Roxos'],
    ];

    protected $fillable = ['tenant_id', 'nome', 'cor'];

    protected $attributes = ['cor' => 'cinza'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot(['origem', 'aplicado_por'])
            ->withTimestamps('created_at', null);
    }

    /** chave => rotulo. So os nomes, para quando um select basta. */
    public static function cores(): array
    {
        return array_map(fn (array $c) => $c['label'], self::PALETA);
    }

    /** O que o seletor consome: chave => rotulo, pilula e ponto, ja resolvidos. */
    public static function paletaCompleta(): array
    {
        $paleta = [];

        foreach (self::PALETA as $chave => $dados) {
            $paleta[$chave] = [
                'label' => $dados['label'],
                'grupo' => $dados['grupo'],
                'pill'  => self::pilula($chave),
                'dot'   => self::ponto($chave),
            ];
        }

        return $paleta;
    }

    /**
     * Classes da pilula. Escritas literalmente porque o Tailwind resolve no
     * build: cor montada por concatenacao nao existiria no css. O theme.css
     * varre app/**\/*.php justamente por causa deste match.
     */
    public static function pilula(?string $cor): string
    {
        return match ($cor) {
            'grafite'   => 'bg-slate-100 text-slate-800 ring-slate-200 dark:bg-slate-500/20 dark:text-slate-300 dark:ring-slate-500/30',
            'pedra'     => 'bg-stone-100 text-stone-800 ring-stone-200 dark:bg-stone-500/20 dark:text-stone-300 dark:ring-stone-500/30',
            'vermelho'  => 'bg-red-100 text-red-800 ring-red-200 dark:bg-red-500/20 dark:text-red-300 dark:ring-red-500/30',
            'vinho'     => 'bg-red-200 text-red-900 ring-red-300 dark:bg-red-900/40 dark:text-red-200 dark:ring-red-800',
            'coral'     => 'bg-rose-100 text-rose-800 ring-rose-200 dark:bg-rose-500/20 dark:text-rose-300 dark:ring-rose-500/30',
            'laranja'   => 'bg-orange-100 text-orange-800 ring-orange-200 dark:bg-orange-500/20 dark:text-orange-300 dark:ring-orange-500/30',
            'ambar'     => 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:ring-amber-500/30',
            'amarelo'   => 'bg-yellow-100 text-yellow-800 ring-yellow-200 dark:bg-yellow-500/20 dark:text-yellow-300 dark:ring-yellow-500/30',
            'marrom'    => 'bg-amber-200 text-amber-900 ring-amber-300 dark:bg-amber-900/40 dark:text-amber-200 dark:ring-amber-800',
            'limao'     => 'bg-lime-100 text-lime-800 ring-lime-200 dark:bg-lime-500/20 dark:text-lime-300 dark:ring-lime-500/30',
            'verde'     => 'bg-green-100 text-green-800 ring-green-200 dark:bg-green-500/20 dark:text-green-300 dark:ring-green-500/30',
            'esmeralda' => 'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:ring-emerald-500/30',
            'turquesa'  => 'bg-teal-100 text-teal-800 ring-teal-200 dark:bg-teal-500/20 dark:text-teal-300 dark:ring-teal-500/30',
            'musgo'     => 'bg-green-200 text-green-900 ring-green-300 dark:bg-green-900/40 dark:text-green-200 dark:ring-green-800',
            'ciano'     => 'bg-cyan-100 text-cyan-800 ring-cyan-200 dark:bg-cyan-500/20 dark:text-cyan-300 dark:ring-cyan-500/30',
            'celeste'   => 'bg-sky-100 text-sky-800 ring-sky-200 dark:bg-sky-500/20 dark:text-sky-300 dark:ring-sky-500/30',
            'azul'      => 'bg-blue-100 text-blue-800 ring-blue-200 dark:bg-blue-500/20 dark:text-blue-300 dark:ring-blue-500/30',
            'marinho'   => 'bg-blue-200 text-blue-900 ring-blue-300 dark:bg-blue-900/40 dark:text-blue-200 dark:ring-blue-800',
            'indigo'    => 'bg-indigo-100 text-indigo-800 ring-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-300 dark:ring-indigo-500/30',
            'violeta'   => 'bg-violet-100 text-violet-800 ring-violet-200 dark:bg-violet-500/20 dark:text-violet-300 dark:ring-violet-500/30',
            'purpura'   => 'bg-purple-100 text-purple-800 ring-purple-200 dark:bg-purple-500/20 dark:text-purple-300 dark:ring-purple-500/30',
            'magenta'   => 'bg-fuchsia-100 text-fuchsia-800 ring-fuchsia-200 dark:bg-fuchsia-500/20 dark:text-fuchsia-300 dark:ring-fuchsia-500/30',
            'rosa'      => 'bg-pink-100 text-pink-800 ring-pink-200 dark:bg-pink-500/20 dark:text-pink-300 dark:ring-pink-500/30',
            default     => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-white/10 dark:text-gray-300 dark:ring-white/20',
        };
    }

    /** Cor do ponto no seletor, onde nao ha texto para contrastar. */
    public static function ponto(?string $cor): string
    {
        return match ($cor) {
            'grafite'   => 'bg-slate-500',
            'pedra'     => 'bg-stone-500',
            'vermelho'  => 'bg-red-500',
            'vinho'     => 'bg-red-800',
            'coral'     => 'bg-rose-500',
            'laranja'   => 'bg-orange-500',
            'ambar'     => 'bg-amber-500',
            'amarelo'   => 'bg-yellow-400',
            'marrom'    => 'bg-amber-800',
            'limao'     => 'bg-lime-500',
            'verde'     => 'bg-green-500',
            'esmeralda' => 'bg-emerald-500',
            'turquesa'  => 'bg-teal-500',
            'musgo'     => 'bg-green-800',
            'ciano'     => 'bg-cyan-500',
            'celeste'   => 'bg-sky-500',
            'azul'      => 'bg-blue-500',
            'marinho'   => 'bg-blue-800',
            'indigo'    => 'bg-indigo-500',
            'violeta'   => 'bg-violet-500',
            'purpura'   => 'bg-purple-500',
            'magenta'   => 'bg-fuchsia-500',
            'rosa'      => 'bg-pink-500',
            default     => 'bg-gray-400',
        };
    }

    public function classes(): string
    {
        return self::pilula($this->cor);
    }

    public function pontinho(): string
    {
        return self::ponto($this->cor);
    }

    /** Rotulo legivel da cor deste registro. */
    public function corLabel(): string
    {
        return self::PALETA[$this->cor]['label'] ?? (string) $this->cor;
    }
}
