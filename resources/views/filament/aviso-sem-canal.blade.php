{{--
    Aparece em TODA tela enquanto faltar algo essencial.

    O motivo e uma ambiguidade que so o cliente novo vive: caixa de entrada vazia porque
    ninguem escreveu hoje e caixa de entrada vazia porque o WhatsApp nunca foi conectado sao
    a MESMA TELA. Sem este aviso, o unico jeito de descobrir a diferenca e telefonar para
    alguem — e esse alguem sou eu.

    So para administrador: atendente nao tem como resolver e o aviso viraria ruido.
--}}
@php
    $servico = app(App\Services\PrimeirosPassos::class);
    $mostrar = auth()->check()
        && auth()->user()->admin
        && ! request()->routeIs('filament.admin.pages.primeiros-passos')
        && $servico->faltamEssenciais() > 0;
@endphp

@if ($mostrar)
    <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 dark:border-red-500/30 dark:bg-red-500/10">
        <x-filament::icon icon="heroicon-o-exclamation-triangle"
                          class="h-5 w-5 shrink-0 text-red-600 dark:text-red-400" />

        <div class="flex-1 text-sm text-gray-800 dark:text-gray-100">
            <strong>O WhatsApp ainda não está conectado.</strong>
            Nenhuma mensagem entra nem sai enquanto isso — a caixa de entrada fica vazia porque
            não há por onde chegar, não porque ninguém escreveu.
        </div>

        <x-filament::button tag="a" size="sm"
            href="{{ route('filament.admin.pages.primeiros-passos') }}">
            Ver o que falta
        </x-filament::button>
    </div>
@endif
