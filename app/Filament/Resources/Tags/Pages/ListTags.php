<?php

namespace App\Filament\Resources\Tags\Pages;

use App\Filament\Resources\Tags\TagResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListTags extends ListRecords
{
    protected static string $resource = TagResource::class;

    public function getTabs(): array {
        $tabs=[];
        foreach(['contato'=>'Contatos','conversa'=>'Atendimentos','item'=>'Tarefas','ticket'=>'Demandas'] as $contexto=>$rotulo) $tabs[$contexto]=\Filament\Schemas\Components\Tabs\Tab::make($rotulo)->badge(\App\Models\Tag::where('contexto',$contexto)->count())->modifyQueryUsing(fn($query)=>$query->where('contexto',$contexto));
        return $tabs;
    }
    public function getDefaultActiveTab(): string {return 'contato';}
    protected function getHeaderActions(): array
    {
        // Como o resource nao registra rota 'create', esta acao abre em modal.
        // Etiqueta e nome mais cor: trocar de pagina para dois campos tira a
        // lista da frente justamente quando se quer conferir o que ja existe.
        // Large porque a grade de 24 cores tem 12 colunas e nao cabe em sm.
        return [
            CreateAction::make()
                ->modalHeading(fn()=> 'Nova etiqueta • '.(['contato'=>'Contatos','conversa'=>'Atendimentos','item'=>'Tarefas','ticket'=>'Demandas'][$this->activeTab??'contato']))
                ->mutateDataUsing(function(array $data){$data['contexto']=array_key_exists($this->activeTab??'',\App\Models\Tag::CONTEXTOS)?$this->activeTab:'contato';return $data;})
                ->fillForm(fn()=>['contexto'=>$this->activeTab??'contato','cor'=>'cinza'])
                ->modalSubmitActionLabel(__('ui_af3d3c69e687'))
                ->modalWidth(Width::Large),
        ];
    }
}
