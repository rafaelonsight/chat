{{--
    Icone da plataforma de onde a conversa veio.

    Recebe $plataforma ('whatsapp' | 'instagram' | 'messenger' | 'site') e, opcional, $classe.

    Um arquivo so, usado na lista e no cabecalho: o dia em que o Instagram entrar, o icone
    aparece nos dois lugares sem ninguem precisar lembrar do segundo.

    SVG embutido e nao imagem: nao depende de arquivo em disco que um deploy pode nao copiar,
    nao faz pedido nenhum, e herda tamanho e cor de quem chama. Icone que falha em silencio
    deixa a linha sem a unica informacao que ela precisava dar.

    Caminhos do Simple Icons (CC0), na grade 24x24.
--}}
@php
    $classe = $classe ?? 'h-3.5 w-3.5';

    [$cor, $caminho] = match ($plataforma) {
        'instagram' => [
            'text-[#E4405F]',
            'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z',
        ],
        'messenger' => [
            'text-[#0084FF]',
            'M12 0C5.24 0 0 4.952 0 11.64c0 3.499 1.434 6.522 3.769 8.61.196.175.314.421.325.684l.065 2.137a.72.72 0 0 0 1.011.638l2.384-1.052a.719.719 0 0 1 .48-.03c1.09.301 2.25.463 3.463.463 6.76 0 12-4.952 12-11.64C24 4.952 18.76 0 12 0zm7.209 8.66l-3.525 5.596a1.8 1.8 0 0 1-2.6.481L10.283 12.6a.72.72 0 0 0-.867 0l-3.773 2.864c-.504.382-1.163-.221-.824-.755l3.526-5.596a1.8 1.8 0 0 1 2.6-.48l2.8 2.137a.72.72 0 0 0 .867 0l3.778-2.87c.503-.38 1.162.224.82.758z',
        ],
        /*
            O CHAT DO SITE nao e uma rede social: ele nao tem marca para emprestar cor.
            Entao ele usa a NOSSA — e o desenho e um balao dentro de uma janela de navegador,
            que e literalmente o que a coisa e. Um globo diria "internet", que nao distingue
            nada aqui dentro: tudo aqui vem da internet.
        */
        'site' => [
            'text-amber-500',
            'M21 3H3a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2zm0 16H3V8h18v11zM4.5 6.5a1 1 0 1 1 0-2 1 1 0 0 1 0 2zm3 0a1 1 0 1 1 0-2 1 1 0 0 1 0 2zM7 10.5h10a1 1 0 0 1 1 1v3.5a1 1 0 0 1-1 1h-5.6L8 18.5v-2.5H7a1 1 0 0 1-1-1v-3.5a1 1 0 0 1 1-1z',
        ],

        /*
            O PADRAO DEIXOU DE SER O WHATSAPP.

            Antes, tipo de canal que ninguem tivesse mapeado aqui virava um icone verde de
            WhatsApp — e foi exatamente isso que aconteceu com o chat do site: a conversa
            aparecia com o simbolo de um aplicativo pelo qual ela nunca passou. Icone errado e
            pior que icone nenhum, porque ele nao levanta duvida: quem olha acredita.

            Agora o desconhecido tem cara de desconhecido, e o proximo canal novo aparece
            pedindo para ser mapeado em vez de mentir calado.
        */
        'whatsapp' => [
            'text-[#25D366]',
            'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.966 1.164-.199.199-.397.223-.694.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.174-.298-.019-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L0 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z',
        ],

        default => [
            'text-gray-400',
            'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z',
        ],
    };

    $rotulos = [
        'whatsapp' => 'WhatsApp', 'instagram' => 'Instagram',
        'messenger' => 'Messenger', 'site' => 'Chat no site',
    ];
@endphp

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"
     class="shrink-0 {{ $classe }} {{ $cor }}"
     role="img" aria-label="{{ $rotulos[$plataforma] ?? 'Canal' }}">
    <title>{{ $rotulos[$plataforma] ?? 'Canal' }}</title>
    <path d="{{ $caminho }}" />
</svg>
