<?php
namespace App\Models;
use App\Models\Concerns\AgendaTenantScoped;
use Illuminate\Database\Eloquent\Model;
class AgendaCalendarEntry extends Model {
 use AgendaTenantScoped;
 protected $table='agenda_calendar_entries';
 protected $dateFormat='Y-m-d H:i:sP';
 protected $guarded=['id','tenant_id'];
 protected $casts=['start_at'=>'immutable_datetime','end_at'=>'immutable_datetime','occupied_until'=>'immutable_datetime','snapshot'=>'array','active'=>'boolean','price'=>'decimal:2','version'=>'integer'];
 public const STATES=['confirmado'=>'Confirmado','em_atendimento'=>'Em atendimento','concluido'=>'Concluído','cancelado'=>'Cancelado','falta'=>'Falta','bloqueado'=>'Bloqueio'];
 public function validateForAgenda():void {validator($this->getAttributes(),['professional_id'=>'required|integer','kind'=>'required|in:booking,block','version'=>'required|integer|min:1'])->validate();}
 public function professional(){return $this->belongsTo(AgendaProfessional::class,'professional_id');}
}
