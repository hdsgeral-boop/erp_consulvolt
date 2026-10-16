# 07 — Modais: vistas Grade / Linhas

**Data:** 2026-10-15

## 1. Propósito

Inventário de todos os modais do frontend React do ERP novo (`erp_laravel/frontend/src`), para decidir onde aplicar a alternância **Grade | Linhas** do legado (`js/shared/formulario_linhas.js` + `formularios_linhas_config.js`). O componente comum fica em `frontend/src/componentes/formularios/` (`ModalFormulario`, `useFormularioLinhas`) e foi aplicado primeiro aos pilotos «Nova Fase / Milestone» e «Nova tarefa» (`modulos/projectos/separadores/Planeamento.tsx`, com subpasta `planeamento/`) e «Nova Função» (`modulos/rh/Funcoes.tsx`, através de `modulos/rh/comum/CadastroSimples.tsx`).

**Estado:** os três pilotos já usam `ModalFormulario` (`Planeamento.tsx:517` «Nova tarefa», `Planeamento.tsx:604` «Novo milestone», `CadastroSimples.tsx:175` com a prop `linhas`, usada em «Nova Função»). Os números de linha dos outros ficheiros são os da data da recolha e podem mudar.

**Âmbito e método.** Recolha por script sobre `src/**/*.ts(x)` (sem testes): cada `<Modal` (Ant Design), cada `<Drawer` e cada chamada imperativa `Modal.confirm/info/warning/error/success` (incluindo `modal.*` de `App.useApp()`). Total: **461 ocorrências** (315 `<Modal`, 18 `<Drawer`, 128 chamadas imperativas). Excluídas 2 falsas ocorrências em comentários (`componentes/responsivo/utilitarios.ts`).

Nota sobre o número «~537»: a pesquisa literal por `<Modal` apanha também as **invocações** de componentes próprios cujo nome começa por `Modal` (`<ModalMotivo`, `<ModalItem`, …; cerca de 223). Essas invocações não são modais novos — o `<Modal` que as implementa já está contado uma vez na sua definição. Os componentes reutilizados em mais de um sítio estão no anexo A.

Regra «Grade/Linhas = Sim»: só formulários de **criação** de registos repetíveis (cadastros, tarefas, fases, funções, categorias, produtos, contas, …). «Não» para confirmações, detalhes, impressão, assistentes de vários passos e formulários de documento com linhas próprias (facturas, encomendas, lançamentos, processamentos). Em «criar+editar» com Sim, a vista Grade/Linhas aplica-se **só ao modo criar**. «(?)» marca classificação incerta (escolhida a mais provável). A coluna «Legado tem Linhas» compara com `Versões/v4/ERP/js/shared/formularios_linhas_config.js` («—» quando o modal não é de criação/edição de uma entidade registada no legado).

## 2. Resumo por módulo

| Módulo | Total | Criar | Editar | Criar+editar | Confirmação | Detalhe | Impressão | Outro | Levam Grade/Linhas | Legado com Linhas |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `componentes` | 11 | 1 | 1 | 1 | 2 | 1 | 0 | 5 | 1 | 0 |
| `layout` | 3 | 0 | 1 | 0 | 0 | 0 | 0 | 2 | 0 | 0 |
| `modulos/acrescimos` | 8 | 0 | 1 | 1 | 1 | 1 | 0 | 4 | 1 | 0 |
| `modulos/activos` | 39 | 4 | 4 | 5 | 11 | 4 | 0 | 11 | 6 | 1 |
| `modulos/compras` | 27 | 2 | 6 | 2 | 6 | 3 | 0 | 8 | 2 | 0 |
| `modulos/config` | 25 | 0 | 0 | 7 | 10 | 2 | 0 | 6 | 5 | 2 |
| `modulos/contab` | 44 | 0 | 2 | 2 | 23 | 8 | 0 | 9 | 1 | 1 |
| `modulos/crm` | 16 | 0 | 0 | 7 | 2 | 3 | 0 | 4 | 4 | 0 |
| `modulos/estrutura` | 11 | 1 | 1 | 3 | 1 | 1 | 0 | 4 | 3 | 0 |
| `modulos/geral` | 5 | 1 | 0 | 1 | 0 | 1 | 0 | 2 | 1 | 0 |
| `modulos/metodo` | 2 | 0 | 0 | 1 | 0 | 1 | 0 | 0 | 0 | 0 |
| `modulos/orcamento` | 10 | 4 | 0 | 1 | 1 | 1 | 0 | 3 | 1 | 0 |
| `modulos/pos` | 43 | 3 | 4 | 3 | 12 | 4 | 2 | 15 | 3 | 0 |
| `modulos/projectos` | 48 | 9 | 5 | 7 | 11 | 3 | 0 | 13 | 9 | 9 |
| `modulos/rh` | 94 | 7 | 8 | 7 | 31 | 13 | 6 | 22 | 9 | 2 |
| `modulos/stock` | 20 | 3 | 0 | 1 | 8 | 4 | 0 | 4 | 1 | 0 |
| `modulos/teso` | 34 | 1 | 2 | 3 | 15 | 2 | 0 | 11 | 1 | 1 |
| `modulos/vendas` | 21 | 0 | 2 | 2 | 9 | 4 | 0 | 4 | 2 | 1 |
| **Total** | **461** | **36** | **37** | **54** | **143** | **56** | **8** | **127** | **50** | **17** |

«Levam Grade/Linhas» = 50 (40 certos + 10 marcados «(?)»).

## 3. Chaves do legado (`formularios_linhas_config.js`) e correspondência no sistema novo

| Chave legado | Título | Campos (legado) | Modal no sistema novo |
|---|---|---|---|
| `colaboradores` | colaboradores | nome, NIF, Nº INSS, função, tipo de órgão, UN, CC, estado, dias contr., aposentado, avençado, sexo, nascimento, naturalidade, BI, admissão, telefone, e-mail, endereço, emergência (nome/telefone/parentesco) | Não encontrado como modal — a ficha é uma página (`modulos/rh/Colaboradores.tsx:549`, «Adicionar Novo Colaborador») |
| `funcoes` | funções | nome da função, descrição | `modulos/rh/comum/CadastroSimples.tsx:175` (via `modulos/rh/Funcoes.tsx`) — piloto |
| `contratos` | contratos | colaborador, valor mensal por rubrica, dias/mês, horas/dia, início, fim, estado, moeda | `modulos/rh/Contratos.tsx:266` |
| `unidades_negocio` | unidades de negócio | código, nome, abreviado, descrição, pai, estado, início, fim, cidade, telefone, e-mail, gerente, vendas, serviços | `modulos/config/UnidadesNegocio.tsx:120` |
| `centros_custo` | centros de custo | código, descrição | Não encontrado como modal — cartão «Novo» em `modulos/contab/TabelasAux.tsx:118` (`TabelaSimples`) |
| `terceiros` | terceiros | NIF, nome, tipo, conta cliente (31), conta fornecedor (32/37.0), moeda, rua, porta, bairro, município, província, país | Não encontrado como modal — cartão «Novo Terceiro» em `modulos/contab/tabelasAux/SeparadorTerceiros.tsx:167` |
| `diarios` | diários | código, descrição | Não encontrado como modal — cartão «Novo» em `modulos/contab/TabelasAux.tsx:118` (`TabelaSimples`) |
| `plano_contas` | contas | código, descrição, tipo (M/T), moeda (só classe 4) | `modulos/config/PlanoContas.tsx:164` e `modulos/contab/TabelasAux.tsx:312` |
| `categorias` | categorias | nome da categoria | Criação não é modal — cartão «Nova Categoria» em `modulos/vendas/produtos/Produtos.tsx:372`; modal só de edição em `:397` |
| `produtos` | produtos e serviços | código, nome, preço, IVA, categoria, gerir stock, stock inicial, contas (venda, custo, IVA liq./ded., compras, existências) | Não encontrado como modal — formulário em página `modulos/vendas/produtos/Produtos.tsx:203` («Novo Produto / Serviço») |
| `activos` | activos imobilizados | código, descrição, categoria, estado, UN, CC, fornecedor, data e valor de aquisição, residual, vida útil, vida remanescente, cota fixa, já amortizado | `modulos/activos/ModalActivo.tsx:88` |
| `projectos` | projectos / obras | tipo, código, designação, UN, CC, cliente, encomenda, estado | `modulos/projectos/ModalProjecto.tsx:61` |
| `projecto_tarefas` | tarefas | código WBS, designação, início, fim, execução %, estado, fase, responsável, valor adjudicado | `modulos/projectos/separadores/Planeamento.tsx:517` — piloto |
| `projecto_fases` | fases / milestones | designação, data alvo | `modulos/projectos/separadores/Planeamento.tsx:604` — piloto |
| `projecto_equipa` | membros da equipa | tipo de recurso, colaborador, entidade externa, nome livre, papel, horas/dia | `modulos/projectos/separadores/Equipa.tsx:89` (já existe `ModalMassa` em `:115` para vários colaboradores) |
| `projecto_orcamento` | linhas de orçamento | tarefa, rubrica, conta, valor previsto | `modulos/projectos/separadores/Orcamento.tsx:152` |
| `projecto_horas` | registos de horas | colaborador, data, horas | `modulos/projectos/separadores/Horas.tsx:124` |
| `projecto_equipamento` | usos de equipamento | equipamento, data, horas, custo/hora | `modulos/projectos/separadores/Horas.tsx:147` |
| `projecto_aditamentos` | aditamentos / trabalhos a mais | descrição, valor, estado | `modulos/projectos/separadores/Orcamento.tsx:175` |
| `lancamentos` | lançamentos (agrupados por diário + data + nº doc.) | diário, data fiscal, data doc., nº doc., referência, conta, D/C, valor, terceiro, nota DEMO, nota fluxo, UN, CC, descrição, URL, período, moeda, câmbio | Não é modal — página `modulos/contab/lancamentos/NovoLancamento.tsx`. Fase 2 — exige agrupamento por documento e validação D = C |
| `tesouraria` | documentos de tesouraria (agrupados por data + tipo + conta + ref.) | data, tipo, conta disponibilidades, referência, descrição geral, contrapartida, terceiro, doc. origem, data origem, nota DEMO, nota fluxo, UN, CC, descrição linha, valor, URL, D/C, período salarial, projecto, câmbio | Não é modal — página `modulos/teso/pagamentos/FormularioDocumento.tsx` (o modal `ModalPendentes` em `:256` só escolhe documentos em aberto). Fase 2 — exige agrupamento por documento |

Das 21 chaves do legado, 13 têm modal correspondente no sistema novo; 8 são formulários em página/cartão (colaboradores, centros de custo, terceiros, diários, categorias, produtos, lançamentos, tesouraria) e ficam fora deste inventário de modais.

## 4. Inventário detalhado por módulo

Colunas: `Ficheiro:linha` relativo a `frontend/src`. Prefixos no título: `Drawer ·` para gavetas; `Modal.confirm ·`, `Modal.warning ·`, etc. para chamadas imperativas.

### `componentes` (11 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `componentes/Accoes.tsx:62` | ModalMotivo: Motivo da acção (título por parâmetro) | confirmação | Não | Pede o motivo antes de uma acção (componente partilhado `ModalMotivo`) | — |
| `componentes/ajuda/PainelAjuda.tsx:82` | PainelAjuda: Drawer · Ajuda contextual | outro | Não | Gaveta de ajuda contextual | — |
| `componentes/formularios/ModalFormulario.tsx:103` | ModalFormulario: Título por parâmetro + alternador Grade \| Linhas | outro | Não | Invólucro comum Grade/Linhas (`ModalFormulario`) — não é um ecrã; conta nos modais que o usam | — |
| `componentes/formularios/PainelLinhas.tsx:308` | PainelLinhas: Colar linhas do Excel | outro | Não | Colar linhas do Excel (parte do componente comum) | — |
| `componentes/orcamento/DialogoExcesso.tsx:145` | DialogoExcesso: Excesso orçamental — aprovação necessária | confirmação | Não | Aprovação de excesso orçamental | — |
| `componentes/preferencias/VisoesGuardadas.tsx:79` | VisoesGuardadas: Guardar visão | criar | Não | Guarda o estado actual da vista (preferência pessoal, um a um) | — |
| `componentes/reparticao/CriteriosReparticao.tsx:96` | CriteriosReparticao: Novo critério de repartição / Critério … | criar+editar | Sim (?) | Cadastro de critérios de repartição (código, nome, forma); Grade só no modo criar | Não |
| `componentes/reparticao/DetalheReparticao.tsx:122` | ModalAlocacaoUN: Alocação por unidades de negócio (título por parâmetro) | editar | Não | Edição de percentagens de alocação por UN | — |
| `componentes/reparticao/ModalRepartirLinha.tsx:50` | ModalRepartirLinha: Repartir a linha por unidades de negócio | outro | Não | Repartição de uma linha por UN | — |
| `componentes/simulacoes/PreVisualizacaoLancamento.tsx:80` | BotaoPreVisualizarLancamento: Pré-visualização do lançamento — … | detalhe só leitura | Não | Pré-visualização do lançamento | — |
| `componentes/vistas/FiltrosColuna.tsx:133` | ModalFiltroPersonalizado: Filtro personalizado | outro | Não | Filtro personalizado da tabela | — |

