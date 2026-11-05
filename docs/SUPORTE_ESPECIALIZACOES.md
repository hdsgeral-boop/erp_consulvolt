# Backoffice de suporte — Especializações dos agentes

## Para que serve

A **especialização** de um agente de suporte define quais as categorias de tickets (Geral, Financeiro,
Marketing/Comunicação, Técnico) que o agente vê e responde; o agente nunca acede a clientes, planos nem definições.
Tecnicamente é um perfil de utilizador restrito à plataforma (`perfis_utilizador.restrito_plataforma = true`) cujas
permissões são só `suporte_tickets_<grupo>_view` / `_gerir` (ADR-077).

## Grupos e categorias (fonte única)

A fonte de verdade é `TicketSuporte::CHAVE_POR_CATEGORIA` (categoria do ticket → chave `suporte_tickets_<grupo>_gerir`).
O ecrã de especializações, o `/api/suporte/eu`, o filtro de tickets (`SuporteController::index`), as métricas
(`DashboardSuporteController`), as conversas, as notas internas e as respostas rápidas usam todos esta tabela, via
`ServicoEspecializacoesSuporte`. Cada categoria pertence a exactamente um grupo (há um teste que o garante):

| Grupo | Categorias |
|---|---|
| `geral` | GERAL, MELHORIA, DUVIDA, ADMINISTRATIVA, OUTROS |
| `financeiro` | PLANO, FACTURACAO, PAGAMENTO, SUBSCRICAO |
| `marketing` | MARKETING |
| `tecnico` | INCIDENTE_TECNICO, ERRO_FUNCIONAL, AUTENTICACAO, PERMISSOES, CONFIGURACAO, DESEMPENHO, INTEGRACAO, SEGURANCA |

Um super-administrador (e a conta root) vê todas as categorias; um agente sem especialização não vê nenhuma.

## Especializações base

Criadas de forma **idempotente** (só o que falta; nunca se altera nem apaga um perfil existente):

- Agente de Suporte Geral / Técnico / Financeiro / Marketing e Comunicação
- Agente de Suporte (todas as categorias)

Três vias, todas com a mesma lógica (`ServicoEspecializacoesSuporte::garantirBase()`):

1. Migração de dados `2026_12_03_090100_especializacoes_suporte_base` (precedida de `2026_12_03_090000_perfis_utilizador_ativo`,
   que acrescenta a coluna `perfis_utilizador.ativo`, default `true`). `perfis_utilizador` é global (sem `tenant_id`/RLS),
   por isso não depende do tenant da plataforma (a migração não faz nada em `APP_ENV=testing`: a suite assume `perfis_utilizador` sem dados seus).
2. Comando `php artisan erp:suporte:criar-especializacoes [--simular]`.
3. Lazy: `GET /api/plataforma/perfis-suporte` cria as base se não existir nenhuma especialização.

Nota: o modelo do catálogo que se chamava «Agente de Suporte Técnico» mas concedia só `suporte_tickets_geral_*`
passou a chamar-se «Agente de Suporte Geral» (o técnico verdadeiro mantém o nome).

## Gestão (consola /admin → Agentes de suporte → «Especializações»)

`/api/plataforma/especializacoes-suporte` (GET/POST/PUT/DELETE), chave `plataforma_suporte_gerir` (ver:
`plataforma_suporte_view`). O pedido traz **grupos**, nunca permissões: as chaves são geradas no servidor, por isso
nada fora de `suporte_*` pode ser concedido. Regras: pelo menos um grupo; nome único; só perfis que sejam
especializações se editam/eliminam por esta via; não se elimina uma especialização com agentes (`ESPECIALIZACAO_EM_USO`);
desactivar (`ativo=false`) deixa de a oferecer a novos agentes mas não afecta os que já a têm.

## Agentes

- Modal «Novo agente»: selector com descrição das categorias de cada especialização, «Geral» por omissão (menor
  privilégio) e validação (só especializações activas).
- Lista de agentes: coluna Especialização com as categorias.
- `/suporte` (painel do agente): «As suas categorias: …» (`GET /api/suporte/eu`); o filtro de categoria usa as categorias
  devolvidas pelo servidor.

## Fora de âmbito

Atribuição automática de tickets a agentes por fila: não existe hoje (a atribuição é manual); a fila de cada agente é
a visibilidade por categoria descrita acima. O selector do ecrã de Operadores da plataforma (perfis com chaves
`plataforma_*`) não foi alterado — não é o mesmo conceito.
