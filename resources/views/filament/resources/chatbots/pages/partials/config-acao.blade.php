@php
    $acao = $acaoAberta ? \App\Models\ChatbotAction::find($acaoAberta) : null;
    $T = \App\Models\ChatbotAction::class;
@endphp

@if (! $acao)
    <p class="text-sm text-gray-500">{{ __('ui_8312f4a3d28a') }}</p>
@else
    <form wire:submit="salvarAcao" class="space-y-3">
        <p class="vc-flow-config-title">{{ $acao->rotulo() }}</p>
        <p class="text-xs text-gray-500">{{ __('ui_6206af79aec9') }}</p>

        @if ($acao->tipo === $T::MENSAGEM)
            <label class="block text-xs font-medium">{{ __('ui_d35f04a61007') }}
            <select wire:model.live="form.tipo_midia" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
                @foreach (['text'=>'Texto','image'=>'Imagem','video'=>'Vídeo','audio'=>'Áudio','document'=>'Documento'] as $valor => $nome)<option value="{{ $valor }}">{{ $nome }}</option>@endforeach
            </select></label>
            @if (($form['tipo_midia'] ?? 'text') !== 'text')
                <input type="file" wire:model="arquivoBloco" class="w-full text-sm">
                <div wire:loading wire:target="arquivoBloco" class="text-xs">{{ __('ui_c7198c42424c') }}</div>
                @error('arquivoBloco')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                @if (!empty($form['media_nome']))<p class="text-xs">Arquivo salvo: {{ $form['media_nome'] }}</p>@endif
            @endif
        @endif
        @if (in_array($acao->tipo, ['agente_ia','supervisor_ia']))
            <a href="{{ \App\Filament\Resources\AiAgents\AiAgentResource::getUrl() }}" target="_blank" class="text-xs text-primary-600">{{ __('ui_e9cfe8f19f33') }}</a>
            @if (empty($form['agente_id']) && !empty($form['instrucoes']))
                <p class="text-xs text-gray-500">{{ __('ui_59b551b886da') }}</p>
                <textarea wire:model="form.instrucoes" rows="5" class="w-full rounded-lg border border-gray-300 p-2 text-sm"></textarea>
            @endif
        @endif
        @if(in_array($acao->tipo,['webhook','pergunta_dinamica']))
            <a href="{{ \App\Filament\Resources\ChatbotIntegrations\ChatbotIntegrationResource::getUrl() }}" target="_blank" class="text-xs text-primary-600">{{ __('ui_94b4ab2b9f63') }}</a>
            <p class="text-xs text-gray-500">{{ __('ui_c8dc26db4c0e') }}</p>
        @endif
        @if (in_array($acao->tipo, $T::NOVOS))
            @foreach (\App\Services\BlocosChatbot::campos($acao->tipo) as $chave => [$rotulo, $tipoCampo])
                @if(in_array($acao->tipo,['webhook','pergunta_dinamica']) && !empty($form['integracao_id']) && in_array($chave,['url','timeout']))@continue @endif
                @if ($tipoCampo === 'agentes_multiplos')<div class="block text-xs font-medium">{{ __($rotulo) }}@else<label class="block text-xs font-medium">{{ __($rotulo) }}@endif
                @if ($tipoCampo === 'agentes_multiplos')
                    <div class="space-y-2 rounded-lg border p-2">
                    @foreach(\App\Services\BlocosChatbot::opcoes($tipoCampo,$acao->tenant_id) as $id=>$nome)
                        <label class="flex items-center gap-2 text-xs"><input type="checkbox" wire:model="form.especialistas" value="{{ $id }}">{{ $nome }}</label>
                    @endforeach
                    </div>
                    <p class="text-xs text-gray-500">{{ __('ui_625310c18224') }}</p>
                @elseif ($tipoCampo === 'area')
                    <textarea wire:model="form.{{ $chave }}" rows="4" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm"></textarea>
                @elseif (in_array($tipoCampo, ['text','number','url']))
                    <input type="{{ $tipoCampo }}" wire:model="form.{{ $chave }}" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
                @else
                    <select wire:model.live="form.{{ $chave }}" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
                        <option value="">{{ __('ui_eafa95efa442') }}</option>
                        @foreach (\App\Services\BlocosChatbot::opcoes($tipoCampo, $acao->tenant_id, $acao->chatbot_id) as $id => $nome)
                        <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($tipoCampo === 'agentes_multiplos')</div>@else</label>@endif
                @error('form.'.$chave)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
            @endforeach
            <p class="text-xs text-gray-500">Use variáveis como @{{nome}} e @{{protocolo}}. Integrações e IA usam a configuração desta empresa. A publicação verifica os campos obrigatórios.</p>
        @endif

        @if (in_array($acao->tipo, [$T::MENSAGEM, $T::MENU, $T::PERGUNTA]))
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                    {{ $acao->tipo === $T::MENU ? 'Texto antes das opções' : 'Texto' }}
                </label>
                <textarea wire:model.live.debounce.350ms="form.texto" rows="4"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
            </div>
        @endif

        @if (in_array($acao->tipo, [$T::MENSAGEM, $T::MENU, $T::PERGUNTA]))
            <div class="vc-flow-help">Exemplo: Olá, @{{nome}}! Como podemos ajudar?</div>
            <p class="text-xs text-gray-500">{{ __('ui_1d4d43cc6f3a') }} <code>@{{nome}}</code> {{ __('ui_d908de02d6d5') }} <code>@{{problema}}</code>.</p>
            <div class="vc-flow-preview">
                <span class="vc-flow-preview-label">{{ __('ui_451632f28474') }}</span>
                <div class="vc-flow-bubble">@php $previa = str_replace('{'.'{nome}'.'}', 'Maria', $form['texto'] ?? '') ?: 'Sua mensagem aparecerá aqui.'; @endphp{{ $previa }}
                    @if ($acao->tipo === $T::MENU)
                        @foreach ($form['opcoes'] ?? [] as $opcao)
                            <div>{{ $opcao['gatilho'] ?? '' }}. {{ $opcao['rotulo'] ?? '' }}</div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif

        @if ($acao->tipo === $T::MENU)
            <label class="block text-xs font-medium">{{ __('ui_1021e2ffcfcd') }}
                <select wire:model.live="form.formato" class="mt-1 w-full rounded-lg border border-gray-300 p-2 text-sm">
                    @foreach (\App\Services\MenuChatbot::FORMATOS as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                </select>
            </label>
            <p class="text-xs text-gray-500">{{ __('ui_c41073e06f34') }}</p>
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_4c8df52c061d') }}</label>
                @error('form.opcoes')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                <div class="space-y-1.5">
                    @foreach ($form['opcoes'] ?? [] as $i => $opcao)
                        <div class="flex items-center gap-1.5" wire:key="op-{{ $i }}">
                            <input type="text" wire:model.live.debounce.350ms="form.opcoes.{{ $i }}.gatilho" placeholder="1"
                                   class="w-12 rounded border border-gray-300 px-2 py-1.5 text-center text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                            <input type="text" wire:model.live.debounce.350ms="form.opcoes.{{ $i }}.rotulo" placeholder="{{ __('ui_a5afb2b83b99') }}"
                                   class="min-w-0 flex-1 rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                            <button type="button" wire:click="removerOpcao({{ $i }})"
                                    class="shrink-0 rounded p-1 text-gray-400 hover:text-red-600" aria-label="{{ __('ui_42388eb25df9') }}">
                                <x-filament::icon icon="heroicon-m-x-mark" class="h-3.5 w-3.5" />
                            </button>
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="adicionarOpcao"
                        class="mt-1.5 text-xs font-medium text-primary-600 dark:text-primary-400">{{ __('ui_305ceaf42240') }}</button>
                <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_aa16aa7d0f94') }}
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::MENU)
            {{-- Menu tambem preenche cadastro: "1) Plano 300MB" e uma resposta, nao so
                 um caminho no fluxo. --}}
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                    {{ __('ui_97342bc03a8e') }}
                </label>
                <select wire:model="form.campo_contato"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">{{ __('ui_1a4e4a8ddd7e') }}</option>
                    @foreach ($this->camposDoContato as $grupo => $opcoes)
                        @if (! empty($opcoes))
                            <optgroup label="{{ $grupo }}">
                                @foreach ($opcoes as $chave => $rotulo)
                                    <option value="{{ $chave }}">{{ __($rotulo) }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_b7cbe1015326') }} <strong>{{ __('ui_109ef883bf10') }}</strong> {{ __('ui_e838b14565c2') }}
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::PERGUNTA)
            {{-- O que o cliente responder cai no cadastro dele. E o motivo de existir
                 uma pergunta: perguntar o CPF e nao guardar em lugar nenhum obriga a
                 perguntar de novo no proximo atendimento. --}}
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                    {{ __('ui_d05e12b4dcf3') }}
                </label>
                <select wire:model="form.campo_contato"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                    <option value="">{{ __('ui_1a4e4a8ddd7e') }}</option>
                    @foreach ($this->camposDoContato as $grupo => $opcoes)
                        @if (! empty($opcoes))
                            <optgroup label="{{ $grupo }}">
                                @foreach ($opcoes as $chave => $rotulo)
                                    <option value="{{ $chave }}">{{ __($rotulo) }}</option>
                                @endforeach
                            </optgroup>
                        @endif
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_344732f68f98') }}
                    <strong>{{ __('ui_312ba3ec5cbd') }}</strong>.
                </p>
            </div>

            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">
                    {{ __('ui_4c4e7804dcba') }} <span class="font-normal text-gray-400">(opcional)</span>
                </label>
                <input type="text" wire:model="form.guardar_em" placeholder="problema"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_80fed7e6e25c') }} <code>@{{apelido}}</code>{{ __('ui_c287d8bf2a79') }} <code>@{{cpf}}</code>.
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::ESPERAR)
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_6de9df82d693') }}</label>
                <input type="number" min="1" max="300" wire:model="form.segundos"
                       class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
            </div>
        @endif

        @if ($acao->tipo === $T::CONDICIONAL)
            <label class="block text-xs font-medium">{{ __('ui_29b29040945a') }}
                <select wire:model.live="form.modo" class="mt-1 w-full rounded-lg border p-2"><option value="sim_nao">{{ __('ui_cebaab8a029f') }}</option><option value="multiplo">{{ __('ui_748e9506dfbc') }}</option></select>
            </label>
            @if (($form['modo']??'sim_nao')==='multiplo')
                <p class="text-xs text-gray-500">{{ __('ui_c1ed8d589101') }}</p>
                @error('form.casos')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                @foreach ($form['casos']??[] as $i=>$caso)
                    <div class="space-y-2 rounded-lg border border-gray-200 p-3" wire:key="caso-{{ $caso['id'] }}">
                        <label class="block text-xs font-medium">Saída {{ $i+1 }}<input wire:model="form.casos.{{ $i }}.nome" maxlength="60" placeholder="{{ __('ui_f307f100f536') }}" class="mt-1 w-full rounded border p-2"></label>
                        <div class="flex gap-2 text-xs"><button type="button" wire:click="moverCasoCondicao({{ $i }},-1)">{{ __('ui_4d0bb340a011') }}</button><button type="button" wire:click="moverCasoCondicao({{ $i }},1)">{{ __('ui_9d88821f1448') }}</button><button type="button" wire:click="removerCasoCondicao({{ $i }})" class="text-red-600">{{ __('ui_04caa1bef0ec') }}</button></div>
                        <label class="block text-xs">{{ __('ui_9cd63d01d858') }}<select wire:model="form.casos.{{ $i }}.combinacao" class="mt-1 w-full rounded border p-2"><option value="todas">{{ __('ui_f188b5482084') }}</option><option value="qualquer">{{ __('ui_a52d6aded357') }}</option></select></label>
                        @foreach ($caso['regras']??[] as $j=>$regra)
                            <div class="space-y-2 rounded border border-gray-100 p-2" wire:key="regra-{{ $caso['id'] }}-{{ $j }}-{{ $regra['fonte']??'resposta' }}">
                                <label class="block text-xs">{{ __('ui_c23b590f24f9') }}<select wire:model.live="form.casos.{{ $i }}.regras.{{ $j }}.fonte" class="w-full rounded border p-2"><option value="resposta">{{ __('ui_3dac904e7eb1') }}</option><option value="contato">{{ __('ui_bed27138ba62') }}</option><option value="etiqueta">{{ __('ui_07287eda176e') }}</option></select></label>
                                @if (($regra['fonte']??'resposta')==='etiqueta')
                                    <label class="block text-xs">{{ __('ui_07287eda176e') }}<select wire:model="form.casos.{{ $i }}.regras.{{ $j }}.etiqueta_id" class="w-full rounded border p-2"><option value="">{{ __('ui_eafa95efa442') }}</option>@foreach(\App\Models\Tag::where('tenant_id',$acao->tenant_id)->whereIn('contexto',['contato','conversa'])->orderBy('nome')->get() as $tag)<option value="{{ $tag->id }}">{{ $tag->contexto==='contato'?'Contato':'Atendimento' }} • {{ $tag->nome }}</option>@endforeach</select></label>
                                @elseif (($regra['fonte']??'resposta')==='contato')
                                    <label class="block text-xs">{{ __('ui_9c034704bcab') }}<select wire:model="form.casos.{{ $i }}.regras.{{ $j }}.campo" class="w-full rounded border p-2"><option value="">{{ __('ui_eafa95efa442') }}</option>@foreach(\App\Services\CampoDoContato::agrupadas() as $grupo=>$campos)<optgroup label="{{ $grupo }}">@foreach($campos as $id=>$nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach</optgroup>@endforeach</select></label>
                                @else
                                    <label class="block text-xs">{{ __('ui_122402d41777') }}<input wire:model="form.casos.{{ $i }}.regras.{{ $j }}.campo" placeholder="{{ __('ui_c53e34ee5eca') }}" class="w-full rounded border p-2"></label>
                                @endif
                                <label class="block text-xs">{{ __('ui_db4792f182ba') }}<select wire:model.live="form.casos.{{ $i }}.regras.{{ $j }}.operador" class="w-full rounded border p-2">
                                    @foreach(($regra['fonte']??'')==='etiqueta'?['presente'=>'Possui etiqueta','ausente'=>'Não possui etiqueta']:\App\Services\CondicoesChatbot::OPERADORES as $id=>$nome)<option value="{{ $id }}">{{ $nome }}</option>@endforeach
                                </select></label>
                                @if (($regra['fonte']??'')!=='etiqueta' && !in_array($regra['operador']??'igual',['vazio','preenchido']))<label class="block text-xs">{{ __('ui_8af3b5067da7') }}<input wire:model="form.casos.{{ $i }}.regras.{{ $j }}.valor" class="w-full rounded border p-2"></label>@endif
                                <button type="button" wire:click="removerRegraCondicao({{ $i }},{{ $j }})" class="text-xs text-red-600">{{ __('ui_233a39b892f5') }}</button>
                            </div>
                        @endforeach
                        <button type="button" wire:click="adicionarRegraCondicao({{ $i }})" class="text-xs text-primary-600">{{ __('ui_b52da4e167cb') }}</button>
                    </div>
                @endforeach
                <button type="button" wire:click="adicionarCasoCondicao" class="text-xs font-medium text-primary-600">{{ __('ui_f9bef9f2a81e') }}</button>
                <div class="rounded-lg border p-3 text-xs"><strong>{{ __('ui_312723131f3f') }}</strong><p class="mt-1 text-gray-500">{{ __('ui_2b9bce1ad83f') }}</p></div>
            @else
            <label class="block text-xs">{{ __('ui_ff34fe5dc81c') }}<select wire:model.live="form.fonte" class="w-full rounded border p-2"><option value="resposta">{{ __('ui_3dac904e7eb1') }}</option><option value="etiqueta">{{ __('ui_55d8a77103d8') }}</option></select></label>
            @if(($form['fonte']??'resposta')==='etiqueta')
                <label class="block text-xs">{{ __('ui_07287eda176e') }}<select wire:model="form.etiqueta_id" class="w-full rounded border p-2"><option value="">{{ __('ui_ba8aed357753') }}</option>@foreach(\App\Models\Tag::whereIn('contexto',['contato','conversa'])->orderBy('nome')->get()->groupBy('contexto') as $contexto=>$grupo)<optgroup label="{{ $contexto==='contato'?'Etiquetas do contato':'Etiquetas do atendimento' }}">@foreach($grupo as $et)<option value="{{ $et->id }}">{{ $et->nome }}</option>@endforeach</optgroup>@endforeach</select></label>
                <label class="block text-xs">{{ __('ui_db4792f182ba') }}<select wire:model="form.operador" class="w-full rounded border p-2"><option value="presente">{{ __('ui_f3f12bdecef4') }}</option><option value="ausente">{{ __('ui_4827c5753a0f') }}</option></select></label>
            @else
                <label class="block text-xs">{{ __('ui_3dac904e7eb1') }}<input wire:model="form.campo" placeholder="problema" class="w-full rounded border p-2"></label><label class="block text-xs">{{ __('ui_db4792f182ba') }}<select wire:model="form.operador" class="w-full rounded border p-2"><option value="contem">{{ __('ui_b0471756fe22') }}</option><option value="igual">{{ __('ui_57591825ab8a') }}</option><option value="comeca">{{ __('ui_8259acc4a3d2') }}</option></select></label><label class="block text-xs">{{ __('ui_4271eb8ae4f5') }}<input wire:model="form.valor" placeholder="lento" class="w-full rounded border p-2"></label>
            @endif
            <p class="text-xs text-gray-500">{{ __('ui_699a2e1e22b2') }}</p>
            @endif
        @endif

        @if ($acao->tipo === $T::TRANSFERIR)
            <div class="space-y-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_e03e3996dc23') }}</label>
                    <select wire:model="form.team_id"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100">
                        <option value="">{{ __('ui_a999f5f34267') }}</option>
                        @foreach ($this->equipes as $id => $nome)
                            <option value="{{ $id }}">{{ $nome }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_164219478c59') }}</label>
                    <textarea wire:model="form.aviso" rows="2"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_f78604da359c') }}
                </p>
            </div>
        @endif

        @if ($acao->tipo === $T::ETIQUETA)
            @php $etiquetas = \App\Models\Tag::whereIn('contexto',['contato','conversa'])->orderBy('contexto')->orderBy('nome')->get(); @endphp

            @if ($etiquetas->isEmpty())
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_22366730350d') }}
                    <strong>{{ __('ui_a0fdb7462e71') }}</strong>.
                </p>
            @else
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_fadcf2d5a3d5') }}</label>
                    <div class="space-y-1">
                        @foreach ($etiquetas->groupBy('contexto') as $contexto=>$grupo)
                            <p class="mt-2 text-xs font-semibold">{{ $contexto==='contato'?'Etiquetas do contato':'Etiquetas do atendimento' }}</p>
                            @foreach ($grupo as $et)
                            <label class="flex items-center gap-2 text-xs" wire:key="add-{{ $et->id }}">
                                <input type="checkbox" value="{{ $et->id }}" wire:model="form.adicionar"
                                       class="h-3.5 w-3.5 rounded border-gray-300 text-primary-600">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $et->classes() }}">{{ $et->contexto==='contato'?'Contato':'Atendimento' }} • {{ $et->nome }}</span>
                            </label>
                        @endforeach
                        @endforeach
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_4bd4ea834263') }}</label>
                    <div class="space-y-1">
                        @foreach ($etiquetas->groupBy('contexto') as $contexto=>$grupo)
                            <p class="mt-2 text-xs font-semibold">{{ $contexto==='contato'?'Etiquetas do contato':'Etiquetas do atendimento' }}</p>
                            @foreach ($grupo as $et)
                            <label class="flex items-center gap-2 text-xs" wire:key="rem-{{ $et->id }}">
                                <input type="checkbox" value="{{ $et->id }}" wire:model="form.remover"
                                       class="h-3.5 w-3.5 rounded border-gray-300 text-primary-600">
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 {{ $et->classes() }}">{{ $et->contexto==='contato'?'Contato':'Atendimento' }} • {{ $et->nome }}</span>
                            </label>
                        @endforeach
                        @endforeach
                    </div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('ui_9fc9fc699343') }}
                </p>
            @endif
        @endif

        @if ($acao->tipo === $T::CONCLUIR)
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-200">{{ __('ui_bd1ee380000b') }}</label>
                <textarea wire:model="form.aviso" rows="2"
                          class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-white/20 dark:bg-gray-800 dark:text-gray-100"></textarea>
            </div>
        @endif

        <div class="flex items-center gap-2 border-t border-gray-100 pt-3 dark:border-white/5">
            <x-filament::button type="submit" size="sm">{{ __('ui_93bf2d32ec33') }}</x-filament::button>

            <button type="button"
                    wire:click="removerAcao({{ $acao->id }})"
                    wire:confirm="Remover esta ação?"
                    class="ms-auto text-xs text-red-600 hover:underline">
                {{ __('ui_57fc7282f8e6') }}
            </button>
        </div>
    </form>
@endif