### `layout` (3 ocorrências; 0 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `layout/LayoutPrincipal.tsx:537` | Estrutura: Drawer · Menu de navegação (gaveta em ecrã estreito) | outro | Não | Gaveta de navegação (menu móvel) | — |
| `layout/LayoutPrincipal.tsx:649` | Estrutura: Renomear favorito | editar | Não | Renomear favorito | — |
| `layout/PesquisaEcras.tsx:61` | PesquisaEcras: Ir para um ecrã | outro | Não | Pesquisa de ecrãs | — |

### `modulos/acrescimos` (8 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/acrescimos/DetalheItem.tsx:156` | ModalRegularizar: Regularizar «…» | outro | Não | Regularização de um registo existente | — |
| `modulos/acrescimos/DetalheItem.tsx:181` | ModalTerminar: Terminar antecipadamente «…» | outro | Não | Terminar antecipadamente um registo | — |
| `modulos/acrescimos/ModalItem.tsx:93` | ModalItem: Editar registo #… / Novo registo de acréscimo / diferimento | criar+editar | Sim (?) | Registos de acréscimo/diferimento repetíveis; formulário extenso com «Ver plano» | Não |
| `modulos/acrescimos/Propostas.tsx:76` | PropostaMes: Modal.confirm · Contabilizar proposta | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/acrescimos/Propostas.tsx:246` | PropostaMes: Resultado da contabilização | detalhe só leitura | Não | Resultado da contabilização | — |
| `modulos/acrescimos/Recolher.tsx:153` | EscolherLinha: Diferir — … | outro | Não | Escolha de linha a diferir | — |
| `modulos/acrescimos/Recolher.tsx:178` | EscolherAcrescimo: Regularizar com … | outro | Não | Escolha do acréscimo a regularizar | — |
| `modulos/acrescimos/Registos.tsx:205` | ModalDefinicoes: Definições de acréscimos e diferimentos | editar | Não | Definições do módulo | — |

### `modulos/activos` (39 ocorrências; 6 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/activos/Activos.tsx:119` | Cadastro: modal.confirm · Eliminar em Massa | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/Amortizacoes.tsx:57` | Amortizacoes: Modal.confirm · Integrar … até …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/Amortizacoes.tsx:210` | Amortizacoes: Definir cota manual para o período … | editar | Não | Cota manual de um período | — |
| `modulos/activos/Amortizacoes.tsx:226` | Amortizacoes: Verificação das amortizações | detalhe só leitura | Não | Verificação das amortizações | — |
| `modulos/activos/Amortizacoes.tsx:302` | PainelCalculo: Modal.confirm · Deseja processar o Cálculo de … período(s)? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/Amortizacoes.tsx:336` | PainelIntegracao: Modal.confirm · Confirma a integração contabilística de … períodos? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/Amortizacoes.tsx:405` | ModalPrevisualizar: Integração de … | detalhe só leitura | Não | Pré-visualização da integração | — |
| `modulos/activos/Amortizacoes.tsx:471` | ModalReabrir: Reabrir … período(s) / Reabrir … | confirmação | Não | Reabrir períodos | — |
| `modulos/activos/Categorias.tsx:93` | ModalCategoria: Editar categoria «…» / Nova categoria | criar+editar | Sim | Cadastro de categorias de activos; Grade só no modo criar | Não |
| `modulos/activos/DetalheActivo.tsx:250` | DetalheActivo: Integrar amortizações do activo | confirmação | Não | Integrar amortizações do activo | — |
| `modulos/activos/Manutencao.tsx:155` | ModalManutencao: Registar manutenção | criar | Sim (?) | Registos de manutenção repetíveis (activo, tipo, data, custo) | Não |
| `modulos/activos/Manutencao.tsx:186` | ModalConcluir: Executar Manutenção | outro | Não | Execução/conclusão de manutenção existente | — |
| `modulos/activos/Mapa.tsx:169` | Mapa: Editar cota de amortização para o período … (…) | editar | Não | Editar cota de amortização | — |
| `modulos/activos/ModaisActivos.tsx:82` | ModalImportarActivos: Importar activos | outro | Não | Importação de activos (Excel) | — |
| `modulos/activos/ModaisActivos.tsx:188` | ModalEdicaoMassa: Edição em massa — … activo(s) | editar | Não | Edição em massa | — |
| `modulos/activos/ModaisActivos.tsx:220` | ModalTransferir: Transferir de centro de custo | outro | Não | Transferência de centro de custo (operação) | — |
| `modulos/activos/ModaisActivos.tsx:255` | ModalAfectacao: Editar afectação / Afectar activo a projecto | criar+editar | Não (?) | Afectação de um activo concreto a projecto | Não |
| `modulos/activos/ModalAbate.tsx:48` | ModalAbate: Modal.warning · Abate registado com avisos | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/activos/ModalAbate.tsx:93` | ModalAbate: Abate / venda — … … / Abate / venda de activo | outro | Não | Abate/venda de activo (gera lançamento) | — |
| `modulos/activos/ModalAbate.tsx:109` | ModalAbate: Modal.confirm · Confirmar o abate? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/ModalActivo.tsx:88` | ModalActivo: Editar activo … / Novo activo | criar+editar | Sim | Cadastro de activos; Grade só no modo criar | Sim |
| `modulos/activos/Pendentes.tsx:139` | ModalInventariar: Inventariar … — conta … | outro | Não | Assistente de inventariação a partir de linha de documento (já tem tabela de itens) | — |
| `modulos/activos/Pendentes.tsx:190` | ModalLigar: Ligar activos a … | outro | Não | Ligar activos a documento | — |
| `modulos/activos/edificios/Folha.tsx:172` | ComparacaoInventarioEcra: modal.confirm · Aplicar localizações | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/edificios/Folha.tsx:180` | ComparacaoInventarioEcra: modal.confirm · Fechar inventário | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/edificios/Modais.tsx:26` | ModalAndar: Editar andar / Novo andar | criar+editar | Sim | Cadastro de andares; Grade só no modo criar | Não |
| `modulos/activos/edificios/Modais.tsx:59` | ModalPortas: Criar portas | criar | Não | Já cria várias portas por intervalo de números | Não |
| `modulos/activos/edificios/Modais.tsx:89` | ModalPorta: Porta … | editar | Não | Ficha da porta (editar/eliminar) | — |
| `modulos/activos/edificios/Modais.tsx:143` | ModalGerarInventario: modal.confirm · Folha sem bens | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/edificios/Modais.tsx:150` | ModalGerarInventario: Gerar folha de inventário | outro | Não | Gerar folha de inventário | — |
| `modulos/activos/edificios/Planta.tsx:180` | DetalhePorta: Porta … (detalhe) | detalhe só leitura | Não | Detalhe da porta | — |
| `modulos/activos/manutencao/Ordens.tsx:72` | ModalOrdemAvulsa: Nova ordem de manutenção | criar | Não (?) | Ordem de manutenção: documento operacional com necessidades próprias | Não |
| `modulos/activos/manutencao/Ordens.tsx:102` | DetalheOrdemModal: Ordem … (detalhe) | detalhe só leitura | Não | Detalhe da ordem | — |
| `modulos/activos/manutencao/Ordens.tsx:193` | ModalItem: Acrescentar necessidade | criar | Sim (?) | Necessidades da ordem são repetíveis (várias de uma vez) | Não |
| `modulos/activos/manutencao/Ordens.tsx:217` | AssistenteRequisicao: Requisitar o que falta — ordem … | outro | Não | Assistente de requisição | — |
| `modulos/activos/manutencao/Ordens.tsx:256` | ModalConclusao: modal.confirm · Necessidades por receber | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/activos/manutencao/Ordens.tsx:271` | ModalConclusao: Concluir a ordem … | outro | Não | Conclusão da ordem | — |
| `modulos/activos/manutencao/Planos.tsx:138` | ModalPlano: Plano … / Novo plano de manutenção | criar+editar | Sim (?) | Cadastro de planos de manutenção; Grade só no modo criar (formulário extenso) | Não |
| `modulos/activos/manutencao/Requisicoes.tsx:76` | ModalEntrega: Entregar o material — … | outro | Não | Entrega de material | — |

### `modulos/compras` (27 ocorrências; 2 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/compras/EncomendasClientes.tsx:267` | EncomendasClientes: Gerar Pedido de Compra | outro | Não | Gera pedido de compra a partir da encomenda | — |
| `modulos/compras/comum/FluxoDocumental.tsx:120` | ModalFluxoDocumental: Fluxo Completo de Compra | detalhe só leitura | Não | Fluxo documental | — |
| `modulos/compras/comum/GestaoTerceiros.tsx:178` | GestaoTerceiros: Modal.confirm · Eliminar …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/compras/comum/GestaoTerceiros.tsx:317` | GestaoTerceiros: Drawer · Ficha | detalhe só leitura | Não | Ficha do terceiro (gaveta só de leitura) | — |
| `modulos/compras/comum/ModaisEncomenda.tsx:52` | ModalRececao: Registar recepção — encomenda … | criar | Não | Recepção de encomenda: documento com linhas próprias | Não |
| `modulos/compras/comum/ModaisEncomenda.tsx:69` | ModalRececao: Modal.warning · Indique pelo menos uma quantidade recebida. | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/compras/comum/ModaisEncomenda.tsx:156` | ModalFaturaEncomenda: Registar factura — encomenda … | criar | Não | Factura de encomenda: documento com linhas próprias | Não |
| `modulos/compras/comum/ModaisEncomenda.tsx:173` | ModalFaturaEncomenda: Modal.warning · Indique pelo menos uma quantidade a facturar. | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/compras/comum/ModaisTerceiros.tsx:56` | ModalEdicaoMassaTerceiros: Editar em massa | editar | Não | Edição em massa de terceiros | — |
| `modulos/compras/comum/ModaisTerceiros.tsx:126` | ModalImportarClientes: Importar clientes (Excel) | outro | Não | Importação de clientes (Excel) | — |
| `modulos/compras/comum/ModalContas.tsx:30` | ModalContas: Contas por omissão (título por parâmetro) | editar | Não | Contas por omissão (configuração) | — |
| `modulos/compras/comum/ModalIva.tsx:27` | ModalIva: IVA (título por parâmetro) | editar | Não | Configuração de IVA | — |
| `modulos/compras/comum/Rececoes.tsx:164` | EscolherEncomenda: Registar recepção | outro | Não | Escolha da encomenda a receber | — |
| `modulos/compras/comum/Rececoes.tsx:258` | DetalheRececao: Validar a recepção … | confirmação | Não | Validar a recepção | — |
| `modulos/compras/comum/accoesLista.tsx:66` | ComDocumento: (a carregar documento) | outro | Não | Indicador de carregamento (modal vazio com esqueleto) | — |
| `modulos/compras/comum/accoesLista.tsx:105` | ModalDeliberacao: Deliberação por valor — pedido … | detalhe só leitura | Não | Etapas de deliberação (só leitura) | — |
| `modulos/compras/contratos/DetalheContrato.tsx:116` | DetalheContrato: Modal.confirm · Retirar a encomenda … do contrato? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/compras/contratos/DetalheContrato.tsx:178` | DetalheContrato: Modal.confirm · Eliminar o marco «…»? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/compras/contratos/DetalheContrato.tsx:203` | DetalheContrato: Associar encomendas | outro | Não | Associar encomendas ao contrato | — |
| `modulos/compras/contratos/DetalheContrato.tsx:220` | DetalheContrato: Editar marco / Novo marco | criar+editar | Sim | Marcos de contrato repetíveis; Grade só no modo criar | Não |
| `modulos/compras/contratos/ModalContrato.tsx:46` | ModalContrato: Editar contrato … / Novo contrato | criar+editar | Sim (?) | Contratos de fornecimento; Grade só no modo criar | Não |
| `modulos/compras/encomendas/Encomendas.tsx:82` | ModalContratoEncomenda: Contrato da encomenda … | editar | Não | Contrato da encomenda | — |
| `modulos/compras/faturacao/FaturacaoCompras.tsx:188` | ModalEditarFatura: Editar fatura … | editar | Não | Editar factura existente | — |
| `modulos/compras/pedidos/DetalhePedido.tsx:163` | DetalhePedido: Decisão — … | confirmação | Não | Decisão de aprovação | — |
| `modulos/compras/pedidos/ModalEscaloes.tsx:42` | ModalEscaloes: Escalões de aprovação por valor | editar | Não | Escalões de aprovação (já em grelha) | — |
| `modulos/compras/prospeccao/DetalheProposta.tsx:84` | DetalheProposta: Modal.confirm · Anular a proposta …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/compras/prospeccao/DetalheProposta.tsx:130` | DetalheProposta: Adjudicar a proposta … | outro | Não | Adjudicar proposta e gerar encomenda | — |

