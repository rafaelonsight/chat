<x-filament-panels::page>
    {{--
        UMA VISTA DE CADA VEZ NO CELULAR, TRES COLUNAS NO COMPUTADOR.

        O atendente trabalha do telefone o dia todo. Ate aqui esta tela era tres paineis lado a
        lado com largura fixa e nenhum ponto de quebra: no celular viravam tres colunas
        espremidas, e nenhuma delas usavel.

        No telefone passa a ser uma vista por vez — lista, conversa ou detalhes — trocando
        sozinha conforme o que a pessoa faz. Nao ha escolha nova para ela: abrir uma conversa ja
        significa querer ve-la, e o caminho de volta e um botao no cabecalho.

        A TROCA VIVE NO ALPINE, NAO NO SERVIDOR. E estado de tela, nao de dados. Passar pelo
        Livewire custaria uma ida ao servidor para mostrar algo que ja esta no navegador — e no
        celular, com rede de rua, e exatamente onde a lentidao aparece.

        O padrao "hidden + lg:flex" resolve sozinho o conflito: o Tailwind emite as variantes de
        breakpoint depois das utilidades base, entao no tamanho grande o lg: vence e as tres
        colunas voltam, independentemente do que o Alpine tenha decidido para o celular.
    --}}
    <div data-inbox
         x-data="{ vista: @js($conversaInicial ? 'conversa' : 'lista') }"
         x-on:abrir-conversa.window="vista = 'conversa'"
         x-on:abrir-detalhes.window="vista = (vista === 'detalhes' ? 'conversa' : 'detalhes')"
         x-on:voltar-para-lista.window="vista = 'lista'"
         x-on:voltar-para-conversa.window="vista = 'conversa'"
         {{-- dvh e nao vh: no celular a barra de endereco aparece e some, e com vh o campo de
              escrever fica escondido atras dela justamente enquanto se digita. --}}
         {{-- No CELULAR a conta continua, e por um motivo que o flex nao resolve: a barra de
              endereco aparece e some, e so o dvh acompanha isso. No COMPUTADOR quem manda e o
              flex do tema — nenhum numero fixo, nenhuma tela em que a conta esteja errada. --}}
         class="vc-workspace relative flex h-[calc(100dvh-7rem)] min-h-[24rem] overflow-hidden rounded-2xl border lg:h-auto">

        {{-- lista --}}
        <div :class="vista === 'lista' ? 'flex' : 'hidden'"
             {{-- 360px em telas amplas, 320px no primeiro breakpoint. A coluna estreita vinha cortando nome de contato no meio
                  ("Lohan Munhoz Property Dev…") e apertando SEIS controles de filtro numa
                  fileira — e aperto sempre cobra de alguem: primeiro quebrou o rotulo de um
                  chip, depois esmagou os seletores ate sobrar so a setinha. O breakpoint
                  progressivo preserva a conversa em notebooks e entrega a fila completa em
                  monitores operacionais. --}}
             class="vc-queue w-full shrink-0 flex-col border-r lg:flex lg:w-80 xl:w-[22.5rem]">
            <livewire:inbox.new-conversation />
            <livewire:inbox.conversation-list :selecionada="$conversaInicial" :balde="$baldeInicial" />
        </div>

        {{-- conversa --}}
        <div :class="vista === 'conversa' ? 'flex' : 'hidden'"
             class="min-w-0 w-full flex-1 flex-col overflow-hidden lg:flex">
            <livewire:inbox.conversation-window :conversation-id="$conversaInicial" />
            @if (! auth()->user()?->ehAuditor())
                <livewire:inbox.message-composer :conversation-id="$conversaInicial" />
            @else
                <div class="border-t border-gray-200 p-4 text-sm text-gray-500">{{ __('ui_516dcbcd3ee6') }}</div>
            @endif
        </div>

        {{--
            display:contents faz este invólucro sumir da montagem, e quem vira coluna e o
            proprio painel de detalhes — que ja sabe se esta aberto ou fechado. Assim o celular
            controla so a VISTA, e o computador continua com a regra de sempre, sem duas
            verdades sobre o mesmo painel.
        --}}
        <div :class="vista === 'detalhes' ? 'contents' : 'hidden'" class="lg:contents">
            <livewire:inbox.contact-details :conversation-id="$conversaInicial" />
        </div>
    </div>

    {{--
        A LISTA DE ATALHOS, chamada por "?".

        Atalho que ninguem conhece e codigo morto. Todo produto que tem atalho tem esta tela, e
        sempre no mesmo lugar — a interrogacao — porque foi assim que as pessoas aprenderam a
        procurar. O aviso discreto no rodape existe para quem nunca pensou em apertar "?".
    --}}
    {{-- display:contents porque este invólucro nao e uma faixa da tela: ele guarda um
         dialogo que aparece flutuando. Como filho normal do flex ele nao tinha altura,
         mas o vao de 32px entre irmaos contava para ele — 32px de faixa morta abaixo
         da bancada, para conter nada. --}}
    <div class="contents" x-data="{ aberta: false }"
         x-on:onchat-atalhos.window="
            if ($event.detail.acao === 'alternar') aberta = ! aberta;
            if ($event.detail.acao === 'fechar') aberta = false;
         ">
        {{--
            A DICA DOS ATALHOS SAIU DAQUI, e foi para dentro da tela vazia ("Selecione uma
            conversa"), que era area morta e virou o lugar de ensinar.

            Aqui ela custava uma linha de altura em TODA sessao, para sempre, para ensinar uma
            coisa uma vez. Lugar de ensinar e onde a pessoa esta parada sem saber o que fazer,
            nao debaixo da bancada onde ela trabalha o dia inteiro.
        --}}

        <div x-show="aberta" x-cloak x-on:click="aberta = false"
             class="fixed inset-0 z-50 grid place-items-center bg-black/40 p-4">
            <div x-on:click.stop
                 class="w-full max-w-sm rounded-xl border border-gray-200 bg-white p-5 shadow-xl dark:border-white/10 dark:bg-gray-900">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ui_1e9500f20441') }}</h2>
                    <button type="button" x-on:click="aberta = false"
                            class="rounded px-2 text-lg leading-none text-gray-400 hover:text-gray-700">&times;</button>
                </div>

                <dl class="space-y-2 text-sm">
                    @foreach ([
                        ['j', 'Próxima conversa'],
                        ['k', 'Conversa anterior'],
                        ['r', 'Responder — vai para o campo de escrever'],
                        ['e', 'Encerrar o atendimento'],
                        ['u', 'Marcar como não lida e voltar'],
                        ['/', 'Buscar conversa'],
                        ['Esc', 'Sair do campo, fechar isto'],
                        ['?', 'Mostrar esta lista'],
                    ] as [$tecla, $oque])
                        <div class="flex items-center gap-3">
                            <kbd class="grid min-w-8 place-items-center rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 font-sans text-xs text-gray-700 dark:border-white/20 dark:bg-white/5 dark:text-gray-200">{{ $tecla }}</kbd>
                            <dd class="text-gray-600 dark:text-gray-300">{{ $oque }}</dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 border-t border-gray-100 pt-3 text-xs text-gray-400 dark:border-white/5">
                    {{ __('ui_5381702d6b10') }}
                </p>
            </div>
        </div>
    </div>

    <span id="onchat-ajuda-atalhos" class="hidden"></span>
</x-filament-panels::page>
