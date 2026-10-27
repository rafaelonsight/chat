<?php
namespace App\Filament\Resources\Chatbots\Pages;
use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Filament\Resources\Chatbots\Schemas\ChatbotForm;
use App\Services\ChatbotModelos;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Resources\Pages\ListRecords;
class ListChatbots extends ListRecords {
 protected static string $resource = ChatbotResource::class;
 protected function getHeaderActions(): array {
  return [
   Action::make('criarChatbot')->label(__('ui_d8bd9aad069b'))->icon('heroicon-o-plus')
    ->modalHeading(__('ui_5438e5df0ee0'))->modalSubmitActionLabel(__('ui_02c1df6e70e2'))
    ->schema([
      Radio::make('modelo')->label(__('ui_5353f11b05cb'))->options(fn () => \App\Support\Idiomas::opcoes(ChatbotModelos::NOMES))
        ->descriptions(['zero'=>'Canvas vazio para montar seu atendimento.',
         'recepcao'=>'Boas-vindas e menu com Financeiro, Suporte e Comercial.',
         'suporte'=>'Menu de problemas, perguntas básicas e encaminhamento técnico.',
         'financeiro'=>'Segunda via, pagamento e cobrança, com encaminhamento.',
         'comercial'=>'Coleta interesse e localidade antes de encaminhar para vendas.'])
        ->default('zero')->required()->columns(2),
      ...ChatbotForm::creationFields(),
    ])
    ->action(function(array $data) {
      $bot=app(ChatbotModelos::class)->criar($data);
      return redirect(ChatbotResource::getUrl('fluxo',['record'=>$bot]));
    }),
  ];
 }
}
