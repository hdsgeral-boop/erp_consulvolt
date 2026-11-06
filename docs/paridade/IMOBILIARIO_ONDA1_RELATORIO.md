# Gestão Imobiliária — Onda 1 (F0 + F1 + F2): relatório

Data: 2026-11-06 · ramo `paridade-bd-front` · commits `2757277` (backend) e `9eafb06` (frontend) · sem push. Base: `IMOBILIARIO_PLANO.md` (§2 tabelas, §3.4 permissões, §7 fases), `IMOBILIARIO_INVENTARIO.md` e `imobiliario/ANEXO_A_CADASTRO.md`.

## 1. O que ficou funcional

| Fase | Entrega |
|---|---|
| **F0 Alicerces** | Módulo `imobiliario` no catálogo de permissões (editado à mão): **13 ecrãs, 41 tarefas (35 do legado + 6 novas), 20 sensíveis (14 + 6)**, 5 pares de segregação, 5 perfis-modelo; Auditor/Consulta e Direcção recebem as 13 consultas. Módulo em `erp.planos_catalogo.modulos`. Menu «Gestão Imobiliária» (13 itens, ordem e nomes do legado) entre Estrutura Orgânica e Planeamento Estratégico. Migração base. Numeração atómica dos códigos de imóvel. Taxas configuráveis. Ficheiro de rotas próprio `routes/api/imobiliario.php`. |
| **F1 Cadastro** | Imóveis (CRUD, papel/proprietário, ligação ao edifício do Activos, documentos por ligação, avisos do imóvel, resumo/KPIs), fracções (CRUD, gerar por piso/bloco, fracção única, importar portas do Activos de forma idempotente, estados com transições e histórico), ficha da fracção, **Mapa de Ocupação** por piso (cor e texto, filtro por estado, taxa de ocupação), listagens `TabelaComModos`, formulários responsivos, ecrãs acessíveis (botões com nome, estado sempre em texto). |
| **F2 Modelos e taxas** | 8 modelos de fábrica (extraídos do legado por script), 59 variáveis, importação idempotente, editor com inserção de variáveis no cursor, pré-visualização com `[Rótulo]` e lista de faltas, versão que sobe quando o texto muda. Taxas fiscais por ano (21 valores), com herança do último ano gravado, limites e estado **«por validar»**. |

Ecrãs construídos: `imo_imoveis` (Imóveis e Fracções), `imo_ocupacao` (Mapa de Ocupação), `imo_modelos` (Modelos e Taxas). Os outros 10 estão registados como «em construção» (como fez o Planeamento Estratégico) e entram nas ondas seguintes.

## 2. Migração nova (para o coordenador ensaiar na cópia da produção)

`backend/database/migrations/2026_12_15_090000_imobiliario_onda1_cadastro_modelos.php` — **só tabelas novas**, aditiva e idempotente (`hasTable`, `CREATE … IF NOT EXISTS`, CHECK com `pg_constraint`), RLS via `IsolamentoRls::aplicarATodas()` e GRANT a `erp_app`; `down()` não remove nada. Nenhuma tabela existente é alterada.

| Tabela | Notas |
|---|---|
| `imoveis` | UNIQUE `(empresa_id, lower(codigo))`; UNIQUE parcial por matriz e por edifício do Activos; CHECK tipo/papel/estado/isenção/áreas/valores; CHECK `PROPRIO` ⇒ sem proprietário, `ARRENDADO`/`MEDIACAO` ⇒ proprietário; FK `ativo_edificio_id` → `ativos_imobilizados` (restrict), `proprietario_terceiro_id` → `terceiros`. |
| `fracoes` | UNIQUE `(imovel_id, lower(codigo))`, `(imovel_id, lower(designacao))` e parcial `(imovel_id, porta_id)`; FK `porta_id` → `portas_edificios` **ON DELETE SET NULL** (nunca bloqueia a planta do Activos); colunas `park_*` já criadas para o Parqueamento (sem ecrã nesta onda). |
| `historico_estado_fracao` | trilho das mudanças de estado (de, para, motivo, utilizador, quando). |
| `modelos_documento_imo` | UNIQUE `(empresa_id, tipo, lower(nome))`; `sistema` marca só os de fábrica. |
| `taxas_imobiliario` | UNIQUE `(empresa_id, ano)`; CHECK 0–100 nas percentagens e ≥ 0 nos valores; `estado_validacao` (`POR_VALIDAR`/`VALIDADA`), `validada_por/em`, `validacao_nota`. |

A numeração reutiliza `sequencias_documentos` (`ServicoNumeracao`: bloqueio + `FOR UPDATE`), chave `imo:PR` por empresa, semeada pelo maior `PR<n>` existente; não precisa de tabela nova.

**Sem dados a migrar**: o módulo arranca vazio (decisão do utilizador). Os dados de fábrica (taxas de arranque, tipos, variáveis e modelos) vêm de `backend/resources/imobiliario/fabrica.json`, gerado por `node ferramentas/paridade/extrair_modelos_imobiliario.mjs` (`--verificar` falha se divergir do legado). Os modelos importam-se por empresa (botão ou `importarPadrao`), não por migração.

## 3. Decisões e correcções do legado

