<?php
return [
    'mailer' => env('ACESSO_MAILER', 'smtp'),
    // So para homologacao: entra com e-mail e senha em vez do codigo, para quem testa sem caixa de e-mail.
    'senha' => (bool) env('ACESSO_POR_SENHA', false),
];
