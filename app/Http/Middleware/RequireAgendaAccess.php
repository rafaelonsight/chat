<?php
namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RequireAgendaAccess
{
 public function handle(Request $request,Closure $next) {
  if(!$actor=$request->user())return $next($request);
  $user=User::withoutGlobalScopes()->where('tenant_id',$actor->tenant_id)->find($actor->id);
  $version=$request->session()->get('agenda.access_version');
  if(!$user || !$user->canEnterAgenda() || ($version===null && $user->agenda_access_version!==1) || ($version!==null && (int)$version!==$user->agenda_access_version)) {
   Auth::logout();$request->session()->invalidate();$request->session()->regenerateToken();
   return redirect('/admin/login');
  }
  Auth::setUser($user);
  $request->session()->put('agenda.access_version',$user->agenda_access_version);
  if($user->isAgendaProfessional()) {
   $allowed=$request->is('admin','admin/perfil','admin/logout','meu-perfil','idioma','sessao/*','livewire/*');
   if($request->is('admin/produtos') && $user->canAgendaProducts())$allowed=true;
   if($request->is('produtos/*/foto') && $user->canAgendaProducts())$allowed=true;
   if($request->is('admin/financeiro','admin/financeiro/*') && $user->canAgendaFinance())$allowed=true;
   if($request->is('perfil-foto/*'))$allowed=(int)$request->route('usuario')===$user->id;
   abort_unless($allowed,403);
  }
  return $next($request);
 }
}
