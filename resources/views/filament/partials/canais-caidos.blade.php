{{--
    WHATSAPP CAIDO, NA CARA DE QUEM ATENDE.

    So canal que ja esteve conectado (conectado_em) e agora esta fechado: canal recem-criado,
    ainda sem QR lido, nao e queda. "connecting" tambem nao entra: e a Evolution se refazendo.
    Quem e administrador ganha o botao; os demais sabem que nao estao recebendo e a quem pedir.
--}}
@php
    $caidos = \App\Models\Channel::query()
        ->where('tipo', \App\Models\Channel::EVOLUTION)
        ->where('status', 'close')
        ->whereNotNull('conectado_em')
        ->orderBy('nome')
        ->get();
    $podeReconectar = (bool) auth()->user()?->gerenciaUsuarios();
@endphp
@foreach ($caidos as $canal)
    <div role="alert" data-canal-caido="{{ $canal->id }}"
         class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200">
        <span><strong>WhatsApp {{ $canal->nome }} desconectado.</strong>
            As mensagens desse número não estão chegando.
            @unless ($podeReconectar) Avise um administrador da conta. @endunless
        </span>
        @if ($podeReconectar)
            <a href="{{ \App\Notifications\CanalDesconectado::link($canal) }}"
               class="rounded-lg bg-red-600 px-3 py-1.5 font-medium text-white hover:bg-red-700">Reconectar</a>
        @endif
    </div>
@endforeach
