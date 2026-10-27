<?php

/*
 * MODULOS POR PLANO.
 *
 * O nucleo (inbox, contatos, canais, equipes, etiquetas, horario, relatorios basicos) existe em
 * todo plano e nao aparece aqui. O que esta aqui liga ou desliga por conta, a partir do plano da
 * licenca e das excecoes gravadas em licenses.modulos.
 *
 * Licenca sem plano vale como 'completo': as contas que ja existiam antes dos planos nao perdem
 * nada no dia em que isto entra.
 */
return [
    'catalogo' => [
        'automacao'  => 'Automação (chatbot e sequências)',
        'campanhas'  => 'Campanhas',
        'vendas'     => 'Vendas (painéis e propostas)',
        'qualidade'  => 'Qualidade do atendimento',
        'ia'         => 'Inteligência artificial',
        'agenda'     => 'Agenda',
        'financeiro' => 'Financeiro e produtos',
        'video'      => 'Vídeo',
        'demandas'   => 'Central de demandas',
    ],

    'planos' => [
        'essencial' => ['nome' => 'Essencial', 'modulos' => ['automacao']],
        'equipe'    => ['nome' => 'Equipe', 'modulos' => ['automacao', 'campanhas', 'vendas', 'qualidade', 'video']],
        'completo'  => ['nome' => 'Completo', 'modulos' => ['automacao', 'campanhas', 'vendas', 'qualidade', 'ia', 'agenda', 'financeiro', 'video', 'demandas']],
    ],

    'plano_padrao' => 'completo',
];
