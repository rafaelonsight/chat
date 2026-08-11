<?php

namespace App\Livewire\Inbox;

use App\Jobs\SendMediaMessage;
use App\Jobs\SendTextMessage;
use App\Jobs\SendTemplateMessage;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\MetaTemplate;
use App\Services\MediaService;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;
use Livewire\WithFileUploads;

class MessageComposer extends Component
{
    use WithFileUploads;

    public ?int $conversationId = null;

    public string $corpo = '';

    public $anexo = null;

    // Quando ligado, o que for escrito fica interno. O risco aqui e humano:
    // escrever achando que e nota e ir para o cliente. Por isso o modo tem
    // cor propria na tela e se desliga sozinho ao trocar de conversa.
    public bool $nota = false;

    /**
     * Qual mensagem a proxima resposta vai citar.
     *
     * Guarda o id e nao o objeto: propriedade de componente Livewire viaja para o navegador e
     * volta a cada clique, e mandar a mensagem inteira levaria junto corpo, midia e o que mais
     * ela tiver — dado que ja esta no banco, atravessando a rede duas vezes por clique.
     */
    public ?int $respondendoA = null;

    /**
     * Quem a pessoa escolheu chamar na nota, por id.
     *
     * Preenchido pela lista suspensa do "@". Existe alem do texto porque nome digitado e
     * ambiguo e nome escolhido nao e — e porque a tela precisa mostrar ANTES de salvar quem vai
     * ser avisado. Aviso que a pessoa descobre depois de mandar e aviso que ela nao queria.
     *
     * @var array<int, int>
     */
    public array $mencionados = [];

    /** Template escolhido quando a janela de 24h esta fechada: la, texto livre nao sai. */
    public ?int $templateId = null;

    /** Um valor por variavel, na ordem de {{1}}, {{2}}: a Meta recebe parametros posicionais. */
    public array $valoresTemplate = [];

    public function mount(?int $conversationId = null): void
    {
        $this->conversationId = $conversationId;
    }

    public function getListeners(): array
    {
        return [
            'abrir-conversa' => 'abrir',
            'responder-a'    => 'responderA',
        ];
    }

    public function abrir(int $conversationId): void
    {
        // Trocar de conversa apaga a citacao pendente. Sem isto, escolher "responder" numa
        // conversa e mudar para outra deixaria a citacao armada apontando para mensagem de
        // outro cliente — e a checagem de conversa no responderA nao pega isto, porque aqui
        // quem mudou foi a conversa, nao a mensagem.
        $this->respondendoA = null;

        $this->conversationId = $conversationId;
        $this->reset(['corpo', 'anexo', 'nota', 'templateId', 'valoresTemplate']);
    }

    public function responderA(int $messageId): void
    {
        $mensagem = Message::find($messageId);

        // Conferir a conversa nao e paranoia: o evento vem do navegador, e sem isto bastaria
        // forjar um id para citar mensagem de OUTRO cliente — que apareceria no historico
        // desta conversa e, pior, sairia citada no WhatsApp de quem nao devia ver.
        if (! $mensagem || $mensagem->conversation_id !== $this->conversationId) {
            return;
        }

        // Nota interna nao existe do lado de la; citar dentro dela nao significaria nada para
        // o cliente. Volta para o modo de responder, que e o que a pessoa quis dizer.
        $this->nota = false;
        $this->respondendoA = $messageId;
    }

    /**
     * Avisa o cliente que alguem esta escrevendo.
     *
     * Chamado do navegador com estrangulamento de alguns segundos, e nao a cada tecla: uma ida
     * ao servidor por letra digitada seria absurdo para um enfeite.
     *
     * NAO vale para nota interna. A nota nao vai para o cliente, e mostrar "digitando" para
     * ele enquanto o atendente escreve um lembrete interno anuncia uma resposta que nunca vem.
     */
    public function digitando(bool $ativo = true): void
    {
        if ($this->nota || ! $this->conversationId) {
            return;
        }

        $conversa = Conversation::find($this->conversationId);

        if (! $conversa) {
            return;
        }

        $enviador = app(\App\Services\Canais\Enviadores::class)->para($conversa->channel);

        if (! $enviador->podeDigitando()) {
            return;
        }

        $enviador->digitando(
            $conversa->channel,
            $conversa->contact->destinoWhatsApp(),
            $ativo,
        );
    }

