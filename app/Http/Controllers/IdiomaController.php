<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Support\Idiomas;
class IdiomaController {
 public function atualizar(Request $r){
  $d=$r->validate(['idioma'=>['required',Rule::in(array_keys(Idiomas::OPCOES))]]);
  $r->user()->forceFill(['idioma_interface'=>$d['idioma']])->save();$r->session()->put('idioma_interface',$d['idioma']);
  $anterior=url()->previous();$origem=parse_url($anterior,PHP_URL_HOST);
  return redirect($origem===parse_url(config('app.url'),PHP_URL_HOST) && parse_url($anterior,PHP_URL_PATH)?$anterior:'/admin');
 }
}
