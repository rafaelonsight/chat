<?php
namespace App\Models;
use App\Models\Concerns\AgendaTenantScoped;
use Illuminate\Database\Eloquent\Model;
class AgendaChannelConnection extends Model {
 use AgendaTenantScoped;
 protected $table='agenda_channel_connections';
 protected $guarded=['id','tenant_id'];
 protected $hidden=['credentials'];
 protected $casts=['credentials'=>'encrypted:array','validated_at'=>'immutable_datetime','received_at'=>'immutable_datetime','version'=>'integer'];
 public const TYPES=['meta_cloud'=>'WhatsApp oficial','evolution'=>'WhatsApp não oficial','instagram'=>'Instagram','messenger'=>'Facebook'];
 public const STATES=['configurado'=>'Configuração salva','validado'=>'Credencial validada','desconectado'=>'Desconectado','falha'=>'Falha na validação','conectando'=>'Aguardando leitura do QR Code','conectado'=>'WhatsApp conectado'];
 public function validateForAgenda():void {validator($this->getAttributes(),['type'=>'required|in:meta_cloud,evolution,instagram,messenger','name'=>'required|string|max:120','public_id'=>'required|uuid'])->validate();}
}
