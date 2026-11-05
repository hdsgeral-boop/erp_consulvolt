# Revisão de campos — Activos, Projectos e RH (2026-11)

Método: para cada coluna `FUNCIONAL_SO_BACKEND`, `FUNCIONAL_SEM_API` e `ORFA` verificou-se o código real (Models, Services, Controllers, Requests; frontend em `modulos/activos|projectos|rh`). As serializações `toArray()` expõem todas as colunas, pelo que «só backend» significa quase sempre «a API entrega, o ecrã não mostra».

## Contagens (antes → depois)

| Módulo | COBERTO | SO_BACKEND | SEM_API | ORFA |
| :--- | ---: | ---: | ---: | ---: |
| Activos | 156 → 156 | 33 → 33 | 23 → 23 | 0 |
| Projectos | 151 → 151 | 17 → 17 | 9 → 9 | 4 → 4 |
| Projetos (2 tabelas) | 4 → 4 | 0 | 0 | 0 |
| RH | 379 → 388 | 45 → 37 | 65 → 64 | 1 → 1 |

A auditoria é um limite superior: as colunas abaixo classificadas como falso positivo continuam a constar na lista porque a heurística (nome literal) não as vê.

## Implementado (RH, só frontend — a API já devolvia os campos)

- `emprestimos_colaboradores.aprovado_por`, `lancado_por`, `lancado_em`, `liquidado_em`, `cancelado_em`, `cancelado_por`: detalhe do empréstimo (`Emprestimos.tsx`).
- `prestacoes_emprestimos.descontado_em`: coluna «Descontada em» no plano (`TabelaPlano.tsx`, também no Portal); teste `TabelaPlano.test.tsx`.
- `ausencias_faltas_colaboradores.justificada_em/justificada_por`: dica em «Motivo / decisão» (`Assiduidade.tsx`).

## Falsos positivos (documentados)

- **Sub-entidades aninhadas** (campos por chaves dinâmicas, validação pontuada `habilitacoes.*.curso`, JSON do pai): `dependentes_colaboradores.*`, `habilitacoes_colaboradores.*` (incl. o ORFA `curso`, editado em `Colaboradores.tsx`), `itens_carta_pagamento.*`, `prestacoes_emprestimos.*`, `participantes/respostas_avaliacao_360.*`, `participacoes/respostas_ascendentes_rh.*`, `confirmacoes_avaliacao_rh.*`, `mapeamentos_contabeis_rh/sistema_rh.*` (`MapeamentoSalarios.tsx`), `modelos_documentos_rh.*` (rotas `/rh/.../modelos`, Portal).
- **Activos**: `pedidos_manutencao_equipamentos.*` (é a «Manutenção de dados com deliberação», `ServicoManutencaoDados` + `Manutencao.tsx`), `linhas_requisicao_manutencao.*` (`ServicoRequisicoesManutencao`), `ordem` de andares/portas, FKs (`ordem_manutencao_id`, `plano_manutencao_id`, `inventario_fisico_id`), `ativos_imobilizados.lancamento_contabil_id`.
- **Projectos**: `membros_equipa_projeto.*`, `linhas_revisao_projeto.*`, `linhas_requisicao_projeto.*`, `requisicoes_material_projeto.projeto_id`, `configuracoes_projetos.projeto_id`; histórico de medições (`tarefas_projeto.meta_execucoes`) é mostrado em `ModaisMetas.tsx` via `/projetos/{id}/medicoes/folha`.
- **Rotas sem ecrã**: `escalas/aplicar|copiar|do-contrato|limpar` (operação dinâmica em `EscalaMes.tsx`), `contratos/{id}/anular-cessacao|reactivar` (`EstadoContrato.tsx`), `portal/emprestimos/simular` (o Portal simula localmente com `planoEmprestimo`; endpoint redundante mantido), `ativos/amortizacoes/por-integrar-no-ano` (usada por `ServicoEncerramento`), `projetos/folhas-horas/periodos/{p}/imputar|reverter` (chamadas automaticamente por `ServicoFolhaSalarial::contabilizar`; rota manual de recurso), `avaliacao/360/tarefas` (também agregado em `/portal`), `estrutura/chefia/{id}` (utilitário de API).

## Mantidas sem uso (legado, sem UI inventada)

- `documentos_projeto.*` e `faturacao_projetos.projeto_id`: o legado (`v_boss/js/db_v2.js`) só declara o esquema; não há ecrã nem regra. Não se implementa upload de documentos de projecto (com `ValidadorUploadSeguro`) sem decisão do utilizador — ver «Decisões pendentes».
- `linhas_folha_salarial.origem_efetividade/origem_produtividade` (1 % de preenchimento): o legado marcava assim as linhas lançadas da efectividade/produtividade; no Laravel essa função é da coluna `origem`. Não accionar.
- Colunas de auditoria e estado interno de Activos/Projectos (`cancelado_*`, `concluido_*`, `reaberta_*`, `plano_antes`, `satisfeita_em`, `contado_em`, `quantidade_armazem/compra`, `mapa(s)_quantidades`, `razao_analitico_projetos.{modulo,tipo_documento,documento}_origem`, `transferencias_centros_custo_ativos.*`): usadas pelos serviços (reversão, rastreio, migração), já em `tipos.ts` onde interessa; sem valor de edição.

## Decisões pendentes para o utilizador

1. `configuracoes_assiduidade.minimo_dia_horas` (RH): mantida sem uso; semântica por decidir (não ligada).
2. Documentos do projecto (`documentos_projeto`): implementar upload com `ValidadorUploadSeguro` e tipos de documento? O legado não define a funcionalidade.
3. `faturacao_projetos` (facturação por projecto): o legado só tem o esquema; confirmar se se pretende o ecrã.
4. Auditoria de RH ainda não visível no ecrã (`fechos_mensais_assiduidade.lancado_por/reaberto_por`, `periodos_produtividade_rh.lancado_por/reaberto_por`, `ciclos_avaliacao_360.fechado_por`, `bonificacoes_avaliacao_rh.calculado_por/aprovado_por`): disponíveis na API; mostrar se desejado.

## Verificação

`npx tsc --noEmit` sem erros; vitest `src/modulos/rh/faseB` verde (inclui o novo teste). Sem alterações de backend, migrações ou dados.
