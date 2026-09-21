# Detalhes de integrantes em grupos

## Objetivo

Permitir que atendentes vejam nome, telefone e foto dos integrantes de uma conversa de grupo no painel lateral do Virtus Chat.

## Decisão

Adicionar a aba **Integrantes** em Detalhes do contato. A aba existe para toda conversa, mas informa que o recurso é exclusivo de grupos quando o contato não é um grupo.

## Fluxo e privacidade

A lista é consultada sob demanda na Evolution e fica em cache por cinco minutos. Os dados não são persistidos no banco. Todo atendente que já tem acesso à conversa de grupo pode consultar a aba; não há uma nova permissão paralela ao acesso da conversa.

## Interface

A aba mostra foto quando disponível, nome, telefone copiável, quantidade encontrada e busca por nome ou número. O próprio número do canal não aparece como integrante.

Ao clicar em um integrante, abre um mini perfil com os mesmos dados e o botão **Abrir conversa privada**. O botão reaproveita uma conversa individual já aberta no mesmo canal ou cria uma nova, sem enviar mensagem automaticamente. Antes de abrir, o servidor valida novamente que o JID ainda pertence ao grupo, para que dados alterados pelo navegador não criem conversas arbitrárias.

## Validação

Cobertura de teste garante exibição, cópia visual do telefone, exclusão do canal, filtro por nome, mini perfil e abertura da conversa sem mensagem. Os testes existentes de detalhes e participantes de grupo permanecem verdes.
