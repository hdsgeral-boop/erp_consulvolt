# Suporte técnico: tickets, categorias e especialização

> Fase 6, parte 1, da extensão SaaS (`segurança.txt`, secções 13.1-13.3, 15). Para prazos de SLA, alertas,
> transferência e tempo de atendimento, ver [SLA_MANAGEMENT.md](SLA_MANAGEMENT.md).

## 1. Modelo

`App\Models\TicketSuporte` (tabela `tickets_suporte`) — pertence ao **tenant** (`tenant_id`), não a uma empresa;
sem RLS por `empresa_id` (um cliente pode ter várias empresas, mas o suporte é da conta, não de uma empresa
específica dele).

### Estados (11, `ESTADOS`)

`ABERTO`, `EM_TRIAGEM`, `EM_ANALISE`, `ATRIBUIDO`, `EM_CURSO`, `A_AGUARDAR_CLIENTE`, `A_AGUARDAR_TERCEIRO`,
`RESOLVIDO`, `FECHADO`, `REABERTO`, `CANCELADO`. Terminais (`ESTADOS_TERMINAIS`): `RESOLVIDO`, `FECHADO`,
`CANCELADO` — fora das filas de trabalho activas e do cálculo de "em atraso".

`App\Services\Plataforma\MaquinaEstadosTicket` valida as transições: **qualquer estado não-terminal pode
transicionar para qualquer outro estado** (incluindo não-terminal→terminal e entre não-terminais à vontade); os
três terminais só transicionam para `REABERTO` (nunca directamente para outro estado, e nunca entre si). Esta
simplicidade é deliberada — uma primeira versão mais restritiva (ex.: só `ABERTO→{EM_TRIAGEM,ATRIBUIDO,…}`)
partiu vários fluxos já em produção que esperavam transições directas simples (`ABERTO→EM_CURSO`,
`ABERTO→RESOLVIDO`).

Reabertura automática: quando o cliente responde a um ticket já num estado terminal, o estado passa a
`REABERTO` (nunca `ABERTO` de novo — distingue um pedido novo de um que voltou a precisar de atenção).

### Categorias (18, `CATEGORIAS`)

4 originais (`GERAL`, `PLANO`, `FACTURACAO`, `MARKETING`) + 14 novas orientadas a incidentes técnicos
(`INCIDENTE_TECNICO`, `ERRO_FUNCIONAL`, `AUTENTICACAO`, `PERMISSOES`, `PAGAMENTO`, `SUBSCRICAO`,
`CONFIGURACAO`, `MELHORIA`, `DUVIDA`, `DESEMPENHO`, `INTEGRACAO`, `SEGURANCA`, `ADMINISTRATIVA`, `OUTROS`).

## 2. Especialização de agentes (ADR-077, alargado nesta fase)

`TicketSuporte::CHAVE_POR_CATEGORIA` mapeia cada categoria para uma chave de permissão:
`suporte_tickets_geral_gerir`, `suporte_tickets_financeiro_gerir` (plano/facturação/pagamento/subscrição) ou
`suporte_tickets_tecnico_gerir` (incidente/erro/autenticação/permissões/configuração/desempenho/
integração/segurança — categoria nova desta fase). Um agente só vê e gere os tickets das categorias cuja chave
o seu perfil concede (`SuporteController::categoriasPermitidas`); um super-administrador vê tudo, sem filtro.

Perfil-modelo "Agente de Suporte Técnico" já no catálogo de permissões
(`backend/resources/permissoes/catalogo.json`).

## 3. Rotas (o mesmo controlador, três portas de entrada)

`App\Http\Controllers\Api\Plataforma\SuporteController` é reaproveitado por:
- `/api/plataforma/suporte/*` — super-administrador, vê tudo.
- `/api/suporte/tickets/*` — agente especializado, só as suas categorias.

Criação de tickets pelo **cliente** (não pelo mesmo controlador, portas diferentes, mesmos dados):
- `/api/minha-conta/suporte` (`MinhaContaController`) — só o administrador da conta.
- `/api/licenca/suporte` (`LicencaController`) — qualquer utilizador autenticado da empresa, incluindo com a
  licença expirada (uma das rotas `licenca.*` sempre acessíveis, ver
  [TENANT_LIFECYCLE.md](TENANT_LIFECYCLE.md) secção 5).

## 4. Notas internas e anexos

- Notas internas (`NotaInternaSuporteController`/modelo próprio) ficam **sempre fora** da conversa visível ao
  cliente — arquitecturalmente num modelo/endpoint separado, nunca um campo opcional na mesma mensagem.
- Anexos (`ServicoAnexosSuporte`): validação de MIME **pelo conteúdo real do ficheiro**, nunca só pela extensão.
- Respostas rápidas (`RespostaRapidaSuporteController`): catálogo partilhado por toda a equipa, não por agente.

## 5. Avaliação do cliente (CSAT)

`csat_nota`/`csat_comentario` — só depois do ticket estar `RESOLVIDO`/`FECHADO`, e só uma vez por ticket
(`avaliacao()` em `LicencaController`/`MinhaContaController`).

## 6. Testes

`MaquinaEstadosTicketTest`, `SuporteProfissionalizacaoTest`, `PlataformaClienteTest`,
`PlataformaGestaoTest`, `EmailsTest`, e o fluxo completo em
`CicloCompletoSaasTest::test_ciclo_completo_suporte_com_sla_transferencia_e_isolamento_entre_clientes`.
