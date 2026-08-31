# Menções em grupos do WhatsApp

## Escopo aprovado

- Disponível somente em conversas de grupo conectadas pela Evolution API.
- `@` abre participantes com nome, telefone e foto quando fornecidos pelo WhatsApp.
- Permite várias pessoas e `@todos`, disponível a todos os atendentes.
- A lista vem do endpoint de participantes da Evolution, fica em cache por cinco minutos e cai para remetentes já vistos no histórico se o provedor falhar.
- O texto visível mantém `@nome`; os JIDs são enviados no campo `mentioned` da Evolution.
- Os JIDs ficam persistidos na mensagem para que reenvios preservem as menções.
- O servidor cruza os JIDs selecionados com a lista atual do grupo antes de enfileirar, evitando valores forjados pelo navegador.

## Fluxo

1. Atendente digita `@` no compositor de um grupo.
2. O navegador carrega participantes sob demanda e filtra localmente.
3. A escolha insere o nome no texto e guarda o JID; `@todos` seleciona todos os JIDs válidos.
4. Ao enviar, o backend revalida e persiste os JIDs.
5. O job converte JIDs em números e envia `mentioned` para a Evolution.

## Compatibilidade e falhas

- Conversas individuais e demais canais continuam sem seletor de participantes.
- Falha ao consultar a Evolution não bloqueia texto comum.
- Nomes históricos complementam participantes sem nome; sem nome conhecido, exibe telefone.
