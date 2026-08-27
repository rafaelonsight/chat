<?php

namespace App\Livewire;

use App\Models\{BoardItem, FunnelStage, User};
use App\Support\AvisoDeItem;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class BoardItemDiscussion extends Component
{
    #[Locked]
    public int $itemId;
    public string $texto = '';
    public array $mencoes = [];
    public ?int $status = null;

    private function item(): BoardItem
    {
        return BoardItem::where('tenant_id', auth()->user()->tenant_id)->findOrFail($this->itemId);
    }

    public function mount(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->status = $this->item()->funnel_stage_id;
    }

    public function enviar(): void
    {
        $this->texto = trim($this->texto);
        $this->validate(['texto' => 'required|string|max:5000', 'mencoes' => 'array|max:50', 'mencoes.*' => 'integer']);
        DB::transaction(function () {
            $item = $this->item();
            $ids = User::where('tenant_id', $item->tenant_id)->whereIn('id', $this->mencoes)->pluck('id')->all();
            $item->updates()->create([
                'tenant_id' => $item->tenant_id, 'user_id' => auth()->id(),
                'corpo' => $this->texto, 'mencoes' => $ids,
            ]);
            $participantes = $item->updates()->pluck('user_id')->push($item->responsavel_id)->all();
            AvisoDeItem::enviar($item, $ids, auth()->user()->primeiroNome().' mencionou você em uma tarefa');
            AvisoDeItem::enviar($item, array_diff($participantes, $ids), 'Nova mensagem na tarefa');
        });
        $this->reset('texto', 'mencoes');
    }

    public function alterarStatus(): void
    {
        DB::transaction(function () {
            $item = $this->item();
            $etapa = FunnelStage::where('tenant_id', $item->tenant_id)->where('funnel_id', $item->funnel_id)->findOrFail($this->status);
            $item->update(['funnel_stage_id' => $etapa->id, 'etapa_em' => now()]);
        });
        $this->dispatch('status-item-alterado');
    }

    public function render()
    {
        $item = $this->item();
        return view('livewire.board-item-discussion', [
            'atualizacoes' => $item->updates()->with('user')->latest('id')->limit(100)->get()->reverse(),
            'pessoas' => User::where('tenant_id', $item->tenant_id)->orderBy('name')->get(),
            'etapas' => FunnelStage::where('funnel_id', $item->funnel_id)->orderBy('ordem')->get(),
        ]);
    }
}
