{{--
    A BOLHA DO CHAT DA EQUIPE.

    Fica no canto de TODA tela do painel de proposito: falar com um colega e coisa que acontece
    no meio de outra coisa. Se morasse numa pagina propria, sair do atendimento para perguntar
    algo custaria perder o lugar onde se estava — e ninguem perde o lugar, ninguem pergunta.

    QUEM ESTA ONLINE VEM DO CANAL DE PRESENCA, ao vivo. Nao ha coluna "ultimo visto" no banco:
    ela envelheceria sozinha, e a pessoa que fechou o navegador continuaria verde ate alguem
    lembrar de limpar. Aqui, quem cai some.
--}}
<div class="fixed bottom-28 right-3 z-30 print:hidden sm:bottom-20 sm:right-4"
     x-data="{
        online: [],

        init() {
            if (! window.Echo) return;

            window.Echo.join('equipe.{{ auth()->user()->tenant_id }}')
                .here((todos) => { this.online = todos.map((u) => u.id); })
                .joining((u) => { if (! this.online.includes(u.id)) this.online.push(u.id); })
                .leaving((u) => { this.online = this.online.filter((id) => id !== u.id); });
        },

        esta(id) {
            return this.online.includes(id);
        },
     }">

    {{-- ------------------------------------------------------------- o painel --}}
    <div x-show="$wire.aberto" x-cloak x-transition.opacity.duration.150ms
         class="vc-popover mb-3 flex h-[30rem] w-[min(22rem,calc(100vw-2rem))] flex-col overflow-hidden rounded-2xl">

        {{-- cabecalho: lista ou conversa --}}
        @if ($falandoCom)
            <div class="vc-toolbar flex items-center gap-2 border-b px-3 py-2">
                <button type="button" wire:click="voltar"
                        class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10"
                        title="{{ __('ui_1e78117de087') }}">&larr;</button>

                <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-emerald-500/15 text-xs font-semibold text-emerald-700 dark:text-emerald-300">
                    {{ mb_strtoupper(mb_substr($falandoCom->name, 0, 1)) }}
                    <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-gray-900"
                          :class="esta({{ $falandoCom->id }}) ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"></span>
                </span>

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900 dark:text-gray-100">{{ $falandoCom->name }}</p>
                    <p class="text-[11px]"
                       :class="esta({{ $falandoCom->id }}) ? 'text-emerald-600' : 'text-gray-400'"
                       x-text="esta({{ $falandoCom->id }}) ? 'online agora' : 'offline — recebe quando entrar'"></p>
                </div>
            </div>

            {{--
                As mensagens. wire:key com a CONTAGEM dentro: quando chega ou sai mensagem a
                chave muda, o Livewire troca o elemento e o x-init roda de novo, descendo a
                rolagem. Sem isso a conversa cresce para baixo e a ultima linha fica escondida
                logo abaixo da borda — o lugar exato onde ninguem olha.
            --}}
            <div wire:key="recados-{{ $comQuem }}-{{ $conversa->count() }}"
                 x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                 class="vc-thread flex-1 space-y-2 overflow-y-auto p-3">

                @forelse ($conversa as $recado)
                    @php $meu = $recado->de_user_id === auth()->id(); @endphp

                    <div class="flex {{ $meu ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[85%] rounded-lg px-2.5 py-1.5 text-sm
                                    {{ $meu
                                        ? 'vc-message-out'
                                        : 'vc-message-in' }}">
                            <p class="whitespace-pre-wrap break-words">{{ \App\Support\TextoComLinks::render($recado->corpo) }}</p>
                            @if($recado->file_path)
                                @if(str_starts_with($recado->file_mime??'','audio/') || in_array($recado->file_mime,['video/webm','video/mp4']))
                                    <div x-data><audio x-ref="player" @play="document.querySelectorAll('audio').forEach(a=>{if(a!==$el)a.pause()})" controls preload="none" style="max-width:230px" src="{{ route('internal-media',$recado->id) }}"></audio><select aria-label="Velocidade do áudio {{ $recado->id }}" @change="$refs.player.playbackRate=Number($event.target.value)"><option value="1">{{ __('ui_af7b38532554') }}</option><option value="1.5">{{ __('ui_eaef8f00b1d3') }}</option><option value="2">{{ __('ui_26abeae5abe3') }}</option></select></div>
                                @endif
                                <a class="underline" href="{{ route('internal-media',$recado->id) }}" target="_blank" rel="noopener">{{ $recado->file_name }}</a>
                            @endif
                            <p class="mt-0.5 text-[10px] {{ $meu ? 'text-emerald-100/80' : 'text-gray-400' }}">
                                {{ $recado->created_at?->format('d/m H:i') }}
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-2 py-6 text-center text-xs text-gray-400">
                        Nenhum recado ainda. Escreva o primeiro — se {{ $falandoCom->primeiroNome() }} estiver fora, ele vê ao entrar.
                    </p>
                @endforelse
            </div>

            <div class="vc-toolbar border-t p-2" x-data="{rec:null,stream:null,chunks:[],url:null,blob:null,recording:false,error:'',timer:null,ticker:null,seconds:0,cancelled:false,async start(){if(this.recording)return;this.discard();this.cancelled=false;try{this.stream=await navigator.mediaDevices.getUserMedia({audio:true});this.chunks=[];this.rec=new MediaRecorder(this.stream);this.rec.ondataavailable=e=>{if(e.data.size)this.chunks.push(e.data)};this.rec.onstop=()=>{this.recording=false;this.stream.getTracks().forEach(t=>t.stop());if(this.cancelled)return;this.blob=new Blob(this.chunks,{type:this.rec.mimeType});this.url=URL.createObjectURL(this.blob);this.stream.getTracks().forEach(t=>t.stop());this.recording=false};this.rec.start();this.recording=true;this.seconds=0;this.ticker=setInterval(()=>this.seconds++,1000);this.timer=setTimeout(()=>this.stop(),300000)}catch(e){this.error='Não foi possível acessar o microfone. Verifique a permissão ou anexe um áudio.'}},stop(){clearTimeout(this.timer);clearInterval(this.ticker);if(this.rec?.state==='recording')this.rec.stop()},discard(){this.cancelled=true;this.stop();$wire.arquivo=null;if(this.url)URL.revokeObjectURL(this.url);this.url=null;this.blob=null},send(){if(!this.blob)return;let ext=this.blob.type.includes('mp4')?'m4a':(this.blob.type.includes('ogg')?'ogg':'webm');$wire.upload('arquivo',new File([this.blob],'audio.'+ext,{type:this.blob.type}),()=>{this.error='Áudio anexado. Clique em Enviar.'},()=>{this.error='Falha no upload. Tente novamente.'})},destroy(){clearTimeout(this.timer);clearInterval(this.ticker);if(this.stream)this.stream.getTracks().forEach(t=>t.stop());if(this.url)URL.revokeObjectURL(this.url)}}" @internal-media-sent.window="discard()">
                <p x-show="recording" x-text="'Gravando: '+seconds+'s / 300s'" role="status"></p>
                <input type="file" wire:model="arquivo" aria-label="{{ __('ui_32799a17a00f') }}" style="max-width:100%;font-size:11px">
                <div style="display:flex;gap:10px;margin:6px 0"><button type="button" x-show="!recording" @click="start()">{{ __('ui_eb8840e5fb9a') }}</button><button type="button" x-show="recording" x-cloak @click="stop()">{{ __('ui_4228588595d5') }}</button><button type="button" x-show="url" x-cloak @click="send()">{{ __('ui_2fa2096dc28c') }}</button><button type="button" x-show="url" x-cloak @click="discard()">{{ __('ui_63a77c77e07e') }}</button></div>
                <audio x-show="url" x-cloak :src="url" controls style="max-width:100%"></audio><p x-text="error" style="font-size:11px" role="status"></p>
                @if($arquivo)<p class="text-xs">Anexo pronto: {{ $arquivo->getClientOriginalName() }}</p>@endif
                @error('arquivo')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            </div>
            <form wire:submit="enviar" class="vc-toolbar flex items-center gap-2 border-t p-2">
                <input type="text" wire:model="texto" autocomplete="off"
                       placeholder="{{ __('ui_f398ef66b5e8') }}"
                       class="min-w-0 flex-1 rounded border border-slate-300 px-2.5 py-1.5 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100">
                <button type="submit" wire:loading.attr="disabled"
                        class="rounded bg-violet-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-violet-700">
                    {{ __('ui_a91a95517c03') }}
                </button>
            </form>
        @else
            <div class="vc-toolbar flex items-center justify-between border-b px-3 py-2">
                <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ __('ui_e03e3996dc23') }}</p>
                <button type="button" wire:click="$set('aberto', false)"
                        class="rounded px-2 text-lg leading-none text-gray-400 hover:text-gray-700">&times;</button>
            </div>

            @if ($equipes->isNotEmpty())
                <div class="border-b border-gray-100 px-3 py-2 dark:border-white/5">
                    <select wire:model.live="equipe"
                            class="w-full rounded-full border-0 bg-gray-100 py-1 pl-2 pr-6 text-xs font-medium text-gray-700 dark:bg-white/10 dark:text-gray-200">
                        <option value="">{{ __('ui_f2645f822bc1') }}</option>
                        @foreach ($equipes as $eq)
                            <option value="{{ $eq->id }}">{{ $eq->nome }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            {{--
                A LISTA TRAZ TODO MUNDO, e quem esta online sobe para o topo por CSS (order:-1),
                nao por reordenacao no servidor: a presenca muda a toda hora, e uma ida ao
                servidor por pessoa que entra ou sai encheria a rede para mexer numa lista de
                dez linhas.
            --}}
            <div class="flex flex-1 flex-col overflow-y-auto">
                @forelse ($pessoas as $p)
                    <button type="button" wire:click="abrir({{ $p['id'] }})"
                            :style="esta({{ $p['id'] }}) ? 'order:-1' : ''"
                            class="flex w-full items-center gap-2.5 px-3 py-2 text-left hover:bg-gray-50 dark:hover:bg-white/5">

                        <span class="relative grid h-8 w-8 shrink-0 place-items-center rounded-full bg-gray-200 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-200">
                            {{ $p['inicial'] }}
                            <span class="absolute -bottom-0.5 -right-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-gray-900"
                                  :class="esta({{ $p['id'] }}) ? 'bg-emerald-500' : 'bg-gray-300 dark:bg-gray-600'"></span>
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-gray-800 dark:text-gray-100">{{ $p['nome'] }}</span>
                            <span class="block text-[11px]"
                                  :class="esta({{ $p['id'] }}) ? 'text-emerald-600' : 'text-gray-400'"
                                  x-text="esta({{ $p['id'] }}) ? 'online' : 'offline'"></span>
                        </span>

                        @if (($naoLidas[$p['id']] ?? 0) > 0)
                            <span class="grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1.5 text-[11px] font-semibold text-white">
                                {{ $naoLidas[$p['id']] }}
                            </span>
                        @endif
                    </button>
                @empty
                    <p class="p-4 text-center text-xs text-gray-400">
                        @if ($equipe)
                            Ninguém nesta equipe.
                        @else
                            Você é a única pessoa cadastrada por enquanto.
                        @endif
                    </p>
                @endforelse
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------- a bolha --}}
    <button type="button" wire:click="$toggle('aberto')"
            class="relative grid h-12 w-12 place-items-center rounded-2xl border border-violet-400/30 bg-violet-600 text-white shadow-[0_10px_30px_rgba(99,56,246,0.3)] transition duration-200 hover:-translate-y-0.5 hover:bg-violet-700 hover:shadow-[0_14px_34px_rgba(99,56,246,0.38)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 motion-reduce:transform-none"
            title="{{ __('ui_f609a9ad7694') }}" aria-label="{{ __('ui_41652714ed2f') }}">

        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round"
                  d="M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
        </svg>

        {{-- O contador soma TODOS os remetentes: a bolha fechada precisa responder "tem coisa
             para mim?", e nao "de quem". De quem, a lista responde quando abrir. --}}
        @if ($total > 0)
            <span class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[11px] font-bold text-white ring-2 ring-white dark:ring-gray-900">
                {{ $total > 9 ? '9+' : $total }}
            </span>
        @endif
    </button>
</div>
