# Revisão de campos — Tesouraria, Contabilidade e Orçamento (2026-11)

Revisão à mão das colunas classificadas `FUNCIONAL_SO_BACKEND` / `FUNCIONAL_SEM_API` / `ORFA` por `ferramentas/paridade/auditar_campos.mjs`
(relatório `COBERTURA_CAMPOS_2026-11.md`), lendo Models, Services, Controllers e o frontend de `teso`, `contab` e `orcamento`.
Entregas em código: 3 lacunas reais da Tesouraria e 4 do Orçamento; a Contabilidade não tinha lacunas funcionais (só falsos positivos e legado).

## Contagens (colunas)

| Módulo | Antes (SO_BACKEND / SEM_API / ORFA) | Depois (SO_BACKEND / SEM_API / ORFA) |
| :--- | :--- | :--- |
| Tesouraria | 36 / 12 / 1 | 31 / 12 / 1 |
| Contabilidade | 3 / 50 / 3 | 3 / 50 / 3 |
| Orçamento | 11 / 2 / 0 | 1 / 2 / 0 |

A auditoria é um limite superior: o que resta está classificado abaixo (falso positivo, interno, legado sem uso ou decisão pendente). Por isso a Contabilidade
não muda de contagem — os 56 casos são todos falsos positivos, estado interno ou legado sem dados.

Rotas sem ecrã nos três módulos: 3 (`POST /contabilidade/encerramento/{ano}/cancelar-apuramento`, `POST /contabilidade/rotinas/anular`, `POST /orcamento/verificar`) —
**falsos positivos**: o frontend monta o URL por constante/estado (`Encerramento.tsx` com `motivoAccao`, `Rotinas.tsx` «Anular», `DialogoExcesso.tsx` «Verificar estado»).
Chamadas do frontend sem rota: 0. Campos de formulário sem coluna nos três módulos: 6, todos de ecrã (não são colunas): `Rubricas.tsx` (`contas_texto`, `contas_prefixo`,
`controlo_modo`, `controlo_base` — o formulário compõe `contas` e `controlo`) e `MeiosPagamento.tsx` (`activo_sn`, `padrao_sn` — traduzidos para `ativo`/`predefinido`).

## Lacunas reais implementadas

### Tesouraria
| Coluna | O que foi feito |
| :--- | :--- |
| `movimentos_caixa.url_documento` | O movimento manual da Folha de Caixa aceita o URL do documento de suporte (validação `url:http,https`, máx. 150, como nos documentos de tesouraria): `OperacoesTesourariaController::registarMovimento`, `ServicoCaixa::registarMovimento`; ao contabilizar a sessão o URL vai para as linhas do lançamento (`ServicoLancamentos::criar` aceita `url_documento` por linha, só http/https) e aparece no Diário («Ver Doc.»). UI: campo no formulário e na vista Linhas, coluna «Documento» na tabela de movimentos. As importações de caixa já o gravavam. |
| `conferencias_caixa.conta_regularizacao`, `nome_conta_regularizacao`, `registado_por` | `ServicoConferenciaCaixa::finalizar` passa a preencher também `nome_conta_regularizacao` (antes ficava sempre nulo) e a reabertura limpa-o. UI: coluna «Regularização» no histórico (lançamento + conta), colunas na exportação, e «Conta de regularização»/«Registado por» no auto impresso. |
| `linhas_extrato_bancario.lote_codigo` (+ `reconciliacao_codigo` que só aparecia em dica) | Colunas «Reconciliação» e «Lote» em «Extratos Carregados» e na exportação. |

### Orçamento (a API já devolvia os campos; faltava o ecrã)
| Coluna | O que foi feito |
| :--- | :--- |
| `orcamentos_anuais.versao_origem_id`, `substituido_por_id`, `substituido_em`, `consolidado_por`, `consolidado_ids` | Ficha do orçamento: «Versão de origem» e «Substituído por» (ligações + data) e «Consolidado» com quem consolidou e quantos filhos. |
| `previsoes_orcamentais.publicado_por`, `publicado_em` | Cabeçalho da revisão: «publicada por X em data». |
| `pedidos_extrapolacao_orcamento.valor_consumido`, `decidido_em`, `utilizado_em` | Tabela de pedidos de excesso: «consumido de orçado» sob a execução, data da decisão e da utilização. |

Testes: `backend/tests/Feature/CoberturaCamposTesourariaTest.php` (URL do documento: gravação, recusa de `javascript:`/`ftp:`/>150, propagação ao lançamento; nome da conta de regularização),
asserções novas em `OrcamentoTest` (`versao_origem_id`, `substituido_em`, `consolidado_por`), `OrcamentoControloTest` (`decidido_em`, `utilizado_em`) e `OrcamentoPlaneamentoTest`
(`publicado_por/em`); frontend `teso/coberturaCampos.test.tsx`.

## Decisão por tabela (restantes colunas)

