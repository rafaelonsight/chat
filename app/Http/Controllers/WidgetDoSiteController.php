<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * O arquivo que o site do cliente carrega.
 *
 * SERVIDO PELO APP, e nao entregue para o cliente colar inteiro. A diferenca aparece no dia da
 * primeira correcao: com uma linha de <script>, o conserto chega sozinho em todos os sites que
 * ja instalaram; com o codigo colado, cada cliente carrega para sempre a versao do dia em que
 * instalou.
 */
class WidgetDoSiteController extends Controller
{
    public function __invoke(Request $pedido)
    {
        return response(view('widget')->render())
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // Uma hora: curto para a correcao chegar no mesmo dia, longo para nao virar um
            // pedido por visita.
            ->header('Cache-Control', 'public, max-age=3600')
            ->header('Access-Control-Allow-Origin', '*');
    }
}