    public function cancelarResposta(): void
    {
        $this->respondendoA = null;
    }

    /**
     * Poe o nome de quem escreveu na frente da mensagem, quando a conta pede.
     *
     * Existe porque o cliente ve UM numero, nao uma equipe: sem assinatura, tres pessoas
     * diferentes respondendo parecem a mesma pessoa mudando de ideia.
     *
     * O nome entra no CORPO e nao num campo separado. Isso e proposital: e o que o cliente
     * recebe, e o historico daqui tem de mostrar exatamente o texto que chegou la. Guardar
     * separado faria a bolha e o aparelho do cliente contarem historias diferentes.
     *
     * Nota interna nao assina: ninguem de fora le, e o autor ja aparece do lado.
     */
    private function assinar(string $texto): string
    {
        if ($this->nota || trim($texto) === '') {
            return $texto;
        }

        $conta = \App\Models\Tenant::find(auth()->user()?->tenant_id);

        if (! $conta?->assinatura_ativa) {
            return $texto;
        }

        $nome = trim((string) auth()->user()?->name);

        // O asterisco e negrito no WhatsApp. Primeiro nome so: "Ana Paula Rodrigues da Silva"
        // ocupando uma linha inteira antes de cada resposta cansa em cinco mensagens.
        $primeiro = $nome === '' ? '' : explode(' ', $nome)[0];

        return $primeiro === '' ? $texto : '*'.$primeiro.'*'.PHP_EOL.$texto;
    }

    public function mensagemCitada(): ?Message
    {
        return $this->respondendoA ? Message::find($this->respondendoA) : null;
    }

    public function alternarNota(): void
    {
        $this->nota = ! $this->nota;
        $this->resetErrorBag();

        // Anexo em nota interna nao existe: o arquivo iria para o WhatsApp.
        if ($this->nota) {
            $this->reset('anexo');
        }
    }

    public function updatedAnexo(): void
    {
        $this->validate(['anexo' => 'nullable|file|max:32768']);
    }

    public function removerAnexo(): void
    {
        $this->reset('anexo');
        $this->resetErrorBag('anexo');
    }

