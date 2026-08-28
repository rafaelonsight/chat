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
            $changed=array_intersect(array_keys($item->getChanges()),['titulo','descricao','prioridade','prazo_em','inicio_em','contact_id','dados','arquivado_em','recorrencia']);
            if($changed){
                $item->updates()->create(['tenant_id'=>$item->tenant_id,'user_id'=>auth()->id(),'tipo'=>'edicao','corpo'=>'Propriedades atualizadas: '.implode(', ',$changed).'.']);
            }
            if ($item->wasChanged('responsavel_id')) {
                \App\Support\AvisoDeItem::enviar($item, [$item->responsavel_id], 'Nova tarefa atribuída a você');
            }
            if ($item->wasChanged('funnel_stage_id')) {
                $etapa = FunnelStage::where('tenant_id', $item->tenant_id)->find($item->funnel_stage_id);
                $item->updates()->create([
                    'tenant_id' => $item->tenant_id, 'user_id' => auth()->id(),
                    'tipo' => 'status', 'corpo' => 'Status alterado para '.($etapa?->nome ?? 'outra coluna'),
                ]);
                $ids = $item->updates()->pluck('user_id')->push($item->responsavel_id)
                    ->merge($item->responsaveis()->pluck('users.id'))
                    ->merge(\Illuminate\Support\Facades\DB::table('board_watchers')->where('board_item_id',$item->id)->pluck('user_id'))->all();
                \App\Support\AvisoDeItem::enviar($item, $ids, 'Status de tarefa atualizado');
            }
        });
    }

    protected $fillable = [
        'tenant_id', 'funnel_id', 'funnel_stage_id', 'contact_id', 'responsavel_id',
        'titulo', 'descricao', 'prioridade', 'prazo_em', 'etapa_em', 'dados', 'arquivado_em',
        'parent_id', 'created_by', 'version', 'checklist',
        'inicio_em','modelo','recorrencia','occurrence_of',
    ];

    protected $casts = [
        'prazo_em' => 'datetime',
        'etapa_em' => 'datetime',
        'arquivado_em' => 'datetime',
        'dados' => 'array',
        'checklist' => 'array',
        'version' => 'integer',
        'inicio_em' => 'datetime',
        'modelo' => 'boolean',
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

    public function responsaveis(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'board_item_assignees')->withPivot('tenant_id');
    }
}
