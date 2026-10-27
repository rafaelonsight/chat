<?php

namespace App\Filament\Resources\Chatbots\Pages;

use App\Filament\Resources\Chatbots\ChatbotResource;
use App\Models\Chatbot;
use App\Models\ChatbotAction;
use App\Models\ChatbotEdge;
use App\Models\ChatbotStep;
use App\Models\Team;
use App\Services\ChatbotFluxo;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * Construtor visual do fluxo. Cada acao persiste na hora — nao existe "salvar
 * tudo": o usuario arrasta um cartao e o desenho dele fica guardado. O que precisa
 * de ato explicito e PUBLICAR, porque e isso que passa a valer para o cliente.
 */
class EditarFluxo extends Page
{
    use \App\Filament\TraduzirNavegacao;

    use InteractsWithRecord;
    use \Livewire\WithFileUploads;
    public $arquivoBloco = null;
    #[\Livewire\Attributes\Locked]
    public array $itensCopiados = [];
    public function copiarItens(): void {
        $passo=$this->passoDo($this->passoAberto); if(!$passo)return;
        $this->itensCopiados=$passo->actions()->orderBy("ordem")->get()->map(fn($a)=>["tipo"=>$a->tipo,"config"=>$a->config])->all();
        Notification::make()->success()->title("Ações copiadas neste editor")->send();
    }
    public function colarItens(): void {
        $passo=$this->passoDo($this->passoAberto);if(!$passo || !$this->itensCopiados)return;
        $this->estruturaMudou(); foreach($this->itensCopiados as $a)$this->fluxo()->adicionarAcao($passo,$a["tipo"],$a["config"]);
        Notification::make()->success()->title("Ações coladas. Conecte as saídas deste grupo.")->send();
    }

    protected static string $resource = ChatbotResource::class;

    protected string $view = 'filament.resources.chatbots.pages.editar-fluxo';

    protected static ?string $title = 'Fluxo';

    /** Passo aberto na gaveta lateral. */
    public ?int $passoAberto = null;

    /** Acao sendo configurada. */
    public ?int $acaoAberta = null;

    /** Config em edicao, espelhada da acao aberta. */
    public array $form = [];

    public bool $paletaAberta = false;
    #[\Livewire\Attributes\Locked]
    public array $simulacao = [];
    public string $respostaTeste = '';
    public function iniciarTeste(): void {
        $this->simulacao = app(\App\Services\ChatbotSimulador::class)->iniciar($this->record);
        $this->respostaTeste = '';
    }
    public function responderTeste(): void {
        $this->simulacao = app(\App\Services\ChatbotSimulador::class)->responder($this->record, $this->simulacao, $this->respostaTeste);
        $this->respostaTeste = '';
    }
    public function fecharTeste(): void { $this->simulacao = []; }


    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        // Fluxo sem inicio nao roda; criar sob demanda evita que o usuario precise
        // saber que existe um passo especial.
        app(ChatbotFluxo::class)->garantirInicio($this->record);

