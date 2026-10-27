<?php
namespace App\Filament\Resources\Users\Pages;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Schemas\UserForm;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\{Wizard\Step,Text};
class CreateUser extends CreateRecord {
 use HasWizard;
 protected static string $resource=UserResource::class;
 public function getSteps(): array {return [
  Step::make('Dados')->description(__('ui_b6ee0be47496'))->schema(UserForm::dados()),
  Step::make('Perfil')->description(__('ui_ab1c4f44dd8d'))->schema(UserForm::perfil()),
  Step::make('Acesso')->description(__('ui_d9fa98cb3611'))->schema(config('agenda.edition') ? array_merge(UserForm::acesso(),UserForm::business()) : UserForm::acesso()),
  Step::make('Conferência')->description(__('ui_e1ba33347793'))->schema([
   Text::make(fn($get)=>'Nome: '.$get('name')),
   Text::make(fn($get)=>'E-mail: '.$get('email')),
   Text::make(fn($get)=>'Perfil: '.(\App\Models\User::PERFIS[$get('perfil')]??'Atendente')),
   Text::make(fn($get)=>'Canais autorizados: '.count($get('canais')??[]).' • Equipes selecionadas: '.count($get('teams')??[])),
   Text::make(config('agenda.edition') ? 'Após o cadastro, envie um convite para a pessoa definir sua senha. A entrada será por e-mail e senha.' : 'Após o cadastro, a pessoa poderá solicitar seu código na tela de entrada. Nenhuma senha será criada ou enviada.'),
  ]),
 ];}
 protected function mutateFormDataBeforeCreate(array $data): array {
  $data['tenant_id']=\App\Support\TenantContext::get();
  abort_unless($data['tenant_id'],422,'Selecione uma empresa em Gestão de contas antes de cadastrar seus usuários.');
  UserForm::validarAcesso($this->data,$data['tenant_id']);
  return $data;
 }
 protected function afterCreate(): void {
  if(!config('agenda.edition'))return;
  $result=app(\App\Services\CriarCliente::class)->convidar($this->record);
  if(!$result['enviado']) \Filament\Notifications\Notification::make()->warning()->title('Usuário cadastrado')->body('O envio de convites por e-mail ainda precisa ser configurado.')->send();
 }
 protected function getCreatedNotificationTitle(): ?string {return __('ui_d81d614b64e5');}
}