### `modulos/config` (25 ocorrências; 5 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/config/ConfigGeral.tsx:192` | Importar: Modal.confirm · Importar a cópia? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/config/ConfigGeral.tsx:262` | Clonar: Modal.confirm · Criar a empresa e clonar a estrutura? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/config/Empresas.tsx:153` | FichaEmpresa: Nova empresa / Empresa — … | criar+editar | Não (?) | Ficha de empresa extensa; criação rara | Não |
| `modulos/config/Manutencao.tsx:260` | DetalhePedido: Drawer · Pedido #… — … / Pedido | detalhe só leitura | Não | Detalhe do pedido de manutenção | — |
| `modulos/config/Manutencao.tsx:317` | ModalDecisao: Decisão sobre o pedido #… | confirmação | Não | Decisão sobre pedido | — |
| `modulos/config/Moedas.tsx:206` | ListaMoedas: Nova moeda / Moeda … | criar+editar | Sim | Cadastro de moedas; Grade só no modo criar | Não |
| `modulos/config/Moedas.tsx:359` | Cambios: Novo câmbio / Editar câmbio | criar+editar | Sim | Câmbios repetíveis (data, moeda, taxa); Grade só no modo criar | Não |
| `modulos/config/Moedas.tsx:483` | Bai: Modal.warning · Câmbios não substituídos | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/config/Moedas.tsx:592` | BaiAutomatico: Modal.warning · Câmbios não gravados | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/config/Moedas.tsx:684` | BaiAutomatico: Rejeitar câmbios do BAI | confirmação | Não | Rejeitar câmbios do BAI | — |
| `modulos/config/Perfis.tsx:212` | EditorPerfil: Modal.warning · Gravado com conflitos de segregação | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/config/Perfis.tsx:219` | EditorPerfil: Modal.confirm · Segregação de funções | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/config/Perfis.tsx:246` | EditorPerfil: Drawer · Novo perfil / Perfil — … | criar+editar | Não | Perfil com matriz de permissões própria (gaveta) | Não |
| `modulos/config/Perfis.tsx:284` | EditorPerfil: Modal.confirm · Aplicar o modelo «…»? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/config/PlanoContas.tsx:164` | Contas: Nova conta / Conta … | criar+editar | Sim | Plano de contas; Grade só no modo criar | Sim |
| `modulos/config/RestauroLegado.tsx:142` | RestauroLegado: Modal.confirm · Cancelar o restauro #…? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/config/RestauroLegado.tsx:310` | ModalAprovar: Aprovar o restauro #… | confirmação | Não | Aprovar restauro | — |
| `modulos/config/RestauroLegado.tsx:341` | ModalExecutar: Executar o restauro #… | confirmação | Não | Executar restauro | — |
| `modulos/config/UnidadesNegocio.tsx:120` | UnidadesNegocio: Nova unidade de negócio / Unidade de negócio — … | criar+editar | Sim | Cadastro de unidades de negócio; Grade só no modo criar | Sim |
| `modulos/config/Utilizadores.tsx:253` | ModalUtilizador: Novo utilizador / Editar — … | criar+editar | Sim | Cadastro de utilizadores; Grade só no modo criar | Não |
| `modulos/config/Utilizadores.tsx:345` | ModalPalavraPasse: Repor palavra-passe — … | outro | Não | Repor palavra-passe | — |
| `modulos/config/plano/PlanoContasParidade.tsx:87` | CopiarOutraEmpresa: Modal.confirm · Copiar … conta(s) de «…»? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/config/plano/PlanoContasParidade.tsx:96` | CopiarOutraEmpresa: Copiar Plano de Contas | outro | Não | Copiar plano de outra empresa | — |
| `modulos/config/plano/PlanoContasParidade.tsx:212` | ImportarPlano: Importar Plano de Contas (Excel) | outro | Não | Importar plano (Excel) | — |
| `modulos/config/validacoes/Validacoes.tsx:154` | DetalheValidacaoGaveta: Drawer · Detalhe da validação | detalhe só leitura | Não | Detalhe da validação (gaveta) | — |

### `modulos/contab` (44 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/contab/Consolidacao.tsx:139` | Consolidacao: Novo grupo de consolidação / Editar grupo de consolidação | criar+editar | Não (?) | Grupo de consolidação com lista de empresas; criação rara | Não |
| `modulos/contab/Consolidacao.tsx:242` | PainelGrupo: Modal.confirm · Consolidar até … em …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/Consolidacao.tsx:291` | DetalheExecucao: Execução … | detalhe só leitura | Não | Detalhe da execução | — |
| `modulos/contab/Encerramento.tsx:80` | Encerramento: Modal.confirm · Encerrar o exercício de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/Encerramento.tsx:204` | Encerramento: Passo … — … / Pré-visualização | detalhe só leitura | Não | Pré-visualização do passo de encerramento | — |
| `modulos/contab/Encerramento.tsx:283` | Encerramento: Reabrir o exercício de … / Cancelar o apuramento de … | confirmação | Não | Reabrir/cancelar apuramento (motivo) | — |
| `modulos/contab/IAAssistente.tsx:231` | ListaRegras: Regra já existente | confirmação | Não | Substituir regra existente | — |
| `modulos/contab/IAAssistente.tsx:250` | VerRegra: Drawer · … (v…) / Regra | detalhe só leitura | Não | Ver regra (gaveta) | — |
| `modulos/contab/IAAssistente.tsx:421` | ResultadosDias: Validações por cumprir | confirmação | Não | Continuar com validações por cumprir | — |
| `modulos/contab/ImpostoSelo.tsx:115` | ImpostoSelo: Modal.confirm · Lançar … Kz de Imposto de Selo? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/MapeamentoSalarios.tsx:316` | MapeamentoSalarios: Copiar Mapeamento contábil | outro | Não | Copiar mapeamento contábil | — |
| `modulos/contab/RelatorioContas.tsx:140` | RelatorioContas: Modal.confirm · O relatório tem erros | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/RelatorioContas.tsx:177` | RelatorioContas: Modal.warning · Exercício em aberto | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/contab/RelatorioContas.tsx:181` | RelatorioContas: Modal.confirm · Concluir o relatório de … | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/RelatorioContas.tsx:196` | RelatorioContas: Modal.confirm · O relatório tem erros | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/RelatorioContas.tsx:210` | RelatorioContas: Modal.confirm · Tem alterações por gravar | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/RelatorioContas.tsx:288` | RelatorioContas: Modal.confirm · Reabrir o relatório de … | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/Rotinas.tsx:400` | Historico: Anular a rotina … | confirmação | Não | Anular rotina | — |
| `modulos/contab/TabelasAux.tsx:192` | TabelaSimples: Copiar … de outra empresa | outro | Não | Copiar tabela de outra empresa | — |
| `modulos/contab/TabelasAux.tsx:201` | TabelaSimples: Enviar … para outra empresa | outro | Não | Enviar registo para outra empresa | — |
| `modulos/contab/TabelasAux.tsx:312` | PlanoContas: Nova conta / Editar conta … | criar+editar | Sim | Plano de contas (Tabelas auxiliares); Grade só no modo criar | Sim |
| `modulos/contab/TabelasAux.tsx:390` | Reciclagem: Modal.confirm · Esvaziar a reciclagem? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/comum/Demonstracao.tsx:127` | DetalheNota: Nota … — … / Nota … | detalhe só leitura | Não | Detalhe da nota | — |
| `modulos/contab/comum/Demonstracao.tsx:180` | MovimentosSemNota: Movimentos por mapear (sem nota DEMO) | detalhe só leitura | Não | Movimentos sem nota | — |
| `modulos/contab/comum/ficheiros.tsx:65` | ModalImportar: Importar ficheiro (título por parâmetro) | outro | Não | Importação de ficheiro | — |
| `modulos/contab/lancamentos/DetalheLancamento.tsx:198` | DetalheLancamento: Estornar … | confirmação | Não | Estornar lançamento | — |
| `modulos/contab/lancamentos/ListaLancamentos.tsx:182` | ListaLancamentos: Modal.confirm · Alterar em massa | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/lancamentos/ModaisLancamento.tsx:71` | ModalClassificacao: Classificação da linha (título por parâmetro) | editar | Não | Classificação de uma linha de lançamento | — |
| `modulos/contab/lancamentos/ModaisLancamento.tsx:149` | ModalTransferir: Transferir Lançamento para Outra Empresa | outro | Não | Transferir lançamento para outra empresa | — |
| `modulos/contab/lancamentos/ModaisParidade.tsx:27` | ModalColar: Colar valores (título por parâmetro) | outro | Não | Colar valores | — |
| `modulos/contab/lancamentos/ModaisParidade.tsx:100` | ModalDescontabilizar: Descontabilizar Selecionados | confirmação | Não | Descontabilizar seleccionados | — |
| `modulos/contab/lancamentos/ModaisParidade.tsx:178` | ModalEstornar: Estornar … | confirmação | Não | Estornar | — |
| `modulos/contab/lancamentos/ModaisParidade.tsx:260` | ModalFluxoLancamento: Fluxo do lançamento | detalhe só leitura | Não | Fluxo do lançamento | — |
| `modulos/contab/lancamentos/ModalSaldosHistoricos.tsx:123` | ModalSaldosHistoricos: Modal.confirm · Sair sem gravar? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/lancamentos/ModalSaldosHistoricos.tsx:148` | ModalSaldosHistoricos: Importar Histórico de Exercício (Saldos Iniciais) | editar | Não | Saldos históricos em grelha própria | — |
| `modulos/contab/lancamentos/ModalSaldosHistoricos.tsx:178` | ModalSaldosHistoricos: Modal.confirm · Gravar o histórico de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/lancamentos/ModalSaldosHistoricos.tsx:188` | ModalSaldosHistoricos: Modal.confirm · Mudar de ano sem gravar? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/mapas/ControloDesequilibrios.tsx:68` | ControloDesequilibrios: Controlo de desequilíbrios | detalhe só leitura | Não | Controlo de desequilíbrios | — |
| `modulos/contab/mapas/MapaExtrato.tsx:408` | MapaExtrato: Colar múltiplas Contas | outro | Não | Colar múltiplas contas (filtro) | — |
| `modulos/contab/mapas/MapaExtrato.tsx:425` | MapaExtrato: Regularizar diferença de … Kz | outro | Não | Regularizar diferença (gera lançamento) | — |
| `modulos/contab/mapas/MapaExtrato.tsx:447` | MapaExtrato: Compensação … | detalhe só leitura | Não | Detalhe da compensação | — |
| `modulos/contab/mapas/MapaExtrato.tsx:459` | MapaExtrato: Modal.confirm · Reverter a compensação …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/tabelasAux/ContasBancariasTerceiro.tsx:100` | ContasBancariasTerceiro: Modal.confirm · Eliminar a conta …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/contab/tabelasAux/SeparadorTerceiros.tsx:323` | SeparadorTerceiros: Modal.confirm · Eliminar …? | confirmação | Não | Diálogo de confirmação imperativo | — |

