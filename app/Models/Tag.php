<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToTenant;

    /**
     * Paleta fechada, nao cor livre. Duas razoes: o contraste do texto continua
     * sendo garantia nossa, e a tela fica coerente mesmo com trinta etiquetas.
     */
    public const CORES = [
        'cinza'     => 'Cinza',
        'vermelho'  => 'Vermelho',
        'laranja'   => 'Laranja',
        'ambar'     => 'Âmbar',
        'verde'     => 'Verde',
        'esmeralda' => 'Esmeralda',
        'ciano'     => 'Ciano',
        'azul'      => 'Azul',
        'indigo'    => 'Índigo',
        'violeta'   => 'Violeta',
        'rosa'      => 'Rosa',
        'marrom'    => 'Marrom',
    ];

    protected $fillable = ['tenant_id', 'nome', 'cor'];

    protected $attributes = ['cor' => 'cinza'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot(['origem', 'aplicado_por'])
            ->withTimestamps('created_at', null);
    }

    /**
     * Classes da pilula. Escritas literalmente porque o Tailwind resolve no build:
     * cor montada por concatenacao nao existiria no css.
     */
    public function classes(): string
    {
        return match ($this->cor) {
            'vermelho'  => 'bg-red-100 text-red-800 ring-red-200 dark:bg-red-500/20 dark:text-red-300 dark:ring-red-500/30',
            'laranja'   => 'bg-orange-100 text-orange-800 ring-orange-200 dark:bg-orange-500/20 dark:text-orange-300 dark:ring-orange-500/30',
            'ambar'     => 'bg-amber-100 text-amber-800 ring-amber-200 dark:bg-amber-500/20 dark:text-amber-300 dark:ring-amber-500/30',
            'verde'     => 'bg-green-100 text-green-800 ring-green-200 dark:bg-green-500/20 dark:text-green-300 dark:ring-green-500/30',
            'esmeralda' => 'bg-emerald-100 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-300 dark:ring-emerald-500/30',
            'ciano'     => 'bg-cyan-100 text-cyan-800 ring-cyan-200 dark:bg-cyan-500/20 dark:text-cyan-300 dark:ring-cyan-500/30',
            'azul'      => 'bg-blue-100 text-blue-800 ring-blue-200 dark:bg-blue-500/20 dark:text-blue-300 dark:ring-blue-500/30',
            'indigo'    => 'bg-indigo-100 text-indigo-800 ring-indigo-200 dark:bg-indigo-500/20 dark:text-indigo-300 dark:ring-indigo-500/30',
            'violeta'   => 'bg-violet-100 text-violet-800 ring-violet-200 dark:bg-violet-500/20 dark:text-violet-300 dark:ring-violet-500/30',
            'rosa'      => 'bg-pink-100 text-pink-800 ring-pink-200 dark:bg-pink-500/20 dark:text-pink-300 dark:ring-pink-500/30',
            'marrom'    => 'bg-yellow-100 text-yellow-900 ring-yellow-200 dark:bg-yellow-500/20 dark:text-yellow-200 dark:ring-yellow-500/30',
            default     => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-white/10 dark:text-gray-300 dark:ring-white/20',
        };
    }

    /** Cor do ponto no seletor, onde nao ha texto para contrastar. */
    public function pontinho(): string
    {
        return match ($this->cor) {
            'vermelho'  => 'bg-red-500',
            'laranja'   => 'bg-orange-500',
            'ambar'     => 'bg-amber-500',
            'verde'     => 'bg-green-500',
            'esmeralda' => 'bg-emerald-500',
            'ciano'     => 'bg-cyan-500',
            'azul'       => 'bg-blue-500',
            'indigo'    => 'bg-indigo-500',
            'violeta'   => 'bg-violet-500',
            'rosa'      => 'bg-pink-500',
            'marrom'    => 'bg-yellow-600',
            default     => 'bg-gray-400',
        };
    }
}
