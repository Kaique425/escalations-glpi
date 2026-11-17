# Changelog

## 0.4.0
- Permissões em camadas: direitos por perfil (reconhecer, resolver, silenciar, cancelar, logs, SLO/gaps, ver todos, criar, administrar), editáveis dentro do plugin com modelos de perfil; "somente notificado" e escopo de hierarquias por perfil; exceções por usuário (permitir/negar); papel no nível (escalado ou somente notificado).
- Quem só é notificado recebe e-mail informativo, pop-up informativo e WhatsApp sem a linha de ACK.
- Tela de permissões efetivas de quem aparece nas hierarquias.

## 0.3.0
- Caixa de saída (cópia de cada e-mail, cartão do Teams e mensagem de WhatsApp) e modo simulação por canal.

## 0.2.0
- API para outros plugins (`Api::escalate`, `track`, `resolve`); problemas, mudanças, projetos e tarefas de projeto como origens; análise de gaps; origens ativas configuráveis (por padrão só o GLPI).

## 0.1.0
- Hierarquias com níveis, regras, ACK, e-mail com histórico, pop-up, Teams, WhatsApp, logs rastreáveis com hash encadeado, SLO e SLA, webhook de eventos.
