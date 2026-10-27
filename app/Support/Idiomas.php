<?php
namespace App\Support;
use App\Models\{Tenant,User};
class Idiomas {
 public const OPCOES=['pt_BR'=>'Português','en_GB'=>'English (UK)'];
 public static function interface(?User $u): string {
  $tenantId=$u?->tenant_id;
  if($u?->operador && $u?->super_admin && request()->hasSession())$tenantId=request()->session()->get(TenantContext::CHAVE_SESSAO_SUPORTE,$tenantId);
  $padrao=Tenant::find($tenantId)?->idioma_padrao;
  return self::valido($u?->idioma_interface ?: $padrao ?: config('agenda.interface_locale'));
 }
 public static function valido(?string $idioma): string {return isset(self::OPCOES[$idioma??''])?$idioma:'pt_BR';}
 public static function comunicacoes(Tenant $t): string {return self::valido($t->idioma_comunicacoes ?: config('agenda.communication_locale'));}
 public static function instrucao(Tenant $t): string {return self::comunicacoes($t)==='en_GB'?'Write all customer-facing content exclusively in British English (UK), using British spelling. Keep the required JSON field names unchanged.':'Escreva o conteúdo destinado ao cliente em português brasileiro. Preserve os nomes dos campos JSON.';}
 public static function opcoes(array $opcoes): array {return array_map(fn($v)=>is_array($v)?self::opcoes($v):(is_string($v)?__($v):$v),$opcoes);}
public static function noIdioma(string $idioma, callable $acao): mixed
 {
  $anterior=app()->getLocale();
  try {app()->setLocale($idioma);return $acao();}
  finally {app()->setLocale($anterior);}
 }
}
