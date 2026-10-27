<?php
namespace App\Services;
use App\Models\{Chatbot, ChatbotAction, ChatbotEdge};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class ChatbotModelos
{
    public const NOMES = ['zero'=>'Começar do zero', 'recepcao'=>'Recepção e triagem', 'suporte'=>'Suporte técnico', 'financeiro'=>'Financeiro', 'comercial'=>'Comercial'];
    public function criar(array $data): Chatbot
    {
        $modelo=$data['modelo'] ?? 'zero';
        if (!array_key_exists($modelo,self::NOMES)) throw ValidationException::withMessages(['modelo'=>'Escolha um modelo válido.']);
        unset($data['modelo']);
        return DB::transaction(function () use ($data,$modelo) {
            $bot=Chatbot::create(\App\Filament\Resources\Chatbots\Pages\CreateChatbot::defaults($data));
            $f=app(ChatbotFluxo::class);
            $inicio=$f->garantirInicio($bot);
            if ($modelo==='zero') return $bot;
            $principal=$f->criarPasso($bot,420,180,self::NOMES[$modelo]);
            $f->ligar($inicio,$principal);
            $english=\App\Support\Idiomas::comunicacoes($bot->tenant)==='en_GB';
            $f->adicionarAcao($principal,ChatbotAction::MENSAGEM,['texto'=>$english?'Hello! Welcome to our customer service.':'Olá! Seja bem-vindo ao nosso atendimento.']);
            if ($modelo==='comercial') {
                $f->adicionarAcao($principal,ChatbotAction::PERGUNTA,['texto'=>$english?'Which product or service are you looking for?':'Qual produto ou serviço você procura?','guardar_em'=>'interesse']);
                $f->adicionarAcao($principal,ChatbotAction::PERGUNTA,['texto'=>$english?'In which town or area do you need the service?':'Em qual cidade e bairro você precisa do serviço?','guardar_em'=>'localidade']);
                $this->transferir($f,$principal,'Comercial');
                return $bot;
            }
            $opcoes=match($modelo) {
                'recepcao'=>['Financeiro','Suporte técnico','Comercial'],
                'suporte'=>['Sem conexão','Conexão lenta','Outro problema'],
                'financeiro'=>['Segunda via','Informar pagamento','Dúvida sobre cobrança'],
            };
            $f->adicionarAcao($principal,ChatbotAction::MENU,['texto'=>$english?'How can we help?':'Como podemos ajudar?','opcoes'=>array_map(fn($i,$nome)=>['gatilho'=>(string)($i+1),'rotulo'=>$english?__($nome,[], 'en_GB'):$nome],array_keys($opcoes),$opcoes)]);
            foreach($opcoes as $i=>$nome) {
                $ramo=$f->criarPasso($bot,800,60+$i*400,$nome);
                $f->ligar($principal,$ramo,ChatbotEdge::opcao((string)($i+1)));
                if($modelo==='suporte') {
                    $f->adicionarAcao($ramo,ChatbotAction::PERGUNTA,['texto'=>$english?'Please describe the problem and how long it has been happening.':'Descreva o problema e há quanto tempo acontece.','guardar_em'=>'problema']);
                    $f->adicionarAcao($ramo,ChatbotAction::PERGUNTA,['texto'=>$english?'Which lights are showing on the equipment?':'Quais luzes aparecem no equipamento?','guardar_em'=>'luzes']);
                }
                if($modelo==='financeiro') $f->adicionarAcao($ramo,ChatbotAction::PERGUNTA,['texto'=>$english?'Please tell us about your billing enquiry.':'Conte os detalhes da sua solicitação para o Financeiro.','guardar_em'=>'solicitacao_financeira']);
                $this->transferir($f,$ramo,$modelo==='recepcao'?$nome:($modelo==='suporte'?'Suporte técnico':'Financeiro'));
            }
            return $bot;
        });
    }
    private function transferir(ChatbotFluxo $f,$passo,string $equipe): void {
        $f->adicionarAcao($passo,ChatbotAction::TRANSFERIR,['team_id'=>null,'exigir_equipe'=>true,'aviso'=>\App\Support\Idiomas::comunicacoes(\App\Models\Tenant::findOrFail($passo->tenant_id))==='en_GB'?'I will transfer you to our team.':'Vou encaminhar você para '.$equipe.'.']);
    }
}
