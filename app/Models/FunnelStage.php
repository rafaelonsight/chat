<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FunnelStage extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'funnel_id', 'nome', 'cor', 'ordem', 'encerra'];

    protected $casts = ['ordem' => 'integer', 'encerra' => 'boolean'];

    protected $attributes = ['cor' => 'cinza', 'ordem' => 0, 'encerra' => false];

    public function funnel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /** As colunas que uma conta ganha ao abrir o funil pela primeira vez. */
    public static function padrao(): array
    {
        return [
            ['nome' => 'Novo',       'cor' => 'cinza',    'encerra' => false],
            ['nome' => 'Orçamento',  'cor' => 'azul',     'encerra' => false],
            ['nome' => 'Negociação', 'cor' => 'ambar',    'encerra' => false],
            ['nome' => 'Fechado',    'cor' => 'verde',    'encerra' => true],
            ['nome' => 'Perdido',    'cor' => 'vermelho', 'encerra' => true],
        ];
    }

    public function pontinho(): string
    {
        return Tag::ponto($this->cor);
    }
}
