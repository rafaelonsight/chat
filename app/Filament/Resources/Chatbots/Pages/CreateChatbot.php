<?php
namespace App\Filament\Resources\Chatbots\Pages;
use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Filament\Resources\Chatbots\Schemas\ChatbotForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
class CreateChatbot extends CreateRecord {
 protected static string $resource = ChatbotResource::class;
 protected static bool $canCreateAnother = false;
 public function form(Schema $schema): Schema { return $schema->components(ChatbotForm::creationFields()); }
 public static function defaults(array $data): array {
  return array_merge($data, ['ativo'=>false, 'status'=>'rascunho', 'mensagem_boas_vindas'=>'Olá!', 'mensagem_nao_entendi'=>'Não entendi. Tente novamente.']);
 }
 protected function mutateFormDataBeforeCreate(array $data): array { return self::defaults($data); }
 protected function getRedirectUrl(): string { return static::getResource()::getUrl('fluxo', ['record'=>$this->getRecord()]); }
}
