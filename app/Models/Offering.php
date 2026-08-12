<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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

    /** A letra que abre o codigo interno. S de servico, P de produto. */
    public const PREFIXOS = [
        self::SERVICO => 'S',
        self::PRODUTO => 'P',
    ];

    protected $attributes = [
        'tipo' => self::SERVICO,
        'preco' => 0,
        'recorrente' => false,
        'ativo' => true,
    ];

    protected function casts(): array
    {
        return [
            'preco' => 'decimal:2',
            'recorrente' => 'boolean',
            'ativo' => 'boolean',
        ];
    }

    /**
     * O proximo codigo interno livre deste tipo: S-0001, P-0007.
     *
     * SUGESTAO E NAO IMPOSICAO. Quem ja tem codigo de outro sistema digita o dele; quem nao usa
     * codigo apaga o campo e segue, que a coluna e nula de proposito. O que nao pode e obrigar
     * alguem a inventar um numero na hora de cadastrar o primeiro servico.
     *
     * SEQUENCIA POR TIPO porque o codigo existe para ser lido de relance: 'S-0007' diz que e
     * servico sem precisar abrir o cadastro.
     *
     * SO CONTA O QUE TEM A FORMA DO GERADO. Um codigo digitado a mao como 'S-ANTIGO' ordenaria na
     * frente de 'S-0009' e a conta do proximo daria 1 — voltando a bater num codigo que ja existe.
     * O recorte por expressao regular deixa fora tudo que nao seja prefixo mais digitos.
     */
    public static function proximoCodigo(string $tipo, ?int $tenantId = null): string
    {
        $tenantId ??= (int) TenantContext::get();
        $prefixo = (self::PREFIXOS[$tipo] ?? 'S').'-';

        $ultimo = static::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->whereRaw('codigo ~ ?', ['^'.$prefixo.'[0-9]+$'])
            ->orderByRaw('length(codigo) desc, codigo desc')
            ->value('codigo');

        $seq = $ultimo ? ((int) Str::afterLast($ultimo, '-')) + 1 : 1;

        return $prefixo.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
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
