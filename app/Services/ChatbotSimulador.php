<?php
namespace App\Services;
use App\Models\{Chatbot, ChatbotAction as A, ChatbotEdge as E};
use Illuminate\Support\Str;

/** Execução em memória: nunca grava contatos, conversas, etiquetas ou mensagens. */
class ChatbotSimulador
{
    public function iniciar(Chatbot $bot): array {
        $s=['passo'=>$bot->inicio()?->id,'indice'=>0,'aguardando'=>null,'fim'=>false,'respostas'=>['nome'=>'Maria'],'etiquetas'=>[],'historico'=>[]];
        $this->rodar($bot,$s);
        return $s;
    }
    private function evento(array &$s,string $tipo,string $texto): void {
        $s['historico'][]=compact('tipo','texto');
        $s['historico']=array_slice($s['historico'],-150);
    }
    private function texto(array $s,string $texto): string {
        foreach($s['respostas'] as $k=>$v) $texto=str_replace('{{'.$k.'}}',(string)$v,$texto);
        return $texto;
    }
    public function responder(Chatbot $bot,array $s,string $texto): array {
        if(isset($s['bot_id']))$bot=Chatbot::where('tenant_id',$bot->tenant_id)->findOrFail($s['bot_id']);
        $texto=trim($texto);
        if($s['fim'] || !$s['aguardando'] || $texto==='') return $s;
        $acao=$bot->actions()->find($s['aguardando']);
        if(!$acao) { $s['fim']=true; $this->evento($s,'sistema','Bloco removido. Reinicie o teste.'); return $s; }
        $this->evento($s,'cliente',mb_substr($texto,0,2000));
        if($acao->tipo==='pergunta_dinamica') { $s['respostas'][$acao->cfg('guardar_em','escolha')]=$texto;$this->mover($bot,$s,'sucesso'); }
        elseif(in_array($acao->tipo,['agente_ia','supervisor_ia'])) { $this->evento($s,'sistema','Resposta de IA simulada; nenhum provedor foi chamado.');$s['fim']=true;return $s; }
        elseif($acao->tipo===A::MENU) {
            $opcao=collect($acao->cfg('opcoes',[]))->first(fn($o)=>Str::lower(Str::ascii($texto))===Str::lower(Str::ascii((string)($o['gatilho']??''))) || Str::lower(Str::ascii($texto))===Str::lower(Str::ascii((string)($o['rotulo']??''))));
            if(!$opcao) {$this->evento($s,'bot',$bot->mensagem_nao_entendi);return $s;}
            $this->mover($bot,$s,E::opcao((string)$opcao['gatilho']));
        } else {
            $chave=$acao->cfg('guardar_em') ?: $acao->cfg('campo_contato');
            if($chave) $s['respostas'][$chave]=$texto;
            $s['indice']++;
        }
        $s['aguardando']=null;
        $this->rodar($bot,$s);
        return $s;
    }
    private function mover(Chatbot $bot,array &$s,string $handle=E::SAIDA): void {
        $passo=$bot->steps()->find($s['passo']);
        $s['passo']=$passo?->destino($handle)?->id;
        $s['indice']=0;
    }
    private function rodar(Chatbot $bot,array &$s): void {
        for($i=0;$i<100 && !$s['fim'];$i++) {
            $passo=$bot->steps()->find($s['passo']);
            if(!$passo) {$s['fim']=true;$this->evento($s,'sistema','Fim do caminho. Não há próximo grupo conectado.');return;}
            $acoes=$passo->actions()->get();
            $acao=$acoes[$s['indice']]??null;
            if(!$acao) {$this->mover($bot,$s);continue;}
            $cfg=$acao->config??[];
            switch($acao->tipo) {
                case A::MENSAGEM: if($acao->cfg('tipo_midia','text')!=='text')$this->evento($s,'sistema','Mídia simulada: '.$acao->cfg('media_nome','arquivo')); $this->evento($s,'bot',$this->texto($s,$cfg['texto']??''));break;
                case A::MENU:
                case A::PERGUNTA:
                    $texto=$this->texto($s,$cfg['texto']??'');
                    if($acao->tipo===A::MENU) foreach($cfg['opcoes']??[] as $o) $texto.="\n".($o['gatilho']??'').'. '.($o['rotulo']??'');
                    $this->evento($s,'bot',$texto);$s['aguardando']=$acao->id;return;
                case A::ESPERAR: $this->evento($s,'sistema','Espera simulada de '.($cfg['segundos']??1).' segundos (avançada automaticamente).');break;
                case A::ETIQUETA:
                    $s['etiquetas']=array_values(array_diff(array_unique(array_merge($s['etiquetas'],$cfg['adicionar']??[])),$cfg['remover']??[]));
                    $this->evento($s,'sistema','Etiquetas atualizadas somente neste teste.');break;
                case A::CONDICIONAL:
                    if(($cfg['modo']??'')==='multiplo'){
                        $lado=CondicoesChatbot::escolher($cfg,$s['respostas']??[],['contato'=>['nome'=>'Maria']],$s['etiquetas']??[]);
                        $this->evento($s,'sistema','Condição: '.$lado.' (dados do contato fictícios)');$this->mover($bot,$s,$lado);continue 2;
                    }
                    if(($cfg['fonte']??'resposta')==='etiqueta') {
                        $sim=in_array($cfg['etiqueta_id']??null,$s['etiquetas']);
                        if(($cfg['operador']??'presente')==='ausente') $sim=!$sim;
                    } else {
                        $valor=Str::lower(Str::ascii((string)($s['respostas'][$cfg['campo']??'']??'')));
                        $esperado=Str::lower(Str::ascii((string)($cfg['valor']??'')));
                        $sim=match($cfg['operador']??'igual') {'contem'=>str_contains($valor,$esperado),'comeca'=>str_starts_with($valor,$esperado),default=>$valor===$esperado};
                    }
                    $this->evento($s,'sistema','Condição: '.($sim?'sim':'não'));
                    $this->mover($bot,$s,$sim?E::SIM:E::NAO);continue 2;
                case A::TRANSFERIR:
                case A::CONCLUIR:
                    if($cfg['aviso']??'') $this->evento($s,'bot',$this->texto($s,$cfg['aviso']));
                    $this->evento($s,'sistema',$acao->tipo===A::TRANSFERIR?'Transferência simulada. Nenhuma equipe foi acionada.':'Atendimento encerrado no teste.');
                    $s['fim']=true;return;
                case 'metadados':$s['respostas'][$cfg['chave']??'variavel']=$this->texto($s,$cfg['valor']??'');$this->evento($s,'sistema','Variável atualizada somente no teste.');break;
                case 'modelo':$this->evento($s,'sistema','Envio de template simulado; nenhuma mensagem enviada.');break;
                case 'aguardar':case 'pergunta_dinamica':case 'agente_ia':case 'supervisor_ia':
                    $this->evento($s,'bot',$this->texto($s,$cfg['texto']??'Escreva uma mensagem de teste.'));
                    if($acao->tipo==='pergunta_dinamica')$this->evento($s,'sistema','Opções simuladas: digite 1. A integração não é chamada.');
                    $s['aguardando']=$acao->id;return;
                case 'webhook':$s['respostas'][$cfg['guardar_em']??'resultado']=['simulado'=>true];$this->evento($s,'sistema','Webhook simulado pelo caminho sucesso. Nenhuma URL foi chamada.');$this->mover($bot,$s,'sucesso');continue 2;
                case 'criar_card':$s['respostas'][$cfg['guardar_em']??'card_id']='card_simulado';$this->evento($s,'sistema','Criação de card simulada.');break;
                case 'mover_card':case 'alterar_card':case 'sequencia':$this->evento($s,'sistema',$acao->rotulo().' simulado, sem alterar dados.');break;
                case 'outro_chatbot':
                    $novo=Chatbot::where('tenant_id',$bot->tenant_id)->find($cfg['chatbot_id']??0);
                    if(!$novo){$s['fim']=true;$this->evento($s,'sistema','Chatbot de destino indisponível.');return;}
                    $bot=$novo;$s['bot_id']=$bot->id;$s['passo']=$bot->inicio()?->destino()?->id;$s['indice']=0;continue 2;
                default:$this->evento($s,'sistema','Bloco não suportado pelo simulador.');$s['fim']=true;return;
            }
            $s['indice']++;
        }
        if(!$s['fim']) {$s['fim']=true;$this->evento($s,'sistema','Teste interrompido: limite de ações atingido. Verifique ciclos.');}
    }
}
