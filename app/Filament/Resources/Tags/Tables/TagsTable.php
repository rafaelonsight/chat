<?php

namespace App\Filament\Resources\Tags\Tables;

use App\Models\Tag;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class TagsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount(['contacts','conversations','boardItems','tickets']))
            ->columns([
                // Mostra a etiqueta como ela aparece de verdade. Nome numa coluna de
                // texto simples nao diz nada sobre a cor escolhida.
                TextColumn::make('nome')
                    ->label(__('ui_07287eda176e'))
                    ->html()
                    ->formatStateUsing(fn ($state, Tag $record) => new HtmlString(
                        '<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 '
                        .$record->classes().'">'.e($state).'</span>'
                    ))
                    ->searchable()
                    ->sortable(),

                // Ponto antes do nome: quem procura "aquela azul" acha pela cor.
                TextColumn::make('cor')
                    ->label(__('ui_62037d43904a'))
                    ->html()
                    ->formatStateUsing(fn ($state, Tag $record) => new HtmlString(
                        '<span class="inline-flex items-center gap-1.5">'
                        .'<span class="size-3 rounded-full ring-1 ring-black/10 dark:ring-white/20 '
                        .$record->pontinho().'"></span>'
                        .e($record->corLabel()).'</span>'
                    ))
                    ->toggleable(),

                TextColumn::make('uso')->getStateUsing(fn(Tag $record)=>match($record->contexto){'conversa'=>$record->conversations_count,'item'=>$record->board_items_count,'ticket'=>$record->tickets_count,default=>$record->contacts_count})
                    ->label(__('ui_17a43d279a49'))
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'primary' : 'gray'),
            ])
            ->defaultSort('nome')
            // Mesma largura da criacao: a grade de 24 cores tem 12 colunas.
            ->recordActions([
                EditAction::make()
                    ->modalHeading(__('ui_221a2ba5697b'))
                    ->modalWidth(Width::Large),
                \Filament\Actions\Action::make('historico')->label(__('ui_bea29e92ac2b'))->modalHeading(__('ui_bc8b7bcd0045'))->modalSubmitAction(false)->modalContent(fn(Tag $record)=>view('filament.etiquetas-historico',['movimentos'=>\Illuminate\Support\Facades\DB::table('etiqueta_movimentos')->leftJoin('users','users.id','=','etiqueta_movimentos.usuario_id')->where('etiqueta_movimentos.tenant_id',$record->tenant_id)->where('tag_id',$record->id)->orderByDesc('etiqueta_movimentos.id')->limit(100)->select('etiqueta_movimentos.*','users.name as usuario_nome')->get()])),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('ui_101e64506d3f'))
            ->emptyStateDescription(__('ui_6b859fe7148b'));
    }
}
