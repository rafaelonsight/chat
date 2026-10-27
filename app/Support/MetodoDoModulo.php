<?php

namespace App\Support;

use Livewire\ComponentHook;

/*
 * Acoes da inbox que pertencem a um modulo. O botao some da tela (Blade), mas a chamada Livewire
 * pode ser forjada no navegador: aqui ela e recusada no servidor.
 */
class MetodoDoModulo extends ComponentHook
{
    private const METODOS = [
        'traduzirMensagem' => 'ia',
        'sugerirEscrita'   => 'ia',
        'sugerirResposta'  => 'ia',
        'gerarResumoIA'    => 'ia',
        'reenviarResumo'   => 'ia',
        'chamarPorVideo'   => 'video',
    ];

    public function call($method, $params, $returnEarly): void
    {
        $modulo = self::METODOS[$method] ?? null;

        if ($modulo && str_starts_with(get_class($this->component), 'App\\Livewire\\Inbox\\')) {
            abort_unless(Modulos::ativo($modulo), 403, 'Recurso fora do plano desta conta.');
        }
    }
}
