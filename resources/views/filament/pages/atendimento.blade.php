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
    <div x-data="{ vista: 'lista' }"
         x-on:abrir-conversa.window="vista = 'conversa'"
         x-on:abrir-detalhes.window="vista = (vista === 'detalhes' ? 'conversa' : 'detalhes')"
         x-on:voltar-para-lista.window="vista = 'lista'"
         x-on:voltar-para-conversa.window="vista = 'conversa'"
         {{-- dvh e nao vh: no celular a barra de endereco aparece e some, e com vh o campo de
              escrever fica escondido atras dela justamente enquanto se digita. --}}
         class="flex h-[calc(100dvh-10rem)] min-h-[24rem] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm lg:h-[calc(100vh-14rem)] dark:border-white/10 dark:bg-gray-900">

        {{-- lista --}}
        <div :class="vista === 'lista' ? 'flex' : 'hidden'"
             class="w-full shrink-0 flex-col border-r border-gray-200 lg:flex lg:w-80 dark:border-white/10">
            <livewire:inbox.new-conversation />
            <livewire:inbox.conversation-list />
        </div>

        {{-- conversa --}}
        <div :class="vista === 'conversa' ? 'flex' : 'hidden'"
             class="w-full flex-1 flex-col overflow-hidden lg:flex">
            <livewire:inbox.conversation-window />
            <livewire:inbox.message-composer />
        </div>

        {{--
            display:contents faz este invólucro sumir da montagem, e quem vira coluna e o
            proprio painel de detalhes — que ja sabe se esta aberto ou fechado. Assim o celular
            controla so a VISTA, e o computador continua com a regra de sempre, sem duas
            verdades sobre o mesmo painel.
        --}}
        <div :class="vista === 'detalhes' ? 'contents' : 'hidden'" class="lg:contents">
            <livewire:inbox.contact-details />
        </div>
    </div>
</x-filament-panels::page>