### `modulos/crm` (16 ocorrências; 4 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/crm/Campanhas.tsx:184` | Campanhas: Modal.confirm · Registar a campanha para … conta(s)? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/crm/Campanhas.tsx:220` | Campanhas: Ver campanha (assunto) | detalhe só leitura | Não | Ver campanha | — |
| `modulos/crm/ConfigCRM.tsx:179` | EditorFunil: Novo pipeline / Editar pipeline — … | criar+editar | Não | Pipeline com etapas próprias | Não |
| `modulos/crm/ConfigCRM.tsx:295` | Modelos: Novo modelo de email / Editar modelo — … | criar+editar | Não (?) | Modelos de email com texto longo | Não |
| `modulos/crm/ConfigCRM.tsx:357` | Sequencias: Nova sequência de emails / Editar sequência — … | criar+editar | Não | Sequência com passos próprios | Não |
| `modulos/crm/Contas.tsx:105` | ModalDoCliente: Acrescentar cliente existente | outro | Não | Acrescentar cliente existente | — |
| `modulos/crm/Contas.tsx:125` | FormConta: Novo prospect / Editar conta | criar+editar | Sim | Contas/prospects CRM; Grade só no modo criar | Não |
| `modulos/crm/Contas.tsx:162` | FichaConta: Drawer · Ficha da conta | detalhe só leitura | Não | Ficha da conta (gaveta) | — |
| `modulos/crm/Contas.tsx:322` | FichaConta: Passar o prospect a cliente | confirmação | Não | Passar prospect a cliente | — |
| `modulos/crm/Contas.tsx:349` | FormContacto: Novo contacto / Editar contacto | criar+editar | Sim | Contactos repetíveis; Grade só no modo criar | Não |
| `modulos/crm/comum/Oportunidade.tsx:71` | FichaOportunidade: Drawer · Ficha da oportunidade | detalhe só leitura | Não | Ficha da oportunidade (gaveta) | — |
| `modulos/crm/comum/Oportunidade.tsx:205` | FichaOportunidade: Converter em … | outro | Não | Converter oportunidade em documento | — |
| `modulos/crm/comum/Oportunidade.tsx:309` | FormOportunidade: Editar oportunidade / Nova oportunidade | criar+editar | Sim (?) | Oportunidades repetíveis; Grade só no modo criar (formulário extenso) | Não |
| `modulos/crm/comum/componentes.tsx:65` | ModalMoverEtapa: Mover para «…» | outro | Não | Mover oportunidade de etapa | — |
| `modulos/crm/comum/componentes.tsx:128` | ModalActividade: Nova actividade / Editar actividade | criar+editar | Sim | Actividades repetíveis; Grade só no modo criar | Não |
| `modulos/crm/comum/componentes.tsx:249` | ModalEmail: Enviar email | outro | Não | Enviar email | — |

### `modulos/estrutura` (11 ocorrências; 3 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/estrutura/Estrutura.tsx:383` | FormUnidade: Nova unidade orgânica / Editar … | criar+editar | Sim | Unidades orgânicas; Grade só no modo criar | Não |
| `modulos/estrutura/Estrutura.tsx:426` | FormPosto: Novo cargo — … / Editar cargo | criar+editar | Sim | Cargos da unidade; Grade só no modo criar | Não |
| `modulos/estrutura/Estrutura.tsx:476` | ModalAfectarUm: Modal.warning · Afectação gravada | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/estrutura/Estrutura.tsx:484` | ModalAfectarUm: Afectação — … | editar | Não | Afectação de um colaborador | — |
| `modulos/estrutura/Estrutura.tsx:531` | ModalAfectarEmMassa: Afectar colaboradores | outro | Não | Aviso (sem unidades) do assistente de afectação | — |
| `modulos/estrutura/Estrutura.tsx:544` | ModalAfectarEmMassa: Modal.warning · … colaborador(es) afectado(s) | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/estrutura/Estrutura.tsx:553` | ModalAfectarEmMassa: Afectar colaboradores | outro | Não | Afectação em massa (selecção de colaboradores) | — |
| `modulos/estrutura/Estrutura.tsx:642` | Cargos: Novo cargo / Editar cargo | criar+editar | Sim | Cadastro de cargos; Grade só no modo criar | Não |
| `modulos/estrutura/Organigrama.tsx:348` | DetalheCaixa: Unidade orgânica (detalhe) | detalhe só leitura | Não | Detalhe da caixa do organigrama | — |
| `modulos/estrutura/comum/ModaisCriacao.tsx:65` | ModalVariasUnidades: Várias unidades no mesmo nível | criar | Não | Já cria várias unidades em tabela | Não |
| `modulos/estrutura/comum/ModaisCriacao.tsx:135` | ModalVariasUnidades: Modal.confirm · Criar estrutura base | confirmação | Não | Diálogo de confirmação imperativo | — |

### `modulos/geral` (5 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/geral/integracoes/AssistenteIA.tsx:92` | AssistenteIA: Drawer · Assistente IA para lançamentos | outro | Não | Assistente IA (gaveta) | — |
| `modulos/geral/integracoes/AssistenteIA.tsx:280` | Regras: Nova regra interna / Regra interna | criar+editar | Sim (?) | Regras internas do assistente IA; Grade só no modo criar | Não |
| `modulos/geral/integracoes/PainelPowerBI.tsx:66` | PainelPowerBI: Drawer · Power BI — feed OData de leitura | outro | Não | Painel Power BI (gaveta) | — |
| `modulos/geral/integracoes/PainelPowerBI.tsx:116` | PainelPowerBI: Novo token de leitura | criar | Não | Token de leitura (único, raro) | Não |
| `modulos/geral/integracoes/PainelPowerBI.tsx:129` | PainelPowerBI: Token criado | detalhe só leitura | Não | Mostra o token criado | — |

### `modulos/metodo` (2 ocorrências; 0 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/metodo/Assuntos.tsx:161` | HistoricoAssunto: Histórico — … | detalhe só leitura | Não | Histórico do assunto | — |
| `modulos/metodo/FormularioAssunto.tsx:203` | FormularioAssunto: Novo assunto / Assunto … | criar+editar | Não | Assunto com fluxo e formulário extenso | Não |

### `modulos/orcamento` (10 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/orcamento/Alertas.tsx:96` | ModalDecidir: Excesso em … | confirmação | Não | Decisão sobre excesso | — |
| `modulos/orcamento/Cenarios.tsx:247` | ModalNovo: Novo cenário | criar | Não | Cenário derivado de orçamento (raro) | Não |
| `modulos/orcamento/Controlo.tsx:247` | ModalAnaliseDesvio: Análise do desvio … | detalhe só leitura | Não | Análise do desvio | — |
| `modulos/orcamento/DetalheOrcamento.tsx:160` | ModalRepartir: Repartir o orçamento pelos filhos | outro | Não | Repartir orçamento pelos filhos | — |
| `modulos/orcamento/DetalheOrcamento.tsx:202` | ModalContributos: Pedir contributos (bottom-up) | outro | Não | Pedir contributos | — |
| `modulos/orcamento/Orcamentos.tsx:122` | ModalNovoOrcamento: Novo orçamento | criar | Não | Orçamento: documento com grelha própria | Não |
| `modulos/orcamento/Previsoes.tsx:82` | ModalNova: Nova previsão | criar | Não | Previsão: documento com grelha própria | Não |
| `modulos/orcamento/Previsoes.tsx:196` | NovaRevisao: Nova revisão da previsão | criar | Não | Nova revisão da previsão (acção) | Não |
| `modulos/orcamento/Rubricas.tsx:113` | ModalRubrica: Editar rubrica … / Nova rubrica | criar+editar | Sim | Rubricas orçamentais; Grade só no modo criar | Não |
| `modulos/orcamento/comum/GrelhaMensal.tsx:134` | GrelhaMensal: Repartir … por 12 meses | outro | Não | Repartir por 12 meses | — |

### `modulos/pos` (43 ocorrências; 3 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/pos/Desvios.tsx:207` | ModalDeliberar: Deliberar desvio — … | confirmação | Não | Deliberar desvio de caixa | — |
| `modulos/pos/FrenteCaixa.tsx:117` | FrenteImersiva: Modal.confirm · Confirmar | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/Prestacao.tsx:97` | PorRegularizar: Modal.warning · Transferências com erro | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/pos/Prestacao.tsx:275` | ModalRegistar: Prestar … · … Kz | outro | Não | Registar prestação de contas de um item | — |
| `modulos/pos/Terminais.tsx:164` | Terminais: Modal.confirm · Eliminar o terminal …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/Terminais.tsx:316` | EditorTerminal: Novo terminal / Configurar terminal | criar+editar | Não (?) | Terminal com meios de pagamento e definições próprias | Não |
| `modulos/pos/Terminais.tsx:433` | EditorTerminal: Modal.confirm · Remover este meio de pagamento? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/Terminais.tsx:463` | EditorTerminal: Copiar meios de pagamento de outro terminal | outro | Não | Copiar meios de pagamento | — |
| `modulos/pos/Terminais.tsx:583` | ModalCopiarMeios: Copiar meios de pagamento para … | outro | Não | Copiar meios para terminal | — |
| `modulos/pos/comum/DefinicoesCaixa.tsx:37` | ModalDefinicoesCaixa: Definições de caixa (POS) | editar | Não | Definições de caixa | — |
| `modulos/pos/comum/DetalheSessao.tsx:35` | DetalheSessao: Drawer · Sessão … | detalhe só leitura | Não | Detalhe da sessão (gaveta) | — |
| `modulos/pos/contas/PreVisualizacaoIntegracao.tsx:82` | ModalPreVisualizacaoIntegracao: modal.info · Integração contabilística | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/pos/contas/PreVisualizacaoIntegracao.tsx:100` | ModalPreVisualizacaoIntegracao: Pré-visualização da integração | detalhe só leitura | Não | Pré-visualização da integração | — |
| `modulos/pos/frente/FechoZ.tsx:93` | FechoZ: Modal.confirm · Fechar a caixa …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/frente/FechoZ.tsx:109` | FechoZ: Fecho de caixa … | outro | Não | Fecho de caixa (Z) | — |
| `modulos/pos/frente/NovoClienteModal.tsx:37` | NovoClienteModal: Criar Novo Cliente | criar | Não | Criação rápida de cliente no balcão, durante a venda | Não |
| `modulos/pos/frente/PainelEstadia.tsx:63` | CheckinQuarto: Modal.confirm · O alojamento fica com preço 0. Continuar? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/frente/PainelMesas.tsx:87` | PainelMesas: Criar mesa | criar | Sim | Mesas repetíveis | Não |
| `modulos/pos/frente/PainelVenda.tsx:256` | PainelVenda: Modal.confirm · O carrinho do balcão tem artigos | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/frente/PainelVenda.tsx:424` | PainelVenda: Modal.confirm · Limpar o carrinho? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/frente/PainelVenda.tsx:642` | PainelVenda: Pagamento · … Kz | outro | Não | Pagamento da venda | — |
| `modulos/pos/frente/PainelVenda.tsx:665` | PainelVenda: Documento emitido | impressão | Não | Documento emitido (imprimir) | — |
| `modulos/pos/frente/RelatorioXModal.tsx:20` | RelatorioXModal: Relatório X | impressão | Não | Relatório X | — |
| `modulos/pos/hotelaria/Checkout.tsx:122` | Checkout: Check-out · … | outro | Não | Check-out | — |
| `modulos/pos/hotelaria/Estadia.tsx:39` | ModalCheckin: Check-in · … | outro | Não | Check-in (operação de balcão) | — |
| `modulos/pos/hotelaria/Estadia.tsx:127` | DetalheEstadia: Drawer · Estadia | detalhe só leitura | Não | Detalhe da estadia (gaveta) | — |
| `modulos/pos/hotelaria/Estadia.tsx:278` | ModalAlterar: Alterar estadia | editar | Não | Alterar estadia | — |
| `modulos/pos/lavandaria/DetalheOrdem.tsx:87` | DetalheOrdem: Drawer · Ordem de serviço | detalhe só leitura | Não | Detalhe da ordem de lavandaria (gaveta) | — |
| `modulos/pos/lavandaria/DetalheOrdem.tsx:113` | DetalheOrdem: Modal.confirm · Emitir a factura das linhas por facturar? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/lavandaria/DetalheOrdem.tsx:372` | ModalReceber: Receber · … | outro | Não | Receber pagamento | — |
| `modulos/pos/lavandaria/DetalheOrdem.tsx:430` | ModalEntregar: Entregar · … | outro | Não | Registar entrega | — |
| `modulos/pos/lavandaria/DetalheOrdem.tsx:481` | ModalReclamacao: Dano ou reclamação · … | criar | Não | Reclamação sobre uma peça concreta | Não |
| `modulos/pos/lavandaria/DetalheOrdem.tsx:500` | ModalMaterial: Consumo de material · … | outro | Não | Consumo de material (abate ao stock) | — |
| `modulos/pos/lavandaria/ImportarTabela.tsx:106` | ImportarTabela: Importar … | outro | Não | Importar tabela | — |
| `modulos/pos/lavandaria/Ordens.tsx:281` | Ordens: modal.warning · …: … | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/pos/lavandaria/Ordens.tsx:303` | ModalAtribuir: Atribuir responsável — … ordem(ns) | editar | Não | Atribuir responsável em massa | — |
| `modulos/pos/lavandaria/Ordens.tsx:387` | ModalAlterarEstado: Alterar estado | editar | Não | Alterar estado em massa | — |
| `modulos/pos/lavandaria/Ordens.tsx:518` | ModalOrcamentosMassa: Modal.confirm · Aprovar … orçamento(s) no total de … (…)? / Recusar … orçamento(s)? As peças são devolvidas sem custo. | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/pos/lavandaria/Ordens.tsx:531` | ModalOrcamentosMassa: Aprovar / recusar orçamentos | confirmação | Não | Aprovar/recusar orçamentos em massa | — |
| `modulos/pos/lavandaria/Reclamacoes.tsx:206` | ModalDecidir: Decisão da reclamação | confirmação | Não | Decisão da reclamação | — |
| `modulos/pos/lavandaria/Reclamacoes.tsx:254` | ModalPagar: Pagar indemnização · … Kz | outro | Não | Pagar indemnização | — |
| `modulos/pos/lavandaria/Tabelas.tsx:119` | ModalPeca: Peça … / Nova peça | criar+editar | Sim | Cadastro de peças; Grade só no modo criar | Não |
| `modulos/pos/lavandaria/Tabelas.tsx:261` | ModalServico: Serviço … / Novo serviço | criar+editar | Sim | Cadastro de serviços; Grade só no modo criar | Não |