    public function enviar(): void
    {
        $this->resetErrorBag();

        if ($this->nota && trim($this->corpo) === '') {
            $this->addError('corpo', 'Escreva a nota.');

            return;
        }

        if (! $this->nota && ! $this->anexo && trim($this->corpo) === '') {
            $this->addError('corpo', 'Escreva algo ou anexe um arquivo.');

            return;
        }

        $conversa = Conversation::findOrFail($this->conversationId);

        if ($this->nota) {
            $this->salvarNota($conversa);

            return;
        }

        $mensagem = $this->anexo
            ? $this->comAnexo($conversa)
            : $this->somenteTexto($conversa);

        if (! $mensagem) {
            return;
        }

        $conversa->update(['ultima_msg_em' => now()]);

        $this->reset(['corpo', 'anexo', 'respondendoA']);
        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    /**
     * Quem foi chamado nesta nota: o escolhido na lista mais o digitado no texto.
     *
     * O CRUZAMENTO COM QUEM PODE VER e o que impede a mencao de virar vazamento pelo avesso:
     * sem ele, bastaria escrever "@fulano" para dar a fulano um aviso com o TEXTO da nota e o
     * nome do cliente de uma conversa que ele nao tem permissao de abrir.
     *
     * O nome digitado casa pelo PRIMEIRO nome, sem acento e sem caixa — e so quando um so
     * usuario casa. Dois "Ana" na equipe e ambiguidade, e adivinhar qual das duas seria pior
     * que nao avisar nenhuma.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\User>
     */
    private function quemFoiChamado(Conversation $conversa): \Illuminate\Support\Collection
    {
        $podem = \App\Models\User::quePodemVer($conversa);

        $escolhidos = $podem->whereIn('id', $this->mencionados);

        $digitados = collect();

        preg_match_all('/@([\p{L}][\p{L}0-9._-]{1,30})/u', (string) $this->corpo, $achados);

        foreach ($achados[1] ?? [] as $pedaco) {
            $casam = $podem->filter(
                fn ($u) => \Illuminate\Support\Str::slug($u->primeiroNome()) === \Illuminate\Support\Str::slug($pedaco),
            );

            if ($casam->count() === 1) {
                $digitados->push($casam->first());
            }
        }

        return $escolhidos->merge($digitados)->unique('id')->values();
    }

    private function salvarNota(Conversation $conversa): void
    {
        $this->validate(['corpo' => 'required|string|max:4000']);

        ConversationEvent::create([
            'conversation_id' => $conversa->id,
            'user_id'         => auth()->id(),
            'tipo'            => ConversationEvent::NOTA,
            'descricao'       => trim($this->corpo),
        ]);

        /*
         * E AVISA QUEM FOI CHAMADO.
         *
         * A lista vem de dois lugares de proposito: do que a pessoa ESCOLHEU na lista suspensa
         * ($this->mencionados) e do que ela DIGITOU no texto. Confiar so na escolha faria
         * quem digita "@celso" direto, sem esperar a lista aparecer, achar que chamou alguem
         * quando nao chamou — silencio com cara de sucesso, que e o pior tipo de erro.
         */
        $chamados = $this->quemFoiChamado($conversa);

        if ($chamados->isNotEmpty()) {
            \App\Support\AvisoDeMencao::enviar(auth()->user(), $conversa, $this->corpo, $chamados);
        }

        // De proposito NAO toca ultima_msg_em: essa coluna responde "quem esta
        // esperando ha mais tempo". Nota nossa subindo a conversa na fila faria
        // a ordenacao mentir sobre o cliente.
        $this->reset(['corpo', 'anexo', 'mencionados']);
        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    private function somenteTexto(Conversation $conversa): ?Message
    {
        $this->validate(['corpo' => 'required|string|max:4000']);

        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => 'text',
            'responde_a_id'            => $this->respondendoA,
            'corpo'           => $this->assinar($this->corpo),
            'status'          => Message::STATUS_QUEUED,
        ]);

        SendTextMessage::dispatch($mensagem->id);

        return $mensagem;
    }

