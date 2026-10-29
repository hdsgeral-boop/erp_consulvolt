# Relatório de testes da extensão SaaS

> Fase 9 da extensão SaaS. Última corrida completa: 2026-11-06, `docker exec erp_app_e2e php artisan test`.

## 1. Resultado da suite completa

**1022 testes passados, 4 falhas — nenhuma nova, nenhuma relacionada com esta extensão.**

As 4 falhas são pré-existentes e conhecidas, sem relação com o trabalho desta extensão:

| Teste | Natureza |
|---|---|
| `AutenticacaoTest > limita tentativas de login` | Teste de limitação de tentativas (rate limit), sensível a tempo/estado partilhado entre execuções. |
| `CompressaoCacheRedisTest > conjunto grande fica comprimido...` | Teste de compressão do Redis, sensível ao estado da ligação de cache entre execuções. |
| `EsquemaContratoTest > cada model aponta para a sua...` | Teste de paridade de esquema (cada modelo aponta para a tabela certa), pré-existente. |
| `SegurancaAutorizacaoTest > login tem também um limite...` | Mesma família do primeiro — limitação de tentativas de login. |

**Nota sobre falsos positivos observados durante esta sessão**: a mesma suite, corrida em paralelo com outras
tarefas pesadas (build do frontend, `vitest`), produziu uma vez 31 falhas — 27 delas uma cascata de
`QueryException` em testes de Contabilidade completamente alheios a esta extensão, a partir de um único teste
(`ContabilidadeDecisoesRonda2Test`) que, corrido isolado, passa sem problema. Diagnóstico: contenção de recursos
do ambiente Docker local sob carga simultânea, não uma regressão de código — confirmado ao repetir a suite
completa sem nenhuma tarefa concorrente, obtendo de volta só as 4 falhas conhecidas. Lição para quem correr esta
suite: não a correr em paralelo com builds/outros testes pesados no mesmo ambiente; se aparecerem falhas em
cascata num módulo completamente alheio à mudança feita, suspeitar primeiro de contenção de recursos, confirmar
correndo o teste "paciente zero" isolado antes de investigar o código.

## 2. Cobertura por fase

| Fase | Ficheiros de teste principais |
|---|---|
| 0 — Webhook | `PagamentosWebhookSegurancaTest`, `NotificacoesEGatewaysTest` |
| 1 — Máquina de estados | `MaquinaEstadosLicencaTest` |
| 2 — Suspensão/regularização | `SuspensaoRegularizacaoTest`, `LicencaEmpresaTest` |
| 3 — Sincronização automática | `SincronizarEstadoLicencasCommandTest` |
| 4 — RBAC/MFA | `RbacPlataformaTest`, `MfaTest`, `ServicoTotpTest` |
| 5 — Dashboard executivo | `DashboardExecutivoTest` |
| 6 — Suporte/SLA | `MaquinaEstadosTicketTest`, `SuporteProfissionalizacaoTest`, `ServicoSlaSuporteTest`, `SlaSuporteAvisarCommandTest` |
| 7 — Monitorização | `SaudeTest`, `MonitorizacaoPlataformaTest` |
| 8 — Limites/planos | `PlanoAvancadoTest` |
| 9 — Integração ponta-a-ponta | `CicloCompletoSaasTest` |

## 3. Teste de integração ponta-a-ponta (`CicloCompletoSaasTest`)

Dois cenários, só por chamadas HTTP reais (nunca atalhos internos), ligando fases que os testes isolados não
conectam:

1. **`test_ciclo_completo_registo_pagamento_suspensao_e_regularizacao`**: auto-registo público → período de
   demonstração (`TRIAL`) → plataforma atribui plano → pedido de renovação → confirmação de pagamento (separação
   de funções: o próprio administrador não confirma) → licença `ACTIVA` → o tempo passa, comando diário suspende
   (`SUSPENSA`/`INCUMPRIMENTO`) → leitura continua disponível, escrita bloqueada, suporte continua acessível →
   novo pedido + confirmação regulariza automaticamente → escrita restaurada.
2. **`test_ciclo_completo_suporte_com_sla_transferencia_e_isolamento_entre_clientes`**: dois clientes distintos →
   cliente A abre ticket (SLA calculado na criação) → cliente B nunca o vê (404) → plataforma responde, atribui,
   **transfere** para outro agente com tempo de atendimento registado → resolve e fecha → segundo ticket
   violando o SLA → comando de aviso envia a mensagem **exactamente uma vez**, mesmo correndo duas vezes.

Este teste encontrou e corrigiu um gap real: tickets criados pelo próprio cliente
(`/api/minha-conta/suporte`, `/api/licenca/suporte`) nunca tinham `prazo_sla` calculado — só os criados pela
plataforma em nome do cliente o tinham. Corrigido ao escrever este teste (não um comportamento nunca pedido:
estava implícito desde a Fase 6 que todo o ticket tem SLA).

## 4. Cenário de falha/recuperação garantido por construção (não por teste)

Não existe, por desenho, nenhum caminho de código em que um timeout ou erro de rede com um gateway de pagamento
possa causar uma suspensão indevida: a suspensão é sempre por data (`MaquinaEstadosLicenca::calcular()`) ou por
acção administrativa explícita — nunca por uma consulta automática a um gateway externo (não existe nenhum
processo agendado que consulte gateways). Ver [TENANT_LIFECYCLE.md](TENANT_LIFECYCLE.md) secção 4 e
[BILLING_AND_PAYMENTS.md](BILLING_AND_PAYMENTS.md).

## 5. Frontend

`npx tsc -b` sem erros; `npx vitest run` sobre os módulos tocados (suporte, configuração/plataforma, layout) sem
falhas; `npm run build` sempre executado depois de cada mudança (o `erp_web_e2e` serve de `frontend/dist`, não do
servidor de desenvolvimento Vite).
