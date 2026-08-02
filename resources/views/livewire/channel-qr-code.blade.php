<div @if ($estado !== 'open') wire:poll.5s="atualizar" @endif class="space-y-3 text-center">
    @if ($estado === 'open')
        <p class="font-medium text-emerald-600">Numero conectado.</p>
        <p class="text-xs text-slate-500">Ja pode receber e enviar mensagens pelo inbox.</p>
    @elseif ($estado === 'erro')
        <p class="font-medium text-red-600">Nao consegui falar com a Evolution.</p>
        <p class="text-xs text-slate-500">{{ $channel->ultimo_erro }}</p>
    @elseif ($qrBase64)
        <p class="text-sm text-slate-600">
            No celular: WhatsApp &rarr; Aparelhos conectados &rarr; Conectar aparelho
        </p>
        <img src="{{ $qrBase64 }}" alt="QR Code" class="mx-auto h-64 w-64">
        <p class="text-xs text-slate-400">Estado: {{ $estado }} &middot; o codigo se renova sozinho</p>
    @else
        <p class="text-sm text-slate-500">Gerando QR Code&hellip;</p>
    @endif
</div>
