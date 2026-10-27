<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
class PerfilInicialController {
 public function mostrar(Request $request){return view('auth.perfil-inicial',['usuario'=>$request->user()]);}
 public function salvar(Request $request){
  $dados=$request->validate([
   'name'=>['required','string','max:255','regex:/^\s*\S+\s+\S.+$/u'],
   'telefone'=>['required','string','max:30','regex:/^\+?[\d\s().-]+$/'],
   'apelido'=>['required','string','max:40',"regex:/^[\pL\pN][\pL\pN .'-]*$/u"],
   'cargo'=>'nullable|string|max:100',
   'cor_chat'=>['nullable',\Illuminate\Validation\Rule::in(array_keys(\App\Models\User::PALETA_CHAT))],
   'foto'=>'nullable|image|mimes:jpg,jpeg,png,webp|max:2048|dimensions:max_width=4096,max_height=4096',
  ],['name.regex'=>'Informe seu nome completo, com nome e sobrenome.','apelido.regex'=>'Use letras, números e espaços no nome exibido.','foto.max'=>'A foto deve ter até 2 MB.']);
  $telefone=preg_replace('/\D/','',$dados['telefone']);
  if(strlen($telefone)<8 || strlen($telefone)>15) throw \Illuminate\Validation\ValidationException::withMessages(['telefone'=>'Informe um telefone válido com código de área.']);
  $u=$request->user();$anterior=$u->foto_perfil;$foto=null;
  if($request->hasFile('foto')) $foto=$request->file('foto')->store('perfis/'.$u->id,'local');
  try {
   $u->forceFill(['name'=>trim($dados['name']),'telefone'=>trim($dados['telefone']),'apelido'=>trim($dados['apelido']),'cargo'=>trim($dados['cargo']??''),'cor_chat'=>$dados['cor_chat']??$u->cor_chat??'roxo','foto_perfil'=>$foto?:$anterior,'perfil_preenchido_em'=>$u->perfil_preenchido_em?:now()])->save();
  } catch(\Throwable $e){if($foto) Storage::disk('local')->delete($foto);throw $e;}
  if($foto && $anterior && str_starts_with($anterior,'perfis/'.$u->id.'/')) Storage::disk('local')->delete($anterior);
  $destino=$request->session()->pull('perfil.destino','/admin');
  return redirect($destino==='/gestao' && $u->operador?'/gestao':'/admin')->with('status','Perfil atualizado. Bem-vindo ao Virtus Agenda!');
 }
 public function foto(Request $request,int $usuario){
  $u=\App\Models\User::withoutGlobalScopes()->findOrFail($usuario);$ator=$request->user();
  abort_unless($ator->id===$u->id || ($ator->tenant_id && $ator->tenant_id===$u->tenant_id),403);
  abort_unless($u->foto_perfil && Storage::disk('local')->exists($u->foto_perfil),404);
  return Storage::disk('local')->response($u->foto_perfil,null,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
 }
}
