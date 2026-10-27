@if(app()->getLocale()==='en_GB')
{{ $dados['empresa'] }} — Conversation closed
Reference number: {{ $fim->protocolo }}
Customer: {{ $dados['cliente'] }}
Agent: {{ $dados['atendente'] }}
Subject: {{ $fim->assunto }}
Summary: {{ $fim->resumo }}
Resolution: {{ $fim->solucao }}
Next steps: {{ $fim->pendencias }}
Started: {{ $dados['inicio'] }}
Closed: {{ $dados['fim'] }}
@else
{{ $dados['empresa'] }} — Atendimento finalizado
Protocolo: {{ $fim->protocolo }}
Cliente: {{ $dados['cliente'] }}
Atendente: {{ $dados['atendente'] }}
Assunto: {{ $fim->assunto }}
Resumo: {{ $fim->resumo }}
Solução: {{ $fim->solucao }}
Próximos passos: {{ $fim->pendencias }}
Início: {{ $dados['inicio'] }}
Encerramento: {{ $dados['fim'] }}

@endif
