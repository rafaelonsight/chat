<?php
namespace App\Filament\Resources\MetaTemplates\Pages;
use App\Filament\Resources\MetaTemplates\MetaTemplateResource;
use App\Models\Channel;
use App\Services\Canais\CriarTemplateMeta;
use Filament\Resources\Pages\EditRecord;
class EditMetaTemplate extends EditRecord
{
    protected static string $resource = MetaTemplateResource::class;
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['channel_id'] = Channel::where('meta_waba_id', $data['meta_waba_id'])->where('tipo',Channel::META_CLOUD)->value('id');
        $data['botoes'] = []; $exemplos=[];
        foreach (($data['componentes'] ?? []) as $c) {
            if ($c['type'] === 'BODY') {
                $exemplos = $c['example']['body_text'][0] ?? array_column($c['example']['body_text_named_params'] ?? [], 'example');
            }
            if ($c['type'] === 'BUTTONS') foreach ($c['buttons'] as $b) $data['botoes'][] = ['tipo'=>$b['type'],'texto'=>$b['text'] ?? '', 'valor'=>$b['url'] ?? $b['phone_number'] ?? ''];
        }
        $data['exemplos']=implode("\n",$exemplos);
        return $data;
    }
    protected function mutateFormDataBeforeSave(array $data): array { abort_unless($this->record->status === 'DRAFT',403); return app(CriarTemplateMeta::class)->preparar($data); }
    protected function getRedirectUrl(): string { return static::getResource()::getUrl('index'); }
}
