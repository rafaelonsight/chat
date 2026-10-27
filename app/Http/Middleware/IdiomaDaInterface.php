<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Support\Idiomas;
class IdiomaDaInterface {
 public function handle(Request $request,Closure $next){
  $anterior=app()->getLocale();$fallback=app('translator')->getFallback();$carbon=\Carbon\Carbon::getLocale();
  $idioma=$request->user()?Idiomas::interface($request->user()):Idiomas::valido($request->hasSession()?$request->session()->get('idioma_interface'):'pt_BR');
  app()->setLocale($idioma);app('translator')->setFallback($idioma==='en_GB'?'en':'pt_BR');\Carbon\Carbon::setLocale($idioma==='en_GB'?'en_GB':'pt_BR');
  // O Filament pode renderizar partes da página depois da cadeia de middleware.
  // O idioma é sempre definido no início de cada requisição, sem restauração prematura.
  return $next($request);
 }
}
