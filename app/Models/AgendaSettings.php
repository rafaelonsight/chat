<?php
namespace App\Models;
use App\Models\Concerns\AgendaTenantScoped;
use Illuminate\Database\Eloquent\Model;
class AgendaSettings extends Model {
 use AgendaTenantScoped;
 protected $table='agenda_settings';
 protected $fillable=['timezone','min_notice_minutes','horizon_days','cancel_notice_minutes','slot_minutes','professional_manage'];
 protected $attributes=['timezone'=>'America/Sao_Paulo','min_notice_minutes'=>60,'horizon_days'=>90,'cancel_notice_minutes'=>1440,'slot_minutes'=>15,'professional_manage'=>false];
 protected $casts=['professional_manage'=>'boolean','min_notice_minutes'=>'integer','horizon_days'=>'integer','cancel_notice_minutes'=>'integer','slot_minutes'=>'integer'];
 public function validateForAgenda():void {
  validator($this->getAttributes(),['timezone'=>['required',\Illuminate\Validation\Rule::in(\DateTimeZone::listIdentifiers())],'min_notice_minutes'=>'required|integer|between:0,43200','horizon_days'=>'required|integer|between:1,365','cancel_notice_minutes'=>'required|integer|between:0,43200','slot_minutes'=>'required|integer|between:5,120','professional_manage'=>'required|boolean'])->validate();
 }
 public static function current(): self {return static::first() ?? new static;}
}
