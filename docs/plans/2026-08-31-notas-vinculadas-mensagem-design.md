# Notas internas vinculadas a mensagens

## Experiência aprovada

- Cada mensagem recebe um botão de nota interna ao lado das ações de reação.
- O botão ativa o compositor no modo interno e mostra a prévia da mensagem vinculada.
- É possível registrar várias notas na mesma mensagem e mencionar colaboradores com `@`.
- Acima da mensagem aparece somente um alerta âmbar compacto com contador; o conteúdo fica recolhido por padrão.
- Ao clicar no alerta, as notas expandem com autor, data e conteúdo; outro clique recolhe.
- Notas nunca são enviadas ao cliente.
- Autor, administrador ou operador podem editar e excluir; os demais apenas leem.

## Persistência e segurança

- O vínculo usa `conversation_events.dados.message_id`, preservando notas comuns já existentes e evitando nova tabela.
- O backend confere se mensagem, nota e conversa pertencem ao mesmo atendimento antes de qualquer ação.
- Notas vinculadas deixam a linha cronológica geral e são exibidas exclusivamente sobre sua mensagem.
- A notificação por menção reutiliza o mecanismo atual, que cruza os mencionados com quem pode abrir a conversa.
