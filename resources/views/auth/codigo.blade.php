<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/fontes/archivo.css"><title>{{ __('ui_cc204ba632dd') }}</title>
<style>body{margin:0;min-height:100dvh;display:grid;place-items:center;background:#f6f7f9;color:#141b27;font:15px 'Archivo',Arial,sans-serif}main{box-sizing:border-box;width:min(440px,calc(100% - 32px));padding:32px;background:white;border:1px solid #e8e8e9;border-radius:16px}h1{font-size:24px;margin:8px 0}p{color:#72767d;line-height:1.6}label{display:block;margin:20px 0 8px}input,button{box-sizing:border-box;width:100%;padding:13px;font:inherit;border-radius:8px}input{border:1px solid #c8ced6}button{border:0;background:#6338f6;color:white;margin-top:16px;cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}button.secondary{background:#f1edff;color:#6338f6}input:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #b6a0ff;outline-offset:3px}.error{color:#b42318}.status{background:#f1edff;padding:12px;border-radius:8px;color:#49309b}.brand{font-weight:700;color:#6338f6}small{display:block;margin-top:22px;color:#72767d}.token-boxes{display:flex;gap:8px;margin-top:12px}.token-digit{min-width:0;flex:1;width:0;height:58px;text-align:center;font-size:24px;font-weight:600;padding:8px;border-radius:10px}@media(max-width:360px){.token-boxes{gap:5px}.token-digit{height:48px;font-size:20px}}</style></head>
<body><main><div class="brand">{{ __('ui_543e5bd69e61') }}</div><h1>{{ $painel === 'revenda' ? 'Gestão de contas' : 'Entre no Virtus Chat' }}</h1><p>{{ __('ui_fb7abde809f5') }}</p>
@if(session('status'))<p class="status" role="status">{{ session('status') }}</p>@endif
@if(session('acesso.solicitado') && session('acesso.painel') === $painel)
<p>{{ __('ui_01cec2be406f') }} <strong>{{ session('acesso.email') }}</strong> {{ __('ui_76ad670bd2a3') }}</p>
<form method="post" action="{{ route('acesso.confirmar') }}">@csrf
<label for="token-digit-1">{{ __('ui_bfd35895046d') }}</label>
<input id="codigo" name="codigo" type="hidden">
<div class="token-boxes" role="group" aria-label="{{ __('ui_9c9fc13a9a12') }}" aria-describedby="token-prazo">
@for($digito = 0; $digito < 6; $digito++)
<input id="token-digit-{{ $digito + 1 }}" class="token-digit" type="text" inputmode="numeric" pattern="[0-9]" maxlength="1" aria-label="Número {{ $digito + 1 }} do token" autocomplete="{{ $digito === 0 ? 'one-time-code' : 'off' }}" required @if($digito === 0) autofocus @endif>
@endfor
</div>
<p id="token-prazo">{{ __('ui_dfddd5ea801a') }} <strong id="token-timer" role="timer" data-seconds="{{ $segundosRestantes }}">{{ sprintf('%02d:%02d', intdiv($segundosRestantes, 60), $segundosRestantes % 60) }}</strong>{{ __('ui_f26b1618230b') }}</p>
<p id="token-expirado" class="error" role="status" hidden>{{ __('ui_289068c6afa4') }}</p>
@error('codigo')<p class="error" role="alert">{{ $message }}</p>@enderror
<button>{{ __('ui_42a3aa4eae39') }}</button></form>
<form method="post" action="{{ route('acesso.solicitar') }}">@csrf<input type="hidden" name="painel" value="{{ $painel }}"><input type="hidden" name="email" value="{{ session('acesso.email') }}"><button class="secondary">{{ __('ui_b17a53d55c84') }}</button></form>
@error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
<form method="post" action="{{ route('acesso.trocar-email') }}">@csrf<input type="hidden" name="painel" value="{{ $painel }}"><button class="secondary">{{ __('ui_2e710719888c') }}</button></form>
@else
<form method="post" action="{{ route('acesso.solicitar') }}">@csrf<input type="hidden" name="painel" value="{{ $painel }}"><label for="email">{{ __('ui_0d01e2e86669') }}</label><input id="email" name="email" type="email" autocomplete="email" value="{{ old('email') }}" required autofocus>@error('email')<p class="error" role="alert">{{ $message }}</p>@enderror<button>{{ __('ui_3e5110ac6a84') }}</button></form>
@endif
<small>{{ __('ui_40e4b34f3a19') }}</small></main><script>
(() => {
    const boxes = [...document.querySelectorAll('.token-digit')];
    const value = document.getElementById('codigo');
    if (!value || !boxes.length) return;
    const timer = document.getElementById('token-timer');
    const deadline = performance.now() + Number(timer.dataset.seconds) * 1000;
    const renderTimer = () => {
        const seconds = Math.max(0, Math.ceil((deadline - performance.now()) / 1000));
        timer.textContent = `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
        if (!seconds) {
            document.getElementById('token-expirado').hidden = false;
            value.form.querySelector('button').disabled = true;
        }
    };
    renderTimer();
    setInterval(renderTimer, 1000);
    const sync = () => value.value = boxes.map(box => box.value).join('');
    const insert = (text, index) => {
        const digits = text.replace(/[^0-9]/g, '');
        if (!digits) return;
        const start = digits.length >= 6 ? 0 : index;
        [...digits].slice(0, 6 - start).forEach((digit, offset) => boxes[start + offset].value = digit);
        sync();
        boxes[Math.min(start + digits.length, 5)].focus();
        boxes[Math.min(start + digits.length, 5)].select();
    };
    boxes.forEach((box, index) => {
        box.addEventListener('focus', () => box.select());
        box.addEventListener('beforeinput', event => {
            if (event.data && event.data.length > 1) { event.preventDefault(); insert(event.data, index); }
        });
        box.addEventListener('input', () => {
            const digits = box.value.replace(/[^0-9]/g, '');
            if (digits.length > 1) { insert(digits, index); return; }
            box.value = digits; sync();
            if (digits && index < 5) { boxes[index + 1].focus(); boxes[index + 1].select(); }
        });
        box.addEventListener('paste', event => { event.preventDefault(); insert(event.clipboardData.getData('text'), index); });
        box.addEventListener('keydown', event => {
            if (event.key === 'Backspace' && !box.value && index > 0) {
                event.preventDefault(); boxes[index - 1].value = ''; boxes[index - 1].focus(); sync();
            }
            if (event.key === 'ArrowLeft' && index > 0) { event.preventDefault(); boxes[index - 1].focus(); }
            if (event.key === 'ArrowRight' && index < 5) { event.preventDefault(); boxes[index + 1].focus(); }
        });
    });
    value.form.addEventListener('submit', () => sync());
})();
</script></body></html>
