# Arquitectura SaaS — visão geral

> Fase 9 da extensão SaaS (`segurança.txt`, secção 24). Descreve a arquitectura resultante das Fases 0-8 desta
> extensão. Para o procedimento operacional de activar/desligar o SaaS (RLS, variáveis de ambiente), ver
> [SAAS_ATIVACAO.md](SAAS_ATIVACAO.md). Uma só aplicação Laravel, um só frontend React — nunca duas pilhas
> separadas para "ERP" e "plataforma" (secção 23 do documento-fonte).

## 1. Dois níveis de isolamento

O sistema tem **dois** conceitos de "conta", resolvidos por contextos distintos (`App\Support\Tenancy`):

| Conceito | Modelo | Contexto | O que isola |
|---|---|---|---|
| Cliente SaaS (tenant) | `Tenant` | `ContextoTenant` | Utilizadores, tickets de suporte, planos/subscrições — tudo o que pertence ao *cliente*, não a uma empresa dele. |
| Empresa (negócio) | `Empresa` | `ContextoEmpresa` | Dados de negócio (vendas, contabilidade, RH, …) — Row-Level Security do PostgreSQL por `empresa_id`. |

Um tenant pode ter várias empresas (ex.: um grupo económico). `ContextoEmpresa::alinharTenant()` garante que uma
empresa só se cria/usa dentro do tenant a que pertence (`app_tenant_da_empresa()`, função SQL). Com
`erp.saas.ativo=false`, `ContextoTenant` fica inerte (tudo corre como antes do SaaS, tenant único implícito = 1).

RLS é **fail-closed**: qualquer consulta a um modelo `PertenceEmpresa` sem `ContextoEmpresa` activo lança
`ErroContextoEmpresa` (ver `App\Models\Scopes\EscopoEmpresa`), nunca devolve silenciosamente "tudo" ou "nada".
Contrato garantido por `IsolamentoRlsContratoTest`.

## 2. Módulos e limites por plano

- `PlanoLicenca`/`PlanoLicencaVersao` (`App\Models`): catálogo de planos, com versionamento imutável — uma versão
  publicada nunca se altera; para mudar, cria-se um rascunho novo (`ServicoVersoesPlano`). Publicar copia preço,
  `max_empresas`, `max_utilizadores`, `dias_teste` (Fase 8) e a lista de módulos para o `PlanoLicenca` em vigor.
- `App\Support\Tenancy\ModulosPlano`: decide se um módulo de negócio (vendas, stock, RH, …) está incluído no plano
  do tenant. Aplicado no **backend** (`ServicoPermissoes`), nunca só no frontend — um módulo fora do plano fica
  bloqueado na API, não só escondido no menu.
- `App\Services\Plataforma\ServicoLimitesPlano`: limites de `max_empresas`/`max_utilizadores`. Durante o período de
  demonstração (nenhuma empresa com licença paga) o limite é sempre 1/1, independentemente do plano escolhido.

## 3. Ciclo de vida comercial (Fases 1-3)

Ver [TENANT_LIFECYCLE.md](TENANT_LIFECYCLE.md) para a máquina de estados completa (`estado_licenca` em `empresas`,
`App\Services\Plataforma\MaquinaEstadosLicenca`) e [SUBSCRIPTION_MANAGEMENT.md](SUBSCRIPTION_MANAGEMENT.md) para
planos/subscrições/limites.

## 4. Cobrança e pagamentos (Fase 0)

Ver [BILLING_AND_PAYMENTS.md](BILLING_AND_PAYMENTS.md). Resumo: fluxo de cobrança **manual** por decisão explícita
do utilizador (sem geração automática de cobrança) — um super-administrador confirma o pagamento depois de validar
uma transferência bancária ou um webhook de gateway com assinatura HMAC verificada (`ServicoSaude`/
`ProcessadorPagamentos`).

## 5. Back-office (Fases 4, 5 e 7)

Ver [BACKOFFICE_MANAGEMENT.md](BACKOFFICE_MANAGEMENT.md): RBAC granular dos operadores da plataforma (ADR-077
alargado), MFA opcional, dashboard executivo com indicadores financeiros e de suporte, monitorização operacional.

## 6. Suporte técnico e SLA (Fase 6)

Ver [SUPPORT_TICKETING.md](SUPPORT_TICKETING.md) e [SLA_MANAGEMENT.md](SLA_MANAGEMENT.md).

## 7. Decisões deliberadas de não construir

- **Impersonação/acesso assistido**: não existe, de propósito — o próprio documento-fonte recomenda evitá-la. Um
  super-administrador da plataforma que precise de agir "como" um cliente usa `X-Empresa-Id` com a sua própria
  conta (acção administrativa auditada), nunca um login como o utilizador do cliente.
- **Horizon/Telescope**: não instalados — não se justificam à escala actual (um só servidor Docker). Ver
  `App\Services\Sistema\ServicoSaude` para o que já existe (DB/Redis/filas/batimentos).
- **Armazenamento, operações, integrações e funcionalidades premium como dimensões de limite**, e **prorata/
  crédito na troca de plano**: avaliados na Fase 8 e deliberadamente deixados de fora — não têm nenhuma medição
  real no sistema hoje, e o prorata tocaria directamente em facturação (aprovação prévia exigida pelo documento-
  fonte, secção 25). Ver [SUBSCRIPTION_MANAGEMENT.md](SUBSCRIPTION_MANAGEMENT.md) secção "Fora de âmbito".
- **Base de conhecimento** (Fase 10): diferida a pedido explícito do utilizador, sem implicações de segurança.

## 8. Mapa de serviços principais

| Área | Serviços/Controladores |
|---|---|
| Tenancy | `ContextoTenant`, `ContextoEmpresa`, `IsolamentoRls`, `ModulosPlano`, `DefinicoesPlataforma` |
| Licença/estado | `MaquinaEstadosLicenca`, `ServicoLicenca`, `SincronizarEstadoLicencasCommand`, `AvisarLicencasCommand` |
| Planos/limites | `ServicoVersoesPlano`, `ServicoLimitesPlano`, `PlanosPlataformaController` |
| Pagamentos | `ProcessadorPagamentos`, `GatewayReferenciaMulticaixa`, `GatewayMulticaixaExpress`, `ServicoComprovativosPagamento`, `ServicoFaturacaoSaas` |
| Back-office | `OperadoresPlataformaController`, `ServicoPermissoes`, `ServicoTotp`/`MfaController`, `ServicoDashboardExecutivo`, `ServicoSaude`/`MonitorizacaoController` |
| Suporte | `TicketSuporte`, `MaquinaEstadosTicket`, `ServicoSlaSuporte`, `SlaSuporteAvisarCommand`, `SuporteController`, `ConversaSuportePlataformaController` |
