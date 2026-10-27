<?php
namespace App\Filament\Resources\KnowledgeDocuments\Pages;
class ListKnowledgeDocuments extends \Filament\Resources\Pages\ListRecords {
 protected static string $resource=\App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource::class;
 protected function getHeaderActions():array{return [\Filament\Actions\CreateAction::make()->label(__('Adicionar conteúdo'))->mutateDataUsing(function(array $data){$data['tenant_id']=\App\Support\TenantContext::get();return $data;})];}
}
