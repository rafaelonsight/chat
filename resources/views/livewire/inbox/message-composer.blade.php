<div class="border-t border-slate-200 p-3">
    @if ($conversationId)
        {{-- previa do anexo antes de enviar --}}
        @if ($anexo)
            <div class="mb-2 flex items-center gap-3 rounded border border-slate-200 bg-slate-50 p-2">
                @php
                    $mime = method_exists($anexo, 'getMimeType') ? ($anexo->getMimeType() ?? '') : '';
                    $nome = method_exists($anexo, 'getClientOriginalName') ? $anexo->getClientOriginalName() : 'arquivo';
                @endphp

                @if (str_starts_with($mime, 'image/'))
                    <img src="{{ $anexo->temporaryUrl() }}" alt="" class="h-14 w-14 rounded object-cover">
                @elseif (str_starts_with($mime, 'audio/'))
                    <span class="grid h-14 w-14 place-items-center rounded bg-emerald-100 text-emerald-700">&#9835;</span>
                @elseif (str_starts_with($mime, 'video/'))
                    <span class="grid h-14 w-14 place-items-center rounded bg-slate-200 text-slate-600">&#9654;</span>
                @else
                    <span class="grid h-14 w-14 place-items-center rounded bg-slate-200 text-slate-600">&#128196;</span>
                @endif

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm text-slate-700">{{ $nome }}</p>
                    <p class="text-xs text-slate-500">{{ $mime ?: 'arquivo' }}</p>
                </div>

                <button type="button" wire:click="removerAnexo"
                        class="rounded px-2 py-1 text-xs text-red-600 hover:bg-red-50">
                    remover
                </button>
            </div>
        @endif

        <div wire:loading wire:target="anexo" class="mb-2 text-xs text-slate-500">
            enviando arquivo para o servidor&hellip;
        </div>

        <form wire:submit="enviar" class="flex items-end gap-2">
            {{-- anexar --}}
            <label class="cursor-pointer rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                   title="Anexar imagem, video, audio ou documento">
                &#128206;
                <input type="file" wire:model="anexo" class="hidden"
                       accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">
            </label>

            {{-- gravar nota de voz --}}
            <div x-data="gravadorDeVoz()" class="shrink-0">
                <button type="button"
                        x-show="!gravando"
                        x-on:click="iniciar()"
                        class="rounded border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
                        title="Gravar nota de voz">
                    &#127908;
                </button>
                <button type="button"
                        x-show="gravando"
                        x-on:click="parar()"
                        class="rounded bg-red-600 px-3 py-2 text-sm text-white"
                        title="Parar e anexar">
                    <span x-text="'■ ' + segundos + 's'"></span>
                </button>
            </div>

            <input type="text" wire:model="corpo" autocomplete="off"
                   placeholder="{{ $anexo ? 'Legenda (opcional)' : 'Escreva uma mensagem' }}"
                   class="flex-1 rounded border border-slate-300 px-3 py-2 text-sm">

            <button type="submit" wire:loading.attr="disabled" wire:target="enviar,anexo"
                    class="rounded bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                Enviar
            </button>
        </form>

        @error('corpo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('anexo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
    @else
        <p class="text-center text-xs text-slate-400">Selecione uma conversa para responder</p>
    @endif
</div>

@script
<script>
    // Grava pelo navegador e entrega o blob ao Livewire como se fosse upload de
    // arquivo. O webm sai daqui e o servidor converte para OGG/Opus com ffmpeg —
    // sem isso o WhatsApp mostra como arquivo anexado, nao como nota de voz.
    window.gravadorDeVoz = () => ({
        gravando: false,
        segundos: 0,
        rec: null,
        pedacos: [],
        cronometro: null,

        formatoSuportado() {
            const opcoes = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
            return opcoes.find((t) => window.MediaRecorder?.isTypeSupported?.(t)) ?? '';
        },

        async iniciar() {
            if (!navigator.mediaDevices?.getUserMedia) {
                alert('Este navegador nao permite gravar audio.');
                return;
            }

            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            } catch (e) {
                alert('Precisa autorizar o microfone para gravar.');
                return;
            }

            const tipo = this.formatoSuportado();
            this.pedacos = [];
            this.rec = tipo ? new MediaRecorder(stream, { mimeType: tipo }) : new MediaRecorder(stream);

            this.rec.ondataavailable = (e) => {
                if (e.data && e.data.size > 0) this.pedacos.push(e.data);
            };

            this.rec.onstop = () => {
                stream.getTracks().forEach((t) => t.stop());
                clearInterval(this.cronometro);

                const mime = this.rec.mimeType || 'audio/webm';
                const ext = mime.includes('ogg') ? 'ogg' : mime.includes('mp4') ? 'm4a' : 'webm';
                const blob = new Blob(this.pedacos, { type: mime.split(';')[0] });
                const arquivo = new File([blob], `nota-de-voz.${ext}`, { type: mime.split(';')[0] });

                $wire.upload('anexo', arquivo);
            };

            this.rec.start();
            this.gravando = true;
            this.segundos = 0;
            this.cronometro = setInterval(() => {
                this.segundos += 1;
                if (this.segundos >= 300) this.parar(); // teto de 5 min
            }, 1000);
        },

        parar() {
            this.gravando = false;
            if (this.rec && this.rec.state !== 'inactive') this.rec.stop();
        },
    });
</script>
@endscript
