# Detalhes de integrantes em grupos

## Objetivo

Permitir que atendentes vejam nome, telefone e foto dos integrantes de uma conversa de grupo no painel lateral do Virtus Chat.

## Decisão

Adicionar a aba **Integrantes** em Detalhes do contato. A aba existe para toda conversa, mas informa que o recurso é exclusivo de grupos quando o contato não é um grupo.

## Fluxo e privacidade

A lista é consultada sob demanda na Evolution e fica em cache por cinco minutos. Os dados não são persistidos no banco. Todo atendente que já tem acesso à conversa de grupo pode consultar a aba; não há uma nova permissão paralela ao acesso da conversa.

## Interface

A aba mostra foto quando disponível, nome, telefone copiável, quantidade encontrada e busca por nome ou número. O próprio número do canal não aparece como integrante.

## Validação

Cobertura de teste garante exibição, cópia visual do telefone, exclusão do canal e filtro por nome. Os testes existentes de detalhes e participantes de grupo permanecem verdes.