        $this->trazerParaDentro();
    }

    /**
     * Bloco em coordenada negativa fica fora da area visivel, sem jeito de clicar.
     * Ja aconteceu em producao; aqui o desenho inteiro volta para dentro,
     * preservando as posicoes relativas.
     */
    private function trazerParaDentro(): void
    {
        $passos = $this->record->steps()->get();

        if ($passos->isEmpty()) {
            return;
        }

        $menorX = (int) $passos->min('x');
        $menorY = (int) $passos->min('y');

        if ($menorX >= 0 && $menorY >= 0) {
            return;
        }

        $dx = $menorX < 0 ? 40 - $menorX : 0;
        $dy = $menorY < 0 ? 40 - $menorY : 0;

        foreach ($passos as $passo) {
            $passo->update(['x' => $passo->x + $dx, 'y' => $passo->y + $dy]);
        }
    }

    public function getTitle(): string
    {
        return $this->record->nome;
    }

    public function getBreadcrumb(): string
    {
        return __('ui_f7cf4eda51a1');
    }

    private function fluxo(): ChatbotFluxo
    {
        return app(ChatbotFluxo::class);
    }

    // ------------------------------------------------------------------- leitura

    // #[Computed] e nao getXProperty: a magica do getXProperty saiu no Livewire 3.
    // Sem o atributo, \$this->passos na view seria simplesmente nulo.
    #[Computed]
    public function passos()
    {
        return $this->record->steps()->with(['actions', 'saidas'])->orderBy('id')->get();
    }

    #[Computed]
    public function arestas()
    {
        return $this->record->edges()->get();
    }

    #[Computed]
    public function problemas(): array
    {
        return $this->fluxo()->validar($this->record);
    }

    /**
     * Catalogo de campos para a pergunta guardar a resposta. Sai do banco a cada
     * render de proposito: campo personalizado criado agora precisa aparecer no
     * fluxo agora.
     */
    #[Computed]
    public function camposDoContato(): array
    {
        return \App\Services\CampoDoContato::agrupadas();
    }

    #[Computed]
    public function equipes()
    {
        return Team::ativas()->orderBy('nome')->pluck('nome', 'id')->all();
    }

    /**
     * O desenho que a tela precisa: um vetor por cartao, com as saidas ja
     * resolvidas. Montar isto aqui evita o blade fazer consulta dentro de laco.
     */
    #[Computed]
    public function desenho(): array
    {
        $arestas = $this->arestas->groupBy('from_step_id');

        return $this->passos->map(function (ChatbotStep $passo) use ($arestas) {
            $acoes = $passo->actions->map(fn (ChatbotAction $a) => [
                'id'     => $a->id,
                'tipo'   => $a->tipo,
                'rotulo' => $a->rotulo(),
                'resumo' => Str::limit($a->resumo(), 60),
            ])->values()->all();

            // Handles: um por opcao de menu / lado do condicional, ou a saida unica.
            $handles = collect($passo->actions)
                ->flatMap(fn (ChatbotAction $a) => collect($a->handles())->map(fn ($h) => [
                    'handle' => $h,
                    'rotulo' => $this->rotuloDoHandle($a, $h),
                ]))
                ->values()
                ->all();

            if ($handles === [] && ! $this->encerra($passo)) {
                $handles = [['handle' => ChatbotEdge::SAIDA, 'rotulo' => null]];
            }

            $saidas = ($arestas[$passo->id] ?? collect())
                ->mapWithKeys(fn (ChatbotEdge $e) => [$e->from_handle => $e->to_step_id])
                ->all();

            return [
                'id'      => $passo->id,
                'nome'    => $passo->nome,
                'inicio'  => $passo->ehInicio(),
                'x'       => $passo->x,
                'y'       => $passo->y,
                'acoes'   => $acoes,
                'handles' => $handles,
                'saidas'  => $saidas,
            ];
        })->values()->all();
    }

    private function encerra(ChatbotStep $passo): bool
    {
        return $passo->actions->contains(fn (ChatbotAction $a) => $a->encerra());
    }

    private function rotuloDoHandle(ChatbotAction $acao, string $handle): ?string
    {
        if ($acao->tipo === ChatbotAction::CONDICIONAL) {
            if($acao->cfg('modo')==='multiplo'){if($handle==='padrao')return __('ui_312723131f3f');foreach($acao->cfg('casos',[]) as $caso)if('caso:'.$caso['id']===$handle)return $caso['nome'];}
            return $handle === ChatbotEdge::SIM ? 'sim' : 'não';
        }

        $gatilho = str_starts_with($handle, 'opcao:') ? substr($handle, 6) : $handle;

        foreach ($acao->cfg('opcoes', []) as $opcao) {
            if (trim((string) ($opcao['gatilho'] ?? '')) === $gatilho) {
                return $opcao['rotulo'] ?? $gatilho;
            }
        }

        return $gatilho;
    }

    // ------------------------------------------------------------------- escrita

    /** Organiza por distância do início; ramos e ciclos permanecem conectados. */
    public function organizarGrupos(): void
    {
        $this->estruturaMudou();
        $passos = $this->record->steps()->orderBy('id')->get();
        $inicio = $passos->first(fn ($p) => $p->ehInicio());
        if (! $inicio) return;
        $saidas = $this->record->edges()->get()->groupBy('from_step_id');
        $niveis = [$inicio->id => 0];
        $fila = [$inicio->id];
        for ($i = 0; $i < count($fila); $i++) {
            foreach ($saidas[$fila[$i]] ?? [] as $aresta) {
                if (! isset($niveis[$aresta->to_step_id])) {
                    $niveis[$aresta->to_step_id] = $niveis[$fila[$i]] + 1;
                    $fila[] = $aresta->to_step_id;
                }
            }
        }
        $ultimo = max($niveis) + 1;
        $linhas = [];
        \Illuminate\Support\Facades\DB::transaction(function () use ($passos, $niveis, $ultimo, &$linhas) {
            foreach ($passos as $passo) {
                $nivel = $niveis[$passo->id] ?? $ultimo;
                $linha = $linhas[$nivel] ?? 0;
                $passo->update(['x' => 60 + $nivel * 360, 'y' => 60 + $linha * 460]);
                $linhas[$nivel] = $linha + 1;
            }
        });
        $this->dispatch('fluxo-organizado');
        Notification::make()->success()->title(__('ui_39e15f42872a'))->send();
    }

    public function criarPasso(int $x = 400, int $y = 200): void
    {
        $this->estruturaMudou();

        $passo = $this->fluxo()->criarPasso($this->record, $x, $y);
        $this->passoAberto = $passo->id;
        $this->paletaAberta = true;
    }

    /**
     * Sem re-render de proposito: a posicao ja esta correta na tela porque o Alpine
     * a moveu. Redesenhar aqui causaria um pulo visivel a cada solta.
     */
    public function moverPasso(int $id, int $x, int $y): void
    {
        // max(0) tambem aqui: o navegador ja limita, mas coordenada negativa
        // gravada deixa o bloco inalcancavel para sempre.
        $this->passoDo($id)?->update(['x' => max(0, $x), 'y' => max(0, $y)]);
        $this->skipRender();
    }

    public function renomearPasso(int $id, string $nome): void
    {
        $this->estruturaMudou();

        $nome = trim($nome);

        if ($nome === '') {
            return;
        }

        $this->passoDo($id)?->update(['nome' => mb_substr($nome, 0, 60)]);
    }

    public function removerPasso(int $id): void
    {
        $this->estruturaMudou();

        $passo = $this->passoDo($id);

        if (! $passo || $passo->ehInicio()) {
            // O inicio nao se remove: sem ele o fluxo nao tem por onde comecar.
            return;
        }

        $passo->delete();

        if ($this->passoAberto === $id) {
            $this->fecharGaveta();
        }
    }

    public function abrirPasso(int $id): void
    {
        $this->passoAberto = $id;
        $this->acaoAberta = null;
        $this->paletaAberta = false;
    }

    public function fecharGaveta(): void
    {
        $this->passoAberto = null;
        $this->acaoAberta = null;
        $this->paletaAberta = false;
        $this->form = [];
    }

    public function abrirPaleta(int $id): void
    {
        $this->passoAberto = $id;
        $this->acaoAberta = null;
        $this->paletaAberta = true;
    }

    public function adicionarAcao(string $tipo): void
    {
        $this->estruturaMudou();

        $passo = $this->passoDo($this->passoAberto);

        if (! array_key_exists($tipo, ChatbotAction::TIPOS)) return;
        if (! $passo) {
            $indice = $this->record->steps()->count();
            $passo = $this->fluxo()->criarPasso($this->record, 420, 60 + $indice * 80, ChatbotAction::TIPOS[$tipo]);
            $this->passoAberto = $passo->id;
        }

        $acao = $this->fluxo()->adicionarAcao($passo, $tipo, $this->configPadrao($tipo));

        $this->paletaAberta = false;
        $this->abrirAcao($acao->id);
    }

    private function configPadrao(string $tipo): array
    {
        return match ($tipo) {
            ChatbotAction::MENSAGEM    => ['texto' => ''],
            ChatbotAction::MENU        => ['texto' => '', 'opcoes' => [['gatilho' => '1', 'rotulo' => '']], 'campo_contato' => ''],
            ChatbotAction::PERGUNTA    => ['texto' => '', 'guardar_em' => '', 'campo_contato' => ''],
            ChatbotAction::ESPERAR     => ['segundos' => 5],
            ChatbotAction::CONDICIONAL => ['campo' => '', 'operador' => 'contem', 'valor' => ''],
            ChatbotAction::TRANSFERIR  => ['team_id' => null, 'aviso' => 'Vou te encaminhar para um atendente.'],
            ChatbotAction::CONCLUIR    => ['aviso' => 'Atendimento encerrado. Obrigado!'],
            ChatbotAction::ETIQUETA    => ['adicionar' => [], 'remover' => []],
            default                    => \App\Services\BlocosChatbot::padrao($tipo),
        };
    }

    public function abrirAcao(int $id): void
    {
        $acao = $this->acaoDo($id);

        if (! $acao) {
            return;
        }

        $this->arquivoBloco=null;
        $this->acaoAberta = $id;
        $this->passoAberto = $acao->step_id;
        $this->paletaAberta = false;

        // O formulario espelha a config; salvar grava de volta. Sem espelho, editar
        // uma opcao de menu exigiria mexer em jsonb direto no wire:model.
        $this->form = array_merge($this->configPadrao($acao->tipo), $acao->config ?? []);
    }

    public function salvarAcao(): void
    {
        $this->estruturaMudou();

        $acao = $this->acaoDo($this->acaoAberta);

        if (! $acao) {
            return;
        }

        $config = $this->form;
        if($acao->tipo===ChatbotAction::MENSAGEM && ($config['tipo_midia']??'text')!=='text') {
            if($this->arquivoBloco) {
                $this->validate(['arquivoBloco'=>'file|max:20480|mimes:jpg,jpeg,png,webp,mp4,mp3,ogg,wav,pdf,doc,docx,xls,xlsx,txt']);
                $mime=$this->arquivoBloco->getMimeType();$tipo=app(\App\Services\MediaService::class)->tipoPorMime($mime);
                if($tipo!==$config['tipo_midia'])throw \Illuminate\Validation\ValidationException::withMessages(['arquivoBloco'=>'O arquivo precisa corresponder ao tipo de mídia escolhido.']);
                $config['media_path']=$this->arquivoBloco->store('chatbots/'.$acao->tenant_id,'local');
                $config['media_mime']=$mime;$config['media_nome']=$this->arquivoBloco->getClientOriginalName();
            }
            if(!preg_match('~^chatbots/'.$acao->tenant_id.'/[a-zA-Z0-9_.-]+$~',(string)($config['media_path']??'')) || !\Illuminate\Support\Facades\Storage::disk('local')->exists($config['media_path']))throw \Illuminate\Validation\ValidationException::withMessages(['arquivoBloco'=>'Envie um arquivo para este bloco.']);
        }

        if($acao->tipo===ChatbotAction::CONDICIONAL && ($config['modo']??'')!=='multiplo' && ($config['fonte']??'')==='etiqueta') {
            if(!\App\Models\Tag::whereIn('contexto',['contato','conversa'])->whereKey((int)($config['etiqueta_id']??0))->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['form.etiqueta_id'=>'Selecione uma etiqueta desta empresa.']);
            $config['operador']=($config['operador']??'')==='ausente'?'ausente':'presente';
        }
        if($acao->tipo===ChatbotAction::ETIQUETA) foreach(['adicionar','remover'] as $campo) $config[$campo]=\App\Models\Tag::whereIn('contexto',['contato','conversa'])->whereIn('id',(array)($config[$campo]??[]))->pluck('id')->all();

        if ($acao->tipo === ChatbotAction::MENU) {
            // Opcao sem gatilho nao e escolhivel: some em vez de virar lixo no menu.
            $config['opcoes'] = collect($config['opcoes'] ?? [])
                ->filter(fn ($o) => trim((string) ($o['gatilho'] ?? '')) !== '')
                ->map(fn ($o) => [
                    'gatilho' => trim((string) $o['gatilho']),
                    'rotulo'  => trim((string) ($o['rotulo'] ?? '')),
                ])
                ->values()
                ->all();
        }

        if($acao->tipo===ChatbotAction::MENU && !empty($config['opcoes'])) {
            $errors=\App\Services\MenuChatbot::validar((string)($config['formato']??'numerico'),$config['opcoes']);
            if($errors!==[])throw \Illuminate\Validation\ValidationException::withMessages(['form.opcoes'=>implode(' ', $errors)]);
        }
        if(in_array($acao->tipo,['agente_ia','supervisor_ia']) && !empty($config['agente_id'])) {
            $candidate=clone $acao;$candidate->config=$config;
            try{app(\App\Services\BlocosChatbot::class)->configIA($candidate);}catch(\Throwable $e){throw \Illuminate\Validation\ValidationException::withMessages(['form.agente_id'=>'Selecione um agente ativo desta empresa com a função correspondente.']);}
        }

        if ($acao->tipo === ChatbotAction::ESPERAR) {
            $config['segundos'] = max(1, (int) ($config['segundos'] ?? 1));
        }

        if($acao->tipo===ChatbotAction::CONDICIONAL && ($config['modo']??'')==='multiplo'){
            $errors=\App\Services\CondicoesChatbot::validar($config,$acao->tenant_id);
            if($errors)throw \Illuminate\Validation\ValidationException::withMessages(['form.casos'=>implode(' ', $errors)]);
        }
        $acao->update(['config' => $config]);

        // Gatilho removido deixa aresta orfa apontando para um caminho que nao
        // existe mais; a validacao acusaria e o usuario nao saberia por que.
        $this->limparArestasOrfas($acao->step);

        Notification::make()->success()->title(__('ui_3424e755ec45'))->send();
    }

    public function updatedForm(mixed $value,string $key): void {
        if(preg_match('/^casos\.(\d+)\.regras\.(\d+)\.fonte$/',$key,$m)){
            $this->form['casos'][(int)$m[1]]['regras'][(int)$m[2]]['operador']=$value==='etiqueta'?'presente':'igual';
            $this->form['casos'][(int)$m[1]]['regras'][(int)$m[2]]['campo']='';
        }
    }
    public function adicionarCasoCondicao(): void {
        if(!$a=$this->acaoDo($this->acaoAberta))return;
        if($a->tipo!==ChatbotAction::CONDICIONAL || count($this->form['casos']??[])>=10)return;
        $this->form['casos'][]=['id'=>(string)\Illuminate\Support\Str::uuid(),'nome'=>'Nova saída','combinacao'=>'todas','regras'=>[['fonte'=>'resposta','campo'=>'','operador'=>'igual','valor'=>'']]];
    }
    public function removerCasoCondicao(int $index): void {if($this->acaoDo($this->acaoAberta)?->tipo!==ChatbotAction::CONDICIONAL)return;unset($this->form['casos'][$index]);$this->form['casos']=array_values($this->form['casos']??[]);}
    public function adicionarRegraCondicao(int $index): void {if($this->acaoDo($this->acaoAberta)?->tipo!==ChatbotAction::CONDICIONAL || !isset($this->form['casos'][$index]) || count($this->form['casos'][$index]['regras']??[])>=10)return;$this->form['casos'][$index]['regras'][]=['fonte'=>'resposta','campo'=>'','operador'=>'igual','valor'=>''];}
    public function removerRegraCondicao(int $caso,int $regra): void {if($this->acaoDo($this->acaoAberta)?->tipo!==ChatbotAction::CONDICIONAL)return;unset($this->form['casos'][$caso]['regras'][$regra]);$this->form['casos'][$caso]['regras']=array_values($this->form['casos'][$caso]['regras']??[]);}
    public function moverCasoCondicao(int $index,int $direcao): void {if($this->acaoDo($this->acaoAberta)?->tipo!==ChatbotAction::CONDICIONAL || !in_array($direcao,[-1,1]))return;$to=$index+$direcao;if(isset($this->form['casos'][$index],$this->form['casos'][$to]))[$this->form['casos'][$index],$this->form['casos'][$to]]=[$this->form['casos'][$to],$this->form['casos'][$index]];}

    private function limparArestasOrfas(ChatbotStep $passo): void
    {
        $passo->refresh()->load('actions');

        $validos = collect($passo->actions)
            ->flatMap(fn (ChatbotAction $a) => $a->handles())
            ->push(ChatbotEdge::SAIDA)
            ->unique()
            ->all();

        $passo->saidas()->whereNotIn('from_handle', $validos)->delete();
    }

    public function removerAcao(int $id): void
    {
        $this->estruturaMudou();

        $acao = $this->acaoDo($id);

        if (! $acao) {
            return;
        }

        $passo = $acao->step;
        $acao->delete();

        if ($passo) {
            $this->limparArestasOrfas($passo);
        }

        if ($this->acaoAberta === $id) {
            $this->acaoAberta = null;
            $this->form = [];
        }
    }

    public function reordenarAcoes(int $stepId, array $ids): void
    {
        $this->estruturaMudou();

        $passo = $this->passoDo($stepId);

        if ($passo) {
            $this->fluxo()->reordenarAcoes($passo, $ids);
        }
    }

    public function adicionarOpcao(): void
    {
        $opcoes = $this->form['opcoes'] ?? [];
        $proximo = (string) (count($opcoes) + 1);

        $this->form['opcoes'] = array_merge($opcoes, [['gatilho' => $proximo, 'rotulo' => '']]);
    }

    public function removerOpcao(int $i): void
    {
        $opcoes = $this->form['opcoes'] ?? [];
        unset($opcoes[$i]);
        $this->form['opcoes'] = array_values($opcoes);
    }

    public function ligar(int $deId, string $handle, int $paraId): void
    {
        $this->estruturaMudou();

        $de = $this->passoDo($deId);
        $para = $this->passoDo($paraId);

        if ($de && $para) {
            $this->fluxo()->ligar($de, $para, $handle);
        }
    }

    public function desligar(int $deId, string $handle): void
    {
        $this->estruturaMudou();

        $this->passoDo($deId)?->saidas()->where('from_handle', $handle)->delete();
    }

    public function desativar(): void
    {
        $this->record->update(['ativo' => false]);
        Notification::make()->success()->title(__('ui_5f3616f1c9ea'))->send();
    }

    public function publicar(): void
    {
        $this->estruturaMudou();

        $problemas = $this->fluxo()->publicar($this->record);

        if ($problemas !== []) {
            Notification::make()
                ->danger()
                ->title(__('ui_c7e71ed36ad9'))
                ->body(implode("\n", array_slice($problemas, 0, 4)))
                ->send();

            return;
        }

        $this->record->refresh();

        Notification::make()
            ->success()
            ->title(__('ui_a04e4a2210b2'))
            ->body(__('ui_ab86dbdd802c').$this->record->versao.'. A partir de agora é este que atende.')
            ->send();
    }

    public function criarExemplo(): void
    {
        $this->estruturaMudou();

        if ($this->record->steps()->where('tipo', ChatbotStep::GRUPO)->exists()) {
            Notification::make()->warning()->title(__('ui_e5ea5e026444'))->send();

            return;
        }

        $this->fluxo()->criarExemplo($this->record);

        Notification::make()->success()->title(__('ui_53a4ec8d07d5'))->send();
    }

    // --------------------------------------------------------------------- apoio

    /**
     * Computed property guarda o resultado dentro do mesmo request. Depois de
     * mexer na estrutura e obrigatorio descartar, senao a tela redesenha com o
     * desenho antigo e o usuario ve a acao "nao ter funcionado".
     */
    private function estruturaMudou(): void
    {
        unset($this->passos, $this->arestas, $this->desenho, $this->problemas);
    }

    private function passoDo(?int $id): ?ChatbotStep
    {
        return $id ? $this->record->steps()->whereKey($id)->first() : null;
    }

    private function acaoDo(?int $id): ?ChatbotAction
    {
        return $id ? $this->record->actions()->whereKey($id)->first() : null;
    }
}
