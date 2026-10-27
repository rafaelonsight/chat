<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MetaTarifa extends Model {
 protected $guarded=['id'];
 protected $casts=['tarifa'=>'decimal:8','faixas'=>'array','vigente_desde'=>'date','verificado_em'=>'datetime','tentativa_em'=>'datetime'];
 public const FONTE='https://whatsappbusiness.com/products/platform-pricing/';
}
