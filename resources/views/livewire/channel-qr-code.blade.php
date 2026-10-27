<div @if ($estado !== 'open') wire:poll.5s="atualizar" @endif class="space-y-3 text-center">
    @if ($estado === 'open')
        <p class="font-medium text-emerald-600">{{ __('ui_2bcab4d473b8') }}</p>
        <p class="text-xs text-slate-500">{{ __('ui_cecaf735d76c') }}</p>
    @elseif ($estado === 'erro')
        <p class="font-medium text-red-600">{{ __('ui_f17b6cf2e7d8') }}</p>
        <p class="text-xs text-slate-500">{{ $channel->ultimo_erro }}</p>
    @elseif ($qrBase64)
        <p class="text-sm text-slate-600">
            {{ __('ui_5125fdc21e96') }}
        </p>
        <img src="{{ $qrBase64 }}" alt="{{ __('ui_44742187d5d4') }}" class="mx-auto h-64 w-64">
        <p class="text-xs text-slate-400">Estado: {{ $estado }} &middot; o codigo se renova sozinho</p>
    @else
        <p class="text-sm text-slate-500">{{ __('ui_65778206e463') }}</p>
    @endif
</div>