- **Código de imóvel atómico** (`PR0001`…): sem a corrida do legado; salta os códigos escritos à mão. Código da fracção = maior sufixo + 1 (o legado contava as irmãs e colidia após eliminações).
- **Limite de 500 fracções gerado de uma vez conta os blocos** (o legado só multiplicava piso × por piso).
- **Importar portas idempotente** por `porta_id` (UNIQUE); nada é escrito nos Activos. Designação repetida recebe o sufixo `(porta <id>)`.
- **Edifício do Activos**: só `EDIFICIO`; imóvel próprio ↔ bem próprio; arrendado ↔ bem alheio em regime `ARRENDADO`; um edifício serve um só imóvel (o legado só avisava). Mediação não liga a edifícios.
- **Estados**: o legado deixava ir de qualquer estado para qualquer outro; agora há `TRANSICOES` (uma fracção vendida só volta a disponível/indisponível), motivo obrigatório ao sair de arrendada/vendida e ao ficar indisponível, histórico e a edição da fracção já não muda o estado.
- **Taxas**: por ano, herdam o último ano gravado (o legado voltava aos valores base), qualquer alteração volta a «por validar», há selector de ano. O prazo de desocupação do legado (`det_desocupado_meses`) chama-se `det_agravamento_meses`. **O ERP não afirma nem confirma a conformidade legal**: o ecrã e a API trazem o aviso e a «validação» só regista quem (nome/função) confirmou, fora do sistema.
- Modelos: unicidade (tipo + nome), variáveis desconhecidas rejeitadas, `sistema` só nos de fábrica (o legado marcava todos como master-data).
- Mensagens de erro: `ErroNegocio` com texto próprio (as do legado, quando existem); no frontend `notificarErro`.
- Perfis: **Responsável de Cobrança** recebe `imo_renda_facturar` mas **não** `imo_renda_pagar`, porque o par de segregação `facturar × pagar` (aprovado) o proibiria; o Gestor Imobiliário não recebe `imo_reserva_facturar` (par com `imo_reserva_devedor`) nem as tarefas sensíveis de fecho.
- NIF: o cadastro não tem campos de NIF próprios; o proprietário é um Terceiro (já validado em Terceiros). O NIF do proprietário é conferido com `NifAngolano` e gera **aviso** do imóvel quando inválido.

## 4. Fora desta onda (documentado, não começado)

Contratos, rendas, facturação, caução, subarrendamento (F3); parqueamento, áreas comuns e reservas (F4); obras, fichas de apetrechamento, **bens da fracção** e planos (F5); compra e venda (F6); rentabilidade e fiscal (F7); alertas, painel, PDFs, exportações Excel (F8); ETL (F9, dispensado). O plano atribui fichas/bens da fracção à F5, por isso não entram aqui. Também ficaram para as ondas seguintes: anexos reais em `documentos_anexos` (os documentos do imóvel/fracção são **ligações http/https**, como no legado, validadas no servidor), PDF do servidor para os modelos, `imo_exportar`/`imo_alertas_gerir` (as permissões já existem no catálogo), a aplicação de um modelo a um contrato real (contexto com os dados do contrato) e as vagas de estacionamento (`natureza=ESTACIONAMENTO` é recusada com mensagem; as colunas `park_*` já existem).

## 5. Testes e verificações

- **PHPUnit** (`docker exec erp_app_e2e php artisan test --filter=…`, um de cada vez): `ImobiliarioCadastroTest` **21 testes / 821 asserções** e `ImobiliarioModelosTaxasTest` **9 / 148**. Números à mão: designações (`203`, `01`, `C102`, `B · 101`, `1.º Dto.`), 6 + 8 fracções geradas e 14 linhas de histórico, 500 vs 600 fracções (blocos), taxa de ocupação 3/4 = 75 % e 3/7 = 43 %, IP efectivo 25 × 60 ÷ 100 = 15 (e 20 × 60 ÷ 100 = 12), contagens de fábrica (8 tipos, 59 variáveis, 8 modelos, 21 taxas), catálogo (13/41/20/5/5), isolamento por empresa, permissões (403 antes de 422), restrições da base de dados, idempotência da migração e da importação dos modelos.
- Varredores um a um, **todos verdes**: `OwaspControloAcessoTest` (sem entradas novas em `SEM_PERMISSAO_POR_DESENHO`), `AcessoCruzadoGeradoTest`, `ConsultasPorEndpointTest`, `EntradasMaliciosasListagensTest`, `ContaRootTest`; também `FkPerfisModelosTest`, `MenuConfigContaSubscricaoTest`, `R3LayoutBiRetiradoTest`, `CatalogoModulosPlanoTest`, `IsolamentoRlsContratoTest`. Pint limpo nos ficheiros do módulo (o único aviso restante é de `Services/Estrategia/CatalogoFontesModulo.php`, do outro agente).
- `ConsultasPorEndpointTest`: o tecto próprio de `GET api/sistema/copias/exportar` passou de 450 para 480 (uma consulta por tabela; mede 461 com as tabelas novas do Imobiliário e da Estratégia).
- **Vitest**: `logica.test.ts` (9) e `Imobiliario.test.tsx` (10): 19 testes; `tsc --noEmit` limpo; `src/layout`, `src/componentes/icones` e `semAutenticacaoNoNavegador` verdes, **excepto** os 2 testes de `indicePesquisa.test.ts`, que falham até o coordenador regenerar `indicePesquisa.gerado.ts` (`node ferramentas/pesquisa/gerar_indice_pesquisa.mjs`; não foi tocado). `npm run build` feito.

## 6. Notas para o coordenador

1. **Plano de licença**: o módulo está em `erp.planos_catalogo.modulos`, mas **não** foi acrescentado às versões de plano já gravadas (ao contrário da Estratégia). Em produção ninguém o vê até a plataforma o incluir num plano; é deliberado, porque a maior parte dos ecrãs ainda está «em construção».
2. Regenerar o índice de pesquisa; correr `PerfisActualizarModelosCommand` se quiser propagar os 5 perfis novos a perfis já existentes (o catálogo já os traz).
3. Ensaiar a migração `2026_12_15_090000` na cópia da produção (só tabelas novas; não requer `btree_gist`).
4. A base de teste `erp_app_e2e` foi migrada (`artisan migrate`) para correr os testes.