### Tesouraria
| Tabela / colunas | Decisão |
| :--- | :--- |
| `itens_documento_tesouraria.*` (conta, terceiro, n.º documento, notas, descrição, valor, D/C, UN, CC, projecto, venda/factura, `nif_importado`) | **Falso positivo.** Validadas como `linhas.*` em `TesourariaController`, editadas em `FormularioDocumento.tsx`, listadas por `ServicoListagemTesouraria` (que usa `nif_importado` como alternativa ao NIF do terceiro). |
| `itens_documento_tesouraria.{taxa_cambio_id,taxa_cambio_manual,cambial_*,valor_kz_documento}`, `documentos_tesouraria.taxa_cambio_id` | **Legado sem uso**: 0–1 % de preenchimento no legado; o câmbio do documento vive em `taxa_cambio`/`codigo_moeda` do cabeçalho. |
| `reconciliacoes_bancarias.*` | Escrita pelas compensações (`ServicoCompensacoes`); estado interno. `importacao_codigo` já estava como legado. |
| `correspondencias_reconciliacao.*` | **Legado sem uso**: o vínculo extracto↔lançamento vive em `reconciliacao_codigo` das duas linhas. |
| `movimentos_caixa.{taxa_cambio,taxa_cambio_id,contravalor_kz,contra_moeda,contravalor_moeda}` | **Decisão pendente** (ver abaixo). |
| `movimentos_caixa.fatura_compra_id` | **Falso positivo** (validada e gravada em `registarMovimento`, usada na liquidação de facturas). |

### Contabilidade
| Tabela / colunas | Decisão |
| :--- | :--- |
| `lancamentos_contabeis.{referencia_documento,periodo_contabil,documento_origem_id,tipo_documento_origem,taxa_cambio_id,sessao_pos_id,tarefa_projeto_id,empresa_intragrupo_id,arredondamento_cambial,sistema_origem,valor_imposto}` | **Legado sem uso** (0–2 % no legado); a origem do lançamento deriva de `tipo_origem` (`ServicoOrigemLancamentos`) e já se mostra no Diário. |
| `lancamentos_contabeis.{linha_origem_id,execucao_consolidacao_id,tipo_consolidacao,valor_kz_origem}` | **Interno** da consolidação. |
| `lancamentos_contabeis.{projeto_id,reconciliacao_codigo,estornado_em}` | **Falso positivo / coberto** (`projeto_id` por linha na emissão; `reconciliacao_codigo` em `MapaExtrato.tsx`/`ferramentas.ts`; estorno por `estornado_por_id`). |
| `lancamentos_estornados.*` | **Arquivo interno** de linhas recicladas (`ServicoReciclagemLancamentos`); sem UI por desenho. |
| `execucoes_consolidacao.*`, `grupos_consolidacao.empresa_holding_id`, `membros_consolidacao.*` | **Falso positivo**: `Consolidacao.tsx` mostra holding, membros, percentagem, método, moeda e utilizador da execução (o Resource usa os nomes `holding`, `moeda`, `utilizador`). |
| `relatorios_anuais_contas.{ano_relatorio,estado,concluido_em,concluido_por}` | **Falso positivo**: o ano é o parâmetro da rota; estado e conclusão usados em `relatorioContas/conclusao.ts` e `RelatorioContas.tsx`. |

### Orçamento
| Tabela / colunas | Decisão |
| :--- | :--- |
| `linhas_previsao_orcamental.{previsao_orcamental_id,rubrica_orcamental_id}` | **Falso positivo** (chaves de ligação; a grelha de `Previsoes.tsx` indexa por rubrica). |
| `previsoes_orcamentais.revisao_origem_id` | **Interno**: cadeia das revisões (`ServicoPlaneamentoOrcamental`); a ficha mostra o n.º de revisão. |

## Amostra de FUNCIONAL_COBERTO (falsos negativos)
Confirmado no código do ecrã: `conferencias_caixa.{justificacao,saldo_externo,nome_gerente,assinado_gerente_em}` (`Conferencia.tsx`), `movimentos_caixa.{referencia,numero_documento,UN,CC,notas}` (`FolhaCaixa.tsx`),
`documentos_tesouraria.url_documento` (`DetalheDocumento.tsx`, coluna «Ver Doc.») e `orcamentos_anuais.{responsavel,prazo_contributo,rejeicoes}` (`DetalheOrcamento.tsx`). Sem falsos negativos encontrados.

## Decisões pendentes para o utilizador
1. **Caixa em moeda estrangeira** (`movimentos_caixa.taxa_cambio`, `taxa_cambio_id`, `contravalor_kz`, `contra_moeda`, `contravalor_moeda`): o legado (`v_boss/js/moedas_tesouraria.js:355-420`, `caixaFxLiquidacao`/`caixaFxPartidas`)
   liquida documentos em moeda pela caixa com contravalor histórico e lança a diferença de câmbio. A Folha de Caixa v2 só opera em Kz (`codigo_moeda = 'AOA'`). É uma funcionalidade completa (taxas por sessão,
   saldo do documento em moeda, lançamento de diferenças), não um campo: implementar só com confirmação de que as empresas usam caixas em USD/EUR.
2. Nada mais bloqueia: o resto é legado sem dados ou estado interno, documentado em `docs/dicionario/DICIONARIO_DADOS.md` (secção «Estado de exposição na API/UI»).
