<div @class([
        'flex w-80 shrink-0 flex-col overflow-y-auto border-l border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900' => $aberto,
        'hidden' => ! $aberto,
    ])>
    @if ($aberto && $conversa)
        @php
            $contato = $conversa->contact;
            // wa.me quer so digitos. Grupo nao tem numero pessoal: o botao nao
            // apareceria para algo que nao da para abrir.
            $waDigitos = $contato->eGrupo() ? '' : preg_replace('/\D+/', '', (string) $contato->telefone_e164);
        @endphp

        {{-- Cabecalho fixo: quem e o contato fica visivel em qualquer aba, senao
             trocar de aba parece trocar de tela. --}}
        <div class="flex items-start justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
            <div class="flex min-w-0 items-center gap-3">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full text-sm font-semibold {{ $contato->corAvatar() }}">
                    {{ $contato->iniciais() }}
                </span>
                <div class="min-w-0">
                    <p class="truncate font-semibold text-gray-800 dark:text-gray-100">
                        {{ $contato->nomeExibicao() }}
                    </p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Contato desde {{ $contato->created_at?->format('d/m/Y') }}
                    </p>
                </div>
            </div>
            <button type="button" wire:click="fechar"
                    class="shrink-0 rounded px-2 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                    title="Fechar">&times;</button>
        </div>

        @if ($contato->bloqueado() || $contato->arquivado())
            <div class="border-b border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                @if ($contato->bloqueado())
                    <p><strong>Bloqueado</strong> em {{ \Illuminate\Support\Carbon::parse($contato->bloqueado_em)->format('d/m/Y H:i') }}.
                        Nenhuma automação responde; as mensagens dele continuam chegando aqui.</p>
                    @if ($contato->bloqueio_motivo)
                        <p class="mt-0.5 opacity-90">{{ $contato->bloqueio_motivo }}</p>
                    @endif
                @endif
                @if ($contato->arquivado())
                    <p @class(['mt-1' => $contato->bloqueado()])>
                        <strong>Arquivado</strong> em {{ \Illuminate\Support\Carbon::parse($contato->arquivado_em)->format('d/m/Y H:i') }}.
                    </p>
                @endif
            </div>
        @endif

        {{-- Abas em vez de uma coluna unica e rolagem infinita: o atendente
             procura UMA coisa por vez, e anexo de tres meses atras nao pode
             empurrar o telefone para fora da tela. --}}
        <div class="flex border-b border-gray-200 px-2 dark:border-white/10">
            @foreach (\App\Livewire\Inbox\ContactDetails::ABAS as $chave => $rotulo)
                <button type="button" wire:key="aba-{{ $chave }}" wire:click="irPara('{{ $chave }}')"
                        @class([
                            'flex-1 truncate border-b-2 px-1 py-2 text-[11px] font-medium transition',
                            'border-emerald-600 text-emerald-700 dark:border-emerald-400 dark:text-emerald-300' => $aba === $chave,
                            'border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200' => $aba !== $chave,
                        ])>
                    {{ $rotulo }}
                </button>
            @endforeach
        </div>

        {{-- ==================================================== DETALHES ==== --}}
        @if ($aba === 'detalhes')
            <div class="space-y-4 p-4 text-sm">
                {{-- nome editavel: o que vem do WhatsApp e o apelido do cliente,
                     quase sempre inutil para identificar quem e --}}
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400">Nome</label>
                    <div class="flex gap-2">
                        {{-- value explicito: sem ele o campo sai vazio do servidor
                             e so aparece depois que o Livewire hidrata --}}
                        <input type="text" wire:model="nome" value="{{ $nome }}"
                               wire:keydown.enter="salvarNome"
                               class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                        <button type="button" wire:click="salvarNome" wire:loading.attr="disabled"
                                class="shrink-0 rounded bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-emerald-700 disabled:opacity-60">
                            Salvar
                        </button>
                    </div>
                    @error('nome') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <dl class="space-y-3">
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Telefone</dt>
                        <dd class="flex items-center gap-1.5 text-gray-800 dark:text-gray-100">
                            <span class="min-w-0 truncate">{{ $contato->telefone_e164 }}</span>

                            @if ($contato->telefone_e164)
                                <x-inbox.copiar :valor="$contato->telefone_e164" titulo="Copiar telefone" />
                            @endif

                            @if ($waDigitos)
                                {{-- Abre a conversa no WhatsApp do proprio atendente. Serve
                                     para ligar ou mandar audio pelo aparelho, o que o painel
                                     ainda nao faz — nao substitui o envio daqui. --}}
                                <a href="https://wa.me/{{ $waDigitos }}" target="_blank" rel="noopener"
                                   class="shrink-0 rounded p-1 text-emerald-600 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10"
                                   title="Abrir no WhatsApp">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm0 18.15c-1.5 0-2.98-.4-4.27-1.16l-.31-.18-3.12.82.83-3.04-.2-.32a8.19 8.19 0 01-1.26-4.36c0-4.54 3.7-8.24 8.24-8.24s8.24 3.7 8.24 8.24-3.7 8.24-8.15 8.24zm4.52-6.16c-.25-.12-1.47-.72-1.7-.8-.23-.09-.4-.13-.56.12-.17.25-.64.8-.79.97-.14.16-.29.18-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.15-.25-.02-.39.11-.51.11-.11.25-.29.37-.44.12-.14.16-.25.25-.41.08-.17.04-.31-.02-.44-.06-.12-.56-1.35-.77-1.85-.2-.48-.41-.42-.56-.43h-.48c-.16 0-.43.06-.65.31-.23.25-.87.85-.87 2.07 0 1.23.89 2.41 1.01 2.58.12.16 1.74 2.66 4.21 3.73.59.25 1.05.4 1.41.52.59.19 1.13.16 1.55.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.15-1.18-.06-.11-.23-.17-.48-.29z"/>
                                    </svg>
                                </a>
                            @endif
                        </dd>
                    </div>

                    @if ($contato->email)
                        <div>
                            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">E-mail</dt>
                            <dd class="flex items-center gap-1.5 text-gray-800 dark:text-gray-100">
                                <a href="mailto:{{ $contato->email }}" class="min-w-0 truncate hover:underline">{{ $contato->email }}</a>
                                <x-inbox.copiar :valor="$contato->email" titulo="Copiar e-mail" />
                            </dd>
                        </div>
                    @endif

                    @if ($contato->instagram)
                        <div>
                            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Instagram</dt>
                            <dd class="text-gray-800 dark:text-gray-100">
                                <a href="{{ $contato->instagramUrl() }}" target="_blank" rel="noopener"
                                   class="text-pink-600 hover:underline dark:text-pink-400">
                                    &#64;{{ ltrim($contato->instagram, '@') }}
                                </a>
                            </dd>
                        </div>
                    @endif

                    @if ($contato->enderecoResumido())
                        <div>
                            <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Endereço</dt>
                            <dd class="text-gray-800 dark:text-gray-100">
                                {{ $contato->enderecoResumido() }}
                                @if ($contato->cep)
                                    <span class="text-xs text-gray-500">CEP {{ \App\Models\ContactField::formatarCep($contato->cep) }}</span>
                                @endif
                            </dd>
                        </div>
                    @endif

                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Canal</dt>
                        <dd class="text-gray-800 dark:text-gray-100">
                            {{ $conversa->channel->nome }}
                            @if ($conversa->channel->telefone_e164)
                                <span class="text-xs text-gray-500">({{ $conversa->channel->telefone_e164 }})</span>
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">Atendimento</dt>
                        <dd class="text-gray-800 dark:text-gray-100">
                            {{ $conversa->rotuloStatus() }}
                            @if ($conversa->atendente)
                                &middot; {{ $conversa->atendente->name }}
                            @endif
                        </dd>
                    </div>
                </dl>

                {{-- Editar o cadastro completo (endereco, CEP, campos personalizados)
                     e no formulario do contato: duplicar tudo aqui daria duas fontes
                     de verdade para a mesma informacao. --}}
                <a href="{{ route('filament.admin.resources.contacts.edit', ['record' => $contato->getKey()]) }}"
                   class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:underline dark:text-emerald-400">
                    Abrir cadastro completo &rarr;
                </a>

                <div class="rounded border border-gray-200 dark:border-white/10">
                    {{-- Etiquetas: aqui COM nome, ao contrario da lista, onde so a cor
                         aparece para nao roubar espaco do nome do cliente. --}}
                    <div class="px-3 py-3">
                        <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Etiquetas</p>

                        @if ($etiquetas->isEmpty())
                            <p class="text-xs text-gray-400">
                                Nenhuma cadastrada. Crie em <strong>Configurações &rarr; Etiquetas</strong>.
                            </p>
                        @else
                            <div class="flex flex-wrap gap-1">
                                @foreach ($etiquetas as $etiqueta)
                                    @php $posta = in_array($etiqueta->id, $doContato, true); @endphp
                                    <button type="button" wire:key="et-{{ $etiqueta->id }}"
                                            wire:click="alternarEtiqueta({{ $etiqueta->id }})"
                                            @class([
                                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 transition',
                                                $etiqueta->classes() => $posta,
                                                'bg-transparent text-gray-500 ring-gray-200 hover:bg-gray-50 dark:text-gray-400 dark:ring-white/10 dark:hover:bg-white/5' => ! $posta,
                                            ])
                                            title="{{ $posta ? 'Clique para remover' : 'Clique para aplicar' }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $etiqueta->pontinho() }}"></span>
                                        {{ $etiqueta->nome }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Campos personalizados: so os PREENCHIDOS. Este painel e de
                         leitura; linha vazia aqui seria um convite a preencher algo
                         que nao da para editar nesta tela. --}}
                    @if ($camposPreenchidos !== [])
                        <div class="border-t border-gray-100 px-3 py-3 dark:border-white/5">
                            <p class="mb-1.5 text-xs font-medium text-gray-500 dark:text-gray-400">Campos personalizados</p>
                            <dl class="space-y-1.5">
                                @foreach ($camposPreenchidos as $rotulo => $valor)
                                    <div wire:key="cp-{{ \Illuminate\Support\Str::slug($rotulo) }}" class="flex justify-between gap-2 text-xs">
                                        <dt class="shrink-0 text-gray-500 dark:text-gray-400">{{ $rotulo }}</dt>
                                        <dd class="min-w-0 break-words text-right text-gray-800 dark:text-gray-100">{{ $valor }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif

                    {{-- Notas internas: ficam no historico da conversa e NUNCA vao para o
                         cliente. Escreve-se pelo cadeado no compositor. --}}
                    <div class="border-t border-gray-100 px-3 py-3 dark:border-white/5"
                         x-data="{ aberta: {{ $notas->isNotEmpty() ? 'true' : 'false' }} }">
                        <button type="button" x-on:click="aberta = ! aberta"
                                class="mb-1.5 flex w-full items-center justify-between text-xs font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                            <span>Notas internas @if ($notas->isNotEmpty()) ({{ $notas->count() }}) @endif</span>
                            <span x-text="aberta ? '−' : '+'"></span>
                        </button>

                        <div x-show="aberta">
                            @forelse ($notas as $nota)
                                <div wire:key="nota-{{ $nota->id }}" class="mb-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2 py-1.5 dark:border-amber-500/30 dark:bg-amber-500/10">
                                    <p class="whitespace-pre-wrap text-xs text-amber-900 dark:text-amber-200">{{ $nota->descricao }}</p>
                                    <p class="mt-0.5 text-[10px] text-amber-700/80 dark:text-amber-300/70">
                                        {{ $nota->created_at?->format('d/m H:i') }}
                                        @if ($nota->user) &middot; {{ $nota->user->name }} @endif
                                    </p>
                                </div>
                            @empty
                                <p class="text-xs text-gray-400">Nenhuma nota. Use o cadeado no campo de mensagem.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="border-t border-gray-200 px-3 py-2 text-xs font-medium text-gray-500 dark:border-white/10 dark:text-gray-400">
                        Historico
                    </div>
                    <dl class="divide-y divide-gray-100 text-sm dark:divide-white/5">
                        <div class="flex justify-between px-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Mensagens</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $resumo['total'] }}</dd>
                        </div>
                        <div class="flex justify-between px-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Recebidas</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $resumo['recebidas'] }}</dd>
                        </div>
                        <div class="flex justify-between px-3 py-2">
                            <dt class="text-gray-500 dark:text-gray-400">Enviadas</dt>
                            <dd class="text-gray-800 dark:text-gray-100">{{ $resumo['enviadas'] }}</dd>
                        </div>
                        @if ($resumo['primeira'])
                            <div class="flex justify-between px-3 py-2">
                                <dt class="text-gray-500 dark:text-gray-400">Primeiro contato</dt>
                                <dd class="text-gray-800 dark:text-gray-100">
                                    {{ \Illuminate\Support\Carbon::parse($resumo['primeira'])->format('d/m/Y H:i') }}
                                </dd>
                            </div>
                        @endif
                        @if ($resumo['ultima'])
                            <div class="flex justify-between px-3 py-2">
                                <dt class="text-gray-500 dark:text-gray-400">Ultima mensagem</dt>
                                <dd class="text-gray-800 dark:text-gray-100">
                                    {{ \Illuminate\Support\Carbon::parse($resumo['ultima'])->format('d/m/Y H:i') }}
                                </dd>
                            </div>
                        @endif
                        @if ($outrasConversas > 0)
                            <div class="flex justify-between px-3 py-2">
                                <dt class="text-gray-500 dark:text-gray-400">Outros canais</dt>
                                <dd class="text-gray-800 dark:text-gray-100">{{ $outrasConversas }} conversa(s)</dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </div>
        @endif

        {{-- ==================================================== ARQUIVOS ==== --}}
        @if ($aba === 'arquivos')
            <div class="p-4 text-sm">
                @forelse ($arquivos as $arquivo)
                    <a href="{{ $arquivo->midiaUrl() }}" target="_blank" rel="noopener"
                       wire:key="arq-{{ $arquivo->id }}"
                       class="mb-1.5 flex items-center gap-2.5 rounded border border-gray-200 p-2 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                        @if (\Illuminate\Support\Str::startsWith((string) $arquivo->media_mime, 'image/'))
                            {{-- Miniatura de verdade para imagem: o nome do arquivo que
                                 chega do WhatsApp e um hash, e nao diz nada. --}}
                            <img src="{{ $arquivo->midiaUrl() }}" alt=""
                                 class="h-10 w-10 shrink-0 rounded object-cover">
                        @else
                            <span class="grid h-10 w-10 shrink-0 place-items-center rounded bg-gray-100 text-[10px] font-semibold uppercase text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                {{ \Illuminate\Support\Str::limit(pathinfo((string) $arquivo->media_nome, PATHINFO_EXTENSION) ?: 'arq', 4, '') }}
                            </span>
                        @endif

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-medium text-gray-800 dark:text-gray-100">
                                {{ $arquivo->media_nome ?: $arquivo->legenda ?: 'Arquivo' }}
                            </p>
                            <p class="text-[11px] text-gray-500 dark:text-gray-400">
                                {{ $arquivo->entrada() ? 'Recebido' : 'Enviado' }}
                                &middot; {{ $arquivo->created_at?->format('d/m/Y H:i') }}
                                @if ($arquivo->tamanhoLegivel()) &middot; {{ $arquivo->tamanhoLegivel() }} @endif
                            </p>
                        </div>
                    </a>
                @empty
                    <p class="text-xs text-gray-400">Nenhum arquivo nesta conversa.</p>
                @endforelse
            </div>
        @endif

        {{-- =================================================== CONVERSAS ==== --}}
        @if ($aba === 'conversas')
            <div class="p-4 text-sm">
                @forelse ($conversas as $outra)
                    <button type="button" wire:key="conv-{{ $outra->id }}"
                            wire:click="abrirOutra({{ $outra->id }})"
                            class="mb-1.5 block w-full rounded border border-gray-200 p-2 text-left hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
                        <p class="flex items-center justify-between gap-2 text-xs font-medium text-gray-800 dark:text-gray-100">
                            <span class="truncate">{{ $outra->channel?->nome ?? 'Canal removido' }}</span>
                            @if ($outra->nao_lidas > 0)
                                <span class="shrink-0 rounded-full bg-emerald-600 px-1.5 text-[10px] font-semibold text-white">{{ $outra->nao_lidas }}</span>
                            @endif
                        </p>
                        <p class="text-[11px] text-gray-500 dark:text-gray-400">
                            {{ $outra->rotuloStatus() }}
                            @if ($outra->atendente) &middot; {{ $outra->atendente->name }} @endif
                            @if ($outra->ultima_msg_em)
                                &middot; {{ \Illuminate\Support\Carbon::parse($outra->ultima_msg_em)->format('d/m/Y H:i') }}
                            @else
                                &middot; sem mensagem
                            @endif
                        </p>
                    </button>
                @empty
                    <p class="text-xs text-gray-400">
                        Este contato só tem esta conversa. Outras aparecem aqui quando ele
                        escrever por outro canal ou quando esta for encerrada.
                    </p>
                @endforelse
            </div>
        @endif

        {{-- ===================================================== PAINEIS ==== --}}
        @if ($aba === 'paineis')
            <div class="p-4 text-sm">
                {{-- Aba declarada e vazia de proposito, com o motivo escrito: e aqui
                     que os dados dos outros sistemas da empresa vao entrar pela
                     integracao. Aba que finge ter dado e pior que aba vazia. --}}
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Aqui vão aparecer os dados deste cliente que hoje moram nos seus outros
                    sistemas — cadastro, pedidos, pagamentos, agendamentos — sem sair do
                    atendimento.
                </p>
                <p class="mt-2 text-xs text-gray-400">
                    A integração ainda não está ligada. Enquanto isso, consulte no sistema
                    onde esses dados ficam hoje.
                </p>
            </div>
        @endif
    @endif
</div>
