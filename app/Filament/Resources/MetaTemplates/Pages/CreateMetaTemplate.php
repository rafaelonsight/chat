<?php
namespace App\Filament\Resources\MetaTemplates\Pages;
use App\Filament\Resources\MetaTemplates\MetaTemplateResource;
use App\Filament\Resources\MetaTemplates\Schemas\MetaTemplateForm;
use App\Services\Canais\CriarTemplateMeta;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Actions\Action;
class CreateMetaTemplate extends CreateRecord
{
    use HasWizard;
    protected static string $resource = MetaTemplateResource::class;
    protected static bool $canCreateAnother = false;
    public function getSteps(): array { return MetaTemplateForm::steps(); }
    protected function mutateFormDataBeforeCreate(array $data): array { return app(CriarTemplateMeta::class)->preparar($data); }
    protected function getCreateFormAction(): Action { return parent::getCreateFormAction()->label(__('Salvar rascunho')); }
    protected function getCreatedNotificationTitle(): ?string { return __('Rascunho salvo'); }
    protected function getRedirectUrl(): string { return static::getResource()::getUrl('index'); }
}
