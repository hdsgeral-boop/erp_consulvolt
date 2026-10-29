# SLA de suporte: prazos, violações, transferência e tempo de atendimento

> Fase 6, parte 2, da extensão SaaS (`segurança.txt`, secção 14). Ver [SUPPORT_TICKETING.md](SUPPORT_TICKETING.md)
> para o modelo de tickets, estados e categorias.

## 1. Prazo por prioridade

`TicketSuporte::SLA_HORAS`: `URGENTE` 4h, `ALTA` 24h, `NORMAL` 72h, `BAIXA` 120h. Guardado em
`tickets_suporte.prazo_sla` na criação do ticket — **sempre**, seja o ticket criado pela plataforma
(`SuporteController::store`) ou pelo próprio cliente (`MinhaContaController::abrirSuporte`,
`LicencaController::abrirSuporte`; estes dois sempre com prioridade `NORMAL`, já que o cliente não escolhe
prioridade ao abrir um pedido).

## 2. Consciente de dias úteis e feriados

`App\Services\Plataforma\ServicoSlaSuporte::calcularPrazo(string $prioridade, ?Carbon $desde = null): Carbon`:
soma as horas do SLA ao relógio normalmente e, se o resultado cair num dia não útil (fim-de-semana ou feriado),
desliza para o mesmo horário no próximo dia útil (até 14 tentativas). **Honesto sobre o que não faz**: o sistema
não tem, em lado nenhum, um horário de expediente configurado (hora de abertura/fecho) — só dias úteis e
feriados. Inventar uma granularidade horária que não existe violaria a instrução do próprio documento-fonte de
nunca inventar sem verificar primeiro; o ganho real sobre o comportamento anterior (que contava tempo corrido
24/7, incluindo fins-de-semana) já é significativo sem isso.

Reaproveita o motor já existente do módulo de RH (`App\Services\RH\ServicoCalendarioRH`,
`ConfigAssiduidade.dias_uteis`/`feriados`) em vez de duplicar um calendário novo — a mesma fonte usada pela
assiduidade e férias (ADR-038).

**Sem empresa activa** (o caso normal da criação de um ticket, que é ao nível do tenant, não de uma empresa
específica): `ConfigAssiduidade` tem RLS por `empresa_id` e exige um contexto activo para ser consultada
(`ContextoEmpresa::definida()`); sem ele, `ServicoSlaSuporte` usa o calendário por omissão
(`ServicoCalendarioRH::PADRAO`, segunda a sexta, sem feriados) em vez de deixar o pedido rebentar com
`ErroContextoEmpresa` — **esta verificação é a correcção de uma falha real** encontrada ao escrever o teste de
integração: a primeira versão desta fase rebentava com `500` sempre que um ticket era criado fora de um contexto
de empresa, o que é o caso comum.

## 3. Alertas de violação (`erp:suporte:avisar-sla`)

Comando agendado (cada 15 minutos, `withoutOverlapping()`): avisa a equipa de suporte por email
(`TicketSlaVioladoMail`, para `DefinicoesPlataforma::emailSuporte()`) sobre tickets com `prazo_sla` vencido,
ainda num estado não-terminal. **Nunca duplica o aviso**: marca `sla_violado_avisado_em` ao enviar e só considera
tickets ainda sem essa marca. Se um ticket reabrir depois de resolvido e voltar a violar o SLA, repor esse campo
a `null` fica fora do âmbito desta fase (só cobre o primeiro aviso).

## 4. Transferência entre agentes e equipas (pontos 273, 283)

Não existe, em lado nenhum do sistema, um conceito de "equipa" distinto de agente individual — por isso
"transferência de equipa" e "reatribuição" resolvem-se da mesma forma: mudar `agente_id` através do endpoint
genérico de actualização (`PUT /api/plataforma/suporte/{ticket}` ou `/api/suporte/tickets/{ticket}`), já coberto
pela permissão por categoria (secção 2 de [SUPPORT_TICKETING.md](SUPPORT_TICKETING.md)). Construir um conceito de
equipas novo, sem nenhum precedente no sistema, seria uma peça de arquitectura nova não pedida explicitamente.

## 5. Registo de tempo de atendimento (ponto 289)

`tickets_suporte.tempo_atendimento_minutos` (nullable) — registo **manual** do agente, sem cronómetro automático
(não existe, em lado nenhum, um mecanismo de início/fim de sessão de atendimento). Editável pelo mesmo endpoint
de actualização do ticket.

## 6. Indicadores

`DashboardSuporteController::metricas()`: contagem por **todos os 11 estados** (não só os 4 originais — uma
versão anterior desta fase só expunha `ABERTO`/`EM_CURSO`/`RESOLVIDO`/`FECHADO`, deixando 7 estados novos
invisíveis no painel), tempo médio até à primeira resposta, tickets em atraso, tickets por agente. O mesmo gap
existia no frontend (`DashboardSuporte.tsx`, `SuportePainel.tsx`, `PlataformaSuporte.tsx`): os selectores de
estado/categoria só ofereciam os 4 estados e 4 categorias antigos, impedindo seleccionar qualquer um dos novos —
corrigido na mesma fase (Fase 6, parte 2) em que os alertas de SLA foram escritos.

## 7. Testes

`ServicoSlaSuporteTest` (deslize por fim-de-semana e por feriado configurado), `SlaSuporteAvisarCommandTest`
(aviso único, nunca duplicado), `MaquinaEstadosTicketTest` (transferência + tempo de atendimento), e
`CicloCompletoSaasTest::test_ciclo_completo_suporte_com_sla_transferencia_e_isolamento_entre_clientes`.
