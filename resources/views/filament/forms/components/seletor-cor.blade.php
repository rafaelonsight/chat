{{-- Seletor de cor da etiqueta: as 24 cores da paleta aparecem como cores de
     verdade. O select antigo mostrava so o nome, e ninguem escolhe "Âmbar"
     sabendo o que vai sair na tela. --}}
@php
    $statePath = $getStatePath();
    // Irmao no mesmo nivel do formulario: 'data.cor' -> 'data.nome'.
    $nomePath = \Illuminate\Support\Str::beforeLast($statePath, 'cor').'nome';
    $paleta = \App\Models\Tag::paletaCompleta();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.$entangle(@js($statePath)),
            nome: $wire.$entangle(@js($nomePath)),
            paleta: @js($paleta),
            chaves: @js(array_keys($paleta)),

            get atual() {
                return this.paleta[this.state] ?? this.paleta[this.chaves[0]]
            },

            get exemplo() {
                return (this.nome ?? '').trim() || 'Etiqueta'
            },

            // Setas andam na paleta sem precisar de 24 paradas de Tab.
            mover(passo) {
                const i = this.chaves.indexOf(this.state)
                const alvo = this.chaves[((i < 0 ? 0 : i) + passo + this.chaves.length) % this.chaves.length]
                this.state = alvo
                this.$nextTick(() => this.$refs.grade?.children[this.chaves.indexOf(alvo)]?.focus())
            },
        }"
        class="space-y-3"
    >
        <div
            x-ref="grade"
            role="radiogroup"
            aria-label="Cor da etiqueta"
            class="grid max-w-max grid-cols-8 gap-2 sm:grid-cols-12"
            x-on:keydown.arrow-right.prevent="mover(1)"
            x-on:keydown.arrow-left.prevent="mover(-1)"
            x-on:keydown.home.prevent="state = chaves[0]"
            x-on:keydown.end.prevent="state = chaves[chaves.length - 1]"
        >
            @foreach ($paleta as $chave => $dados)
                <button
                    type="button"
                    role="radio"
                    title="{{ $dados['label'] }}"
                    aria-label="{{ $dados['label'] }}"
                    x-bind:aria-checked="state === @js($chave) ? 'true' : 'false'"
                    x-bind:tabindex="state === @js($chave) ? 0 : -1"
                    x-on:click="state = @js($chave)"
                    x-bind:class="state === @js($chave)
                        ? 'ring-2 ring-gray-900 ring-offset-2 dark:ring-white dark:ring-offset-gray-900'
                        : 'ring-1 ring-black/10 hover:scale-110 dark:ring-white/20'"
                    class="size-7 rounded-full outline-none transition focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 {{ $dados['dot'] }}"
                ></button>
            @endforeach
        </div>

        {{-- Confere o resultado antes de salvar, com o nome que esta sendo digitado. --}}
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs text-gray-500 dark:text-gray-400">Fica assim:</span>
            <span
                class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1"
                x-bind:class="atual.pill"
                x-text="exemplo"
            ></span>
            <span class="text-xs text-gray-400 dark:text-gray-500" x-text="atual.label"></span>
        </div>
    </div>
</x-dynamic-component>
