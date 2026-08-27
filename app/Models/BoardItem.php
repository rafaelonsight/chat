<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BoardItem extends Model
{
    use BelongsToTenant;

    public function updates()
    {
        return $this->hasMany(BoardItemUpdate::class);
    }

    protected static function booted(): void
    {
        static::created(function (self $item) {
            $item->updates()->create([
                'tenant_id' => $item->tenant_id, 'user_id' => auth()->id(),
                'tipo' => 'criacao', 'corpo' => 'Tarefa criada',
            ]);
            \App\Support\AvisoDeItem::enviar($item, [$item->responsavel_id], 'Nova tarefa atribuída a você');
        });
        static::updated(function (self $item) {
            if ($item->wasChanged('responsavel_id')) {
                \App\Support\AvisoDeItem::enviar($item, [$item->responsavel_id], 'Nova tarefa atribuída a você');
            }
            if ($item->wasChanged('funnel_stage_id')) {
                $etapa = FunnelStage::where('tenant_id', $item->tenant_id)->find($item->funnel_stage_id);
                $item->updates()->create([
                    'tenant_id' => $item->tenant_id, 'user_id' => auth()->id(),
                    'tipo' => 'status', 'corpo' => 'Status alterado para '.($etapa?->nome ?? 'outra coluna'),
                ]);
                $ids = $item->updates()->pluck('user_id')->push($item->responsavel_id)->all();
                \App\Support\AvisoDeItem::enviar($item, $ids, 'Status de tarefa atualizado');
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'funnel_id', 'funnel_stage_id', 'contact_id', 'responsavel_id',
        'titulo', 'descricao', 'prioridade', 'prazo_em', 'etapa_em', 'dados', 'arquivado_em',
    ];

    protected $casts = [
        'prazo_em' => 'datetime',
        'etapa_em' => 'datetime',
        'arquivado_em' => 'datetime',
        'dados' => 'array',
    ];

    protected $attributes = ['prioridade' => 'normal', 'dados' => '{}'];

    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'funnel_stage_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)
            ->withPivot(['origem', 'aplicado_por', 'created_at']);
    }
}
