<?php
namespace App\Filament\Resources\AiAgents\Pages;
use App\Filament\Resources\AiAgents\AiAgentResource;
class ListAiAgents extends \Filament\Resources\Pages\ListRecords {
 protected static string $resource=AiAgentResource::class;
 protected function getHeaderActions(): array {return [\Filament\Actions\CreateAction::make()->label(__('ui_7683621ca3a7'))->modalHeading(__('ui_1753c5ec4083'))->mutateDataUsing(function(array $data){$data['tenant_id']=\App\Support\TenantContext::get();return $data;})];}
}