### `modulos/projectos` (48 ocorrências; 9 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/projectos/DetalheProjecto.tsx:57` | DetalheProjecto: Modal.confirm · … o projecto? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/ModalProjecto.tsx:61` | ModalProjecto: Editar projecto … / Novo projecto | criar+editar | Sim | Cadastro de projectos; Grade só no modo criar | Sim |
| `modulos/projectos/comum/componentes.tsx:141` | ModalEliminar: Eliminar (título por parâmetro) | confirmação | Não | Eliminar (componente partilhado) | — |
| `modulos/projectos/separadores/CargaTrabalho.tsx:26` | ModalCargaTrabalho: Carga de trabalho da equipa | detalhe só leitura | Não | Carga de trabalho da equipa | — |
| `modulos/projectos/separadores/Equipa.tsx:89` | ModalMembro: Editar … / Adicionar membro | criar+editar | Sim | Membros da equipa; Grade só no modo criar | Sim |
| `modulos/projectos/separadores/Equipa.tsx:115` | ModalMassa: Adicionar vários colaboradores | criar | Não | Já adiciona vários colaboradores de uma vez | Sim |
| `modulos/projectos/separadores/Equipa.tsx:132` | ModalAlterar: Alterar … membro(s) | editar | Não | Alterar membros em massa | — |
| `modulos/projectos/separadores/Execucao.tsx:89` | SeparadorExecucao: Enviar para a Produtividade (RH) — … a … | outro | Não | Enviar para a Produtividade | — |
| `modulos/projectos/separadores/Horas.tsx:124` | ModalHoras: Registar horas | criar | Sim | Registos de horas repetíveis | Sim |
| `modulos/projectos/separadores/Horas.tsx:147` | ModalEquipamento: Imputar uso de equipamento | criar | Sim | Usos de equipamento repetíveis | Sim |
| `modulos/projectos/separadores/Kanban.tsx:110` | ModalColunas: Colunas do Kanban | editar | Não | Colunas do Kanban | — |
| `modulos/projectos/separadores/Orcamento.tsx:152` | ModalLinha: Editar rubrica / Nova rubrica do orçamento | criar+editar | Sim | Linhas de orçamento do projecto; Grade só no modo criar | Sim |
| `modulos/projectos/separadores/Orcamento.tsx:175` | ModalAditamento: Editar aditamento / Novo aditamento | criar+editar | Sim | Aditamentos repetíveis; Grade só no modo criar | Sim |
| `modulos/projectos/separadores/OrcamentoQuantidades.tsx:53` | ModalMapearQuantidade: Mapear o orçamento pelas quantidades | outro | Não | Mapear orçamento por quantidades | — |
| `modulos/projectos/separadores/Organigrama.tsx:140` | ModalPosicao: Editar «…» / Nova posição | criar+editar | Sim | Posições do organigrama; Grade só no modo criar | Não |
| `modulos/projectos/separadores/Organigrama.tsx:174` | ModalAlocar: Alocar membros a «…» | editar | Não | Alocar membros a posição | — |
| `modulos/projectos/separadores/Organigrama.tsx:177` | ModalAlocar: Modal.confirm · Excede as vagas da posição | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/OrganigramaAccoes.tsx:30` | ModalPosicoesLote: Várias posições | criar | Não | Já cria várias posições em lote | Não |
| `modulos/projectos/separadores/OrganigramaAccoes.tsx:62` | ModalAssociarTarefas: Tarefas da posição «…» | editar | Não | Tarefas da posição | — |
| `modulos/projectos/separadores/OrganigramaAccoes.tsx:81` | ModalMapearOrcamento: Mapear o orçamento ao organigrama | outro | Não | Mapear orçamento ao organigrama | — |
| `modulos/projectos/separadores/Planeamento.tsx:35` | Planeamento: Modal.warning · Gravado, mas reveja | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/projectos/separadores/Planeamento.tsx:84` | SeparadorPlaneamento: Modal.confirm · Mudar a fase de nível? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:126` | SeparadorPlaneamento: Modal.confirm · Remover a precedência? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:422` | ModalTarefa: Modal.confirm · Datas fora do nível acima | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:428` | ModalTarefa: Modal.confirm · Precedência | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:476` | ModalTarefa: Modal.confirm · Concluir abaixo da meta? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:517` | ModalTarefa: Editar tarefa «…» / Nova subtarefa / Nova tarefa | criar+editar | Sim | Piloto «Nova tarefa»; Grade só no modo criar | Sim |
| `modulos/projectos/separadores/Planeamento.tsx:604` | ModalMarco: Editar milestone / Novo milestone | criar+editar | Sim | Piloto «Nova Fase / Milestone»; Grade só no modo criar | Sim |
| `modulos/projectos/separadores/Planeamento.tsx:575` | ModalExecucao: Execução de «…» | editar | Não | Execução da tarefa | — |
| `modulos/projectos/separadores/Planeamento.tsx:612` | ModalLigacao: Modal.confirm · Precedência | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:617` | ModalLigacao: Modal.confirm · Datas fora do nível acima | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/Planeamento.tsx:637` | ModalLigacao: Ligar tarefas (precedência) | criar | Não (?) | Ligação de precedência entre duas tarefas (actualiza a tarefa) | Não |
| `modulos/projectos/separadores/Requisicoes.tsx:83` | ModalRequisicao: Nova requisição de material | criar | Não | Requisição: documento com linhas próprias | Não |
| `modulos/projectos/separadores/Revisoes.tsx:218` | ModalNovaRevisao: Revisão mensal (auto de medição) | criar | Não | Auto de medição: documento com linhas próprias | Não |
| `modulos/projectos/separadores/Revisoes.tsx:280` | DetalheAuto: Drawer · Auto … — … … / Auto de medição | detalhe só leitura | Não | Detalhe do auto (gaveta) | — |
| `modulos/projectos/separadores/Revisoes.tsx:330` | ModalFaturar: Facturar o auto … | outro | Não | Facturar o auto | — |
| `modulos/projectos/separadores/planeamento/MapaQuantidades.tsx:71` | ModalMapaQuantidades: Mapa de quantidades | detalhe só leitura | Não | Mapa de quantidades | — |
| `modulos/projectos/separadores/planeamento/ModaisHierarquia.tsx:25` | ModaisHierarquia: Modal.warning · Reveja as datas | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/projectos/separadores/planeamento/ModaisHierarquia.tsx:33` | ModalDuplicar: Duplicar … | outro | Não | Duplicar fase/tarefa | — |
| `modulos/projectos/separadores/planeamento/ModaisHierarquia.tsx:72` | ModalNivel: Mudar de nível | outro | Não | Mudar de nível | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:41` | ModalMedicao: Registar execução — … | criar | Não | Medição de uma tarefa; a Folha de medição cobre o lote | Não |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:93` | ModalFolhaMedicao: Modal.confirm · Registar medições | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:108` | ModalFolhaMedicao: Modal.warning · Folha de medição | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:109` | ModalFolhaMedicao: Modal.success · Folha de medição | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:119` | ModalFolhaMedicao: Folha de medição | outro | Não | Folha de medição (grelha própria) | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:152` | ModalFolhaMedicao: Modal.warning · Colagem parcial | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:162` | ModalColar: Colar medições | outro | Não | Colar medições | — |
| `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:208` | ModalTarefasQuantidade: Criar tarefas por quantidade | criar | Não | Já cria várias tarefas por quantidade | Não |

### `modulos/rh` (94 ocorrências; 9 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/rh/Assiduidade.tsx:88` | BotaoFecharEfectividade: Modal.confirm · Fechar a efectividade de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Assiduidade.tsx:179` | Registos: Importar efectividade (CSV / XLSX) | outro | Não | Importar efectividade | — |
| `modulos/rh/Assiduidade.tsx:192` | Registos: Resultado da importação | detalhe só leitura | Não | Resultado da importação | — |
| `modulos/rh/Assiduidade.tsx:258` | Registos: Registo de efectividade | criar | Sim (?) | Registos de efectividade repetíveis | Não |
| `modulos/rh/Assiduidade.tsx:374` | Apuramento: Modal.confirm · Fechar a efectividade de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Assiduidade.tsx:400` | Apuramento: Reabrir a efectividade do mês | confirmação | Não | Reabrir efectividade | — |
| `modulos/rh/Assiduidade.tsx:494` | Ausencias: Registar ausência / Justificar falta | criar+editar | Sim | Ausências repetíveis; Grade só no modo criar | Não |
| `modulos/rh/Assiduidade.tsx:511` | Ausencias: Decidir ausência | confirmação | Não | Decidir ausência | — |
| `modulos/rh/Avaliacao.tsx:131` | ItensEspecificos: Itens específicos — … | outro | Não | Lista de itens específicos do colaborador | — |
| `modulos/rh/Avaliacao.tsx:137` | ItensEspecificos: Novo item específico | criar | Sim | Itens específicos repetíveis | Não |
| `modulos/rh/Avaliacao.tsx:218` | FormularioAvaliacao: Drawer · Avaliação — … | editar | Não | Formulário de avaliação (gaveta) | — |
| `modulos/rh/Avaliacao.tsx:223` | FormularioAvaliacao: Modal.confirm · Concluir a avaliação? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Avaliacao.tsx:291` | FormularioAvaliacao: Registar a tomada de conhecimento pelo RH | confirmação | Não | Tomada de conhecimento | — |
| `modulos/rh/AvaliacaoCiclo.tsx:139` | Avaliacoes: Parecer do RH na contestação | outro | Não | Parecer do RH | — |
| `modulos/rh/AvaliacaoCiclo.tsx:146` | Avaliacoes: Decidir a contestação | confirmação | Não | Decidir contestação | — |
| `modulos/rh/AvaliacaoCiclo.tsx:155` | Avaliacoes: Resultado 360º — … | detalhe só leitura | Não | Resultado 360° | — |
| `modulos/rh/AvaliacaoCiclo.tsx:222` | Bonificacoes: Modal.confirm · (título por parâmetro) | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/AvaliacaoConfig.tsx:108` | AvaliacaoConfig: Modal.confirm · Abrir o ciclo? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/AvaliacaoConfig.tsx:112` | AvaliacaoConfig: Modal.confirm · Fechar o ciclo? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Bancario.tsx:168` | IbanColaboradores: Adicionar IBAN | criar | Sim | IBAN de colaboradores repetíveis | Não |
| `modulos/rh/Bancario.tsx:182` | IbanColaboradores: Coordenadas bancárias — … | editar | Não | Coordenadas bancárias | — |
| `modulos/rh/Calcular.tsx:291` | PeriodoCalculo: Modal.confirm · Importar lançamentos dos contratos? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Calcular.tsx:294` | PeriodoCalculo: Modal.confirm · Importar as prestações dos empréstimos de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Calcular.tsx:295` | PeriodoCalculo: Modal.confirm · Anular os descontos de empréstimos deste período? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Calcular.tsx:304` | PeriodoCalculo: Modal.confirm · Encerrar o cálculo de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Calcular.tsx:325` | PeriodoCalculo: Modal.confirm · Copiar os lançamentos do mês seleccionado? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Calcular.tsx:459` | PeriodoCalculo: Novo lançamento / Editar lançamento | criar+editar | Sim (?) | Lançamentos salariais simples (colaborador, rubrica, valor); Grade só no modo criar | Não |
| `modulos/rh/Calcular.tsx:484` | PeriodoCalculo: Edição em massa (… lançamento(s)) | editar | Não | Edição em massa | — |
| `modulos/rh/Calcular.tsx:508` | PeriodoCalculo: O cálculo tem avisos | outro | Não | Avisos do cálculo | — |
| `modulos/rh/Calcular.tsx:517` | PeriodoCalculo: Importar efectividade | outro | Não | Importar efectividade | — |
| `modulos/rh/Calcular.tsx:525` | PeriodoCalculo: Importar produtividade | outro | Não | Importar produtividade | — |
| `modulos/rh/Calcular.tsx:535` | PeriodoCalculo: Resultado | detalhe só leitura | Não | Resultado da importação | — |
| `modulos/rh/Colaboradores.tsx:249` | FichaDoColaborador: Modal.confirm · Eliminar …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Contratos.tsx:180` | Contratos: Modal.warning · … contrato(s) não eliminado(s) | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/rh/Contratos.tsx:227` | Contratos: Modal.confirm · Eliminar os … contrato(s) seleccionado(s)? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Contratos.tsx:266` | Contratos: Novo contrato / Editar contrato | criar+editar | Sim | Contratos (um por colaborador); Grade só no modo criar | Sim |
| `modulos/rh/Contratos.tsx:377` | Contratos: Terminar contrato | outro | Não | Terminar contrato | — |
| `modulos/rh/Emprestimos.tsx:119` | Lista: Empréstimo … / Empréstimo | detalhe só leitura | Não | Detalhe do empréstimo | — |
| `modulos/rh/Emprestimos.tsx:144` | Lista: Lançar … na conta corrente | outro | Não | Lançar na conta corrente | — |
| `modulos/rh/Emprestimos.tsx:151` | Lista: Cancelar … | confirmação | Não | Cancelar empréstimo | — |
| `modulos/rh/Ferias.tsx:62` | Ferias: Modal.confirm · Exceder o direito a férias? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Ferias.tsx:64` | Ferias: Modal.confirm · Gozo antes de 6 meses de serviço? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Ferias.tsx:206` | Ferias: Marcar férias / Editar férias | criar+editar | Sim | Marcação de férias repetível; Grade só no modo criar | Não |
| `modulos/rh/Portal.tsx:125` | MeusPedidos: Pedido — … | criar | Não | Pedido individual do portal | Não |
| `modulos/rh/Portal.tsx:181` | MeusPedidos: Documento emitido | impressão | Não | Documento emitido | — |
| `modulos/rh/Portal.tsx:204` | Recibos: Recibo … | impressão | Não | Recibo | — |
| `modulos/rh/Portal.tsx:235` | Aprovacoes: Decidir pedido #… | confirmação | Não | Decidir pedido | — |
| `modulos/rh/Portal.tsx:278` | MinhasFaltas: Justificar falta | outro | Não | Justificar falta | — |
| `modulos/rh/PortalGestao.tsx:112` | Pedidos: Decidir pedido #… | confirmação | Não | Decidir pedido | — |
| `modulos/rh/PortalGestao.tsx:126` | Pedidos: Emitir documento — pedido #… | outro | Não | Emitir documento | — |
| `modulos/rh/PortalGestao.tsx:143` | Pedidos: Documento emitido | impressão | Não | Documento emitido | — |
| `modulos/rh/PortalGestao.tsx:209` | Modelos: Novo modelo / Editar modelo | criar+editar | Não (?) | Modelos de documento com texto longo | Não |
| `modulos/rh/PortalGestao.tsx:271` | Ligacoes: Ligar … a um colaborador | editar | Não | Ligar utilizador a colaborador | — |
| `modulos/rh/Processamento.tsx:111` | DetalheProcessamento: Modal.confirm · Validar o processamento de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Processamento.tsx:114` | DetalheProcessamento: Modal.confirm · Integração contábil de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Processamento.tsx:120` | DetalheProcessamento: Modal.confirm · Reabrir … para edição? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Processamento.tsx:176` | DetalheProcessamento: Descontabilizar (estorno) | confirmação | Não | Descontabilizar | — |
| `modulos/rh/Processamento.tsx:193` | VerificacaoDiario: Verificação das folhas contra o diário (SAL) | detalhe só leitura | Não | Verificação contra o diário | — |
| `modulos/rh/Processamento.tsx:228` | ConfiguracaoSegregacao: Configuração dos processamentos | editar | Não | Configuração (interruptor) | — |
| `modulos/rh/Produtividade.tsx:133` | AccoesPeriodo: Modal.confirm · Fechar o período? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/Produtividade.tsx:137` | AccoesPeriodo: Reabrir período de produtividade | confirmação | Não | Reabrir período | — |
| `modulos/rh/Produtividade.tsx:151` | NovoPeriodo: Novo período de produtividade | criar | Não | Período de produtividade (raro) | Não |
| `modulos/rh/Produtividade.tsx:401` | PainelPeriodo: Novo registo / Editar registo | criar+editar | Sim | Registos de produtividade repetíveis; Grade só no modo criar | Não |
| `modulos/rh/assiduidade/EscalaMes.tsx:135` | EscalaMes: Escala: colaborador — dia | detalhe só leitura | Não | Célula da escala (ver/eliminar) | — |
| `modulos/rh/assiduidade/EscalaMes.tsx:231` | OperacaoEscala: Operação sobre a escala (título por tipo) | outro | Não | Operação sobre a escala | — |
| `modulos/rh/comum/AMinhaEquipa.tsx:98` | AMinhaEquipa: Avaliação de … — … … | outro | Não | Avaliação de um membro da equipa | — |
| `modulos/rh/comum/AMinhaEquipa.tsx:136` | AMinhaEquipa: Reunião de acompanhamento — … | criar | Não | Reunião de acompanhamento de um colaborador | Não |
| `modulos/rh/comum/AvaliacoesPortalRH.tsx:60` | AutoavaliacoesRH: Autoavaliação — … | detalhe só leitura | Não | Autoavaliação | — |
| `modulos/rh/comum/AvaliacoesPortalRH.tsx:102` | AvaliacaoChefiasRH: Avaliação ascendente — … (… …) | detalhe só leitura | Não | Avaliação ascendente | — |
| `modulos/rh/comum/BotoesCadastroLegado.tsx:69` | ModalCopiar: Copiar … de outra empresa | outro | Não | Copiar cadastro de outra empresa | — |
| `modulos/rh/comum/CadastroSimples.tsx:175` | CadastroSimples: Novo … / Editar … (título por parâmetro; ex. «Nova Função») | criar+editar | Sim | Piloto «Nova Função» (`CadastroSimples`, usado em Funções, Infotipos, Bancos, itens); Grade só no modo criar | Sim |
| `modulos/rh/comum/Cartas.tsx:82` | CartasPeriodo: Emitir carta de pagamento | outro | Não | Emitir carta | — |
| `modulos/rh/comum/Cartas.tsx:98` | CartasPeriodo: Carta de pagamento #… | impressão | Não | Carta de pagamento | — |
| `modulos/rh/comum/ContratosMassa.tsx:63` | ContratosMassa: Aplicar rubricas em massa / Aplicar itens de produtividade em massa | editar | Não | Aplicar rubricas em massa | — |
| `modulos/rh/comum/ImportarExcel.tsx:84` | ImportarExcel: Importar Excel (título por parâmetro) | outro | Não | Importar Excel (componente partilhado) | — |
| `modulos/rh/comum/ListaPeriodos.tsx:54` | ListaPeriodos: Abrir período salarial | criar | Não | Abrir período salarial (raro) | Não |
| `modulos/rh/comum/MapeamentosEmFalta.tsx:54` | MapeamentosEmFalta: Mapeamentos contabilísticos em falta | detalhe só leitura | Não | Mapeamentos em falta | — |
| `modulos/rh/comum/PortalAvaliacao.tsx:151` | FormAutoavaliacao: Modal.confirm · Submeter a autoavaliação? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/rh/comum/PortalAvaliacao.tsx:165` | Modal360: Avaliação 360º — … | outro | Não | Avaliação 360° | — |
| `modulos/rh/comum/PortalAvaliacao.tsx:249` | MinhasAvaliacoes: Tomar conhecimento da avaliação | confirmação | Não | Tomar conhecimento | — |
| `modulos/rh/comum/PortalAvaliacao.tsx:255` | MinhasAvaliacoes: Contestar a avaliação | outro | Não | Contestar avaliação | — |
| `modulos/rh/comum/PortalAvaliacao.tsx:302` | ModalResultado360: Resultado 360º | detalhe só leitura | Não | Resultado 360° | — |
| `modulos/rh/comum/PortalAvaliacao.tsx:340` | Acompanhamento: Confirmar a reunião de acompanhamento | confirmação | Não | Confirmar reunião | — |
| `modulos/rh/comum/SimulacaoColaborador.tsx:28` | ModalSimulacaoColaborador: Simulação salarial / Detalhe salarial — … | detalhe só leitura | Não | Simulação/detalhe salarial | — |
| `modulos/rh/comum/SimulacaoColaborador.tsx:143` | ModalReciboColaborador: Recibo de vencimento — … | impressão | Não | Recibo de vencimento | — |
| `modulos/rh/comum/SimularMassa.tsx:35` | SimularMassa: Simular massa salarial (contratos) | detalhe só leitura | Não | Simulação de massa salarial | — |
| `modulos/rh/contratos/EstadoContrato.tsx:59` | Suspender: Suspender contrato | outro | Não | Suspender contrato | — |
| `modulos/rh/contratos/EstadoContrato.tsx:101` | Cessar: Cessar contrato (Lei 12/23) | outro | Não | Cessar contrato | — |
| `modulos/rh/contratos/EstadoContrato.tsx:147` | Observacoes: Observações (título por parâmetro) | editar | Não | Observações do contrato | — |
| `modulos/rh/contratos/EstadoContrato.tsx:163` | VerEstado: Estado do contrato | detalhe só leitura | Não | Estado do contrato | — |
| `modulos/rh/contratos/SubsidiosMinuta.tsx:24` | SubsidiosMinuta: Subsídios na minuta do contrato | editar | Não | Subsídios na minuta | — |
| `modulos/rh/folha/GerarPagamento.tsx:24` | BotaoGerarPagamento: Modal.warning · Pagamento ainda não disponível | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/rh/folha/GerarPagamento.tsx:38` | BotaoGerarPagamento: Pagamento de salários — … | outro | Não | Gerar pagamento de salários | — |
| `modulos/rh/relatorios/RelatoriosRecibos.tsx:156` | ModalRecibos: Recibos de vencimento — … | impressão | Não | Recibos de vencimento | — |

