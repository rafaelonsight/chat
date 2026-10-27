<?php

namespace App\Models;

use App\Models\Concerns\AgendaTenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Validator;

class AgendaService extends Model
{
    use AgendaTenantScoped;
    protected $table='agenda_services';
    protected $fillable=['nome','descricao','preco','duracao_minutos','intervalo_minutos','ativo','photo_path'];
    protected $attributes=['intervalo_minutos'=>0,'ativo'=>true];
    protected $casts=['preco'=>'decimal:2','duracao_minutos'=>'integer','intervalo_minutos'=>'integer','ativo'=>'boolean'];

    public function validateForAgenda(): void
    {
        $this->nome=trim((string) $this->nome);
        abort_if($this->photo_path && !str_starts_with($this->photo_path,'agenda-services/'.\App\Support\TenantContext::get().'/'),422);
        Validator::make($this->getAttributes(), [
            'nome'=>'required|string|max:120','descricao'=>'nullable|string|max:2000',
            'preco'=>['required','numeric','min:0','max:99999999.99','decimal:0,2'],
            'duracao_minutos'=>'required|integer|min:1|max:1440',
            'intervalo_minutos'=>'required|integer|min:0|max:1440','ativo'=>'required|boolean',
        ])->validate();
    }

    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(AgendaProfessional::class,'agenda_professional_service','service_id','professional_id')
            ->withPivotValue('tenant_id',$this->tenant_id ?? \App\Support\TenantContext::get())->withTimestamps();
    }
}
