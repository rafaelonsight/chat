{{-- Paleta agrupada por intencao: a lista plana de sete itens obriga a ler tudo
     para achar o que se quer. --}}
@php
    $grupos = [
        'Mensagem'   => [\App\Models\ChatbotAction::MENSAGEM, \App\Models\ChatbotAction::MENU, \App\Models\ChatbotAction::PERGUNTA],
        'Fluxo'      => [\App\Models\ChatbotAction::CONDICIONAL, \App\Models\ChatbotAction::ESPERAR],
        'Atendimento'=> [\App\Models\ChatbotAction::TRANSFERIR, \App\Models\ChatbotAction::CONCLUIR],
    ];

    $descricoes = [
        'mensagem'    => 'Manda um texto e segue.',
        'menu'        => 'Lista opções numeradas e espera a escolha.',
        'pergunta'    => 'Faz uma pergunta e guarda a resposta.',
        'condicional' => 'Divide o caminho conforme uma resposta.',
        'esperar'     => 'Dá uma pausa antes de continuar.',
        'transferir'  => 'Entrega para uma equipe e sai de cena.',
        'concluir'    => 'Encerra o atendimento.',
    ];
@endphp

<div class="space-y-4">
    @foreach ($grupos as $titulo => $tipos)
        <div>
            <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">{{ $titulo }}</p>
            <div class="space-y-1">
                @foreach ($tipos as $tipo)
                    <button type="button" wire:click="adicionarAcao('{{ $tipo }}')"
                            class="block w-full rounded-lg border border-gray-200 px-3 py-2 text-left hover:border-primary-300 hover:bg-primary-50 dark:border-white/10 dark:hover:border-primary-500/40 dark:hover:bg-primary-500/10">
                        <span class="block text-sm font-medium text-gray-800 dark:text-gray-100">
                            {{ \App\Models\ChatbotAction::TIPOS[$tipo] }}
                        </span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $descricoes[$tipo] }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