### `modulos/stock` (20 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/stock/Armazens.tsx:104` | Armazens: Registar Armazém / Editar Armazém | criar+editar | Sim | Cadastro de armazéns; Grade só no modo criar | Não |
| `modulos/stock/NiveisStock.tsx:120` | ModalAjuste: Ajuste manual de stock | criar | Não | Ajuste de stock: movimento | Não |
| `modulos/stock/NiveisStock.tsx:174` | ModalTransferencia: Transferência entre armazéns | criar | Não | Transferência entre armazéns: movimento | Não |
| `modulos/stock/PosArmazem.tsx:111` | VendasBalcao: Guia … / Guia | detalhe só leitura | Não | Detalhe da guia | — |
| `modulos/stock/PosArmazem.tsx:278` | Picking: Modal.confirm · Confirmar a expedição de …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/ValidarEntradas.tsx:107` | FluxoRececao: Drawer · Fluxo do Processo … | detalhe só leitura | Não | Fluxo da recepção (gaveta) | — |
| `modulos/stock/comum/ExtractoArtigo.tsx:28` | ExtractoArtigo: Drawer · Extracto do artigo … | detalhe só leitura | Não | Extracto do artigo (gaveta) | — |
| `modulos/stock/comum/Inventario.tsx:234` | ModalAbrir: Nova Sessão de Inventário | criar | Não | Sessão de inventário (rara) | Não |
| `modulos/stock/comum/Inventario.tsx:296` | DetalheSessao: Modal.warning · Há contagens por gravar | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/stock/comum/Inventario.tsx:300` | DetalheSessao: Modal.confirm · Há … produto(s) por contar | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/comum/Inventario.tsx:308` | DetalheSessao: Modal.confirm · Terminar a contagem? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/comum/Inventario.tsx:313` | DetalheSessao: Modal.confirm · Aprovar o inventário …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/comum/Inventario.tsx:434` | DetalheSessao: Modal.confirm · Voltar à contagem? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/comum/RecalculoValorizacoes.tsx:30` | RecalculoValorizacoes: Recalcular valorizações de stock | outro | Não | Recalcular valorizações | — |
| `modulos/stock/comum/RecalculoValorizacoes.tsx:48` | RecalculoValorizacoes: Modal.confirm · Aplicar o recálculo? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/posArmazem/Balcao.tsx:232` | Balcao: Modal.info · O carrinho de picking está vazio. | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/stock/posArmazem/Balcao.tsx:235` | Balcao: Modal.confirm · Confirmar saída | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/stock/posArmazem/Balcao.tsx:255` | Balcao: Guia … emitida | detalhe só leitura | Não | Resultado da emissão da guia | — |
| `modulos/stock/posArmazem/Balcao.tsx:277` | AcertoStock: Acertar stock deste armazém | outro | Não | Acerto de stock | — |
| `modulos/stock/posArmazem/Balcao.tsx:290` | AcertoStock: Modal.confirm · Aplicar o acerto de stock a … produto(s) neste armazém? | confirmação | Não | Diálogo de confirmação imperativo | — |

### `modulos/teso` (34 ocorrências; 1 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/teso/Conferencia.tsx:169` | Conferencia: Modal.confirm · Assinar a conferência como gerente? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/Conferencia.tsx:182` | Conferencia: Nova conferência de caixa / Editar conferência | criar+editar | Não | Conferência de caixa: documento de contagem | Não |
| `modulos/teso/Conferencia.tsx:223` | Conferencia: Finalizar conferência | confirmação | Não | Finalizar conferência | — |
| `modulos/teso/Conferencia.tsx:243` | Conferencia: Reabrir conferência | confirmação | Não | Reabrir conferência | — |
| `modulos/teso/FolhaCaixa.tsx:149` | ListaSessoes: Abrir sessão de caixa | outro | Não | Abrir sessão de caixa | — |
| `modulos/teso/FolhaCaixa.tsx:244` | DetalheSessao: Modal.confirm · Contabilizar a sessão? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/FolhaCaixa.tsx:311` | DetalheSessao: Registar movimento de caixa | criar | Não (?) | Movimento de caixa com contrapartida; avaliar com a tesouraria (Fase 2) | Não |
| `modulos/teso/FolhaCaixa.tsx:348` | DetalheSessao: Fechar sessão (contagem) | outro | Não | Fechar sessão (contagem) | — |
| `modulos/teso/FolhaCaixa.tsx:382` | DetalheSessao: Descontabilizar a sessão (estorno) | confirmação | Não | Descontabilizar sessão | — |
| `modulos/teso/Historico.tsx:107` | Integrados: Modal.warning · Alguns documentos não foram anulados | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/teso/Historico.tsx:144` | Integrados: Modal.info · (título da ajuda) | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/teso/Integracao.tsx:87` | PorIntegrar: Modal.confirm · Integrar … documento(s)? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/MeiosPagamento.tsx:140` | MeiosPagamento: Novo Meio de Pagamento / Editar Meio de Pagamento | criar+editar | Sim | Meios de pagamento; Grade só no modo criar | Não |
| `modulos/teso/ModalReparacao.tsx:89` | ModalReparacao: Resgatar Documento Bloqueado / Reparar Inconsistências | outro | Não | Reparação/resgate de documentos | — |
| `modulos/teso/ModalReparacao.tsx:100` | ModalReparacao: Modal.confirm · Repor … documento(s) por integrar? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/ReconciliacaoExtras.tsx:94` | ModalEditarLinhaExtrato: Editar linha do extracto | editar | Não | Editar linha do extracto | — |
| `modulos/teso/ReconciliacaoExtras.tsx:193` | HistoricoFiltrado: Reconciliação … | detalhe só leitura | Não | Detalhe da reconciliação | — |
| `modulos/teso/ReconciliacaoExtras.tsx:216` | HistoricoFiltrado: Anular a reconciliação … | confirmação | Não | Anular reconciliação | — |
| `modulos/teso/caixa/ModalClassificarMovimentos.tsx:38` | ModalClassificarMovimentos: Classificar … movimento(s) | editar | Não | Classificar movimentos seleccionados | — |
| `modulos/teso/caixa/ModalLiquidarFacturas.tsx:74` | ModalLiquidarFacturas: Modal.error · … documento(s) não foram registados | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/teso/caixa/ModalLiquidarFacturas.tsx:82` | ModalLiquidarFacturas: Liquidar facturas (título por configuração) | outro | Não | Assistente de liquidação de facturas | — |
| `modulos/teso/cartas/CartasPagamento.tsx:173` | DetalheCarta: Carta … (detalhe) | detalhe só leitura | Não | Detalhe da carta | — |
| `modulos/teso/cartas/ModalEmissao.tsx:82` | ModalEmissaoCarta: Modal.confirm · … beneficiário(s) já consta(m) de outra carta | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/cartas/ModalEmissao.tsx:189` | ModalEmissaoCarta: Emitir carta de ordem de pagamento | outro | Não | Assistente de emissão de carta | — |
| `modulos/teso/cartas/Modelos.tsx:49` | ModelosTexto: Modal.confirm · Repor o texto padrão deste tipo de carta? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/cartas/Modelos.tsx:77` | ModelosTexto: Modal.confirm · Eliminar o modelo «…»? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/cartas/Modelos.tsx:99` | ModelosTexto: Novo modelo / Editar modelo — tipo de carta | criar+editar | Não (?) | Modelos de carta com texto longo | Não |
| `modulos/teso/cartas/comum.ts:34` | comum: Modal.confirm · Emitir a carta de ordem de pagamento? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/motivo.tsx:18` | MINIMO_MOTIVO: Modal.confirm · Motivo (título por parâmetro) | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/pagamentos/DetalheDocumento.tsx:56` | DetalheDocumento: Modal.confirm · Integrar … na contabilidade? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/teso/pagamentos/DetalheDocumento.tsx:98` | DetalheDocumento: Anular documento / Anular integração (estorno) | confirmação | Não | Anular documento/integração | — |
| `modulos/teso/pagamentos/FormularioDocumento.tsx:256` | ModalPendentes: Documentos em aberto | outro | Não | Escolha de documentos em aberto para o documento de tesouraria. Fase 2: o formulário (página) exige agrupamento por documento | Sim |
| `modulos/teso/pagamentos/ListaDocumentos.tsx:111` | ListaDocumentos: Modal.warning · Alguns documentos não foram tratados | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/teso/pagamentos/ModalImportacao.tsx:50` | ModalImportacaoTesouraria: Importar documentos de tesouraria (Excel) | outro | Não | Importar documentos de tesouraria | — |

