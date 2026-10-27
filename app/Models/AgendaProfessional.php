<?php

namespace App\Models;

use App\Models\Concerns\AgendaTenantScoped;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AgendaProfessional extends Model
{
    use AgendaTenantScoped;
    protected $table='agenda_professionals';
    protected $fillable=['nome','descricao','email','telefone','cor','ativo'];
    protected $attributes=['cor'=>'#6338F6','ativo'=>true];
    protected $casts=['ativo'=>'boolean'];

    public function validateForAgenda(): void
    {
        $this->nome=trim((string) $this->nome);
        $this->email=$this->email ? mb_strtolower(trim($this->email)) : null;
        Validator::make($this->getAttributes(), [
            'nome'=>'required|string|max:120','descricao'=>'nullable|string|max:2000',
            'email'=>'nullable|email|max:255','telefone'=>'nullable|string|max:30',
            'cor'=>['required','regex:/^#[0-9a-fA-F]{6}$/'],'ativo'=>'required|boolean',
        ])->validate();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(AgendaService::class,'agenda_professional_service','professional_id','service_id')
            ->withPivotValue('tenant_id',$this->tenant_id ?? TenantContext::get())->withTimestamps();
    }

    public function accessUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class,'user_id')->where('users.tenant_id',$this->tenant_id ?? TenantContext::get());
    }

    public function syncServices(array $ids): void
    {
        $tenant=TenantContext::get();
        if (!$tenant || (int) $this->tenant_id !== $tenant) {
            throw ValidationException::withMessages(['services'=>'Não foi possível identificar a conta autorizada.']);
        }
        Validator::make(['services'=>$ids],['services'=>'array','services.*'=>'integer|distinct'])->validate();
        $ids=array_map('intval',$ids);
        DB::transaction(function () use ($ids,$tenant) {
            static::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (AgendaService::where('tenant_id',$tenant)->whereIn('id',$ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['services'=>'Selecione somente serviços deste estabelecimento.']);
            }
            $this->services()->sync($ids);
        });
    }
}
