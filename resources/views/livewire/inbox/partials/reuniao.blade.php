{{--
    A reunião por vídeo dentro da linha do tempo do atendimento.

    NO MEIO DA CONVERSA, e não numa aba separada. A chamada aconteceu num ponto do tempo, e o
    que foi digitado no bate-papo dela — o número de série, o endereço corrigido, o link que o
    técnico colou — é parte do atendimento como qualquer outra coisa que foi dita. Numa aba, só
    quem soubesse que ela existe iria olhar.

    Recolhido por padrão: a chamada em si é o assunto, e a lista de recados é o detalhe de quem
    for atrás.
--}}
@php($chat = $r->messages)

<div class="my-2 flex justify-center">
    <div class="w-full max-w-md rounded-xl border border-amber-200 bg-amber-50/60 px-3 py-2 dark:border-amber-500/25 dark:bg-amber-500/5">
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="text-base leading-none">&#127909;</span>

            <span class="font-medium text-amber-900 dark:text-amber-200">
                {{ $r->aberta() && ! $r->expirada() ? 'Chamada de vídeo em andamento' : 'Chamada de vídeo' }}
            </span>

            <span class="text-amber-700/70 dark:text-amber-300/60">
                {{ $r->comecou_em->format('d/m H:i') }}
                @if ($r->criador) · {{ $r->criador->name }} @endif
            </span>

            @if ($r->aberta() && ! $r->expirada())
                <a href="{{ $r->url() }}" target="_blank" rel="noopener"
                   class="ms-auto rounded-full bg-amber-500 px-2.5 py-1 text-[11px] font-semibold text-gray-950 hover:bg-amber-400">
                    Entrar
                </a>
            @endif
        </div>

        @if ($chat->isNotEmpty())
            <details class="mt-1.5">
                <summary class="cursor-pointer text-[11px] text-amber-800/80 dark:text-amber-300/70">
                    {{ $chat->count() }} {{ $chat->count() === 1 ? 'recado escrito na chamada' : 'recados escritos na chamada' }}
                </summary>

                <div class="mt-1.5 space-y-1.5 border-t border-amber-200/60 pt-1.5 dark:border-amber-500/20">
                    @foreach ($chat as $recado)
                        <div wire:key="rc-{{ $recado->id }}" class="text-xs">
                            <span class="font-medium text-amber-900 dark:text-amber-200">{{ $recado->nome }}</span>
                            <span class="text-amber-700/60 dark:text-amber-300/50">{{ $recado->created_at->format('H:i') }}</span>
                            {{-- break-words: link comprido colado na chamada estoura a coluna
                                 e empurra a conversa inteira para o lado. --}}
                            <p class="whitespace-pre-wrap break-words text-gray-700 dark:text-gray-300">{{ $recado->corpo }}</p>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</div>