### `modulos/vendas` (21 ocorrências; 2 com Grade/Linhas)

| Ficheiro:linha | Ecrã / título | Finalidade | Grade/Linhas? | Justificação | Legado tem Linhas |
|---|---|---|---|---|---|
| `modulos/vendas/DetalheDocumento.tsx:183` | DetalheDocumento: Modal.confirm · Revalidar e reenviar … à AGT? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/DetalheDocumento.tsx:266` | DetalheDocumento: Converter documento | outro | Não | Converter documento | — |
| `modulos/vendas/DetalheDocumento.tsx:324` | DetalheDocumento: QR code — … | detalhe só leitura | Não | QR code | — |
| `modulos/vendas/DetalheDocumento.tsx:353` | DetalheDocumento: Pedido assinado (não enviado) | detalhe só leitura | Não | Pedido assinado | — |
| `modulos/vendas/DetalheDocumento.tsx:369` | DetalheDocumento: Descontabilizar (estorno) | confirmação | Não | Descontabilizar | — |
| `modulos/vendas/ListaDocumentos.tsx:129` | ListaDocumentos: Modal.confirm · (título por parâmetro) | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/ListaDocumentos.tsx:298` | ListaDocumentos: Modal.confirm · Facturar as guias seleccionadas | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/ListaDocumentos.tsx:347` | ListaDocumentos: Resultado da operação | detalhe só leitura | Não | Resultado da operação | — |
| `modulos/vendas/ModalFluxoProcesso.tsx:62` | ModalFluxoProcesso: Fluxo Completo do Processo | detalhe só leitura | Não | Fluxo do processo | — |
| `modulos/vendas/agt/FaturacaoEletronica.tsx:364` | Series: Modal.confirm · Eliminar a série … …? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/agt/FaturacaoEletronica.tsx:379` | Series: Nova série / Editar série | criar+editar | Sim | Séries AGT; Grade só no modo criar | Não |
| `modulos/vendas/produtos/ModalEditarMassaProdutos.tsx:63` | ModalEditarMassaProdutos: Editar em massa produto(s)/serviço(s) | editar | Não | Edição em massa de produtos | — |
| `modulos/vendas/produtos/ModalImportarProdutos.tsx:45` | ModalImportarProdutos: Importar … (Excel) | outro | Não | Importar produtos | — |
| `modulos/vendas/produtos/Produtos.tsx:397` | Categorias: Editar categoria | editar | Não | Só edição; a criação é no cartão «Nova Categoria» da página | Sim |
| `modulos/vendas/recebimentos/ModalCancelarRecebimentos.tsx:48` | ModalCancelarRecebimentos: Factura paga — cancelar o recebimento de … / Factura paga — cancelar o recebimento | confirmação | Não | Cancelar recebimento | — |
| `modulos/vendas/recibos/Recibos.tsx:51` | ListaRecibos: Modal.warning · Alguns recibos não foram tratados | outro | Não | Aviso/informação imperativo (sem formulário) | — |
| `modulos/vendas/recibos/Recibos.tsx:56` | ListaRecibos: Modal.confirm · Descontabilizar os recibos seleccionados | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/recibos/Recibos.tsx:422` | ModalAlocar: Alocar o adiantamento … | outro | Não | Alocar adiantamento | — |
| `modulos/vendas/series/SeriesNumeracao.tsx:50` | SeriesNumeracao: Modal.confirm · Fechar a série … … (…)? / Reabrir a série … … (…)? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/series/SeriesNumeracao.tsx:145` | SeriesNumeracao: Modal.confirm · Eliminar a série … … (…)? | confirmação | Não | Diálogo de confirmação imperativo | — |
| `modulos/vendas/series/SeriesNumeracao.tsx:213` | FormularioSerie: Alterar série … … / Nova série | criar+editar | Sim | Séries de numeração; Grade só no modo criar | Não |

