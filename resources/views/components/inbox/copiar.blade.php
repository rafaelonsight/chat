@props(['valor', 'titulo' => 'Copiar'])

{{-- Copiar telefone e e-mail e a acao mais repetida do atendimento: quem atende
     precisa colar o dado no sistema do provedor. Selecionar com o mouse dentro de
     um painel estreito erra a selecao metade das vezes.

     O aviso de "Copiado" e obrigatorio: sem retorno visivel, a pessoa clica de
     novo achando que nao funcionou. O clipboard exige contexto seguro (https),
     que e o nosso caso. --}}
<button type="button"
        x-data="{ copiado: false }"
        x-on:click.prevent.stop="
            navigator.clipboard.writeText(@js($valor));
            copiado = true;
            setTimeout(() => copiado = false, 1200);
        "
        x-bind:title="copiado ? 'Copiado' : @js($titulo)"
        class="shrink-0 rounded p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-gray-200">
    <svg x-show="! copiado" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
    </svg>
    <svg x-show="copiado" x-cloak class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
    </svg>
</button>
