<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
class ExigirPerfilInicial {
 public function handle(Request $request,Closure $next){
  if($u=$request->user()) {
   if(!$u->perfil_preenchido_em && $request->is('admin','admin/*','gestao','gestao/*','livewire/*') && ! $request->is('admin/login','gestao/login','admin/logout','gestao/logout')){
    if($request->is('gestao','gestao/*')) $request->session()->put('perfil.destino','/gestao');
    return redirect()->route('perfil.inicial');
   }
  }
  return $next($request);
 }
}
