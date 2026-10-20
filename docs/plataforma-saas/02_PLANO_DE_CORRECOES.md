# Plano de correções

Dá para resolver sem reconstruir o SaaS: a base está boa, falta alinhar segurança, configuração e cobrança numa sequência controlada.

## Fase 1 — Bloquear as falhas críticas (P0)

1. Restringir ao super-administrador do tenant da plataforma:
   - criar e alterar planos;
   - confirmar pagamentos manualmente;
   - simular gateways;
   - configurar gateways.
2. Permitir ao administrador cliente somente: consultar a licença, escolher um plano, iniciar pagamento e consultar os próprios pedidos.
3. Confirmar sempre que a subscrição pertence à empresa ativa.
4. Desativar simulação e confirmação manual em produção.

**Critério:** o cliente deixa de conseguir aprovar a própria licença ou alterar o catálogo global.

## Fase 2 — Tornar a cobrança segura (P0)

Os webhooks devem validar:
- assinatura HMAC;
- timestamp e janela contra replay;
- identificador único do evento;
- gateway correspondente;
- valor e moeda exatos;
- referência e subscrição;
- estado ainda pendente;
- idempotência.

Credenciais dos gateways cifradas com `APP_KEY` e nunca devolvidas pela API. Registar cada tentativa de webhook, inclusive as rejeitadas. O pagamento só prolonga a licença dentro de uma transação com bloqueio da subscrição.

## Fase 3 — Ativar o isolamento real (P1)

Em produção:

```
APP_ENV=production
APP_DEBUG=false
SAAS_ATIVO=true
LICENCAS_ATIVAS=true
DB_USERNAME=erp_app
```

O dono da base fica numa conexão separada (`pgsql_owner`), usada só por migrações e manutenção.

Teste obrigatório antes da ativação:
1. autenticar como tenant A;
2. tentar consultar IDs do tenant B;
3. tentar atualizar e eliminar dados do tenant B;
4. repetir com consultas diretas;
5. confirmar que tudo devolve zero linhas ou acesso negado.

O painel RLS deve validar `current_user`, `row_security`, propriedade das tabelas, `BYPASSRLS` e os dois flags ativos. Só depois pode mostrar "RLS 100%".

## Fase 4 — Corrigir o painel da plataforma (P1)

- As consultas globais da Gestão SaaS usam as funções `SECURITY DEFINER` existentes ou executam no contexto do tenant do cliente.
- Os interruptores deixam de fingir que ativaram o ambiente: mostram o estado efetivo, só leitura. Ligar ou desligar o isolamento durante a execução é perigoso.

## Fase 5 — Unificar planos e licenças (P2)

- Manter só o fluxo versionado "Planos SaaS": rascunho → validação → publicação → plano comercial ativo.
- Remover a edição direta antiga.
- Cada subscrição guarda uma fotografia da versão contratada (preço, moeda, limites, módulos).
- Verificar os módulos no backend: cada API rejeita empresas cujo plano não inclui o módulo.

## Fase 6 — Experiência comercial (P3)

- Mostrar no checkout os dados bancários configurados no painel.
- Atualização controlada após o pagamento Express.
- Histórico e comprovativo.
- Mudança de plano, renovação, cancelamento e período de carência.
- Notificações de pagamento aprovado, recusado e licença a expirar.
- Monitor de webhooks, pagamentos pendentes e falhas de gateway.

## Ordem de execução

| Prioridade | Trabalho | Critério para avançar |
| --- | --- | --- |
| P0 | Autorização e cobrança | Cliente não altera planos nem confirma pagamentos |
| P0 | Webhooks seguros | Assinatura, valor e idempotência testados |
| P1 | RLS real | Teste cruzado entre tenants aprovado |
| P1 | Consultas da plataforma | Painel enxerga todos os clientes sob RLS |
| P2 | Planos unificados | Uma única fonte de catálogo e versões |
| P2 | Módulos por plano | APIs aplicam os limites contratados |
| P3 | Fluxo comercial | Pagamento e renovação sem intervenção manual |

A ativação em produção só deve acontecer depois das fases P0 e P1 e de um backup completo.
