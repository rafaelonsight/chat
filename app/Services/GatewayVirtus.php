<?php
namespace App\Services;
use App\Models\SystemSetting;use Illuminate\Support\Facades\Crypt;
class GatewayVirtus {
 public static function configuracao():array { $valor=SystemSetting::ler('ia_gateway_global');return $valor?json_decode(Crypt::decryptString($valor),true,512,JSON_THROW_ON_ERROR):[]; }
 public static function salvar(array $config):void {SystemSetting::gravar('ia_gateway_global',Crypt::encryptString(json_encode($config,JSON_THROW_ON_ERROR)));}
}
