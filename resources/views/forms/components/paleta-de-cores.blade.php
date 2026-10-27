<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
<div x-data="{cor: $wire.$entangle('{{ $getStatePath() }}')}" class="vc-color-picker">
 <div class="vc-color-grid" role="group" aria-label="{{ __('ui_4cab51e5d5f4') }}">
 @foreach($getCores() as $nome=>$hex)
 <button type="button" class="vc-color-swatch" style="--swatch:{{ $hex }};color:{{ in_array($nome,['lavanda','lima','amarelo','coral']) ? '#141B27' : '#FFFFFF' }}" x-on:click="cor='{{ $nome }}'" :aria-pressed="(cor || 'roxo') === '{{ $nome }}'" :class="{'is-selected':(cor || 'roxo') === '{{ $nome }}'}" aria-label="{{ __(\App\Models\User::CORES_CHAT[$nome]) }}" title="{{ __(\App\Models\User::CORES_CHAT[$nome]) }}" @disabled($isDisabled())><span x-show="(cor || 'roxo') === '{{ $nome }}'" aria-hidden="true">✓</span></button>
 @endforeach
 </div><p class="vc-color-preview" :class="'vc-user-color-'+(cor || 'roxo')">{{ __('ui_69e3f3a31a44') }}</p>
</div></x-dynamic-component>