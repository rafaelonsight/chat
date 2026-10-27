{{-- Paleta agrupada por intencao: a lista plana de sete itens obriga a ler tudo
     para achar o que se quer. --}}
@php
    $grupos = [
        'Inteligência artificial' => ['supervisor_ia','agente_ia'],
        'Mensagem' => ['mensagem','modelo','pergunta','pergunta_dinamica','menu'],
        'Contato' => ['sequencia','etiqueta'],
        'Atendimento' => ['transferir','concluir'],
        'CRM' => ['criar_card','mover_card','alterar_card'],
        'Tempo' => ['aguardar','esperar'],
        'Fluxo' => ['condicional','outro_chatbot'],
        'Avançado' => ['metadados','webhook'],
    ];
    $descricoes = [
        'mensagem'=>'Texto, imagem, vídeo, áudio ou documento.', 'modelo'=>'Template aprovado no WhatsApp oficial.',
        'pergunta'=>'Pergunta e guarda a resposta.', 'pergunta_dinamica'=>'Busca opções numa integração.',
        'menu'=>'Abre caminhos conforme a escolha.', 'sequencia'=>'Inscreve ou remove o contato de uma sequência.',
        'etiqueta'=>'Aplica ou remove etiquetas.', 'transferir'=>'Entrega o atendimento para uma equipe.',
        'concluir'=>'Encerra o atendimento.', 'criar_card'=>'Cria um card no painel.', 'mover_card'=>'Altera a etapa de um card.',
        'alterar_card'=>'Atualiza os dados do card.', 'aguardar'=>'Espera uma nova mensagem do cliente.',
        'esperar'=>'Pausa antes da próxima ação.', 'condicional'=>'Divide o caminho conforme uma regra.',
        'outro_chatbot'=>'Continua em outro fluxo.', 'metadados'=>'Guarda informações para usar no fluxo.',
        'webhook'=>'Consulta uma URL e guarda o resultado.', 'agente_ia'=>'Responde com a IA configurada na conta.',
        'supervisor_ia'=>'Analisa a resposta e decide se precisa de uma pessoa.',
    ];
@endphp

<div class="mb-3 flex gap-2">
    <button type="button" wire:click="copiarItens" class="rounded-lg border px-2 py-1 text-xs">{{ __('ui_dd00ba736dc8') }}</button>
    <button type="button" wire:click="colarItens" @disabled(empty($itensCopiados)) class="rounded-lg border px-2 py-1 text-xs disabled:opacity-40">{{ __('ui_ccb31eddd716') }}</button>
</div>
<div class="space-y-4" x-data="{ busca: '' }">
    <label class="block"><span class="sr-only">{{ __('ui_c5aa6e3c2e8d') }}</span><input type="search" x-model="busca" placeholder="{{ __('ui_581844143451') }}" class="w-full rounded-lg border border-gray-200 px-3 py-2 text-sm"></label>
    @foreach ($grupos as $titulo => $tipos)
        <div>
            <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $titulo }}</p>
            <div class="space-y-1">
                @foreach ($tipos as $tipo)
                    <button type="button" wire:click="adicionarAcao('{{ $tipo }}')"
                            x-show="!busca || @js(mb_strtolower(\App\Models\ChatbotAction::TIPOS[$tipo].' '.$descricoes[$tipo])).includes(busca.toLocaleLowerCase('pt-BR'))" class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-left hover:border-primary-300 hover:bg-primary-50 dark:border-white/10 dark:hover:border-primary-500/40 dark:hover:bg-primary-500/10">
                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">
                            {{ __(\App\Models\ChatbotAction::TIPOS[$tipo]) }}
                        </span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $descricoes[$tipo] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
