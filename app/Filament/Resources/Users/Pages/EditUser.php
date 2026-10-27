<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected ?bool $hasDatabaseTransactions=true;
    protected static string $resource = UserResource::class;

    protected function beforeSave(): void {
        if(config('agenda.edition'))\Illuminate\Support\Facades\DB::table('tenants')->where('id',$this->record->tenant_id)->lockForUpdate()->first();
        abort_unless(UserResource::canEdit($this->record),403);
        \App\Filament\Resources\Users\Schemas\UserForm::validarAcesso($this->data,$this->record->tenant_id);
    }
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