    private function comAnexo(Conversation $conversa): ?Message
    {
        $media = app(MediaService::class);

        try {
            $meta = $media->guardarUpload($conversa, $this->anexo);
        } catch (\Throwable $e) {
            $this->addError('anexo', $e->getMessage());

            return null;
        }

        // Gravacao do navegador vem em webm; sem converter para OGG/Opus o
        // WhatsApp mostra como arquivo anexado em vez de nota de voz.
        if ($meta['tipo'] === 'audio') {
            $convertido = $media->converterParaVoz($meta['path']);

            if ($convertido !== $meta['path']) {
                $meta['path'] = $convertido;
                $meta['mime'] = 'audio/ogg';
                $meta['tamanho'] = Storage::disk('local')->size($convertido);
            }
        }

        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => $meta['tipo'],
            'responde_a_id'   => $this->respondendoA,
            'legenda'         => trim($this->corpo) !== '' ? $this->assinar(trim($this->corpo)) : null,
            'media_path'      => $meta['path'],
            'media_mime'      => $meta['mime'],
            'media_nome'      => $meta['nome'],
            'media_tamanho'   => $meta['tamanho'],
            'status'          => Message::STATUS_QUEUED,
        ]);

        SendMediaMessage::dispatch($mensagem->id);

        return $mensagem;
    }

    public function render()
    {
        $conversa = $this->conversationId
            ? \App\Models\Conversation::with('channel')->find($this->conversationId)
            : null;

        return view('livewire.inbox.message-composer', [
            /*
             * Quem pode ser chamado nesta conversa. So carrega em modo nota: fora dele a lista
             * nao aparece, e buscar usuario em toda renderizacao do compositor seria pagar
             * consulta para uma lista que ninguem vai abrir.
             */
            'mencionaveis' => $this->nota && $conversa
                ? \App\Models\User::quePodemVer($conversa)
                    ->map(fn ($u) => [
                        'id'       => $u->id,
                        'nome'     => $u->name,
                        'primeiro' => $u->primeiroNome(),
                    ])
                    ->all()
                : [],

            'modelos' => $this->conversationId
                ? MessageTemplate::ativos()->orderBy('titulo')->limit(30)->get()
                : collect(),

            // Estado da janela de 24h. Null quando a pergunta nao se aplica — canal
            // sem janela nao deve mostrar aviso de janela.
            'exigeJanela'    => (bool) $conversa?->channel?->exigeJanela(),
            'janelaAberta'   => (bool) $conversa?->janelaAberta(),
            'janelaRestante' => $conversa?->janelaRestante(),

            // Fora da janela, a tela troca de modo: em vez de deixar escrever algo que vai
            // falhar, oferece o unico caminho que funciona.
            'templatesDisponiveis' => $this->templatesDisponiveis($conversa),
            'templateEscolhido'    => $this->templateId
                ? MetaTemplate::enviaveis()->find($this->templateId)
                : null,
        ]);
    }

    public function escolherTemplate(int $id): void
    {
        $modelo = MetaTemplate::enviaveis()->find($id);

        // enviaveis() e nao find() puro: template em analise ou de formato que nao sabemos
        // montar nao pode nem ser escolhido. Barrar na escolha, e nao no envio, poupa o
        // atendente de preencher campos para receber erro no fim.
        if (! $modelo) {
            return;
        }

        $this->templateId = $modelo->id;
        $this->valoresTemplate = array_fill(0, (int) $modelo->variaveis, '');
        $this->resetErrorBag();
    }

    public function limparTemplate(): void
    {
        $this->reset(['templateId', 'valoresTemplate']);
        $this->resetErrorBag();
    }

    public function enviarTemplate(): void
    {
        $this->resetErrorBag();

        $conversa = Conversation::findOrFail($this->conversationId);
        $modelo = MetaTemplate::enviaveis()->find($this->templateId);

        if (! $modelo) {
            $this->addError('template', 'Escolha um template disponível.');

            return;
        }

        $valores = array_map(
            fn ($valor) => trim((string) $valor),
            array_values($this->valoresTemplate),
        );

        foreach ($valores as $i => $valor) {
            // A Meta recusa parametro vazio. Dizer aqui QUAL falta e melhor do que a
            // mensagem sair, falhar e o atendente ter de descobrir na bolha vermelha.
            if ($valor === '') {
                $this->addError('template', 'Preencha o valor '.($i + 1).'.');

                return;
            }
        }

        $mensagem = Message::create([
            'conversation_id' => $conversa->id,
            'channel_id'      => $conversa->channel_id,
            'direcao'         => 'out',
            'tipo'            => 'template',
            // O corpo guarda o texto MONTADO, para o historico mostrar o que o cliente
            // leu. O que sai para a Meta e o nome do template mais os parametros — nunca
            // este texto.
            'corpo'           => $modelo->renderizar($valores),
            'status'          => Message::STATUS_QUEUED,
        ]);

        SendTemplateMessage::dispatch($mensagem->id, $modelo->id, $valores);

        $conversa->update(['ultima_msg_em' => now()]);

        $this->limparTemplate();
        $this->dispatch('abrir-conversa', conversationId: $conversa->id);
    }

    /**
     * Templates que este canal pode enviar agora.
     *
     * Vazio quando a janela esta ABERTA de proposito: ali o atendente manda texto livre,
     * que e melhor para o cliente e nao e cobrado. Oferecer template com a janela aberta
     * seria oferecer o caminho caro sem motivo.
     */
    private function templatesDisponiveis(?Conversation $conversa): \Illuminate\Support\Collection
    {
        $canal = $conversa?->channel;

        if (! $canal || ! $canal->exigeJanela() || $conversa->janelaAberta()) {
            return collect();
        }

        return MetaTemplate::enviaveis()
            ->where('meta_waba_id', (string) $canal->meta_waba_id)
            ->orderBy('nome')
            ->limit(30)
            ->get();
    }

    // Modelo resolvido na hora de usar: o mesmo texto serve para qualquer
    // conversa, com os marcadores trocados pelo contato da vez.
    public function usarModelo(int $id): void
    {
        $modelo = MessageTemplate::ativos()->find($id);

        if (! $modelo) {
            return;
        }

        $this->corpo = $modelo->renderizar(
            Conversation::find($this->conversationId),
            auth()->user(),
        );
    }
}
