<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Um produto ou servico do catalogo.
 *
 * O nome da tabela e 'offerings' e nao 'products': metade do que ele vende e servico (consultoria,
 * implantacao, hora), e chamar tudo de produto faria o codigo mentir sobre o negocio.
 */
#[Fillable([
    'tenant_id', 'codigo', 'nome', 'descricao', 'tipo',
    'preco', 'recorrente', 'periodicidade', 'unidade', 'ativo',
])]
class Offering extends Model
{
    use BelongsToTenant;

    public const PRODUTO = 'produto';

    public const SERVICO = 'servico';

    protected $attributes = [
        'tipo'       => self::SERVICO,
        'preco'      => 0,
        'recorrente' => false,
        'ativo'      => true,
    ];

    protected function casts(): array
    {
        return [
            'preco'      => 'decimal:2',
            'recorrente' => 'boolean',
            'ativo'      => 'boolean',
        ];
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('ativo', true);
    }

    /** Como ele aparece numa lista de escolha: nome, e o preco para conferir sem sair da tela. */
    public function rotulo(): string
    {
        $valor = 'R$ '.number_format((float) $this->preco, 2, ',', '.');

        if ($this->recorrente) {
            $valor .= '/mês';
        } elseif ($this->unidade) {
            $valor .= '/'.$this->unidade;
        }

        return $this->nome.' — '.$valor;
    }
}
