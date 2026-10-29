# Ciclo de vida do cliente/empresa — máquina de estados da licença

> Fase 1-3 da extensão SaaS (`segurança.txt`, secções 4.2, 6, 9, 10). Substitui a antiga inferência por datas
> (`Empresa::licencaExpirada()`, comparação directa a `licenca_fim`) por um estado explícito, validado e auditado.

## 1. Onde vive o estado

- `empresas.estado_licenca` (string) — o estado actual. Nunca se lê `licenca_fim` directamente para decidir se uma
  empresa está bloqueada; lê-se este campo.
- `empresas.motivo_suspensao` — só preenchido quando `estado_licenca = SUSPENSA`; limpo em qualquer outra
  transição.
- `empresas.estado_licenca_desde` — timestamp da última transição.
- **Importante**: o estado *administrativo* (`empresas.estado` = `ATIVO`/`INATIVO`) e o estado *financeiro/da
  licença* (`estado_licenca`) são conceitos distintos — nunca confundir um com o outro (secção 4.2 do documento).

Toda a lógica está em `App\Services\Plataforma\MaquinaEstadosLicenca`.

## 2. Os 9 estados

| Estado | Significado |
|---|---|
| `PENDENTE_ACTIVACAO` | Criada mas ainda não entrou em nenhum período (raramente usado directamente — a maioria das empresas nasce já em `TRIAL` ou `ACTIVA`). |
| `TRIAL` | Período de demonstração (`licenca_origem = TESTE`), licença ainda não vencida. |
| `ACTIVA` | Licença paga (`licenca_origem = PLANO`/`SEM_LIMITE`) e em vigor, ou sem data de fim (`licenca_fim = null`). |
| `PAGAMENTO_PENDENTE` | Existe uma transição manual para este estado (ex.: pagamento em revisão); o cálculo automático nunca o produz por si — ver secção 4. |
| `TOLERANCIA` | Licença vencida há `erp.licencas.dias_tolerancia` dias ou menos (**omissão: 0** — bloqueio imediato, decisão explícita do utilizador; ver ADR-074). |
| `SUSPENSA` | Licença vencida há mais dias que a tolerância (`motivo = INCUMPRIMENTO`), ou suspensão administrativa/de segurança/voluntária decidida pela plataforma. |
| `CANCELADA` | Fim do contrato — dados retidos, nunca eliminados automaticamente. |
| `EM_ENCERRAMENTO` | Período de retenção depois do cancelamento, antes do encerramento definitivo. |
| `ENCERRADA` | Terminal — sem transições possíveis (fim do período de retenção; a política de eliminação de dados fica fora de âmbito desta extensão, por tocar em retenção de dados). |

## 3. Transições permitidas

Tabela exacta em `MaquinaEstadosLicenca::TRANSICOES`. Regras gerais:
- Uma suspensão **exige** um motivo de `Empresa::MOTIVO_{INCUMPRIMENTO,ADMINISTRATIVA,SEGURANCA,VOLUNTARIA}` —
  rejeitada com `MOTIVO_OBRIGATORIO` (422) sem ele.
- Uma transição para um estado diferente do pedido, ou inválida pela tabela, falha com `TRANSICAO_INVALIDA` (422).
- `ENCERRADA` é terminal (array de transições vazio).
- Toda transição é auditada (`ServicoAuditoria::registar`, módulo `Plataforma/Licencas`), com o estado anterior e o
  novo.
- Transição manual via `POST /api/plataforma/saas/empresas/{empresa}/estado-licenca`
  (`PlataformaSaasController::definirEstadoLicenca`), só para a plataforma.

## 4. Cálculo automático vs. decisão manual

`MaquinaEstadosLicenca::calcular(Empresa $e): string` devolve o estado que a empresa **deveria** ter agora, a
partir de `licenca_origem`/`licenca_fim`:
- Nunca deriva automaticamente `CANCELADA`, `EM_ENCERRAMENTO` ou `ENCERRADA` — só por acção administrativa
  explícita.
- Nunca reverte uma `SUSPENSA` cujo motivo não seja `INCUMPRIMENTO` — uma suspensão administrativa/de segurança
  não se desfaz pelo simples passar do tempo.
- `sincronizar(Empresa $e)` aplica `calcular()` e só transiciona se o resultado for diferente do actual
  (idempotente). Chamado:
  - Pelo comando diário `erp:licencas:sincronizar` (Fase 3, `00:30`, `withoutOverlapping()`).
  - Depois de `ServicoLicenca::confirmarPagamento()` (a licença muda de data; o estado tem de reagir no mesmo
    pedido, não esperar pelo comando do dia seguinte).
  - Pela própria `Empresa::booted()` (hook `creating`), para uma empresa nova nascer já no estado certo.

## 5. Acesso durante cada estado (`ResolverEmpresaAtiva`)

Fase 2: nunca bloqueio total. Com `estado_licenca` em `SUSPENSA`/`CANCELADA`/`EM_ENCERRAMENTO`/`ENCERRADA`:
- Pedidos de **leitura** continuam sempre a funcionar.
- Pedidos de **escrita** são bloqueados (`403`, `LICENCA_EXPIRADA`) — **excepto** rotas nomeadas `licenca.*`
  (regularização, consulta de planos, abrir/responder a tickets de suporte) e `plataforma.*` (acção
  administrativa da própria plataforma), que ficam sempre acessíveis.
- O middleware lê o campo persistido `estado_licenca` directamente — nunca recalcula ao vivo (ver nota de
  segurança abaixo).

## 6. Nota de segurança: nunca recalcular `estado_licenca` ao vivo num middleware

Uma versão anterior desta fase tentou recalcular `estado_licenca` a cada pedido em `ResolverEmpresaAtiva` (para
cobrir um caso de um helper de teste que escrevia `licenca_fim` por SQL directo). Isso tinha um efeito secundário
real: uma empresa **suspensa manualmente por incumprimento**, cuja `licenca_fim` ainda não tinha mudado, era
"reactivada" silenciosamente pelo próprio middleware ao ler uma data ainda no futuro — desfazendo a decisão
administrativa sem nenhuma acção de pagamento ter ocorrido. A correcção foi ao contrário: o middleware lê sempre o
estado persistido; qualquer escrita directa em `licenca_fim`/`licenca_origem` (incluindo testes) tem de chamar
`MaquinaEstadosLicenca::sincronizar()` a seguir, nunca confiar em recálculo automático num caminho de leitura.

## 7. Período de teste (trial)

Dias de demonstração: `DefinicoesPlataforma::diasTestePadrao()` (global, `erp.licencas.dias_teste`, omissão 30, ou
`definicoes_plataforma.dias_teste_padrao` em runtime). Desde a Fase 8, um plano pode ter o seu próprio
`PlanoLicenca::dias_teste` — se o tenant tiver um plano com esse campo definido, prevalece sobre o global
(`Empresa::booted()`, hook `creating`). Ver [SUBSCRIPTION_MANAGEMENT.md](SUBSCRIPTION_MANAGEMENT.md).

## 8. Testes

`MaquinaEstadosLicencaTest`, `SuspensaoRegularizacaoTest`, `SincronizarEstadoLicencasCommandTest`,
`LicencaEmpresaTest`, e o fluxo completo em `CicloCompletoSaasTest::test_ciclo_completo_registo_pagamento_suspensao_e_regularizacao`.