## Anexo A — componentes de modal reutilizados

Componentes (ou ecrãs) que contêm um `<Modal>`/`<Drawer>` contado uma vez acima e que são invocados em vários sítios (contagem de `<Componente` em ficheiros não-teste).

| Componente | Definição | Invocações | Ficheiros |
|---|---|---:|---:|
| `ModalMotivo` | `componentes/Accoes.tsx:62` | 39 | 26 |
| `ImportarExcel` | `modulos/rh/comum/ImportarExcel.tsx:84` | 6 | 5 |
| `CadastroSimples` | `modulos/rh/comum/CadastroSimples.tsx:175` | 6 | 5 |
| `ModalFluxoDocumental` | `modulos/compras/comum/FluxoDocumental.tsx:120` | 5 | 5 |
| `ListaDocumentos` | `modulos/vendas/ListaDocumentos.tsx:347` | 5 | 2 |
| `TabelaSimples` | `modulos/contab/TabelasAux.tsx:192` | 4 | 1 |
| `ModalSimulacaoColaborador` | `modulos/rh/comum/SimulacaoColaborador.tsx:28` | 4 | 4 |
| `ModalImportar` | `modulos/contab/comum/ficheiros.tsx:65` | 4 | 4 |
| `ModalActivo` | `modulos/activos/ModalActivo.tsx:88` | 4 | 3 |
| `DetalheSessao` | `modulos/pos/comum/DetalheSessao.tsx:35` | 4 | 4 |
| `DetalheDocumento` | `modulos/vendas/DetalheDocumento.tsx:266` | 4 | 4 |
| `DetalheDocumento` | `modulos/teso/pagamentos/DetalheDocumento.tsx:98` | 4 | 4 |
| `ModalRececao` | `modulos/compras/comum/ModaisEncomenda.tsx:52` | 3 | 2 |
| `ModalProjecto` | `modulos/projectos/ModalProjecto.tsx:61` | 3 | 3 |
| `ModalIva` | `modulos/compras/comum/ModalIva.tsx:27` | 3 | 3 |
| `ModalItem` | `modulos/acrescimos/ModalItem.tsx:93` | 3 | 3 |
| `ModalContrato` | `modulos/compras/contratos/ModalContrato.tsx:46` | 3 | 2 |
| `ModalContas` | `modulos/compras/comum/ModalContas.tsx:30` | 3 | 3 |
| `ModalActividade` | `modulos/crm/comum/componentes.tsx:128` | 3 | 3 |
| `ImportarTabela` | `modulos/pos/lavandaria/ImportarTabela.tsx:106` | 3 | 1 |
| `FormOportunidade` | `modulos/crm/comum/Oportunidade.tsx:309` | 3 | 3 |
| `VisoesGuardadas` | `componentes/preferencias/VisoesGuardadas.tsx:79` | 2 | 2 |
| `UnidadesNegocio` | `modulos/config/UnidadesNegocio.tsx:120` | 2 | 2 |
| `ResultadosDias` | `modulos/contab/IAAssistente.tsx:421` | 2 | 1 |
| `RelatorioXModal` | `modulos/pos/frente/RelatorioXModal.tsx:20` | 2 | 2 |
| `Registos` | `modulos/rh/Assiduidade.tsx:179` | 2 | 1 |
| `Observacoes` | `modulos/rh/contratos/EstadoContrato.tsx:147` | 2 | 1 |
| `MovimentosSemNota` | `modulos/contab/comum/Demonstracao.tsx:180` | 2 | 2 |
| `ModalTransferir` | `modulos/contab/lancamentos/ModaisLancamento.tsx:149` | 2 | 2 |
| `ModalTransferir` | `modulos/activos/ModaisActivos.tsx:220` | 2 | 2 |
| `ModalRegularizar` | `modulos/acrescimos/DetalheItem.tsx:156` | 2 | 2 |
| `ModalMoverEtapa` | `modulos/crm/comum/componentes.tsx:65` | 2 | 2 |
| `ModalManutencao` | `modulos/activos/Manutencao.tsx:155` | 2 | 2 |
| `ModalImportarProdutos` | `modulos/vendas/produtos/ModalImportarProdutos.tsx:45` | 2 | 1 |
| `ModalFolhaMedicao` | `modulos/projectos/separadores/planeamento/ModaisMetas.tsx:119` | 2 | 2 |
| `ModalFaturaEncomenda` | `modulos/compras/comum/ModaisEncomenda.tsx:156` | 2 | 1 |
| `ModalEmail` | `modulos/crm/comum/componentes.tsx:249` | 2 | 2 |
| `ModalEliminar` | `modulos/projectos/comum/componentes.tsx:141` | 2 | 2 |
| `ModalClassificacao` | `modulos/contab/lancamentos/ModaisLancamento.tsx:71` | 2 | 2 |
| `ModalCancelarRecebimentos` | `modulos/vendas/recebimentos/ModalCancelarRecebimentos.tsx:48` | 2 | 2 |
| `ModalAlterar` | `modulos/pos/hotelaria/Estadia.tsx:278` | 2 | 2 |
| `ModalAfectacao` | `modulos/activos/ModaisActivos.tsx:255` | 2 | 2 |
| `ModalAbate` | `modulos/activos/ModalAbate.tsx:93` | 2 | 2 |
| `GestaoTerceiros` | `modulos/compras/comum/GestaoTerceiros.tsx:317` | 2 | 2 |
| `FichaOportunidade` | `modulos/crm/comum/Oportunidade.tsx:71` | 2 | 2 |
| `ExtractoArtigo` | `modulos/stock/comum/ExtractoArtigo.tsx:28` | 2 | 2 |
| `DetalheRececao` | `modulos/compras/comum/Rececoes.tsx:258` | 2 | 2 |
| `DetalheEstadia` | `modulos/pos/hotelaria/Estadia.tsx:127` | 2 | 1 |
| `ComDocumento` | `modulos/compras/comum/accoesLista.tsx:66` | 2 | 2 |
| `CartasPeriodo` | `modulos/rh/comum/Cartas.tsx:82` | 2 | 2 |
| `BotaoPreVisualizarLancamento` | `componentes/simulacoes/PreVisualizacaoLancamento.tsx:80` | 2 | 2 |
| `BotaoGerarPagamento` | `modulos/rh/folha/GerarPagamento.tsx:38` | 2 | 2 |

## 5. Como aplicar

Componente: `frontend/src/componentes/formularios/` (exporta `ModalFormulario`, `SeccaoFormulario`, `PainelLinhas`, `useFormularioLinhas`, `useVistaFormulario` e as conversões).

**Comportamento (igual ao legado `formulario_linhas.js`).**
- O selector **Grade | Linhas** aparece no título, à direita, só na criação (`criacao`). Na edição o modal mostra só a grade.
- A última vista fica guardada por utilizador e por formulário, na preferência `interface/vistas_listas`, entrada `formulario:<chave>`. Há uma cópia no navegador, como nas vistas das listas.
- **Linhas** abre com 5 linhas e mostra o texto do legado. Tem os botões «Adicionar linha», «Duplicar última», «Colar do Excel» (TSV, «|» ou «;»; pela ordem das colunas ou pelo cabeçalho, incluindo `aliases`), o contador «N linha(s) preenchida(s)» e «Registar linhas».
  - Atalhos: Enter passa à linha seguinte (e cria uma linha na última), Ctrl+D duplica a linha, Ctrl+Del limpa a linha. Também se pode colar um bloco do Excel directamente numa célula.
  - As colunas `emLinhas: 'opcional'` mostram-se e escondem-se no botão «Colunas».
  - Antes de gravar, cada linha é validada: obrigatórios, valores colados por reconhecer e `validar` de cada campo. Se alguma linha tiver erro, não se grava nenhuma.
  - Depois há uma confirmação e as linhas são gravadas **uma a uma**. As gravadas saem da tabela. As que falham ficam com a mensagem do servidor. O botão «Parar» interrompe a gravação.
  - No fim aparece o resumo «X de N linha(s) gravada(s)…», as consultas de `invalidar` são invalidadas e o modal fecha se tudo foi gravado.
- Ao passar de Linhas para Grade, a 1.ª linha preenchida passa para os campos. Ao passar de Grade para Linhas, os valores que já estão na grade (ex.: milestone pré-escolhido) passam a ser as omissões das linhas.
- **Grade**: os `children` são o formulário de sempre. `SeccaoFormulario` torna uma secção recolhível (`aria-expanded`), mas os campos continuam montados.

**Quando aplicar:** só aos modais marcados «Sim» na secção 4. Em «criar+editar», `criacao` deve ser verdadeiro só no modo criar.

**Passos**
1. Extrair a transformação «valores do formulário → corpo do pedido» para uma função pura (ex.: `dadosMarco(v)`). Deve aceitar datas em `dayjs` (grade) e em `'AAAA-MM-DD'` (linhas). A grade e as linhas passam a usar a mesma função, e por isso as mesmas regras.
2. Declarar as colunas (`CampoFormulario[]`) pela ordem do legado (`formularios_linhas_config.js`), com `obrigatorio`, `tipo`, `opcoes`, `aliases` e `validar` para as regras cruzadas (ex.: fim anterior ao início). Os campos menos usados ficam com `emLinhas: 'opcional'`, e os que só fazem sentido um a um ficam com `emLinhas: false`.
3. Trocar `<Modal …>` por `<ModalFormulario …>`. As props do Modal mantêm-se, excepto `title`/`open`/`onCancel`, que passam a ser `titulo`/`aberto`/`aoFechar`.
4. `gravarLinha` chama a API **directamente** (`enviar('post', url, dados(r))`), e não o `mutate` do `useAccao`, para não mostrar uma notificação por linha. As consultas são invalidadas uma só vez, com `invalidar`.
5. Nos ecrãs que usam `CadastroSimples` (Funções, Infotipos, Bancos, itens de avaliação e de produtividade), basta passar a prop `linhas={{ chave, campos, nomeRegistos }}`.

**Antes**
```tsx
<Modal title={marco?.id ? 'Editar milestone' : 'Novo milestone'} open={!!marco} onCancel={aoFechar}
  onOk={() => form.submit()} okText="Gravar" confirmLoading={accao.isPending} destroyOnHidden>
  <Form form={form} layout="vertical" onFinish={(v) => {
    const dados = { nome: v.nome, data: dataApi(v.data) ?? null, /* … */ };
    accao.mutate(marco?.id ? { metodo: 'put', url: `${base}/${marco.id}`, dados } : { url: base, dados });
  }}>…</Form>
</Modal>
```

**Depois**
```tsx
const CAMPOS_MARCO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Designação', obrigatorio: true, largura: 260, aliases: ['Nome', 'Fase'] },
  { nome: 'data', rotulo: 'Data alvo', tipo: 'data', largura: 150 },
  { nome: 'meta_quantidade', rotulo: 'Meta da fase', tipo: 'numero', emLinhas: 'opcional', seccao: 'Meta' },
];

<ModalFormulario chave="projectos.fases" titulo={marco?.id ? 'Editar milestone' : 'Novo milestone'}
  aberto={!!marco} aoFechar={aoFechar} criacao={!marco?.id} form={form} campos={CAMPOS_MARCO}
  nomeRegistos="fases / milestones" invalidar={[['projectos']]}
  gravarLinha={(r) => enviar('post', base, dadosMarco(r))}
  onOk={() => form.submit()} okText="Gravar" confirmLoading={accao.isPending}>
  <Form form={form} layout="vertical" onFinish={(v) => {
    const dados = dadosMarco(v);
    accao.mutate(marco?.id ? { metodo: 'put', url: `${base}/${marco.id}`, dados } : { url: base, dados });
  }}>…</Form>
</ModalFormulario>
```

**Testes:** seguir `componentes/formularios/formularios.test.tsx` e `modulos/rh/comum/CadastroSimplesLinhas.test.tsx`. As células têm o rótulo acessível «<Rótulo>, linha N».

**Fora desta fase:** documentos com agrupamento de linhas, como lançamentos e tesouraria. No legado usam `agrupar`/`validarGrupo` (várias linhas formam um documento), e isto ainda não existe no componente.
