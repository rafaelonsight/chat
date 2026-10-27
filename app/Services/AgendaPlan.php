<?php
namespace App\Services;
use Illuminate\Support\Facades\{DB,Schema};
use App\Models\User;
class AgendaPlan {
 public const BASE_CENTS=24990, EXTRA_CENTS=1990, INCLUDED_USERS=5;
 public function summary(int $tenant):array {
  $extra=Schema::hasTable('agenda_plan_entitlements')?(int)(DB::table('agenda_plan_entitlements')->where('tenant_id',$tenant)->value('extra_users')??0):0;
  return ['base_cents'=>self::BASE_CENTS,'extra_cents'=>self::EXTRA_CENTS,'extra_users'=>$extra,'included_users'=>self::INCLUDED_USERS,'user_limit'=>self::INCLUDED_USERS+$extra,'users'=>User::withoutGlobalScopes()->where('tenant_id',$tenant)->count(),'total_cents'=>self::BASE_CENTS+$extra*self::EXTRA_CENTS];
 }
 public function assertSeat(int $tenant):void {
  $plan=$this->summary($tenant);
  if($plan['users'] >= $plan['user_limit'])throw \Illuminate\Validation\ValidationException::withMessages(['data.email'=>__('O limite de usuários do plano foi atingido. Solicite a contratação de um usuário adicional.')]);
 }
}
