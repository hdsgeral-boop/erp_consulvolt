# Decisões de Arquitectura (ADR)

Registo das decisões com impacto duradouro. Cada entrada: contexto → decisão → consequências.

---

## ADR-001 — Directiva 100% em português como fonte de verdade
**Contexto.** Há dois documentos de especificação: `prompt_execucao_migracao_erp_pt.md` (tabelas, colunas e API em português) e `plano_migracao_erp_laravel_react.md` (DDL em inglês). Contradizem-se em nomes, no cabeçalho de tenant (`X-Company-Id` vs `X-Empresa-Id`), no comando de importação e no número de contentores.
**Decisão (utilizador, 2026-09-29).** Prevalece a directiva em português. O plano director fica como referência funcional.
**Consequências.** O nome das tabelas segue literalmente a matriz contratual (ex.: `efectividade_assiduidade`). As colunas usam o AO90 (`ativo`, `projeto`, `atualizado_em`). O cabeçalho é `X-Empresa-Id` e o comando é `erp:migrar-backup-legado`.
**Excepção documentada.** As tabelas internas do framework têm nome português (`migracoes`, `trabalhos_falhados`, `lotes_trabalhos`), mas as suas colunas são as que o Laravel tem fixas no código. Os tokens do Sanctum (`tokens_acesso`) estão 100% em português, através do model `TokenAcesso`.

## ADR-002 — O dicionário DE/PARA é gerado, não escrito à mão
**Contexto.** São 154 tabelas e 1 652 colunas reais; a directiva só descrevia 3 tabelas.
**Decisão.** `ferramentas/levantamento/` lê o backup e gera `mapa_de_para.json`, `DICIONARIO_DADOS.md` e `pendencias.json`, a partir de um glossário (`glossario.mjs`), de normalizações e de regras de integridade versionadas. O gerador **falha** se houver colunas sem tradução, nomes em colisão ou valores enumerados sem código.
**Consequências.** As migrations (Fase 2) e o ETL (Fase 3) consomem o `mapa_de_para.json`. Qualquer alteração de nome faz-se no glossário e regenera-se tudo.

## ADR-003 — Dados "mestre" do legado
**Contexto.** O legado criava, em cada tabela, uma linha fictícia por empresa (`is_master_data=1`, "REGISTO MESTRE OBRIGATÓRIO"), 1 214 linhas no total, para contornar limitações do Dexie. Nenhuma linha real as referencia. Na tabela `companies`, a mesma marca aparece em empresas reais.
**Decisão (utilizador).** As linhas fictícias não migram. As empresas 10 (SUNNA) e 18 mantêm-se e perdem a marca. A empresa 11 ("SISTEMA - DADO MESTRE") não migra.
**Consequências.** São 203 818 linhas reais a migrar. As colunas de enchimento que ficaram nas empresas 10 e 18 são descartadas.

## ADR-004 — Enumerações: código normalizado + texto original
**Decisão (utilizador).** As 30 colunas enumeradas passam a guardar um código (ex.: `Factura`→`FT`, `Nota de CRÉDITO`→`NC`, `Não ACTIVO`→`INACTIVO`) e ganham a coluna `<coluna>_original` com o texto exacto do legado. Valores desconhecidos ficam com código NULL e geram uma ocorrência de migração; nunca se inventa um código.

## ADR-005 — Inconsistências do legado: nada é inventado nem perdido em silêncio
**Decisão.** Aplicam-se as regras de `regras_integridade.mjs`: QUARENTENA, ANULAR_FK, DIARIO_RECUPERACAO, CODIGO_LEGADO e SEMANTICA. Cada aplicação gera uma linha em `ocorrencias_migracao` com o payload original. Os casos notáveis são:
- 117 lançamentos apontam para o diário 103, que não existe, ou para NaN. São reapontados para um diário "REC — Recuperado do Legado", porque retirá-los alteraria os saldos.
- `org_type_id = -1` significa "Avençado" (js/app_v2.js:2648). Passa a `tipo_organizacao_id NULL` com `avencado = true`.
- `sales.pos_session_id = 'POS_SESS_<epoch>'` eram sessões que só existiam no `localStorage` do navegador. Ficam como código legado, sem FK.
- Os lançamentos desequilibrados das empresas 5, 8 e 10 (D−C = −321 900,00 no total) são importados como estão e reportados no ecrã de desequilíbrios do próprio sistema (decisão do utilizador).

## ADR-006 — Multi-empresa: contexto explícito e política fail-closed
**Decisão.** A empresa activa vem do cabeçalho `X-Empresa-Id`, validado pelo middleware `empresa` (existe, está activa e o utilizador tem acesso). É guardada no `ContextoEmpresa`, um singleton `scoped` por pedido ou trabalho. O Global Scope `EscopoEmpresa` **recusa** consultas a models `PertenceEmpresa` sem empresa definida, a menos que se use `semIsolamento()` explícito (ETL, manutenção, consolidação). Gravar com um `empresa_id` diferente do activo é recusado.
**Consequências.** Um esquecimento do programador dá um erro visível em vez de uma fuga de dados entre empresas.
**Legado.** `allowed_companies` vazio significava implicitamente "todas as empresas" (js/data/servicos.js:56-64). Passa a ser a flag explícita `utilizadores.acesso_todas_empresas`.

## ADR-007 — Autenticação: tokens Sanctum e migração transparente das palavras-passe
**Decisão.** O login usa tokens Bearer, sem cookies. Os utilizadores migrados mantêm o hash PBKDF2-SHA256 do legado (120 000 iterações, 32 bytes, Base64; ver js/data/senhas.js) nas colunas `*_legado`. No primeiro login correcto o hash é convertido para **Argon2id** e as colunas legadas são limpas.
**Paridade.** O nome de utilizador é comparado de forma exacta e a sessão expira após 15 minutos de inactividade.
**Melhorias.**
- A validade absoluta do token é de 12 horas.
- São permitidas 5 tentativas de login por minuto, por utilizador e IP.
- A mensagem de erro é igual para utilizador inexistente e para palavra-passe errada.
- Um utilizador desactivado perde de imediato todos os tokens.
- Os logins falhados ficam na auditoria.

## ADR-008 — Permissões: mesma semântica do legado
**Decisão.** `ServicoPermissoes` replica `tem(k)` de js/permissoes.js:591-599. Há acesso total para `SUPER_ADMINISTRADOR` ou para `all:true`. Caso contrário vale `permissoes[norm(k)] === true`, com acesso automático ao portal para quem está ligado a um colaborador. Todas as chaves são abilities do Gate (`Gate::authorize('config_logs_view')`).
**Pendente (Fase 4).** O catálogo de 116 ecrãs e cerca de 195 tarefas, e os mapas `LEGADO`/`LEGADO_VISTA` para os perfis no formato antigo.

## ADR-009 — Auditoria particionada, sem FK e imutável
**Decisão.** `logs_auditoria` é particionada por ano (RANGE `ocorrido_em`) e tem uma partição DEFAULT. Não tem FK para empresas nem para utilizadores, porque o histórico tem de sobreviver às eliminações (o backup tem logs das empresas 11 e 21, que já não existem). O model recusa update e delete.
**Auditoria automática.** O trait `Auditavel` substitui os hooks do Dexie e regista, além disso, os valores anteriores e novos, sem segredos.
**Partições futuras.** Criadas por `erp:auditoria:particoes`, agendado para Dezembro. Se já houver linhas na DEFAULT, o comando move-as.

## ADR-010 — `lancamentos_contabeis` não é particionada (por agora)
**Contexto.** A directiva pede partição anual. No PostgreSQL, uma tabela particionada exige que a PK inclua a chave de partição. Isso impede FKs simples de outras tabelas para `lancamentos_contabeis(id)`: activos, reconciliações e projectos referenciam lançamentos.
**Decisão.** A tabela fica **não particionada**, com índices compostos (`empresa_id, codigo_conta, data_documento`, …). Com 44 400 linhas, o volume está duas ordens de grandeza abaixo do ponto em que a partição compensa.
**Revisão.** A decisão reavalia-se aos 10 milhões de linhas. O caminho seria uma PK composta `(id, data_documento)` e FKs compostas.

## ADR-011 — Fuso horário
**Decisão.** A aplicação corre em `Africa/Luanda` e as colunas temporais são `TIMESTAMPTZ`. A sessão PostgreSQL usa o fuso da aplicação (`config/database.php`, opção `timezone`). Sem isto, o Laravel grava as datas sem offset e o PostgreSQL interpreta-as como UTC, o que desfasa todas as datas em 1 hora. O erro foi detectado pelo teste de inactividade e fica protegido por `FusoHorarioTest`.

## ADR-012 — Desenvolvimento em Windows: `vendor` num volume Linux
**Contexto.** Com o código montado a partir do NTFS, o arranque do Laravel demorava cerca de 7 s por pedido, por causa de milhares de acessos a ficheiros do `vendor`.
**Decisão.** O `vendor` fica no volume nomeado `erp_vendor`. O OPcache revalida os ficheiros a cada 2 s. O entrypoint garante permissões de escrita em `storage/`. O Nginx resolve `app` pelo DNS do Docker, para que a recriação do contentor não cause 502.
**Consequências.** O pedido desce para cerca de 70–160 ms em desenvolvimento. As dependências gerem-se sempre dentro do contentor. Em produção a imagem inclui o código, sem montagem de volume.

## ADR-013 — Cálculo salarial: a fonte de verdade é o `engine_v2.js`
**Contexto.** A tabela de IRT do plano director não corresponde à que o legado usa. O `engine_v2.js` usa parcelas fixas de 12 500, 87 250, 187 250 … 2 342 250.
**Decisão.** O `PayrollService` (Fase 4) reproduz exactamente `js/engine_v2.js:21-97` e `js/app_v2.js:5787-6016`, incluindo as regras implícitas: INSS 3%/8% fixo; isenção de 30 000 Kz nos subsídios identificados pelo nome; avençado com 6,5% sobre o bruto. Os casos de teste são validados contra folhas já processadas no backup. Qualquer correcção a estas regras só se faz com decisão explícita do utilizador.

## ADR-014 — Ecrãs vazios do legado são corrigidos
**Contexto.** Dois ecrãs do menu abrem vazios por erro de código:
- *Compras › Encomendas Clientes*: o menu chama `'encomendas_clientes'`, mas o separador usa `'vendas'` (js/ui_compras_v2.js:719, 868).
- *Activos › Cadastro e Gestão*: chama `renderAssets('dashboard')`, que não tem ramo (js/app_v2.js:1129).

Há ainda a vista `sgd`, que chama `renderSGD`, uma função inexistente.
**Decisão (utilizador, 2026-09-29).** No sistema novo estes ecrãs mostram o conteúdo previsto: a lista de encomendas de clientes que geram pedidos de compra, e o painel de cadastro de activos. A `sgd` passa a ser a Gestão Documental sobre `documentos_anexos` e `tipos_documento`.

## ADR-015 — Rotinas destrutivas escondidas não são portadas
**Contexto.** Alguns ecrãs do legado alteram ou apagam dados só por serem abertos:
- documentos de tesouraria sem data e as suas linhas (js/ui_tesouraria.js:275-286);
- "correcção" de amortizações duplicadas e recálculo do acumulado em **todas** as empresas (js/ui_assets.js:3-37);
- remoção das contas por omissão de clientes e produtos (js/ui_sales.js:366-393);
- normalização de `purchase_items.parent_type`, conversão de `account_code` numérico e remoção de empresas "SISTEMA DADOS MESTRE" duplicadas.

**Decisão (utilizador).** Nenhuma leitura altera dados. Cada rotina passa a ser um **relatório de validação** em *Sistema › Validações de Dados*, que mostra as anomalias com drill-down para o registo. As correcções são **acções explícitas**, com permissão própria, confirmação, execução em transacção, registo em auditoria e, quando destrutivas, aprovação dupla (como a *Manutenção de dados* do legado).
**ETL.** As mesmas regras correm como verificações no fim da importação e alimentam o relatório de migração.

## ADR-016 — Descontabilizar = estorno com rasto
**Contexto.** No legado, descontabilizar apaga fisicamente as `journal_lines` (por `doc_number` ou `period_id`; js/ui_lancamentos.js:2959-3078) e não deixa histórico contabilístico.
**Decisão (utilizador).** Descontabilizar cria um **lançamento de estorno**:
- no mesmo diário, com um novo N.º de lançamento;
- com as mesmas contas e valores e D/C invertidos;
- ligado ao original (`lancamento_estornado_id` / `estornado_por_id`);
- marcado `origem = ESTORNO`.

O lançamento original fica marcado como estornado e **nunca é apagado**. O documento de origem (factura, recepção, folha, tesouraria) volta ao estado "não contabilizado" e pode ser contabilizado de novo. Os bloqueios do legado mantêm-se como pré-condições: reconciliação bancária, guia contabilizada e activos ligados.
**Data do estorno.** Por omissão é a do original. Se o período já estiver fechado (lock de exercício), usa-se o primeiro dia aberto e fica registada uma nota.
**Relatórios.** Apresentam por omissão os saldos líquidos, com a opção "mostrar estornos". O legado `recycled_journal_lines` migra para `lancamentos_estornados` como arquivo histórico.

## ADR-017 — Isenção de IRT de 30 000 Kz pela marcação do infotipo
**Contexto.** O legado identifica os subsídios isentos pelo **nome** da rubrica, com `includes('alimenta')` e `includes('transp')` (js/app_v2.js:5890-5924), e ignora a marcação `infotypes.irt`. No backup, a marcação `irt = "conditional_30k"` está exactamente nas rubricas *Subsídio de alimentação* e *Subsídio de transporte*, nas 13 empresas.
**Decisão (utilizador: "corrigir só se for errado, mas algumas rubricas possuem essa característica de isenção").**
- A isenção de até 30 000 Kz por rubrica aplica-se aos infotipos marcados `conditional_30k`, qualquer que seja o nome. Assim, nomes mal escritos (o backup tem "Subsídio de comusúnicação") ou rubricas novas deixam de falhar.
- Os infotipos com `irt = false` não entram na base de IRT, e os de `irt = true` entram por inteiro.
- As restantes regras salariais do legado mantêm-se: INSS 3%/8%, faltas, pro-rata, horas extra e avençados.

**Garantia de não-regressão.** Com os dados actuais, a regra nova dá o mesmo resultado que a antiga. O teste de aceitação da Fase 4 recalcula as folhas **VALIDADAS** do backup com o motor novo, exige diferença zero em cada colaborador e rubrica, e lista qualquer excepção para decisão.

## ADR-018 — O esquema é gerado a partir do dicionário e verificado contra os dados reais
**Decisão.** `ferramentas/gerador/gerar_esquema.mjs` produz a partir de `mapa_de_para.json`, do `campos_codigo_legado.json` (campos que só o código grava) e das regras de `esquema_extra.mjs`:
- as migrations (uma por módulo, mais uma migration final com todas as FKs);
- os models em dois níveis: `App\Models\Base\<Model>Base`, **regenerado sempre**, e `App\Models\<Model>`, criado uma única vez, onde vive o código de negócio;
- o contrato `database/legado/esquema.json`.

**Salvaguardas.** O gerador falha se:
- uma chave única ou um CHECK for violado pelos dados reais do backup, depois de mapeados e normalizados;
- uma regra referir uma tabela ou coluna inexistente;
- um campo do código ficar sem tradução.

O `EsquemaContratoTest` compara a base real com o contrato: tipos, precisão, nulidade, FKs e o seu ON DELETE, únicos, models, relações e isolamento por empresa.

**FKs.** São todas `DEFERRABLE INITIALLY IMMEDIATE`. Na aplicação comportam-se como FKs normais. O ETL carrega com `SET CONSTRAINTS ALL DEFERRED` e o COMMIT falha se sobrar algum órfão, o que garante o critério "zero órfãos". A regra é RESTRICT, excepto CASCADE nas linhas e itens do próprio documento.

**Tenant.** Todas as tabelas de negócio têm `empresa_id NOT NULL`, incluindo as linhas-filho que no legado não o tinham (itens de venda, itens de tesouraria…). O ETL deriva-o do documento-pai. Assim, o isolamento não depende de joins. As excepções globais são `utilizadores`, `perfis_utilizador`, `moedas`, `configuracoes_sistema` e `taxas_cambio` (onde `empresa_id NULL` significa "todas as empresas").

**Chaves únicas que os dados violam.** Ficam como índice e o caso vai para os relatórios de validação (ADR-015):
- 408 terceiros com o mesmo NIF e tipo;
- 10 cargos e 2 infotipos com o mesmo nome;
- 12 notas DEMO e 6 notas de fluxo com o mesmo código;
- **4 mapeamentos contabilísticos de RH duplicados**, que tornam ambígua a conta usada na integração salarial;
- **2 facturas de fornecedor registadas em duplicado**.

Nas vendas, a numeração única é imposta só aos documentos fiscais (FT/FR/NC/ND), com um índice parcial. Os orçamentos e proformas do legado repetem números, porque "Orçamento" e "Orcamento" eram tratados como tipos distintos.

**Relações amputadas do legado → FKs reais.**
- `purchase_items.parent_id` com `parent_type` passa a 4 FKs (`pedido_compra_id` / `cotacao_compra_id` / `encomenda_compra_id` / `fatura_compra_id`), com um CHECK que exige no máximo uma.
- As listas de ids (`estadias_hotel_ids`, `related_doc_id`, `invoice_ids`, `order_ids`) passam a tabelas pivô com FK; o valor original fica em `<coluna>_legado`.
- `reconciliation_matches.internal_id` / `external_id` passam a FKs para lançamentos e linhas de extracto. Os 323 lançamentos em falta (20%) foram apagados pelo "descontabilizar" do legado, o que confirma o ADR-016.

## ADR-019 — Os testes nunca correm na base principal
**Contexto.** O docker-compose define `DB_DATABASE` como variável real de ambiente. Essa variável chega ao `$_SERVER`, que o Laravel lê **antes** do `$_ENV`, onde o PHPUnit escreve. Por isso os testes corriam na base principal e o `RefreshDatabase` apagava-a. Não houve perda de dados, porque ainda não havia dados reais.
**Decisão.** O `phpunit.xml` define as variáveis críticas também como `<server>`. O `TestCase::beforeRefreshingDatabase()` **aborta** a execução se a base activa não terminar em `_testes`.

## ADR-020 — `delivery_items` tem dois documentos-pai sem discriminador
**Contexto.** O legado grava em `delivery_items.delivery_id` tanto ids de **guias de saída** (ui_warehouse.js:888, ui_pos_armazem.js:489/1018) como de **recepções de compra** (ui_compras_v2.js:2183/2361, moedas_compras.js:193). Não há nenhuma coluna que os distinga e os ids dos dois documentos coincidem: 23 das 47 linhas são ambíguas. O próprio código contradiz-se: company_backup.js:66 trata o campo como id de guia, substituir_conta.js:19 como id de recepção.
**Decisão.** `itens_guia_saida` passa a ter `guia_saida_id` e `rececao_compra_id`, cada uma com FK real, e um CHECK que exige no máximo uma. O ETL (Fase 3) decide o pai de cada linha, por esta ordem:
1. há contadores cambiais de compra (`fx_q*`/`unit_cost_kz`);
2. coincide o produto com o documento;
3. coincide o armazém.

As linhas que continuarem ambíguas vão para quarentena, para decisão.

## ADR-021 — `maintenance_requests` = pedidos de Manutenção de dados
A matriz chama-lhe `pedidos_manutencao_equipamentos`, e o nome mantém-se por ser contratual. Mas, segundo o código (js/manutencao.js), a tabela guarda os **pedidos de manutenção de dados** com aprovação dupla (resets, limpezas, restauros), que podem ser globais. É por isso uma tabela global, com `empresa_id` opcional.

## ADR-022 — Valores monetários com 2 casas decimais: arredondamento documentado
**Contexto.** O legado guarda floats JavaScript. Há 1 866 lançamentos com ruído binário (`261.6700000000001`) e 2 652 com fracções de cêntimo reais, vindas de conversões cambiais e percentagens (`2350.877192982456`). Arredondar cada linha ao cêntimo altera o saldo D−C de quatro empresas em no máximo 3 cêntimos.
**Decisão (utilizador, 2026-09-29).** Os valores ficam com 2 casas decimais (directiva `NUMERIC(15,2)` e padrão fiscal SAF-T/AGT), com arredondamento "half away from zero", igual ao do PostgreSQL:
- o ruído binário desaparece sem registo;
- cada fracção de cêntimo real gera uma ocorrência `ARREDONDAMENTO` com o valor original.

O relatório do ETL mostra, por empresa, o "efeito do arredondamento" e o "D−C do legado" reconstituído. Os valores reconciliam ao décimo de milésimo com as somas do legado (empresa 1: −0,03 migrado = −0,0271 de arredondamento + −0,0029 do legado).

## ADR-023 — ETL em três etapas, numa transacção, com validação antes do COMMIT
**Decisão.** `php artisan erp:migrar-backup-legado <ficheiro> [--simular] [--substituir --force]` corre em três etapas:
1. **Extracção por streaming** (halaxa/json-machine) para `etl_linhas_legado` (UNLOGGED). Memória máxima de cerca de 145 MB para um backup de 57 MB. As linhas "dado mestre" são descartadas nesta etapa.
2. **Carga por ordem topológica das FKs**, numa única transacção com `SET CONSTRAINTS ALL DEFERRED`. Inclui conversão de tipos (`ConversorTipos`), normalizações, empresa derivada do documento-pai ou da holding, polimórficos (ADR-020), pivôs e as regras de `regras_integridade.mjs`. Cada correcção gera uma linha em `ocorrencias_migracao` e cada linha rejeitada vai para `quarentena_migracao`, com o payload original.
3. **Validação:**
   - contagens: lidas = migradas + quarentena, por tabela;
   - órfãos: contados FK a FK; se houver algum, a transacção falha com a lista;
   - D−C por empresa;
   - sequences recalibradas;
   - `SET CONSTRAINTS ALL IMMEDIATE` e só depois COMMIT (ou ROLLBACK em simulação).

Cada execução fica registada em `execucoes_migracao`, com o SHA-256 do ficheiro e o relatório. A execução recusa correr sobre uma base com dados sem `--substituir`, e recusa ficheiros que não sejam exports Dexie.
**Resultado com o backup real (2026-09-29).** 203 818 linhas lidas e 203 843 gravadas (inclui 2 diários REC, as tabelas pivô e as ligações utilizador↔empresa). 31 linhas em quarentena, 0 divergências de contagem e 0 órfãos. 5 039 ocorrências, das quais cerca de 4 350 são arredondamentos INFO. Duração de cerca de 80 s.
**Bugs que os testes do ETL apanharam antes da migração real:**
- `data_documento` estava a ser inferida como TIMESTAMPTZ e as datas "AAAA-MM" ficavam NULL. Passou a DATE (directiva 3.2).
- As referências a tabelas globais, com `empresa_id` NULL (perfis, taxas de câmbio), eram tratadas como órfãs, por causa do `isset()` sobre um valor NULL.

## ADR-024 — Permissões: catálogo e conversão de perfis feitos com o código do próprio legado
**Decisão.**
- O catálogo (15 módulos, 116 ecrãs, 195 tarefas, 19 regras de segregação, 21 perfis-modelo) é extraído por `ferramentas/levantamento/extrair_permissoes.mjs`. O script executa `js/permissoes.js` num contexto isolado do Node e grava `backend/resources/permissoes/catalogo.json`.
- Os perfis no formato antigo (3 no backup, incluindo o "Contabilista Junior" do utilizador `celso`) são convertidos para v2 com o `converterAntigo` do legado. É executado o próprio código: `js/app_v2.js:725-888` e `js/permissoes.js:808`, através de `converter_perfis_antigos.mjs`. O resultado fica em `database/legado/perfis_convertidos.json`, que o ETL aplica. O JSON original fica em `perfis_utilizador.permissoes_originais`.
- O `ServicoPermissoes` reproduz o `hasView`/`can` do v2: um ecrã é visível se o utilizador tiver a consulta, alguma tarefa desse ecrã ou algum ecrã-filho visível. Mantém também os mapas `LEGADO`/`LEGADO_VISTA`.
- Os controllers pedem as chaves do legado, através de `exigir(...)`. Por exemplo: `lancamentos_post` para gravar, `contab_lanc_transferir` para estornar, `contab_mapa_balancete_view` para o balancete.

**Porquê.** Reescrever à mão as regras antigas criaria divergências silenciosas nos acessos. Executar o código original garante que cada utilizador mantém exactamente o que já tinha.

## ADR-025 — Identidade de um lançamento: LAN → referência → documento
**Contexto.** 17 885 linhas têm `numero_lan`; as 26 515 linhas mais antigas não. Agrupá-las por `numero_documento` gerava 1 832 falsos "desequilibrados" na empresa 1, porque a integração da tesouraria grava o número da factura paga em cada linha. Os próprios dados mostram que, nas linhas antigas, o número do lançamento estava em `referencia`, que o legado lia como `lan_number || reference` (js/app_v2.js:14).
**Decisão.** A chave do lançamento é `COALESCE(numero_lan, referencia, numero_documento)`, definida num único sítio (`LancamentoContabil::chaveSql()`) e usada pelo estorno, pelo relatório de desequilíbrios e pelas validações. Com os dados reais:
- os 351 lançamentos com LAN estão equilibrados;
- o desequilíbrio da empresa 8 (−521 899,98) concentra-se em **4 lançamentos** e o da empresa 5 (+200 000,00) em **2**;
- as 19 diferenças da empresa 1 são de cêntimos (arredondamento, ADR-022).

## ADR-026 — Numeração: lock Redis + sequência transaccional, a continuar a do legado
**Decisão.** `ServicoNumeracao` usa `Cache::lock` (Redis, `erp:lock:numeracao:{empresa}:{chave}`) e `SELECT … FOR UPDATE` em `sequencias_documentos`. O incremento pertence à transacção do documento: se a transacção falhar, o número não é consumido (sem saltos nem duplicados). A semente é o maior número já usado no legado (`numero_lan` ou `referencia`, no formato `<diário><ano><seq6>`). Esta sequência serve de base também às séries de facturas, guias e recibos.

## ADR-027 — Tamanhos mínimos de colunas por semântica
**Contexto.** O gerador dimensionava cada `varchar` pelo maior valor do backup × 1,5. Isso deixava colunas curtas demais para registos novos: por exemplo, `produtos.codigo_conta` ficava com `varchar(10)` quando o plano de contas usa códigos até 20.
**Decisão.** O gerador aplica mínimos por semântica:
- contas: 20 (igual a `plano_contas.codigo`);
- nomes e descrições: 255;
- códigos e números: 50;
- email: 150; telefone: 50; IBAN: 50;
- moradas: 150;
- `*_original`: 100;
- `chave`: 150.

O esquema foi regenerado e o backup migrado de novo, sem diferenças nos resultados.

## ADR-028 — Terceiros e produtos: paridade e melhorias
**Paridade** com `saveCustomer`, `saveSupplier`, `saveProduct` e os hooks do legado:
- a conta é obrigatória e tem de ser de movimento;
- o NIF é único na empresa; a API devolve `NIF_DUPLICADO` com o id do existente, para o frontend o seleccionar;
- um fornecedor que passa a cliente fica com o tipo `CLIENTE_FORNECEDOR`;
- a isenção AGT só se aplica com IVA a 0%; `conta_iva` é igual a `conta_iva_liquidado`;
- o catálogo de compras acompanha o produto.

**Melhorias:**
- **A ficha do produto não altera o stock.** O legado mantinha três fontes de stock desalinhadas; no sistema novo o stock só resulta de movimentos de inventário.
- A eliminação é lógica, e fica bloqueada quando o terceiro ou o produto tem documentos, lançamentos ou movimentos.
- O catálogo de venda fica em cache no Redis e é invalidado nas escritas.
- O `Model::shouldBeStrict` detecta erros cedo.

**Cache de acesso às empresas.** A ligação utilizador ↔ empresa passou a um pivô próprio (`UtilizadorEmpresa`), que invalida a cache quando é alterada. As mudanças de estado de uma empresa incrementam uma versão global da chave. Antes disto, um utilizador ligado a uma nova empresa esperava até 1 h pelo acesso (bug detectado pelos testes).

## ADR-029 — Vendas e facturação AGT (parte 1)
**Âmbito.** Emissão de FT, FR, NC, OR, PF e NE; selagem AGT; conversões; recibos; contabilização e estorno. Ficam para depois: guias GR/GD (com o stock, no módulo Logística), envio à AGT, QR, assinatura SAF-T e multi-moeda (parte 2).

**Numeração.** Todos os documentos novos usam o formato `<TIPO> <SÉRIE>/<n>` (ex.: `FT A2026/15`, `RE A2026/3`). A série é por tipo, ano e origem, e é criada automaticamente. Os números vêm de um lock Redis e de `SELECT … FOR UPDATE` na mesma transacção do documento. Nos documentos fiscais, a data não pode ser futura nem anterior à do último documento da série. Isto acaba com a colisão do legado, em que FT e FR partilhavam o prefixo `FA`.

**Cálculo e selagem.** As contas são feitas em decimal exacto, com as regras AGT:
- linha = arred(qtd × preço, 2);
- IVA arredondado ao cêntimo por excesso;
- bruto = líquido + IVA.

FT, FR e NC ficam selados na emissão, mesmo com erros de validação (E01/E02/E03/E22/E23/E24), que ficam em `fe_erros`. A partir daí, os campos fiscais e as linhas são imutáveis, e um fiscal não se anula nem se elimina: corrige-se com uma nota de crédito.

**Regras de negócio corrigidas face ao legado:**
- **Nota de crédito:** exige a factura de origem (FT ou FR do mesmo cliente), um motivo e saldo creditável suficiente. Esse saldo é validado com a factura bloqueada. A NC abate o pendente da factura.
- **Estado persistido:** a FT passa por PENDENTE, PARCIAL e PAGO; a FR nasce PAGO; a NC fica CONCLUIDO; OR, PF e NE passam de PENDENTE a CONCLUIDO ou ANULADO.
- **Anulação:** só para documentos não fiscais que ainda não foram convertidos.
- **Factura-recibo:** exige uma conta de disponibilidade (classe 4) e gera o recibo automaticamente.
- **Condições de pagamento:** PRAZO e MARCOS exigem um plano que some 100%.

**Contabilização.** Cada documento gera um lançamento, equilibrado por construção, através de `ServicoLancamentos`:
- **Diários:** FC para vendas, RC para recebimentos.
- **Contas:** vêm do produto ou do cliente; na falta, da configuração de vendas (`clientes_default`, `proveitos_mercadorias`, `proveitos_servicos`, `iva_vendas`). Se faltarem, a operação é recusada com `CONFIG_VENDAS_EM_FALTA` — nunca se usam contas fixas.
- **Movimentos:** FT = D cliente / C proveitos + C IVA; NC = o inverso; FR = D disponibilidade / C proveitos + C IVA; recibo = D disponibilidade / C cliente.

O n.º do lançamento fica guardado em `numero_lan_contabilizacao`. Descontabilizar é sempre um estorno (ADR-016). Uma FT com recibos activos não se descontabiliza.

**Documentos do legado.** O legado ligava a venda ao lançamento só pelo n.º do documento, que ele próprio reutilizava entre FT, FR e recibos da Tesouraria. O estorno procura o único lançamento activo que movimenta a conta do cliente no sentido da venda; se houver ambiguidade, recusa e remete para a Contabilidade. Verificação sobre o backup real (em transacção revertida, sem alterar dados), nas 51 vendas contabilizadas:
- 12 estornáveis;
- 5 bloqueadas por reconciliação bancária;
- 25 vendas POS, a descontabilizar pela sessão;
- 9 ambíguas ou sem lançamento.

**Recibos.** Só liquidam facturas FT contabilizadas do próprio cliente, com montante até ao pendente. A anulação só é possível com o recibo não contabilizado, exige motivo e fica registada (`estado`, `anulado_em`, `motivo_anulacao`); o recibo nunca é apagado.

## ADR-030 — Vendas parte 2: envio à AGT, Hash SAF-T, ficheiro SAF-T(AO), QR e multi-moeda

**Envio à AGT no próprio backend.** No legado, as credenciais e chaves estavam num serviço Node à parte (`servico_agt/servidor.js`) e o token de acesso ficava no localStorage do browser. O ciclo de envio também corria no browser, com uma variável `emCurso` que não impedia envios duplicados vindos de duas abas.

No sistema novo:
- O backend assina com JWS RS256 (chave do produtor no softwareInfo, chave do contribuinte nos documentos e nos pedidos) e chama os serviços `registarFactura`, `obterEstado`, `consultarFactura` e `solicitarSerie`.
- As credenciais vêm só do ambiente do servidor. As chaves ficam num volume só de leitura (`/run/segredos/agt`, fora do Git, com `*.pem` e `.segredos/` no `.gitignore`). A API de ligação devolve o estado sem expor segredos.
- Há três drivers (`ClienteAgt`): `desligado` (por omissão), `direto` e `intermedio`, que reutiliza o serviço do legado já instalado.
- Mantém-se o ciclo de estados do legado: POR_ENVIAR/ERRO → ENVIADO → VALIDO | INVALIDO; REJEITADO; E09 leva a uma consulta directa. A espera entre consultas cresce conforme a resposta: resultCode 8 (em processamento), 7/E97 (pedido prematuro), E98/HTTP 429 (demasiados pedidos). Com resultCode 9 (cancelado), o documento é reenviado.
- Um lock Redis por empresa e operação impede envios duplicados. O ciclo automático corre no serviço `scheduler` (`erp:agt:ciclo`, de 2 em 2 minutos) e na fila `agt`. É também disparado 3 s depois de cada emissão, se o envio automático estiver ligado.
- **Correcção de documentos:** quando o documento tem erros locais, ou foi considerado inválido ou rejeitado pela AGT, pode ser revalidado. Só o documento electrónico (`fe_documento`) é refeito, a partir dos dados de origem corrigidos (`Venda::$permitirRevalidacao`); os restantes campos fiscais continuam selados. Um documento INVALIDO é reenviado como correcção (`documentStatus` "C").
- **Séries:** as regras do legado mantêm-se (uma série activa por tipo, ano e origem; o código não se altera depois de usada; uma série usada não se elimina). O código da série pode ser pedido à AGT. Com "exigir séries AGT", os documentos fiscais só usam séries com código da AGT, e o limite autorizado é respeitado (`SERIE_ESGOTADA`).
- **Regime:** não se activa se existirem documentos fiscais fora do regime com data igual ou posterior à data de início, e não se desactiva nem muda de data depois de haver documentos no regime.
- **A confirmar na homologação** (herdado do legado): o valor de `typ` nas assinaturas (`AGT_JWS_TYP`) e o caminho dos serviços (`AGT_URL_BASE`).

**Hash SAF-T(AO) na emissão.** O legado calculava na exportação um "hash" falso (soma de caracteres + texto fixo) e voltava a calculá-lo a cada exportação. O sistema novo usa `RSA-SHA1("InvoiceDate;SystemEntryDate;InvoiceNo;GrossTotal;HashAnterior")` em base64, encadeado por série. É calculado na emissão, dentro da transacção que bloqueia a série, e fica selado (`saft_hash`, `saft_hash_controlo`). Sem chave SAF-T (software ainda não certificado), fica `HashControl = "0"`, sem assinatura inventada.

**Ficheiro SAF-T(AO) 1.01_01 (facturação).** É gerado com XMLWriter, que escapa os caracteres especiais. Contém:
- MasterFiles: clientes, produtos (tipo P/S) e tabela de taxas;
- SalesInvoices: TotalDebit = soma das NC e TotalCredit = soma de FT/FR, ambos sem imposto; linhas com o valor sem imposto; References nas NC; isenção vinda do produto ou da configuração, nunca um M10 fixo; `Currency` nos documentos em moeda estrangeira; `Payment` nas FR;
- Payments: os recibos (RG), com a factura de origem.

Correcções face ao legado: as etiquetas erradas (`InvoiceNao`, `AuditFileSchemaVersion`, `SoftwareValidactionNumber`) e o número de certificação inventado. Os documentos anteriores ao sistema novo saem com Hash "0" e ficam listados nos avisos, tal como as linhas isentas sem código M. Antes da certificação, o ficheiro deve passar no validador da AGT.

Verificação sobre os dados reais: foram gerados ficheiros SAF-T das empresas 5, 6 e 18, com XML válido. Os avisos incluem produtos a 0% sem código de isenção; é preciso preenchê-lo na ficha do produto ou definir a isenção por omissão.

**QR code.** É gerado no servidor (chillerlan/php-qrcode + GD), em PNG de 350×350 ou SVG, com correcção M e o endereço `consultar-fe?emissor=<NIF>&document=<n.º>` (espaços como `%20`). O logótipo oficial é opcional, em `storage/app/agt/agt_logo.png`. Como no legado, usa-se a menor versão de QR em que o endereço cabe (a AGT indica a versão 4, que não chega).

**Multi-moeda.** A moeda e o câmbio ficam no cabeçalho do documento. Os valores oficiais são em Kz e os valores na moeda ficam nos campos `*_moeda`.
- **Câmbio:** vem da tabela, com a última taxa até à data do documento; a taxa da empresa prevalece sobre a geral. Também pode ser indicado manualmente.
- **Cálculo em Kz:** as regras AGT são aplicadas ao preço convertido com 6 casas decimais, para o documento electrónico ficar coerente (linhas e totais em Kz, com o bloco `currency`).
- **Nota de crédito:** herda sempre a moeda e o câmbio da factura, para o contravalor anular exactamente o da factura.
- **Conversões:** herdam a moeda; o câmbio é reavaliado na nova data, salvo se era manual.
- **Recibos e diferenças de câmbio:** os recibos são em Kz; as diferenças de câmbio ficam para o módulo Tesouraria.

**Esquema.**
- `taxas_cambio.taxa` passa a `numeric(18,6)`. Era `numeric(9,4)`, o que truncava as taxas do legado (até 6 casas) e limitava o valor a 99 999.
- O gerador passa a aplicar um mínimo de 100 caracteres às colunas "feito por" (`*_por`, `nome_utilizador`, `operador`): 51 colunas estavam com `varchar(10)`/`varchar(20)` e não cabia um nome de utilizador. O teste de configuração do regime detectou o problema.

## ADR-031 — Compras (parte 1): do pedido à contabilização da factura

**Âmbito.**
- Pedido e deliberação por escalões; proposta; comparação; adjudicação que gera a encomenda.
- Recepção em dois passos: registo pelo comprador e validação no armazém.
- Factura de fornecedor, da encomenda ou directa; contabilização e estorno.
- Ficam para depois: contratos e marcos, pedidos gerados a partir de encomendas de clientes, pagamentos (Tesouraria) e controlo orçamental (Orçamento).

**Correcções face ao legado** (levantamento completo de `ui_compras_v2.js`, `compras_deliberacao.js`, `moedas_compras.js` e `ui_warehouse.js`):
- **Numeração por série:** PC (pedido), PP (proposta), EC (encomenda) e RCP (recepção). As encomendas do legado eram `ORD-` + 4 dígitos aleatórios, sem controlo de colisões. O n.º da guia e o da factura do fornecedor continuam a ser os externos.
- **Adjudicação atómica e única:**
  - o exercício é verificado antes de gravar;
  - a proposta tem de estar "proposta para adjudicação", o que mantém a segregação avaliar ≠ adjudicar;
  - as restantes propostas ficam RECUSADAS.

  No legado era possível adjudicar duas vezes, e uma adjudicação podia ficar sem encomenda.
- **Deliberação:** escalões cumulativos, como no legado.
  - Ninguém aprova os próprios pedidos; a recusa exige nota.
  - A adjudicação abre uma revisão (novas etapas, código 409 `REVISAO_DELIBERACAO`) se o valor da proposta exigir mais níveis do que os aprovados.
  - O valor estimado usa o custo médio, e não o preço de venda.
- **Ligação linha a linha:** encomenda ↔ recepção ↔ factura, com as colunas `item_compra_id` e `item_encomenda_id`. O legado casava pelo `product_id` e perdia linhas repetidas do mesmo produto.
- **Limites de quantidade:** a recepção fica limitada ao pendente por receber e a factura ao pendente por facturar; tudo numa única transacção. O legado gravava as quantidades facturadas antes da factura e sem limite.
- **Estado da encomenda gravado:** EM_PROCESSAMENTO, PARCIAL ou RECEBIDO. O legado nunca gravava PARCIAL.
- **Stock:** só por movimentos (`ServicoStock`), com o produto bloqueado durante a operação e custo médio ponderado (coluna nova `produtos.custo_medio`). O legado nunca calculava o custo médio.
- **Validação da recepção** (diário GL):
  - D compras (2.1) / C transitória do fornecedor (3.2.8), seguido de D mercadorias (2.6) / C compras (2.1).
  - A parte já facturada e ainda não recebida entra ao valor da factura; o restante entra ao preço ou câmbio da encomenda na data da recepção (o legado usava a data do dia da validação).
  - A mesma recepção não pode ser validada duas vezes.
- **Reverter a validação:** faz estorno e dá saída de stock ao custo da entrada. Só é permitido sem facturas da encomenda e com stock disponível. O legado deixava a conta 3.2.8 desequilibrada e o stock negativo.
- **Factura:**
  - n.º único por fornecedor, garantido por um lock transaccional;
  - exercício aberto também na factura directa;
  - a factura directa não aceita artigos de stock (no legado debitava a 2.1 sem entrada em stock).
- **Contabilização da factura** (diário FF):
  - artigo de stock: D transitória, pelo valor consumido na recepção; a diferença para o valor da factura vai para diferenças de câmbio;
  - imobilizado: D conta do activo;
  - serviços: D conta de custo do produto. O legado usava por omissão a '72', que no PGC angolano são custos com pessoal;
  - D IVA dedutível;
  - C fornecedor pelo total da factura, que tem de bater com as linhas.
- **Descontabilizar = estorno:**
  - o lançamento é localizado pelo n.º guardado no documento;
  - nos documentos do legado, localiza-se pelo n.º do documento, filtrado pela conta do fornecedor a crédito e pelo diário FF (`LocalizadorLancamentos`, comum com Vendas);
  - o legado apagava as linhas por `doc_number` e podia apagar lançamentos de outro fornecedor ou da Tesouraria com o mesmo número.
- **Anulações com motivo e rasto** (pedido, proposta, encomenda, recepção, factura), nunca eliminação. Uma encomenda anulada devolve a proposta e o pedido ao estado adjudicável.
- **Contas:** vêm do produto ou do fornecedor e, na falta, da configuração de compras (tabela nova `configuracoes_contabeis_compras`). Não há contas fixas no código.

**Dicionário.** A tabela `pa_settings` do legado ("purchase approval settings", que guarda os `niveis`) tinha sido traduzida por engano como `configuracoes_processamento_salarial` (RH). Passa a chamar-se `configuracoes_deliberacao_compras` (model `ConfigDeliberacaoCompra`).

Os estados de pedidos, propostas, encomendas, recepções e facturas passam a ter domínio (CHECK), com o texto original guardado. O IVA de cada linha fica gravado em Kz (`imposto_kz`), para a contabilização em moeda estrangeira bater ao cêntimo.

**ETL.**
- As facturas de fornecedor do legado guardavam as linhas embutidas (`items[]`). São agora expandidas para `itens_compra` (44 linhas), com os totais a bater nas 25 facturas.
- As linhas ligadas no legado à linha da proposta (bug das "linhas virtuais") são religadas à linha da encomenda com o mesmo produto.
- As encomendas 33 e 34 (empresa 6) não têm linhas no backup, apagadas no legado (ex.: `clearAllTransactions` apagava `purchase_items` de todas as empresas). As 11 linhas de factura correspondentes ficam sem ligação à encomenda, registadas como ocorrências.

**Verificação sobre os dados reais** (em transacção revertida, sem alterar dados):
- facturas contabilizadas (23): 18 estornáveis; 2 sem lançamento identificável; 1 reconciliada com o banco; 2 pagas;
- facturas por contabilizar (2): 1 contabiliza; 1 exige a configuração de contas (produto sem conta de custo);
- recepções validadas (20): 19 bloqueadas por terem facturas, como previsto; 1 do legado sem ligação às linhas.

**Pendências conhecidas:** duas facturas da empresa 6 com o mesmo n.º (`FT FA.2026/631`), herdadas do legado; o sistema novo impede novos duplicados.

## ADR-032 — Tesouraria (parte 1): pagamentos, recebimentos, pendentes e ligação às facturas

**Âmbito.** Inclui:
- documentos de pagamento e de recebimento, com criação, edição (só enquanto pendentes), anulação, integração e desintegração por estorno;
- documentos em aberto de clientes e de fornecedores;
- ligação às vendas e às facturas de fornecedor;
- meios de pagamento.

Ficam para a parte 2: reconciliação bancária (importação de extractos, correspondência, compensação), folha de caixa e conferência de caixa, multi-moeda com diferenças de câmbio e cartas de pagamento (o legado tinha as tabelas, mas nenhum código as usava).

**Correcções face ao legado** (levantamento completo de `ui_tesouraria.js`, `moedas_tesouraria.js`, `ui_folha_caixa.js` e `fluxo_tesouraria.js`):
- **Numeração por série:** "PAG" para pagamentos e "REC" para recebimentos. A referência continua a ser texto livre; no legado era a única chave e aparecia repetida (5 781 documentos para 5 111 referências distintas).
- **Validação ao gravar:**
  - a conta financeira tem de ser de movimento, das classes 43 (bancos) ou 45 (caixa);
  - o sentido tem de estar certo: num pagamento os débitos excedem os créditos, num recebimento o contrário;
  - o exercício tem de estar aberto (o legado não verificava);
  - as contas das linhas têm de ser de movimento;
  - **o valor liquidado de um documento não pode exceder o saldo em aberto**, já descontados os pagamentos ainda por integrar (no legado podia pagar-se duas vezes).
- **Ligação explícita** de cada linha à venda (`venda_id`) ou à factura de fornecedor (`fatura_compra_id`). Na integração, actualiza o pago e o estado da venda e o estado da factura de fornecedor (PENDENTE, PARCIAL ou PAGO, calculado a partir do diário). No legado nada era actualizado, e o "pago" das vendas contava documentos por integrar, ignorava o sentido D/C e ignorava a empresa.
- **Pendentes:** calculados com uma única consulta agregada ao diário.
  - Consideram só contas de terceiros da classe 3, excepto a 34 (impostos), e com terceiro; o legado aceitava 1, 2, 3 e 48.
  - Descontam os documentos por integrar.
  - Cada pendente é ligado à venda ou factura quando o n.º e o terceiro não deixam dúvida.
  - A consulta ao diário no servidor substitui a leitura do diário inteiro pelo browser: 3 a 40 ms por empresa nos dados reais.
- **Integração:**
  - numa única transacção, através de `ServicoLancamentos` (diário BD para bancos, CX para caixa);
  - cada contrapartida fica com o n.º do documento que liquida, graças ao `numero_documento` por linha, suportado agora pelo `ServicoLancamentos`.
- **Desintegrar = estorno**, recusado se o lançamento estiver reconciliado com o banco ou ligado a activos.
  - O legado apagava as linhas pelo texto da referência, e o seu bloqueio por reconciliação procurava o prefixo `RECON_`, que nenhum código gerava.
  - Para os documentos do legado, o lançamento é localizado pela referência ou por `TES-<id>`, filtrado pela conta financeira no sentido do documento; se houver ambiguidade, a operação é recusada.
- **Nunca se apaga; anula-se com motivo.**
  - Não foram migradas as rotinas destrutivas do legado:
    - a limpeza automática de documentos sem data, em todas as empresas, ao abrir o ecrã;
    - `forceClearSale`;
    - `repairtreasuryInconsistencies` / `_executeRescue` / `recoverOrphanedModuleDocs`, que repunham estados sem apagar o diário e levavam a dupla contabilização.
  - As validações de dados (ADR-018) cobrem estes casos como relatórios só de leitura.
- **Meios de pagamento:**
  - IBAN angolano validado com os dígitos de controlo (ISO 13616, mod 97); o legado só avisava;
  - SWIFT/BIC com formato válido;
  - um só meio predefinido por empresa, garantido numa transacção;
  - eliminação lógica.
- **Permissões:** as do legado, com segregação efectiva entre `teso_doc_emitir` e `teso_integrar` (no legado era só um aviso ao gravar o perfil).

**ETL.** Os textos passam a ser limpos de caracteres invisíveis (espaço de largura zero, BOM, word joiner), colados no legado ao copiar e colar. Criavam uma conta "fantasma" `\u200B4311` ao lado da 4311 na empresa 22, 11 lançamentos em códigos inexistentes e 4 documentos de tesouraria com conta financeira inválida. A conta fantasma cai agora na regra de conta duplicada (quarentena), e as ocorrências ficam registadas.

**Verificação sobre os dados reais** (desintegração em transacção revertida, sem alterar dados): dos 5 777 documentos integrados:
- 3 659 são estornáveis;
- 2 006 estão bloqueados por reconciliação bancária; o legado apagá-los-ia na mesma;
- 111 têm referência ambígua ou nenhum lançamento, e são recusados em vez de adivinhados;
- 1 está ligado a activos.

## ADR-033 — Tesouraria (parte 2): reconciliação bancária, folha de caixa e conferência de caixa

**Reconciliação bancária** (`processExternalBankStatement`, `runReconciliationAlgorithm`, `confirmBankReconciliation` e `deleteReconciliation` do legado):
- **Importação:** aceita XLSX, XLS e CSV (PhpSpreadsheet); o legado só lia Excel.
  - Os cabeçalhos são reconhecidos por sinónimos (Data, Referência, Descrição, Débito/Crédito ou Valor + D/C), sem acentos nem maiúsculas.
  - Os números são lidos correctamente em "1.500,50" e "1,500.50" (o legado estragava o primeiro); as datas em dd/mm/aaaa, aaaa-mm-dd ou data Excel.
  - **Os duplicados são detectados**: reimportar o mesmo extracto não duplica linhas. Cada importação fica com um lote `IMP-AAAAMMDD-nnnn`.
- **Convenção única:** o extracto está na óptica do banco (C = entrada, D = saída) e corresponde a lançamentos da conta 43 no sentido oposto. O legado tinha convenções contraditórias.
- **Sugestões:** mesma data e valor, depois ±N dias, depois valor único; a referência desempata.
  - As sugestões não gravam nada.
  - O cálculo é indexado por valor e sentido: 0,3 s nas contas com mais linhas pendentes (a primeira versão, que comparava todos com todos, demorava 17 s).
- **Confirmação:**
  - por grupos (N:M), com Σ extracto = Σ diário;
  - numa transacção, com as linhas bloqueadas;
  - recusa linhas de outra conta, já reconciliadas, estornadas ou estornos;
  - código `REC-AAAAMMDD-nnnn` sem colisões (o legado usava `Date.now()` ou 4 caracteres aleatórios).
- **Anular** não apaga: a reconciliação fica ANULADA, com o motivo, e as duas pontas voltam a estar por reconciliar. As linhas de extracto só se anulam se estiverem por reconciliar.
- **Mapa de reconciliação numa data:** saldo do diário, movimentos só no diário, movimentos só no extracto e saldo que o banco deve apresentar.

**Estorno e reconciliação.** O campo `reconciliacao_codigo` do diário é partilhado por dois conceitos:
- reconciliação **bancária** (`REC-`, `MAN-`, `DFT-`, contas 43/45, códigos com correspondências de extracto): bloqueia o estorno;
- **compensação** entre facturas e pagamentos na classe 3 (`REC_`, `AUTO`, `TRF_`, `MATC…`): passa a ser **libertada** no estorno, nas duas pontas, com registo de auditoria, como o legado fazia ao descontabilizar.

Nos dados reais, isto aumenta os documentos de tesouraria estornáveis de 3 659 para 4 793; os 872 bloqueados são reconciliações bancárias.

**Folha de caixa** (`ui_folha_caixa.js`):
- uma sessão aberta **por conta** de caixa, garantida com lock; o legado tinha uma por empresa e permitia duplicá-la com um duplo clique;
- o saldo de abertura sugerido é a contagem física do último fecho, com aviso se o saldo indicado for diferente;
- os movimentos têm contrapartida de movimento e exercício aberto e, quando liquidam uma factura, o valor não pode exceder o saldo em aberto;
- **os pendentes descontam também os movimentos de caixa ainda por contabilizar de qualquer sessão**; o legado só descontava a sessão aberta, o que permitia pagar em duplicado;
- o fecho grava a diferença entre o físico e o sistema;
- a contabilização:
  - é feita numa transacção, com um lançamento por data de movimento (o legado fazia um por linha, datado da abertura) e a diferença de fecho lançada em sobras ou quebras, conforme a configuração;
  - actualiza as vendas e facturas liquidadas;
  - desfaz-se por estorno (o legado não tinha descontabilização).

As sessões contabilizadas no legado, sem ligação aos lançamentos, são estornadas na Contabilidade.

**Conferência de caixa:**
- total físico calculado pelas denominações (notas e moedas em Kz);
- saldo do sistema = saldo da conta no diário **até à data** da conferência (o legado não tinha data de corte);
- uma diferença exige justificação;
- a regularização é opcional e feita no diário CX com n.º próprio: sobra D caixa / C proveito; quebra D custo / C caixa. No legado as contas estavam invertidas (sobra→78, custo; quebra→68, proveito), usava-se o primeiro diário que aparecesse e as linhas eram apagadas pela referência ao editar;
- a assinatura do gerente tem de ser de outra pessoa que não o operador;
- reabrir = estorno da regularização.

**Contas de tesouraria** (tabela nova `configuracoes_contabeis_tesouraria`): sobras, quebras e diferenças de câmbio. O legado usava 6621/7621 fixas no código; passam a vir da configuração. *(Correcção, ronda 2, decisão 19: a justificação anterior — «invertidas face ao PGC angolano» — estava errada. No PGC angolano a classe 6 é de proveitos e a 7 de custos, pelo que 6621 = diferenças de câmbio favoráveis e 7621 = desfavoráveis, como no legado; a configuração é pré-preenchida com estas contas onde existem no plano e as Validações de dados assinalam as empresas onde faltam.)*

**Pendente (Tesouraria parte 3):** multi-moeda (documentos e caixas em moeda estrangeira, diferenças de câmbio na liquidação de facturas em moeda) e cartas de pagamento aos bancos (o legado tinha as tabelas, mas nenhum código).

## ADR-034 — Tesouraria (parte 3): multi-moeda e diferenças de câmbio

**Moeda nos lançamentos.** `ServicoLancamentos` passa a aceitar, por linha, `codigo_moeda`, `valor_moeda` e `taxa_cambio`:
- a contabilização de vendas grava a moeda na linha do cliente (FT e NC em moeda estrangeira);
- a de compras grava-a na linha do fornecedor.

Sem isto não havia saldo em moeda das facturas e, portanto, não havia maneira de calcular a diferença de câmbio na liquidação.

**Pendentes em moeda.** Cada documento em aberto traz o `codigo_moeda` e o `saldo_moeda`, somando as linhas do diário na moeda e descontando os documentos por integrar. Nos dados reais o cálculo continua a demorar entre 30 e 60 ms por empresa.

**Documentos de tesouraria em moeda** (`tesoFxPrepararGravacao` do legado):
- **Moeda do documento:** é a da conta financeira no plano de contas; o câmbio é o da tabela na data do documento, ou manual.
- **Linha que liquida um documento em moeda estrangeira:**
  - **Quantidade em moeda liquidada:** é o valor da linha, se a conta for na mesma moeda; se o pagamento for feito a partir de uma conta em Kz, é o valor em Kz convertido ao câmbio do dia.
  - **Limite:** a quantidade tem de ser ≤ saldo em moeda do documento.
  - **Valor histórico em Kz:** é o saldo em Kz, se liquidar tudo; senão, a proporção `moeda liquidada × saldo Kz ÷ saldo moeda`. O terceiro é lançado por este valor, e por isso a conta fica exactamente saldada em Kz e em moeda.
  - **Diferença de câmbio:** o banco é lançado ao câmbio do documento, e a diferença face ao valor histórico vai para as contas configuradas (favoráveis ou desfavoráveis, conforme o sentido). O legado usava 6621 (favoráveis, proveito) e 7621 (desfavoráveis, custo) fixas — contas correctas no PGC angolano (correcção da ronda 2, decisão 19: não estavam trocadas); são as que se pré-preenchem na configuração.
  - Uma conta numa moeda diferente da do documento é recusada (`MOEDAS_DIFERENTES`).
- **Integração:** recalcula tudo com o saldo actual, de forma a apanhar outros documentos integrados entretanto.
- **Venda e factura liquidadas:** o pago da venda e o estado da factura de fornecedor são actualizados pelo valor histórico em Kz.
- **Documento por integrar:** mesmo depois de desintegrado, continua a reservar o valor até ser anulado, para evitar pagamentos duplicados.

**Fica para outros módulos:**
- caixas em moeda estrangeira (a folha de caixa continua a ser só em Kz, com recusa explícita);
- recibos de vendas (módulo Vendas) em moeda;
- cartas de pagamento aos bancos: pela estrutura das tabelas (colaborador, IBAN, mês) servem o pagamento de salários, por isso passam para o módulo RH/Salários.

## ADR-035 — Compras (parte 2): contratos de fornecedores e encomendas de clientes

**Contratos** (`js/ui_compras_v2.js:4418-4798`):
- **Contrato:**
  - a referência é única por fornecedor;
  - a data de fim não pode ser anterior à de início;
  - o fornecedor não muda depois de haver encomendas;
  - o valor não pode ficar abaixo da soma dos marcos.
- **Encomendas associadas:**
  - só do mesmo fornecedor e não anuladas (no legado era só uma confirmação);
  - cada encomenda pertence a um só contrato, garantido por um índice único no pivô;
  - mover uma encomenda para outro contrato é explícito (`mover`), em vez de retirada silenciosa;
  - mantém-se, por compatibilidade, `contratos_fornecedores.encomenda_compra_id` = 1.ª encomenda.
- **Marcos:**
  - geridos um a um, com ids estáveis; o legado apagava e recriava todos a cada gravação;
  - a soma dos montantes tem de ser ≤ valor do contrato, e a data tem de cair dentro do período;
  - **o estado deixa de ser manual:** PENDENTE → FATURADO (factura do fornecedor ligada; coluna nova `fatura_compra_id`) → PAGO (factura paga);
  - um marco facturado não se altera nem se elimina.
- **Consumo** calculado: encomendado, facturado (facturas das encomendas e dos marcos) e pago, com a percentagem e um aviso quando o valor contratado é excedido.
- **Expiração automática:** ATIVO passa a EXPIRADO depois da data de fim, na listagem e no agendador (diariamente às 00:15). No legado a expiração era manual.
- **Cancelar:** exige motivo e deixa rasto (`cancelado_em`, `motivo_cancelamento`); um contrato cancelado não se altera.
- **Domínios dos estados:** contratos ATIVO/EXPIRADO/CANCELADO; marcos PENDENTE/FATURADO/PAGO.

**Encomendas de clientes → pedidos de compra** (ecrã `compras_encomendas_clientes`, que no legado ficava vazio; `generatePurchaseRequestFromSales`):
- **Listagem:** mostra as encomendas de clientes (NE) com as linhas, a quantidade pendente, o stock disponível e o pedido de compra activo, se houver.
- **Pedido gerado a partir das linhas escolhidas:**
  - um único pedido, **consolidado por produto**;
  - preço estimado ao custo médio (o legado deixava 0, e a deliberação caía no preço de venda);
  - criado pelo utilizador, o que impede a auto-aprovação: no legado `criado_por` ficava vazio;
  - passa pela deliberação normal.
- **Uma linha só entra num pedido activo;** anular o pedido liberta as linhas.

**Verificação sobre os dados reais:**
- 9 encomendas de clientes, com 12 linhas, das quais 5 ainda por comprar;
- o contrato migrado apresenta o consumo (encomendado 100%; facturado acima do contratado, herdado do legado).

**Pendente:** controlo orçamental das compras (`OrcControlo`), que chega com o módulo Orçamento.

## ADR-036 — RH/Salários (parte 1a): motor salarial, ciclo do período, fotografia e contabilização

**Motor** (`App\Services\RH\MotorSalarial`): reescrita pura, em bcmath, de `js/engine_v2.js`, com dois modos.
- **LEGADO** reproduz o legado tal como calculava. Serve apenas para fotografar os períodos migrados.
- **ATUAL** é o modo dos períodos novos. Tem as correcções abaixo, todas do tipo "corrigir só o que está errado" (ADR-017).
- **Mantém-se do legado:**
  - a tabela de IRT, com 11 escalões e parcela fixa;
  - o INSS a 3 % e 8 %, que no modo ATUAL são as taxas da empresa;
  - avençados a 6,5 %;
  - horas extra com 50 % até 30 h e 75 % acima;
  - faltas pro rata dos dias trabalhados face aos `dias_contrato`.
- **Correcções no modo ATUAL:**
  1. A isenção até 30 000 Kz depende da marcação `irt = conditional_30k`, e não do nome. Nos dados reais é exactamente o conjunto alimentação + transporte, portanto o resultado é igual. A isenção passa a incidir sobre o valor **pago**; o legado usava o valor cheio mesmo com faltas.
  2. Um VENCIMENTO com `irt = false` fica fora da base de IRT. O legado ignorava a marcação.
  3. A base de INSS nunca fica negativa.
  4. O IRT do avençado incide sobre bruto − faltas; o legado usava o bruto.
  5. As rubricas OUTROS (ex.: "Dias de Trabalho") são só informativas e não entram no bruto.
  6. Cada componente é arredondado a 2 casas.

**Ciclo** (`ServicoFolhaSalarial`):
- **Estados:** ABERTO → FECHADO (encerrar) → VALIDADO (validar), com domínio normalizado.
  - Os lançamentos só se alteram com o período ABERTO.
  - Reabrir não é possível enquanto o período estiver contabilizado.
- **Importar contratos:** cria os lançamentos a partir das remunerações do contrato ACTIVO válido no mês, para colaboradores ACTIVOS em AOA. É idempotente.
- **Fotografia imutável:**
  - ao encerrar, gravam-se os resultados por colaborador em `resultados_folha_salarial` (componentes, rubricas, avisos, modo);
  - o recibo e a contabilização lêem a fotografia;
  - no legado, mudar um contrato ou um infotipo alterava retroactivamente os meses já pagos.
- **Contabilização:**
  - a partir da fotografia, com os mapeamentos `mapeamentos_contabeis_rh` (rubricas) e os do sistema (NET_PAY_CREDIT, IRT_CREDIT, IRT_AVENCADO_CREDIT, INSS_FUNC_CREDIT, INSS_EMP_DEBIT, INSS_EMP_CREDIT);
  - linhas agregadas por conta, D/C, unidade de negócio e centro de custo, no diário SAL, com o documento `SALMMAAAA`;
  - se faltar um mapeamento, a operação é recusada (`MAPEAMENTO_EM_FALTA`) em vez de deixar o lançamento desequilibrado.
- **Descontabilizar** é feito por estorno (ADR-016). Para os períodos migrados, o lançamento é localizado pelo documento `SALMMAAAA` no diário SAL.
- **Recibos:** só de períodos VALIDADO, como no legado.

**Migração e não-regressão:**
- O legado não guardava resultados. Depois da ETL, `erp:migrar-backup-legado` fotografa os 46 períodos em modo LEGADO.
- Para cada período escolhe o divisor que reproduz o diário. O legado dividia por `dias_contrato` do contrato ou do colaborador, conforme a versão.
- **38 dos 44 períodos contabilizados conferem com o diário** (tolerância de 10 Kz).
- Os 6 restantes são a empresa 1 de 04 a 07/2026 e a empresa 8 em 04 e 05/2026. Os dados foram alterados depois da contabilização, e é esse o problema que a fotografia resolve.
- Estes 6 períodos aparecem na validação `folhas_salariais_vs_diario` (Sistema › Validações) e em `GET /api/rh/salarios/verificacao-legado`.
- Em modo ATUAL, com os dados actuais, diferem 4 períodos, todos apenas pelo efeito das correcções: empresa 8 em 04/2026 (isenção sobre o valor pago), empresa 3 em 05/2026 (isenção sobre o valor pago, com o degrau da tabela de IRT a 150 000 Kz), empresa 8 em 05/2026 (precisão dos dias, 1,85 Kz) e empresa 18 em 01/2026 (avençado, `irt=false` e contrato sem validade no mês) — análise de 2026-10-02.

**Decisões do utilizador registadas aqui (ronda 2, 2026-10-06 — ver ADR-068):**
- **Decisão 2 — `dias_trabalhados` com 3 casas decimais: aceite.** O pro rata usa os dias com 3 casas; a diferença face ao legado é de cêntimos (ex.: empresa 8 em 05/2026, 1,85 Kz) e fica explicada na verificação do legado.
- **Decisão 4 — desconto com `calculo_horas = FALTA` tratado como falta: aceite.** Uma rubrica de DESCONTO marcada `FALTA` (ou com «falta» no nome, como no legado) fica marcada como falta no recibo e abate à base de INSS e à matéria colectável do IRT (soma das faltas), em vez de ser só um desconto sobre o líquido.
- Decisões 1, 3, 5, 6 e 7 (tabela de IRT configurável, confirmação dos avisos ao encerrar, horas extra automáticas com aviso e segregação encerrar/validar, feriados de Angola, férias pela Lei Geral do Trabalho): ver ADR-068.

**Glossário:** o `infotypes.inss` do legado é a marcação "sujeito a INSS" (`sujeito_inss`) e não um número.

**Fica para o bloco b:** CRUD de colaboradores, contratos, infotipos e mapeamentos, coordenadas bancárias, cartas de pagamento e pagamento dos salários por documento de tesouraria.

## ADR-037 — RH/Salários (parte 1b): cadastros, IBAN, cartas de pagamento e pagamento pela tesouraria

**Colaboradores** (`ServicoColaboradores`; js/app_v2.js:3833-3997 e 9068-9127; ficha_colaborador.js):
- **Mantém-se do legado:**
  - nome e NIF obrigatórios;
  - reformado e avençado não podem estar ambos marcados;
  - a ficha grava dependentes e habilitações por substituição, na mesma transacção;
  - a habilitação máxima é calculada quando está vazia (maior nível concluído);
  - é criado o terceiro «COLABORADOR» com o mesmo NIF.
- **Correcções:**
  - o NIF é normalizado e único na empresa (o formulário do legado não verificava);
  - a procura do terceiro faz-se na empresa (no legado era global);
  - os dias úteis são validados entre 1 e 31;
  - o gestor não pode ser o próprio colaborador, e o posto tem de pertencer à unidade orgânica.
- **Eliminação:**
  - é lógica e só é permitida sem utilizações, o que se verifica pelas FKs reais do PostgreSQL (`VerificadorReferencias`);
  - o legado apagava em cascata e deixava contratos e lançamentos órfãos;
  - para quem saiu da empresa, usa-se o estado INACTIVO.

**Coordenadas bancárias:**
- Um IBAN por colaborador (índice único), com banco obrigatório.
- O IBAN é validado com os dígitos de controlo (ISO 13616): AO + 23 dígitos, ou IBAN estrangeiro.
- O NIB de 21 dígitos é aceite e convertido para AO06.
- No legado, os botões deste ecrã não funcionavam: as funções tinham «º» no nome, e só a importação gravava IBAN.
- Nos dados reais, 64 colaboradores activos não têm IBAN e 4 IBAN têm formato inválido. Ambos aparecem em Sistema › Validações.

**Contratos** (`ServicoContratosTrabalho`):
- **Mantém-se do legado:**
  - valores por omissão: 22 dias, 8 h, moeda AOA, estado ACTIVO;
  - pelo menos uma remuneração com valor;
  - valor diário = valor mensal ÷ dias do contrato;
  - terminar exige `contratos_terminate`.
- **Correcção principal — histórico de contratos:**
  - o legado só admitia um contrato por colaborador em toda a vida, e a revisão salarial reescrevia o contrato (e, sem fotografia, os meses passados);
  - agora há vários contratos, desde que os períodos não se sobreponham;
  - o processamento usa o contrato ACTIVO válido no mês.
- **Outras correcções:**
  - data de fim ≥ data de início;
  - horas por dia entre 0 e 24;
  - só rubricas de VENCIMENTO, sem repetições;
  - «sem fim» passa a data de fim vazia (continua a aceitar 9999-12-31).
- **Formato das remunerações:** as novas gravam-se com chaves em português. As migradas mantêm as chaves do legado, e a leitura aceita os dois formatos.

**Rubricas, tipos de organização e bancos** (`ServicoCadastrosRH`):
- Nomes únicos na empresa, sem distinguir maiúsculas; os 2 pares repetidos do legado ficam como estão.
- Domínios validados:
  - tipo: VENCIMENTO, DESCONTO ou OUTROS;
  - IRT: true, false ou conditional_30k;
  - cálculo por horas: EXTRA, FALTA ou NAO. Esta coluna estava gerada como numérica e passou a texto.
- Eliminação só sem utilizações, incluindo remunerações de contratos (jsonb).
- A conta do banco tem de ser de movimento.
- No legado, eliminar um banco deixava IBAN órfãos, e eliminar uma rubrica deixava contratos e lançamentos órfãos.

**Mapeamento contabilístico:**
- A matriz tem rubrica × tipo de organização, mais a coluna Avençado, e as contas do sistema: NET_PAY_CREDIT, IRT_CREDIT, IRT_AVENCADO_CREDIT, INSS_FUNC_CREDIT, INSS_EMP_DEBIT, INSS_EMP_CREDIT e ROUNDING_DIFF.
- A conta tem de existir e ser de movimento. O legado aceitava contas «fora do plano».
- Grava-se um mapeamento por chave, o que elimina os 5 duplicados herdados; limpar uma célula apaga o mapeamento em vez de gravar `''`.
- A contabilização ignora os mapeamentos com a conta vazia que vieram do legado.

**Ordem de pagamento, cartas e pagamento** (`ServicoPagamentoSalarios`):
- **Ordem de pagamento:** só de períodos VALIDADOS, e passa a vir da fotografia.
- **Cartas de pagamento:**
  - as tabelas `payment_letters` existiam no legado mas nunca foram usadas;
  - agora a ordem pode ser gravada como carta sobre uma conta bancária (43), com o IBAN e o valor fixados na emissão;
  - cada colaborador entra numa só carta por período;
  - a carta é recusada se faltar um IBAN (o legado imprimia «N/D»).
- **Pagamento:**
  - gera um documento PAG na tesouraria, PENDENTE e ligado ao período (`periodo_processamento_salarial_id`);
  - debita os salários a pagar (NET_PAY_CREDIT de cada colaborador), com o n.º `SALMMAAAA`;
  - exige o período contabilizado e a conta bancária em Kz;
  - o total pago no período não pode exceder o líquido;
  - a integração no diário faz-se pela Tesouraria (D salários a pagar / C banco);
  - uma carta com pagamento activo não se elimina nem se volta a pagar.
- **Ensaio com os dados reais** (último período de cada empresa, desfeito no fim): nas empresas 1 e 3 a carta foi emitida e o pagamento integrado. Nas outras 8 não há IBAN registados.

**Fica para depois:** importação Excel de colaboradores e contratos, contratos em massa, cópia de mapeamentos entre empresas, recuperação do mapeamento a partir do diário, e PDF da carta e do recibo (Fase 5).

## ADR-038 — RH parte 2a: assiduidade (efectividade), ausências e calendário

**Calendário** (`ServicoCalendarioRH`):
- A configuração da assiduidade (dias úteis da semana e feriados) é a fonte única dos dias úteis para a assiduidade, as ausências e as férias. No legado, as férias contavam segunda a sexta fixos.
- Não existe tabela de feriados: são uma lista na configuração, como no legado.
- Os tipos das colunas desta configuração tinham sido gerados sem dados e estavam errados. Foram corrigidos: dias úteis e feriados em jsonb, arredondamento inteiro e autorização booleana.

**Apuramento mensal** (`ServicoAssiduidade::resumoMes`, paridade com `resumoMes` de `js/modules/rh/assiduidade.js`):
- Mantém do legado:
  - horas do contrato por dia (8 por omissão), arredondamento, tolerância e mínimo de minutos para contar horas extra;
  - dias não úteis contam como extra, com autorização opcional;
  - férias aprovadas ou gozadas e ausências aprovadas não são falta; as ausências não remuneradas continuam a ser descontadas (art.º 222.º/2);
  - compensação DIA, MENSAL ou LIMITE;
  - aviso acima de 3 faltas no mês (art.º 230.º).
- **Não-regressão:** os dois fechos reais da empresa 18 (01/2026 e 09/2026) reproduzem exactamente as horas extra, as horas de falta e os dias de falta do legado.
- Correcções:
  - entram só colaboradores ACTIVOS. O legado só excluía «INACTIVO», mas o formulário gravava «Não ACTIVO», e gerava faltas a inactivos e suspensos;
  - não há falta em dias sem registo antes da admissão ou do início do contrato, nem depois do fim do contrato. O trabalho registado conta sempre;
  - as faltas por justificar são geradas em acções explícitas (detectar, fechar). No legado bastava abrir o ecrã para criar ausências.

**Fecho e lançamento no processamento:**
- **Fecho:**
  - há um fecho por mês (estado FECHADO ou REABERTO, índice único); o histórico fica na auditoria;
  - o fecho guarda as linhas e a configuração usada;
  - reabrir exige motivo e o processamento salarial do mês aberto.
- **Lançar** (`POST /rh/salarios/periodos/{id}/importar-efectividade`):
  - exige `calcular_folha` (no legado não havia guarda) e o processamento ABERTO;
  - as rubricas indicadas têm de ser de horas EXTRA e FALTA;
  - o mês é **recalculado** com a configuração do fecho e as ausências aprovadas até ao momento. Isto corrige três defeitos do legado:
    - subtraía as justificações depois da compensação e perdia horas extra;
    - nunca retirava as ausências pedidas (não detectadas) aprovadas depois do fecho;
    - deixava ficar os lançamentos cujas horas passaram a zero;
  - os lançamentos ficam com `origem = ASSIDUIDADE`, e os que deixam de ter horas são retirados.

**Ausências** (`ServicoAusencias`, catálogo da Lei 12/23):
- Mantém do legado:
  - tipos e unidades (dias de calendário, dias úteis ou horas);
  - o limite de dias seguidos é erro; os limites por mês e por ano são aviso;
  - prova obrigatória, salvo no tipo «Outra»;
  - avisos de pré-aviso de 7 dias e de suspensão acima de 30 dias;
  - só se justificam faltas detectadas com o processamento do mês aberto;
  - cancelar uma justificação devolve a falta a POR_JUSTIFICAR.
- Correcções:
  - o RH regista e justifica ausências. No legado só o colaborador o podia fazer, pelo portal, e quem não tinha utilizador ficava com as faltas por justificar;
  - a sobreposição passa a verificar também as férias;
  - nos tipos «a critério do empregador», a aprovação exige a decisão SIM/NAO sobre a remuneração;
  - ninguém decide a sua própria ausência.
- O domínio de `remunerada` passa a SIM/NAO/EMPREGADOR.

**Fica para depois:** leitura directa do relógio biométrico por URL (o endereço já se configura), e o circuito chefia → RH no portal (RH parte 3).

## ADR-039 — RH parte 2b: férias e subsídio de produtividade

**Férias** (`ServicoFerias`, `js/modules/rh/ferias.js`):
- Mantém do legado:
  - direito por colaborador e ano, 22 dias úteis por omissão;
  - gravar o direito aplica-o a todos os períodos desse ano;
  - saldo = direito − dias marcados, sem contar os cancelados;
  - estados PEDIDO (portal), PLANEADO, APROVADO, GOZADO e CANCELADO;
  - nada se altera enquanto o pedido do portal estiver pendente;
  - só as férias APROVADAS ou GOZADAS contam como ausência na assiduidade.
- Correcções:
  - os dias úteis vêm do calendário da empresa (ADR-038). O legado usava segunda a sexta fixos e ignorava os feriados;
  - a sobreposição com outras férias ou com ausências é recusada (no RH o legado só pedia confirmação);
  - exceder o direito exige `confirmar_excesso`, que corresponde à confirmação do legado;
  - GOZADO só depois de as férias terminarem; PEDIDO só pelo portal;
  - o direito é lido sempre da mesma forma. O legado usava registos diferentes no portal e no RH;
  - um período vindo do portal não se elimina: cancela-se, para não deixar o pedido a apontar para o vazio.
- Nos dados reais, os dias dos 3 períodos confirmados neste ensaio são iguais aos do legado (não há feriados configurados).

**Produtividade** (`ServicoProdutividade`, `js/modules/rh/produtividade.js`):
- Mantém do legado:
  - itens com código único, métrica, preço > 0 (4 casas), rubrica de VENCIMENTO e mínimo/máximo;
  - períodos por mês com janela de medição de até 93 dias, sem sobreposição, sem registos fora da janela, e fechar/reabrir com motivo e o processamento aberto;
  - registos só para quem tem o item no contrato;
  - preço do contrato (se > 0) ou do item, fixado no registo;
  - um item em uso não se elimina, desactiva-se;
  - o lançamento no processamento exige o período FECHADO e o processamento ABERTO, com a opção «substituir».
- Correcções:
  - **o mínimo e o máximo aplicam-se ao total** do colaborador no item e no período. O legado aplicava-os a cada registo, e dividir as quantidades em registos diários contornava o tecto;
  - a quantidade considerada reparte-se pelos registos na proporção da quantidade;
  - um registo por (colaborador, item, data) também na introdução manual;
  - elegibilidade: colaborador ACTIVO com contrato válido na janela;
  - gravar o contrato mantém os itens desactivados (o legado retirava-os) e valida os itens;
  - lançar exige `calcular_folha` e retira os lançamentos de produtividade que deixaram de ter valor (`origem = PRODUTIVIDADE`).
- Nos dados reais, o período de 09/2026 recalculado dá 489 250,00, o mesmo que o fecho do legado e que o valor lançado na folha.
- Tipos corrigidos (tabelas quase sem dados): mínimo e máximo passam de texto a numérico, o preço fica com 4 casas e a data do registo passa a data. O `period_id` do período passa a FK para o processamento salarial.

**Fica para depois:** importação Excel da produtividade (a chave NIF + item + data está pronta no serviço), acumulação, pro rata e transporte de férias, e subsídio de férias. O legado não tinha nenhum destes três.

## ADR-040 — RH parte 3a: estrutura orgânica e portal do colaborador

**Estrutura orgânica** (`ServicoEstruturaOrg`, `js/modules/estrutura/estrutura_dados.js`):
- Mantém do legado:
  - unidades com código único e pai sem ciclos; o responsável é colocado na unidade;
  - postos com unidade, cargo ou título, e vagas (exceder as vagas é só aviso);
  - na afectação, o gestor não pode ser o próprio e o posto tem de ser da unidade;
  - a chefia directa é o gestor explícito ou, subindo na árvore, o primeiro responsável;
  - uma unidade com subunidades ou membros activos não se elimina, nem um posto com ocupantes.
- Correcções:
  - são recusados ciclos na chefia (A chefia B e B chefia A) e no «reporta a» dos postos. No legado, uma contestação podia acabar decidida pelo próprio avaliado;
  - a chefia ignora gestores e responsáveis inactivos e unidades inactivas;
  - **a afectação em massa já não apaga o gestor explícito** (o legado gravava gestor = nulo em todos);
  - um posto indicado sem unidade assume a unidade do posto;
  - eliminar limpa as referências (inactivos, «reporta a»);
  - um cargo usado por postos não se elimina, e o nome do cargo é único;
  - postos e afectações ficam auditados (models auditáveis).
- Nos dados reais: 22 activos na empresa 6 (18 com chefia) e 6 na empresa 18 (5 com chefia), sem ciclos.

**Portal do colaborador** (`ServicoPortalColaborador`, `js/modules/rh/portal_dados.js`):
- Mantém do legado:
  - tipos FÉRIAS, AUSÊNCIA, DOCUMENTO e AGREGADO;
  - circuito CHEFIA → RH nas férias e nas ausências. A chefia é DISPENSADA se não existir ou não tiver utilizador;
  - documentos e agregado vão só ao RH;
  - a recusa exige nota; ninguém decide um pedido seu;
  - só o requerente cancela, e só enquanto o pedido está pendente;
  - no portal, as férias não podem começar no passado nem exceder o saldo.
- Correcções:
  - **uma decisão e todos os seus efeitos numa única transacção.** No legado, decidir uma ausência falhava sempre (tabela fora da transacção Dexie) e era desfeita;
  - a mesma pessoa não aprova as duas etapas (segregação chefia/RH);
  - a passagem chefia → RH não marca a ausência como decidida;
  - a ligação utilizador ↔ colaborador lê-se sempre da base de dados, exige `rh_portal_aprovar` e fica auditada;
  - no agregado, a aprovação é recusada se os dependentes mudaram desde o pedido; os parentescos do legado («Filho(a)») são normalizados;
  - os recibos do portal vêm da fotografia do processamento (o legado recalculava com os dados actuais).

**Documentos** (`ServicoDocumentosRH`):
- Os 6 modelos padrão existem sempre e podem ser personalizados e repostos; a empresa pode ter modelos próprios.
- Há 21 variáveis `{{…}}`; as desconhecidas são recusadas.
- Uma variável sem valor aparece como [Rótulo] e impede a emissão automática; o destinatário é opcional.
- **A numeração `DOC/AAAA/NNNN` é atómica** (`ServicoNumeracao`, a partir do maior número emitido). No legado, dois pedidos em simultâneo recebiam o mesmo número.
- Nos dados reais, a próxima emissão é `DOC/2026/0003`.
- Valores e datas por extenso (`App\Support\Texto\Extenso`): «trezentos mil kwanzas», «2 de Março de 2020».

**Regressão corrigida (partes 2a/3a):**
- As chaves dos mapas de normalização com «_» nunca coincidiam, porque o valor é comparado dobrado: «POR_JUSTIFICAR» passa a «POR JUSTIFICAR».
- Por isso, as 34 faltas por justificar tinham migrado com estado nulo, e o mesmo teria acontecido aos pedidos do portal.
- Corrigido, e `normalizacoes.mjs` passou a falhar se alguma chave não estiver dobrada ou apontar para um valor fora do domínio.

**Fica para depois:** e-mail de notificação dos pedidos (o legado só tinha contadores) e PDF dos documentos emitidos (Fase 5).

## ADR-041 — RH parte 3b: avaliação de desempenho, 360º, contestação, bonificação e avaliação ascendente

**Avaliação de desempenho** (`ServicoAvaliacao`, `js/modules/rh/avaliacao.js`):
- Paridade nas fórmulas:
  - critérios com nota de 1 a 5 e peso (média ponderada);
  - objectivos quantitativos: atingido/meta, ou meta/atingido quando o sentido é MENOR, entre 0 e 200 %;
  - objectivos qualitativos: (nota − 1) × 25 %;
  - pontuação = critérios × (1 − P) + objectivos × P, com P = 30 % por omissão, arredondada a 2 casas;
  - classes: Excelente ≥ 4,5, Muito Bom ≥ 3,5, Bom ≥ 2,5, Suficiente ≥ 1,5, Insuficiente abaixo disso.
- Itens comuns e específicos; na primeira utilização criam-se os 8 critérios padrão.
- Concluir exige todas as notas e resultados, o avaliador e a data. Uma avaliação concluída ou já dada a conhecer não se altera.
- Correcções:
  - uma avaliação por colaborador, ano e período, garantida por índice único (o legado só o verificava na aplicação);
  - ninguém se avalia a si próprio (o legado permitia ao RH);
  - peso dos objectivos vazio passa a 30. No legado `Number('')` dava 0, e os objectivos deixavam de contar sem aviso;
  - a chefia que avalia é a do ciclo do mesmo ano e período (o legado usava o ciclo que estivesse aberto);
  - uma avaliação dada a conhecer, contestada ou com bónus não se elimina (o legado deixava bónus órfãos);
  - um item já usado desactiva-se em vez de ser eliminado.

**Ciclo 360º** (`ServicoAvaliacao360`, `js/modules/rh/aval360_dados.js`):
- Paridade:
  - pesos 50/10/20/20 (chefia, autoavaliação, pares, subordinados), com soma 100 e a chefia com peso;
  - mínimo de anonimato 3 (nunca menos de 2) e até 5 pares;
  - um ciclo por ano e período, e um só ciclo aberto (agora com índice único parcial);
  - ao abrir fotografa a composição a partir da estrutura (ADR-040) e os critérios comuns;
  - respostas anónimas: quem respondeu e o que respondeu ficam em tabelas separadas, sem ligação;
  - grupos abaixo do mínimo juntam-se num grupo «pares e subordinados» ou ficam de fora, com aviso;
  - nota 360 = Σ média × peso / Σ pesos disponíveis, e exige a avaliação da chefia;
  - fases POR_AVALIAR → EM_AVALIACAO → AGUARDA_CONHECIMENTO → PRAZO_CONTESTACAO → (CONTESTADA) → FINAL.
- Contestação:
  - fundamentação com pelo menos 30 caracteres;
  - decide a chefia da chefia, ou o RH se ela não existir;
  - exige o parecer do RH antes da decisão;
  - o avaliado e a chefia avaliadora não decidem;
  - uma decisão ALTERADA recalcula a classificação.
- Correcções:
  - **os resultados anónimos só são mostrados depois do prazo das respostas ou com o ciclo fechado.** Acompanhar ao vivo permitia deduzir a resposta de alguém comparando os resultados antes e depois;
  - a nota 360 é recalculada ao consultar o resultado (o legado só a recalculava ao concluir ou pelo botão).

**Bonificação:**
- Paridade:
  - métodos PERCENTAGEM (base × % × meses), FIXO e BOLSA (distribuída pela nota, com a diferença de arredondamento no maior valor);
  - só entram avaliações na fase FINAL;
  - quem calcula não aprova, e ninguém aprova o seu próprio bónus;
  - o lançamento vai para o processamento do mês configurado, que tem de estar ABERTO.
- Correcções:
  - só entram participantes do ciclo;
  - a configuração do bónus fica bloqueada com o ciclo fechado ou com bónus aprovados ou lançados;
  - lançar recusa uma rubrica já lançada no período para o mesmo colaborador (o legado duplicava);
  - anular o bónus fica auditado; uma linha da folha vinda de um bónus não se remove no ecrã Calcular.

**Avaliação ascendente e acompanhamento:**
- 8 questões de liderança, uma resposta por chefia e por período, anónima.
- O mínimo de anonimato é o do ciclo do período (no legado era 3 fixo), e os resultados só aparecem depois do prazo.
- Os resultados são vistos por período (o agregado anual do legado juntava grupos pequenos) e só pela própria chefia ou pelo RH.
- Reuniões de acompanhamento: registadas pela chefia ou pelo RH, nunca pelo próprio; ficam bloqueadas depois da confirmação do colaborador.

**Tipos corrigidos** (tabelas vazias no backup): critérios, objectivos, componentes, conhecimento e contestação passam a jsonb; pontuações e notas a numérico; `ano` a inteiro; as respostas ascendentes a jsonb; o bónus ganha FKs para a avaliação e para o processamento.

**Não-regressão com dados reais:** o ciclo aberto da empresa 18 (6 participantes, 8 critérios, participantes no formato do legado) calcula as componentes. A única resposta de subordinado existente fica retida, por estar abaixo do mínimo de anonimato.

## ADR-042 — Logística parte 1: motor de stock, armazéns, transferências, inventário e acerto do stock migrado

**Decisões do utilizador (2026-09-30):**
- Stock nas vendas: FT, FR e GR baixam o stock ao emitir; uma FT gerada de uma GR não volta a baixar; a GD repõe; a NC só repõe quando é uma devolução de mercadoria.
- CMV em **inventário permanente**: cada saída lança D 71 / C 26 ao custo médio (implementado na parte 2).
- Stock migrado: os saldos por armazém do legado são a verdade, com um movimento de acerto por diferença; o custo médio inicial é o último custo de recepção.

**O legado não tinha um motor de stock.** Cada ecrã escrevia à mão, sem transacção, em três sítios diferentes: `products.stock_qty`, `warehouse_stock` e `inventory_movements`.
- As recepções e as guias partilhavam a mesma tabela de linhas e apagavam-se umas às outras.
- A saída das guias era gravada sem tipo.
- O «custo médio» caía no preço de venda.
- Não havia transferências.

**Motor de stock** (`ServicoStock`):
- É a única via de alteração do stock.
- Cada movimento regista o sentido (E/S), o valor, o custo médio após o movimento e o documento de origem (`documento_tipo`, `documento_id`). No legado havia só texto livre, lido depois por expressões regulares.
- O custo médio ponderado é recalculado nas entradas; as saídas saem ao custo médio.
- Não há stock negativo (salvo em regularizações).
- **Um armazém com inventário em curso não movimenta**, excepto a própria regularização.
- **Transferências** `TRF AAAA/NNNN`: saída e entrada ao custo médio; o total e o custo médio mantêm-se.
- Ajustes manuais exigem motivo.

**Armazéns e mapas** (`ServicoArmazens`):
- Armazém predefinido explícito (o legado caía no primeiro).
- Eliminar só com o stock a zero.
- Extracto do artigo com saldo corrido em quantidade e valor.
- Valorização ao custo médio real.
- Alerta de ruptura pelo `stock_minimo` do produto (o legado usava ≤ 5 fixo).

**Inventário** (`ServicoInventario`):
- Mantém do legado:
  - uma sessão por armazém de cada vez;
  - fotografia de todos os produtos de stock e contagem cega;
  - revisão com custo e justificação;
  - regularização no diário SQ com o documento INV AAAA/id (sobras: D inventário / C sobras; quebras: D quebras / C inventário).
- Correcções:
  - produtos por contar só passam a zero se isso for confirmado (no legado o aviso nunca aparecia);
  - a regularização usa a data da sessão e valoriza cada linha ao custo médio ou ao custo indicado;
  - sem contas configuradas a aprovação é recusada (o legado mexia no stock sem lançar);
  - a aprovação é feita por outra pessoa, e não pela palavra-passe de administrador;
  - anular deixa a sessão ANULADA com motivo (o legado apagava-a);
  - reabrir estorna o lançamento e anula só o saldo líquido dos ajustes da sessão, o que funciona em reaberturas repetidas.

**Contas da logística** (`ServicoConfigLogistica`): CMV, sobras e quebras. A conta do produto prevalece, e o inventário usa a conta configurada nas Compras. O legado reescrevia prefixos de contas e produzia contas inexistentes.

**Acerto do stock migrado** (`ServicoMigracaoStock`, corre depois da ETL):
- 4 saídas de guia sem tipo foram classificadas como SAÍDA.
- Sentido e valor foram preenchidos em todos os movimentos.
- 4 acertos de saldo inicial, que ficam em Sistema › Validações.
- 4 totais de produto alinhados com a soma dos armazéns.
- Custo médio inicial em 4 produtos; 29 ficaram sem custo conhecido, também em Validações.
- Depois do acerto, os saldos por armazém batem exactamente com os movimentos.

**Atenção na entrada em produção:** um inventário do legado ficou EM_CONTAGEM. No sistema novo, isso congela o respectivo armazém até ser concluído ou anulado.

**Salvaguardas nas ferramentas:**
- O gerador de esquema falha se uma tabela aparecer duas vezes em COLUNAS_NOVAS ou TABELAS_NOVAS. Uma entrada repetida de `produtos` fazia perder colunas sem aviso.
- O catálogo de permissões passou a aceitar tarefas novas do sistema actual (`armazem_transferencia`).

**Fica para a parte 2:** stock e CMV nos documentos de venda, guias de saída do armazém (VENDA, CONSUMO, BACK_TO_BACK) com numeração sem repetições e anulação por estorno, e ligação GR → FT.

## ADR-043 — Logística parte 2: stock e CMV nas vendas, guias de remessa e devolução, guias de consumo

**Stock nas vendas** (`ServicoStockVendas`), aplicando a decisão do utilizador (ADR-042):
- **Saídas ao custo médio:** FT, FR e GR baixam o stock ao emitir.
- **Casos sem nova baixa:**
  - uma FT gerada de uma GR não volta a baixar;
  - uma FT gerada de uma encomenda baixa só o que ainda não saiu por guia.
- **Reposições:**
  - a GD repõe ao custo da GR;
  - a NC repõe ao custo da factura, mas só quando se indica `devolucao_mercadoria`. As NC de correcção de preço não mexem no stock.
- **Movimento e custo gravados na linha:** a saída acontece antes de gravar cada linha, para o custo (`custo_unitario_kz`) e a quantidade movimentada (`quantidade_stock`) ficarem na linha desde a criação. As linhas dos documentos fiscais são imutáveis depois de seladas.
- **Stock negativo permitido nas vendas**, como no legado, para um documento fiscal não ficar à espera do registo das entradas. Os negativos aparecem em Sistema › Validações. As guias de consumo, as transferências e os ajustes continuam a bloquear.
- **Armazém:** o indicado, o da origem ou o predefinido. Uma empresa sem armazéns recebe automaticamente o «Armazém principal».
- **Conversões:** NE → GR e GR → FT/GD, com as quantidades entregue, facturada e devolvida por linha.
  - Uma FT de uma GR marca também a encomenda como facturada. No legado, a encomenda podia voltar a ser facturada.
  - A origem só fica CONCLUIDA quando está totalmente facturada (ou, numa GR, facturada ou devolvida).
- **Anulação de GR/GD:** repõe o stock e as quantidades da origem; se estiver contabilizada, exige estorno primeiro.

**CMV em inventário permanente:**
- As linhas D custo / C inventário (o inverso nas devoluções) entram no lançamento do próprio documento, com as contas do produto ou das contas da logística.
- As GR e GD contabilizam só o CMV, no diário GR.
- O CMV fica uma única vez em cada documento que movimentou stock.
- Isto corrige três defeitos do legado:
  - as facturas não tinham CMV;
  - a guia do armazém e a «GR LOG» espelho podiam lançá-lo as duas;
  - a GD gravada sem cedilha caía no ramo das facturas e lançava proveitos.

**Guias de saída do armazém** (`ServicoGuiasSaida`):
- As saídas para clientes passam a fazer-se pela GR das Vendas. As guias de VENDA e BACK_TO_BACK do legado migram só para consulta.
- A guia de **consumo interno** dá saída ao custo médio e é contabilizada D custo / C inventário no diário GS.
- **Correcções:**
  - numeração `GE AAAA/NNNN` sem repetições, a partir do maior número já emitido (o legado contava as guias e apagava-as);
  - linhas próprias da guia (no legado partilhavam a tabela com as recepções e apagavam-se umas às outras);
  - o movimento tem sempre tipo e sentido;
  - anular deixa a guia ANULADA e repõe o stock (o legado apagava-a); se estiver contabilizada, exige estorno primeiro.

**Fica para depois:**
- MovementOfGoods no SAF-T (GR/GD);
- dados de transporte exigidos pela AGT (matrícula, locais e hora de carga e descarga), que o legado também não tinha;
- o POS e a lavandaria, que passam a usar o mesmo motor de stock e CMV no respectivo módulo.

## ADR-044 — Orçamento parte 1: rubricas, orçamentos, versões, hierarquia e controlo orçado × realizado

**Rubricas** (`ServicoRubricasOrcamentais`, `js/modules/orcamento/orcamento_dados.js`):
- Dois tipos: exploração (PROVEITO/CUSTO) e tesouraria (RECEBIMENTO/PAGAMENTO).
- Ligadas ao plano por conta exacta ou por prefixo, e cada conta pertence a uma só rubrica do mesmo tipo.
- Na tesouraria não se usam 43/45, que são o próprio movimento.
- Controlo de excesso (NENHUM, AVISAR, APROVACAO ou BLOQUEAR) só em custos e pagamentos, e o limite não pode ser inferior ao aviso.
- Rubricas base do PGC Angola: criam-se só as que existem no plano e não se sobrepõem.
- Correcção: uma rubrica usada em previsões ou pedidos de excesso também não se elimina (o legado só verificava as linhas).

**Orçamentos** (`ServicoOrcamentos`):
- Mantém do legado:
  - chave ano|tipo|UN|CC|projecto, válida para todas as versões;
  - método HISTÓRICO: base = realizado ou orçamento aprovado do ano anterior × (1 + crescimento)(1 + inflação), com crescimento distinto para proveitos e custos;
  - método BASE ZERO: cada rubrica com valor exige justificação de pelo menos 10 caracteres;
  - 12 meses por rubrica, e linhas a zero sem notas não se gravam;
  - estados RASCUNHO → SUBMETIDO → APROVADO; o aprovado anterior da mesma chave fica SUBSTITUIDO;
  - devolver com motivo;
  - nova versão só a partir do aprovado, e só uma em preparação de cada vez.
- Hierarquia:
  - top-down reparte o pai pelos filhos em rascunho: IGUAL, pelo REALIZADO anterior, ou MANUAL (percentagens que somam 100);
  - o último filho fica com o resto do arredondamento, para a soma dos filhos igualar o pai;
  - bottom-up cria contributos com responsável; o responsável edita e submete o seu com `orc_contributo`;
  - consolidar soma os contributos no pai.
- Correcções:
  - quem submeteu não aprova (o legado só avisava);
  - **a consolidação usa só a última versão de cada contributo**. O legado somava a v1 aprovada e a v2 submetida, em duplicado;
  - um orçamento com filhos não se elimina (o legado deixava `pai_id` órfão);
  - a chave é verificada dentro de uma transacção com bloqueio.

**Execução** (`ServicoExecucaoOrcamental`), com os resultados a partir do Diário:
- **Exploração:**
  - entram as contas 6/7 e as de outras classes que uma rubrica liste explicitamente;
  - PROVEITO = C − D e CUSTO = D − C;
  - as contas 6/7 sem rubrica aparecem em «sem rubrica».
- **Tesouraria:**
  - em cada documento, o movimento líquido de 43/45 reparte-se pelas contrapartidas na proporção dos valores;
  - as transferências internas anulam-se;
  - calcula o saldo inicial e os saldos de fim de mês.
- Aplica os filtros UN/CC/projecto do orçamento e exclui a classe 9.
- Desvio = real − orçado.
  - É favorável acima do orçado nos proveitos e recebimentos, e abaixo nos custos e pagamentos.
  - Um desvio desfavorável acima de 10 % fica assinalado.
  - O mapa mostra os 5 piores desvios e o orçado inicial (v1) ao lado do corrigido.
- **Correcção:** os lançamentos de apuramento de resultados excluem-se pelo diário AP-*. O legado usava o «período 13/14», mas no backup esse campo também guarda ids de processamentos salariais, e o salário do processamento n.º 13 ficaria de fora.
- Nos dados reais, os 8 orçamentos migrados calculam o controlo em menos de 0,1 s cada.

**Tipos corrigidos** (tabelas quase vazias no backup): nomes e estados das previsões; tipo, variáveis e ajustes dos cenários (jsonb); estado, origem e `autoaprovado` (booleano) dos pedidos de excesso; ids consolidados em jsonb; prazo do contributo como data.

**Fica para a parte 2:**
- controlo orçamental nos documentos (adjudicação, factura directa, pagamento, lançamento manual);
- compromissos por linha de encomenda, libertados pela quantidade facturada ou pelo cancelamento;
- pedidos de excesso com permissão verificada no servidor, e alertas;
- previsões (rolling forecast) e cenários.

## ADR-045 — Orçamento parte 2: controlo orçamental nos documentos, compromissos e pedidos de excesso

**Controlo** (`ServicoControloOrcamental`, `OrcControlo.validar` e `OrcPlano.verificar` do legado):
- **Onde corre:** nos mesmos quatro pontos do legado, sempre dentro da transacção do documento:
  - adjudicação (encomenda);
  - factura de fornecedor directa;
  - pagamento da tesouraria (débitos consomem, créditos abatem);
  - lançamento manual.
- **Mantém do legado:**
  - só entram rubricas activas de CUSTO ou PAGAMENTO com controlo;
  - cada linha é verificada contra todos os orçamentos APROVADOS do tipo e do ano cujas dimensões a abrangem;
  - % = (realizado + compromissos + documento) / orçado, acumulado até ao mês ou do ano inteiro;
  - AVISO a partir do limiar de aviso; acima do limite, APROVACAO ou BLOQUEIO conforme o modo da rubrica.
- **Pedidos de excesso:**
  - circuito PENDENTE → APROVADO/REJEITADO → UTILIZADO;
  - um pedido aprovado para aquele documento, de valor suficiente, deixa-o gravar;
  - quem tem `orc_aprovar_excesso` pode «aprovar no acto», indicando motivo (fica marcado `autoaprovado`);
  - `POST /verificar` simula o controlo sem registar nada.
- **Registo:** todas as ocorrências vão para o registo de alertas, **incluindo as tentativas bloqueadas**. O alerta é gravado pelo manipulador de excepções, depois de desfeita a transacção do documento (`ErroOrcamental`).

**Compromissos**, calculados a partir dos documentos reais:
- encomendas pela parte **ainda por facturar** de cada linha;
- facturas de fornecedor por contabilizar;
- pagamentos da tesouraria pendentes.

**Correcções face ao legado:**
- a primeira factura parcial já não liberta a encomenda inteira;
- as encomendas anuladas deixam de contar (no legado não havia anulação e ficavam comprometidas para sempre);
- uma rubrica sem dotação num orçamento aprovado dá o aviso «sem dotação» e não bloqueia (o legado dividia por zero e bloqueava qualquer gasto);
- falha fechada: um erro no controlo trava o documento (o legado deixava gravar);
- a permissão de aprovar excessos é verificada no servidor, e ninguém decide o seu próprio pedido;
- o pedido aprovado só passa a UTILIZADO se o documento for mesmo gravado (o legado consumia-o antes);
- no modo AVISAR, o monitor nunca mostra «excedido»;
- a encomenda leva o projecto do pedido, pelo que os orçamentos de projecto também são controlados.

**Monitor:** consumo (realizado + compromissos), disponível e estado por rubrica, para todos os orçamentos aprovados do ano.

**Fica para a parte 3:** previsões (rolling forecast) e cenários what-if, e a análise de desvios (temporal, pontual e estrutural).

## ADR-046 — Orçamento parte 3: previsões deslizantes, cenários what-if e análise de desvios

Implementado em `ServicoPlaneamentoOrcamental`, a partir de `orcamento_planeamento.js:180-420` e `576-623`.

**Previsões a 12 meses:**
- Há uma série por dimensões (tipo, UN, CC, projecto), com revisões.
- A partir do mês de referência (o último mês com real fechado), a previsão é semeada por um de quatro métodos:
  - ORCAMENTO: orçamento aprovado do mês;
  - TENDENCIA: média dos 3 últimos meses reais;
  - ANO_ANTERIOR: mês homólogo mais o crescimento;
  - BRANCO: sem valores iniciais.
- Uma nova revisão copia os meses em comum com a anterior e semeia só os meses novos.
- Uma revisão só é criada depois de a anterior ser publicada. Uma revisão publicada não se altera nem se elimina.
- O resumo apresenta:
  - o real dos 3 últimos meses;
  - o total previsto a 12 meses;
  - a estimativa de fecho do ano (real até ao mês de referência + previsão do resto);
  - o orçado.

**Cenários:**
- Variáveis: volume, preço de venda, matérias, pessoal, outros custos e câmbio.
- Cada rubrica tem um indutor e uma parte variável, deduzidos do PGC ou definidos na rubrica, e uma exposição cambial.
- É possível um ajuste por rubrica.
- Há três padrões: Otimista, Realista e Pessimista.
- O resultado compara o orçamento base com o cenário. Um cenário pode gerar uma nova versão, em rascunho, do orçamento aprovado.

**Análise de desvios** (só exploração):
- Desvio mês a mês e acumulado.
- Classificação:
  - SEM_DESVIO;
  - TEMPORAL: os desvios compensam-se;
  - PONTUAL: 2 meses concentram 70 % ou mais;
  - ESTRUTURAL: 70 % ou mais com o mesmo sinal;
  - MISTO.
- Contas face ao mesmo período do ano anterior.
- Os 10 maiores movimentos.
- Estimativa de fecho pela última previsão publicada.

**Correcção:** as permissões `orc_previsoes_edit` e `orc_cenarios_edit` são verificadas no servidor. No legado não protegiam nenhuma função, só o ecrã.

## ADR-047 — POS parte 1: terminais, sessões, vendas, integração e desvios de caixa

Implementado em `app/Services/POS` a partir de `pos_gestao.js`, `pos_prestacao.js:97-489` e `ui_sales.js`. O legado tinha duas camadas: o POS antigo em localStorage e o actual em base de dados, que o substituía. Só o actual foi portado. As vendas antigas (`POS_SESS_<epoch>`) mantêm-se em `sessao_pos_legado_codigo`.

**Terminais e meios de pagamento** (`ServicoTerminaisPOS`):
- Os meios de pagamento são numerário, TPA e transferência. Cada um tem conta transitória e conta de liquidação.
- Regras das contas, validadas no servidor:
  - todas têm de ser contas de movimento;
  - numerário liquida numa conta 45; TPA e transferência numa conta 43;
  - a conta transitória é diferente da de liquidação e não se repete entre meios;
  - um TPA com comissão tem de ter conta de comissão.
- Copiar meios de outro terminal:
  - SUBSTITUIR reaproveita os ids por tipo;
  - ACRESCENTAR junta os meios com ids novos;
  - de outra empresa, só com acesso a essa empresa.
- O código do terminal fica bloqueado depois da primeira sessão.
- Um terminal com sessão aberta não se desactiva; um com movimento não se elimina.

**Sessões** (`ServicoSessoesPOS`):
- Uma sessão aberta por terminal, garantida por índice único parcial e lock.
- Códigos `T01-AAAA-NNNN` e Z `Z-T01-AAAA-NNNN` por `ServicoNumeracao`. A numeração continua a do legado.
- Relatório X.
- Fecho Z:
  - contagem por notas e moedas ou pelo total;
  - talão de cada TPA com movimento;
  - justificação obrigatória acima da tolerância, ou quando o talão difere do sistema.

**Vendas** (`ServicoVendasPOS` → `ServicoDocumentosVenda::emitir` com `pos`):
- Emite uma factura-recibo na série do terminal (`FR T01AAAA/n`), com AGT, hash, stock e CMV, numa única transacção.
- O preço inclui IVA. O desconto global é uma percentagem sobre o total com IVA (`CalculadoraDocumento::calcularComIva`, a mesma regra do documento AGT do legado).
- Pagamentos:
  - pode haver vários meios na mesma venda;
  - o troco só se dá em numerário e o valor gravado é líquido do troco;
  - TPA e transferências não podem exceder o total;
  - a transferência exige o número do comprovativo.
- Descontos e preços alterados exigem `pos_desconto`.
- Cliente: o indicado, senão o padrão do terminal, senão «Consumidor Final».
- O stock é verificado no armazém do terminal.
- A venda POS não se contabiliza nem se descontabiliza sozinha.

**Integração da sessão** (`ServicoContabilizacaoPOS`):
- Um lançamento por data, no diário `GEPOS` (configurável), com `tipo_origem = POS` e `sessao_pos_id`:
  - D contas transitórias por meio. Cada transferência é uma linha com o cliente e o documento.
  - C proveitos e IVA com os valores do documento.
  - CMV: D 71 / C 26.
- Descontabilizar é por estorno. Fica bloqueado se houver prestação de contas ou uma deliberação manual com lançamento.

**Desvios de caixa:**
- Desvio zero: SEM_DESVIO.
- Dentro da tolerância e diferente de zero: fica DELIBERADO automaticamente (sobra ou quebra) e é lançado com a integração. Numa sessão sem vendas, é lançado no fecho.
- Acima da tolerância: fica PENDENTE e é deliberado com uma de quatro decisões:

  | Decisão | Lançamento |
  |---|---|
  | SOBRA_PROVEITO | D transitória / C sobras (6) |
  | FALTA_CUSTO | D quebras (7) / C transitória |
  | FALTA_OPERADOR | D operador (3) / C transitória |
  | SEM_EFEITO | sem lançamento |

- Todas as decisões, excepto SEM_EFEITO, exigem a sessão integrada.
- Quem abriu a sessão não delibera o próprio desvio.
- A anulação é por estorno e fica bloqueada depois de o numerário ser prestado.

**Correcções face ao legado:**
- Gravar o terminal fazia recuar os contadores. As sessões, os Z e os documentos da lavandaria podiam repetir números.
- As vendas POS não lançavam CMV. A saída de stock era valorizada ao preço de venda, pelo stock global lido ao abrir o ecrã, e cortada a zero.
- Os totais do cabeçalho não somavam quando havia desconto.
- Nada corria numa transacção, e a regra de uma sessão aberta por terminal podia ser violada por duas aberturas simultâneas.
- O operador gravado era sempre quem abriu a sessão.
- O cliente padrão do terminal era ignorado.
- O desvio dentro da tolerância nunca era contabilizado.
- Descontabilizar e anular a deliberação apagavam linhas do diário.
- A segregação de funções só gerava um aviso.

**Dados migrados:**
- Os JSON do legado (meios, totais do Z, talões, deliberações e pagamentos) passam a chaves portuguesas no fim da ETL (`ServicoMigracaoPOS`).
- Tipos corrigidos: `desvio` e `tolerancia_desvio` passam a numérico; `documento_comissao_id` passa a FK para documentos de tesouraria.
- Novos valores de domínio: `RESTAURANTE` no tipo de terminal e `MISTO` no meio de pagamento das vendas.
- O «Consumidor Final» do legado não tem conta. A venda POS não a exige, porque lança nas transitórias.

**Verificação nos dados reais (empresa 18, numa transacção desfeita no fim):**
- Os totais das 2 sessões fechadas recalculam exactamente.
- As 2 sessões com prestação de contas ficam bloqueadas para descontabilizar.
- Venda, Z e integração numa sessão aberta dão um lançamento equilibrado com CMV (GEPOS2026000004).

**Gerador:** um índice único parcial por estado já não substitui o índice da FK.

**Próximas partes:**
- Prestação de contas: numerário na folha de caixa, TPA e transferências na tesouraria, comissões.
- Relatórios.
- Hotelaria, lavandaria e POS armazém.

## ADR-048 — POS parte 2: prestação de contas e relatórios

Implementado em `ServicoPrestacaoContasPOS` e `ServicoRelatoriosPOS`, a partir de `pos_prestacao.js:494-797`, `pos_gestao.js:1093-1133` e `relatorios_gestao.js:531-560`.

**Prestação de contas.** Serve para saldar as contas transitórias depois de a sessão estar integrada. Há um item por meio de pagamento, ou por transferência:
- `NUM:<meio>`: movimento REC na folha de caixa ABERTA da conta de liquidação (D caixa / C transitória).
  - Valor = numerário do sistema + desvio, sempre que a deliberação tem efeito (automática ou manual).
  - Se o valor for negativo, gera-se um movimento PAG.
  - Uma sessão sem vendas em numerário mas com desvio presta só o desvio.
- `TPA:<meio>`: RECEBIMENTO pendente no banco (conta 43, em Kz), que credita a transitória pelo valor do sistema.
  - A comissão é sugerida como pct × talão e pode ser editada.
  - Se for deduzida, é uma linha D dentro do recebimento. Se não for, é um PAGAMENTO separado (`documento_comissao_id`).
- `TRF:<venda>:<meio>`: um RECEBIMENTO por comprovativo, com o cliente e o número da venda.
- Contas: a transitória vem do instantâneo da sessão. A de liquidação e a da comissão vêm do meio actual do terminal, mesmo que esteja inactivo.
- Bloqueios: sessão por integrar; desvio por deliberar; desvio deliberado ainda sem lançamento.
- Estado da sessão: PENDENTE, PARCIAL ou LIQUIDADA. É recalculado ao prestar, ao anular, ao integrar e ao deliberar um desvio. Uma sessão sem nada a regularizar fica LIQUIDADA.
- Segregação no servidor: quem operou a sessão não presta contas dela (403 SEGREGACAO_FUNCOES). O legado só avisava.
- Anular nunca apaga a liquidação: fica ANULADO, com `cancelado_em` e `cancelado_por`.
  - Numerário: retira o movimento de uma folha ainda aberta e por contabilizar.
  - Documentos pendentes: são anulados.
  - Documento integrado ou folha contabilizada: dá erro.
- A tesouraria recusa remover ou anular um movimento de caixa, ou alterar ou anular um documento de tesouraria, ligado a uma liquidação registada (MOVIMENTO_POS / DOCUMENTO_POS). No legado apagavam-se sem verificar e a liquidação ficava órfã.
- Quando o talão do TPA difere do sistema, liquida-se pelo sistema. A diferença fica visível no item, nos relatórios e na validação `pos_tpa_talao_difere`.

**Relatórios:**
- KPIs, totais por meio, lista de Z, e vendas por produto, terminal e operador.
- Filtros por período, terminal e operador. O legado não tinha filtros.
- Bloco POS do relatório de gestão «POS e Serviços».

**Validações:** `pos_sessoes_por_prestar`, `pos_liquidacoes_orfas`, `pos_tpa_talao_difere`.

**Esquema:** o código do meio de pagamento na liquidação passa a 40 caracteres (os ids gerados têm 11), a chave do item a 60 e a referência a 100. A comissão deduzida passa a booleano.

**Dados reais (empresa 18):** a prestação das 2 sessões fechadas reproduz as 6 liquidações do legado (sessão 3: 280 000 = 290 000 − 10 000 da falta deliberada). As transitórias 487, 488 e 489 ficam com saldo 0,00.

## ADR-049 — POS parte 3a: lavandaria e alfaiataria

Implementado em `app/Services/POS/Lavandaria` e `ServicoFontesLavandariaPOS`, a partir de `js/lavandaria.js`.

**Ordens de serviço:**
- Número `OS/<terminal>/<ano>/<n>` e etiquetas geradas por `ServicoNumeracao`, a partir dos contadores do legado. No legado os contadores podiam recuar ou duplicar.
- O estado da ordem deriva dos estados das linhas: ORCAMENTO, RECEBIDA, EM_EXECUCAO, PRONTA, ENTREGA_PARCIAL, ENTREGUE ou ANULADA.
- Linhas:
  - o estado da peça à entrada é obrigatório;
  - o preço vem da tabela peça × serviço;
  - urgência, recolha, entrega e armazenagem são taxas com a conta e o IVA das definições.
- Orçamentos aprovados ou recusados um a um ou em massa. O valor aprovado é o facturável pela regra AGT.
- Anular exige `lav_anular`, também pela via da alteração de estado. No legado bastava `lav_ordens`.
- Materiais de alfaiataria: guia de consumo ao custo médio, com o CMV lançado no momento. Sem stock, a operação é recusada; o legado cortava o stock a zero.

**Dinheiro** (numa transacção, com a ordem e a sessão bloqueadas):
- Recebido = total e sem saldos anteriores: factura-recibo POS.
- Nos outros casos: factura em conta corrente e recibo `RC-LAV/<terminal>/<ano>/<n>` (ADIANTAMENTO ou PAGAMENTO).
- A factura em conta corrente sai também na série do terminal e com preço com IVA: `ServicoDocumentosVenda::emitir` passou a aceitar FT em modo POS.
- Consumidor Final: adiantamento mínimo, e o saldo é pago na entrega.
- Armazenagem com o IVA do produto da taxa. O legado usava 14 % fixo.
- Recebimentos aplicados às facturas mais antigas primeiro.
- Um recibo só se anula com a sessão aberta. A factura-recibo corrige-se com nota de crédito.

**Sessão POS:**
- Os recibos entram nos totais por meio, no numerário e nas transferências, mas não em `total_vendas`. O legado somava-os às vendas no Z.
- Integração: FT — D cliente / C proveitos + IVA; recibos — D transitória / C cliente.
- Sem contas fixas (o legado usava '31.1', '34.5.3' e '62'). Um desequilíbrio é erro.

**Reclamações:** AGUARDA_COMPROVATIVO → COMPROVADO → APROVADA ou RECUSADA → PAGA. Quem decide uma reclamação não a paga (segregação aplicada por reclamação). O pagamento é um PAGAMENTO de tesouraria (D conta das indemnizações).

**Migração:**
- `ServicoMigracaoLavandaria::normalizar()` traduz os JSON e calcula o valor das linhas.
- Acerta o valor líquido das linhas das facturas de lavandaria e das vendas POS do legado (preço com IVA e `total_linha` vazio). Sem este acerto, uma reintegração lançaria o bruto todo em proveitos e o IVA a zero. Os 28 documentos conferem com o cabeçalho.
- Recalcula o valor pago das facturas.

**Esquema:** o estado da peça à entrada estava traduzido como `estado_lancamento` e tipado como data; passa a `estado_entrada` em texto. Outros tipos corrigidos:
- número da encomenda, `pago_por` e nota da decisão;
- lançamentos do pagamento em jsonb;
- factor de prazo da urgência;
- tecido e cor da peça.

**Validações:** `lavandaria_servicos_sem_conta_iva`, `lavandaria_servicos_conta_nao_62`, `lavandaria_clientes_sem_conta`. Na empresa 18, a primeira e a terceira disparam: falta configurar a conta de IVA liquidado e a conta de clientes por omissão.

**Fica para depois:** os recibos RC-LAV nos Payments do SAF-T, as impressões e a importação de Excel.

## ADR-050 — POS parte 3b: hotelaria e POS de armazém

Implementado em `app/Services/POS/Hotelaria`, `ServicoPOSArmazem` e `ServicoGuiasSaida`, a partir de `hotelaria.js` e `ui_pos_armazem.js`.

**Hotelaria:**
- Os quartos são produtos com `e_quarto`, `preco_por_hora`, `preco_por_dia` e `horas_minimas`.
- Regras do terminal: entrada às 14:00, saída às 12:00, tolerância de 60 min e bloqueio da venda à hora entre as 21:00 e as 08:00, por omissão.
- Check-in:
  - exige a sessão aberta de um terminal HOTELARIA;
  - modo DIA, com um número inteiro de diárias, ou HORA, com o mínimo de horas do quarto e fora da janela bloqueada;
  - um só check-in aberto por quarto, garantido por lock e por índice único parcial.
- Consumos gravados na estadia; o stock sai no check-out. A anulação só é possível sem consumos e com motivo.
- Check-out numa única transacção:
  - saída tardia: RECALCULAR ou MANTER, com decisão obrigatória;
  - desconto em %;
  - uma factura-recibo POS por quarto, ou factura única;
  - pagamentos rateados pelas facturas, com o cêntimo no maior e o troco na última;
  - facturas ligadas em `vendas_estadias_hotel`.
- Preço diferente do do quarto, preço de consumo alterado e desconto exigem `pos_desconto`. No legado não estavam protegidos.
- Arredondamento: o total segue a regra AGT do documento fiscal (`calcularComIva`), a mesma que o legado usava no documento AGT. Por isso um check-out de 40 500 com desconto pode dar 40 499,99; o ecrã do hotel do legado mostrava 40 500.

**POS de armazém:**
- Venda ao balcão: é só uma guia de saída VENDA_BALCAO, sem factura nem pagamento, como no legado.
  - Numeração `GE POS AAAA/NNNN`, a continuar a do legado (a próxima é 0009).
  - Custo médio, sem stock negativo.
  - CMV no diário GS, com número de lançamento.
  - Se faltarem contas, a guia fica por contabilizar, com aviso e com a validação `pos_armazem_vendas_por_contabilizar`.
- Picking de encomendas: é a conversão NE → GR já existente, com lock e com exigência de stock no armazém escolhido. O estado deriva das quantidades entregues. «EM PICKING» não se grava; no legado ficava para sempre.
- Não portados:
  - o acerto de stock, porque o total é sempre a soma dos armazéns (ADR-042);
  - as senhas de atendimento, que eram só memória do navegador.

**Correcções nas Vendas encontradas durante a integração:**
- Na conversão NE → GR, a quantidade entregue da encomenda era somada duas vezes (em `movimentarLinha` e em `converter`). Agora `movimentarLinha` só a actualiza nas FT/FR directas.
- A conta do cliente só é exigida na FT e na NC, e só se não houver conta de clientes por omissão. FR, guias, encomendas e orçamentos não lançam na conta do cliente, e o legado aceitava clientes sem conta. Isto desbloqueia a expedição das 4 encomendas reais da empresa 18.

**Migração:** `ServicoMigracaoHotelaria::normalizar()` traduz os consumos e o histórico das estadias. A estadia com consumos recalcula o total da factura migrada (65 000).

**Validação:** `hotel_estadias_saida_atrasada`.

**Organização do trabalho (POS partes 2 e 3):** foram desenvolvidas em paralelo, cada uma com os seus ficheiros de rotas (`routes/api/pos_*.php`) e a sua base de testes. A integração, o esquema, a ETL e a suite completa foram feitos no fim: 165 testes.

## ADR-051 — Activos: cadastro, amortizações, abates e manutenções

Implementado em `app/Services/Ativos`, a partir de `ui_assets.js` e `fluxo_imobilizado.js`. A regra de cálculo está isolada em `CalculadoraAmortizacoes`.

**Cálculo (paridade):**
- Quotas constantes mensais, sem pro rata: o mês de aquisição conta inteiro.
- Base = aquisição − valor residual.
- Quota = quota fixa, ou base ÷ vida útil (meses), ao cêntimo com half-up exacto (ADR-022).
- Não há quota antes da aquisição, nos anos até ao ano da amortização inicial, depois da vida útil, nem com a base esgotada.
- Os rascunhos, calculados ou manuais, mantêm o valor ao recalcular.

**Integração:**
- Diário AM, documento `AM-MM-AAAA`, datado do último dia do mês.
- D gasto 73 / C amortização acumulada 18, agrupado por conta, unidade de negócio e centro de custo.
- Reabrir um período é o estorno dos lançamentos do período, incluindo os migrados (ADR-016).

**Correcções face ao legado:**
- A integração individual gerava D e C com números de lançamento diferentes.
- O cálculo de vários meses podia exceder a base, porque os rascunhos da mesma execução não contavam.
- As contas 73.1/18.1 do legado não existiam; agora a operação é recusada.
- Editar uma quota integrada mexia no diário; agora é preciso reabrir o período.
- O último mês da vida útil absorve o resto por amortizar.
- Um activo sem vida útil amortiza pela taxa da categoria; no legado nunca amortizava.
- Um bem totalmente amortizado já não aparece como pendente.
- Abates e vendas passam a ser contabilizados: D 18 / C 11-12 / D terceiro, com C 6 (mais-valia) ou D 7 (menos-valia). O legado só mudava o estado. A anulação é por estorno.
- A inventariação a partir de linhas 11/12 fica limitada ao valor da linha.
- Eliminações bloqueadas pelas FKs, edição em massa sujeita ao bloqueio da ficha e código AST-NNN pela numeração da empresa.

**Dados reais:**
- Das 1 448 quotas migradas, 1 435 reproduzem-se exactamente.
- 9 diferem 1 cêntimo: meio cêntimo exacto, arredondado para baixo em vírgula flutuante no legado.
- 4 diferem no último mês de vida com quota fixa: o legado deixou 47 a 163 Kz por amortizar.
- Os registos migrados não são alterados.

**Esquema e validações:**
- Único (empresa, activo, período) nas amortizações. `acumulado_fim_ano` passa a inteiro (é um ano). Domínios novos: estado do activo, tipo de abate e tipo de manutenção.
- Validações: `ativos_vida_esgotada_por_amortizar` (5 activos), `ativos_sem_categoria_ou_contas` (1), `amortizacoes_integradas_sem_lancamento`.

**Fica para depois:**
- A verificação `ServicoAmortizacoes::porIntegrarNoAno` será chamada pelo fecho do exercício quando este for portado. No legado a verificação nunca detectava nada.
- `pedidos_manutencao_equipamentos` pertence à Manutenção de dados (ADR-021).

## ADR-052 — Projectos: ficha, WBS, equipa, organigrama, autos de medição e razão analítico

Implementado em `app/Services/Projetos`, a partir de `ui_projects.js`, `projectos_dashboard.js`, `projectos_organigrama.js` e `fluxo_projectos.js`.

**Paridade:**
- Um projecto INTERNO exige unidade de negócio e centro de custo; um EXTERNO exige cliente e encomenda. Estados: PREPARACAO, ACTIVO, ENCERRADO e CANCELADO.
- Execução da tarefa = média das subtarefas. A dos marcos e a do projecto calculam-se pelas tarefas principais.
- Kanban configurável.
- Equipa: interno, terceiro ou texto livre, de 1 a 8 h/dia.
- Organigrama com vagas.
- Revisão mensal (autos de medição):
  - mão de obra = horas/8 × vencimentos do mês, deduzindo o já imputado;
  - subempreitada = (% actual − maior % medida) × valor adjudicado;
  - facturação do auto = venda × execução − já facturado.

**Razão analítico (uma só regra em todos os ecrãs):**
- Soma o razão, as FT e FR do projecto menos as NC, as linhas de facturas de fornecedor e os autos sem documento.
- Compromissos = encomendas pela parte por facturar (regra do ADR-045).

**Correcções face ao legado:**
- Fim da dupla contagem das subempreitadas no resumo, no fluxo e no organigrama, e das linhas de pedido e de encomenda no extracto.
- A linha do auto grava o terceiro certo; o legado gravava o id do membro (8 linhas corrigidas na migração).
- O mesmo auto não se factura duas vezes.
- O custo por contrato deixou de ser sempre 0.
- A mão de obra fica datada no mês da revisão.
- Só se registam horas de colaboradores internos da equipa.
- Encerrar o projecto exige `proj_estado`, também pela edição, e um projecto encerrado não aceita imputações.
- As facturas são emitidas por Compras e por Vendas: número único, artigo, IVA do artigo e série AGT.

**Integração:**
- A contabilização do processamento salarial imputa as folhas de horas do mês ao razão analítico; a descontabilização retira-as.
- Continua pendente uma decisão: a mão de obra pode entrar pelo auto e pela folha de horas, como no legado; o auto deduz o que já foi imputado no mês.
- A requisição de material e a factura do auto ainda não gravam a tarefa na linha de compra (há um contorno no serviço de Projectos). Fica como gancho em Compras.

**Dados reais:**
- O extracto confere com o legado nos projectos P6, P8, P19 e P21.
- No P7 o custo fica 500 000 Kz abaixo: o legado contava uma linha de encomenda já facturada, por colisão de ids.

**Esquema e validações:**
- Domínios: BLOQUEADA nas tarefas; PROCESSAMENTO_SALARIAL e FATURA_RECIBO na origem do razão. Tamanhos da origem e da área. Únicos nas configurações do projecto.
- Validações: `projetos_horas_fora_da_equipa`, `projetos_autos_sem_factura`, `projetos_tarefas_datas_invertidas`.

## ADR-053 — Acréscimos e diferimentos: repartição, proposta mensal, contabilização e regularização

Implementado em `app/Services/Acrescimos`, a partir de `js/modules/acrescimos/ad_dados.js`.

**Registos:**
- Tipos ACRESCIMO ou DIFERIMENTO; natureza CUSTO (conta 7) ou PROVEITO (conta 6); conta de balanço 37.
- O diferimento exige a data do documento. O acréscimo tem data limite: a indicada, ou o fim do período + prazo (60 dias por omissão).
- Com lançamentos, só se alteram as notas e a data limite.

**Repartição:** por MESES (cada mês civil tocado vale 1) ou por DIAS. O arredondamento é acumulado e a última quota absorve a diferença.

**Proposta mensal:**
- Junta as linhas por contabilizar até ao mês, incluindo as atrasadas: INICIAL, RECONHECIMENTO, REGULARIZACAO/ANULACAO e TERMINO.
- Um lançamento por linha, com o documento `AD<id>-<AAAAMM>-<TIP>` e `tipo_origem` ACRESCIMOS.
- Cada linha numa transacção, com lock e verificação de duplicado; no legado podia duplicar em simultâneo.

**Descontabilizar = estorno (ADR-016)**, sem buracos. Um registo que já teve lançamentos não se elimina; o legado apagava.

**Recolha:** a partir das facturas de fornecedor e de cliente, do Diário e da tesouraria. Ficam de fora os documentos anulados e os lançamentos estornados.

**Dados reais:**
- Os 8 períodos migrados (empresas 10 e 18) são reproduzidos sem diferenças.
- A conta 3743 da empresa 10 reconcilia: módulo = Diário = 2 859 153,85.

**Esquema e validações:**
- Único (item, tipo, período) nos períodos contabilizados; regularização em jsonb.
- Validações: `ad_periodos_sem_lancamento`, `ad_acrescimos_sem_documento`.

## ADR-054 — CRM: funis, oportunidades, actividades, clientes e ligação a Vendas

Implementado em `app/Services/CRM`, a partir de `js/modules/crm/crm_dados.js`, `crm_ui.js` e `crm_ui_gestao.js`.

**Funis:**
- Etapas ABERTA, GANHA e PERDIDA, com exactamente uma ganha e uma perdida. Cada etapa tem probabilidade, dias de estagnação e tarefas automáticas.
- Sequências de email por etapa.
- A primeira utilização cria, com lock, o funil «Vendas» e 3 modelos.

**Oportunidades:**
- Valor pelas linhas; probabilidade da etapa por omissão; valor ponderado.
- Mudar de etapa grava o histórico e cria as tarefas e os passos das sequências. Ao fechar, as tarefas automáticas pendentes são canceladas. A perda exige motivo.
- Saúde da oportunidade, previsão e indicadores.

**Contas:**
- Prospect ou cliente, com uma conta CRM por terceiro (índice único).
- A passagem a cliente é feita pelo `ServicoTerceiros`, com uma conta 31 de movimento.
- Ficha 360º, com as facturas em atraso lidas de `valor_pendente`.

**Vendas:**
- `POST /api/vendas/documentos` aceita `oportunidade_crm_id` e liga o documento na mesma transacção: mesmo cliente, não anulado, não ligado a outra oportunidade.
- FT, FR e NE marcam a oportunidade como ganha.
- Também é possível ligar depois, com `POST crm/oportunidades/{id}/documentos`.

**Emails:** não há envio SMTP. O `CanalEmailCRM` por omissão devolve uma ligação mailto: e regista a actividade. Um envio real é uma implementação registada no contentor.

**Migração:** `ServicoMigracaoCRM::normalizar()` traduz as chaves dos JSON (linhas, histórico, documentos ligados). É idempotente.

**Esquema e validações:**
- Motivos de perda, origens e passos em jsonb; resultado em texto; origens mapeadas a códigos.
- Validações: `crm_oportunidades_etapa_inexistente`, `crm_vendas_cliente_divergente`.

**Organização do trabalho (ADR-051 a 054):** três agentes em paralelo, com as mesmas regras das partes 2-3 do POS. O coordenador aplicou o esquema, os ganchos (salários → projectos, vendas → CRM), a ETL e as validações. Suite: 194 testes.

## ADR-055 — Contabilidade parte 2: demonstrações financeiras, Relatório e Contas, tabelas auxiliares e importação

**Contexto.** O Balanço, a DR e o Fluxo de Caixa do legado (`ui_reports.js:862-1472`) e o Relatório e Contas (`relatorio_contas.js`) calculam-se pelas notas das linhas, com comparativo e saldos históricos. Havia duas versões do motor, com diferenças entre si.

**Decisão — um só motor** (`ServicoDemonstracoesFinanceiras`), igual ao de `relatorio_contas.js:88-209`:
- Agregado em SQL por nota.
- Excluem-se a classe 9 e o apuramento (períodos 13 e 14) de cada exercício.
- As linhas sem nota são reportadas («Movimentos por mapear»).
- O resultado líquido do Balanço é o mesmo da DR.

**Correcções face ao legado:**
- Notas 16 e 20 ignoradas e nota 6 escondida.
- Ajuste «SPAZIO», que duplicava o resultado anterior.
- Apuramento do ano anterior incluído no comparativo.
- Notas fora da estrutura descartadas em silêncio.
- Fluxo de caixa com |valor| linha a linha, pelo que um estorno contava duas vezes. Agora soma-se por nota com sinal e há controlo pela variação real da classe 4.

**Mapas:**
- Balancete com as opções do legado; os totais deixam de somar as contas totalizadoras.
- Extracto com as contrapartidas de todo o lançamento.
- Evolução e IVA.
- Reconciliação AGT: o estado DIVERGENTE passa a ser atribuído.
- Compensações com código `MATCH-AAAAMMDD-nnnn`. A regularização passa pelo `ServicoLancamentos` e reverter marca a compensação como ANULADA.

**Relatório e Contas:**
- Números, indicadores (ROE, ROA, ROS), notas e Nota 4 por categoria.
- Estados RASCUNHO → APROVADO → reabrir. Só se aprova com o exercício encerrado, e a fotografia fica nas colunas `fotografia`, `concluido_em` e `concluido_por`.

**Tabelas e importação:**
- Tabelas auxiliares com verificação de utilizações e com cópia entre empresas só com acesso a ambas.
- Reciclagem por diário + chave (ADR-025); restaurar cria um lançamento novo.
- Importação de lançamentos e de saldos históricos validada por inteiro e gravada numa transacção.

**Verificação.** Nos dados reais, o Balanço e a DR dão o mesmo que as fórmulas do legado, ao cêntimo, em 13 empresas × 2 anos. As diferenças do Balanço explicam-se pelos desequilíbrios do diário (ADR-025), pela classe 9 e pelas linhas sem nota.

**Validações:** `linhas_sem_nota_demonstracao`, `notas_demonstracao_fora_da_estrutura`, `notas_demonstracao_codigo_repetido` (12 empresas têm a nota «15» duplicada, o que impede um índice único).

**Unidades de negócio:** ficou uma só implementação, a da Administração (ADR-058), em `/api/sistema/unidades-negocio`.

## ADR-056 — Encerramento do exercício e rotinas contabilísticas

Implementado em `ServicoEncerramento`, `ServicoRotinasContabeis` e `ServicoRotinasContabeisSelo`, a partir de `ui_closing.js` e `ui_rotinas.js`.

**Encerramento:**
- O cadeado é a chave `closed_year_<empresa>_<ano>` em `configuracoes_sistema`, como no legado; a chave passa a ser única. Não há lançamento de abertura, porque o legado não o tinha.
- Cinco passos de apuramento (agrupadora .9 → classe 8 → agregadoras → resultados → 889): um lançamento por passo, a 31-12, com `periodo_id = 13`.
- Repetir um passo ou cancelar o apuramento é estorno (ADR-016); o legado apagava. O `ServicoLancamentos` passou a aceitar período e reconciliação, e o estorno herda o período 13 do original.
- As contas do apuramento têm de existir e ser de movimento. As que faltam podem ser criadas a pedido; as totalizadoras são sempre recusadas. No legado gravava-se, por exemplo, na 769, que é totalizadora.
- Validações:
  - sequência dos exercícios;
  - D = C;
  - classes 6 e 7 a zero;
  - amortizações por integrar (`ServicoAmortizacoes::porIntegrarNoAno`; no legado esta verificação nunca detectava nada);
  - armazém ao custo médio contra o saldo acumulado das contas 22 + 26 (o legado usava o preço de venda e só o movimento do ano);
  - balanço histórico.
- Encerrar só sem divergências. Reabrir só sem anos seguintes encerrados.

**Rotinas:**
- Imposto de Selo 1%: arredondado ao cêntimo e sem lançar duas vezes o mesmo mês.
- Capitalização de obras `CAP-AAAAMM`, no último dia do mês.
- Compensação e transferência de saldos com lançamentos equilibrados.
- Actualização em massa validada.
- Anular = estorno; o legado apagava e deixava a linha 3772 órfã.
- A limpeza de reconciliações só pré-visualiza; a execução faz-se na Manutenção de dados.

**Dados reais:**
- Empresa 6, 2025: o apuramento reproduz o legado (passos 2-5 idênticos; 7 movimentos com 1 a 2 cêntimos, ADR-022).
- A empresa 6 tem o armazém a 28 380 000 ao custo e as contas 22 + 26 a zero. **Decisão do utilizador (2026-10-01): a validação do inventário é só um aviso** (`avisos` na validação e na resposta do encerramento) e não impede o encerramento. As restantes validações continuam a bloquear.

**Validação:** `encerramento_exercicio_encerrado_com_resultados`.

## ADR-057 — Consolidação de empresas

Implementado em `app/Services/Consolidacao`, a partir de `consolidacao.js`.

**Holding e acesso:**
- A holding é uma empresa com `e_consolidacao`; os membros entram a 100% pelo método INTEGRAL.
- É exigido acesso à holding e a todas as empresas do grupo.

**Execução (paridade com o legado):**
- Valida os câmbios antes de tudo.
- Une os dados mestre por código e os terceiros por NIF.
- Copia as linhas como AGREGACAO.
- Eliminações por NIF, com o lançamento identificado pela chave do legado (LAN, ou diário + documento + data). A chave do ADR-025 produzia 90 eliminações em vez de 4.
- Diferenças na conta 5.9.8; conversão ao câmbio de fecho nas classes 1-4; reservas na 5.9.9.
- Mapa de Consolidação.

**Correcções:**
- As linhas da holding são uma projecção: as geradas são substituídas (excepção ao ADR-016) e as manuais preservadas. O legado apagava também as manuais.
- Tudo numa transacção.
- Pares estornados não são copiados.

**Dados reais:** o grupo 2 reproduz a execução 5 do legado: 4 eliminações com diferença 0, mesma divergência, reservas 521 899,99. Há 10 linhas da empresa 10 posteriores à última execução, apontadas pela validação `consolidacao_holding_desactualizada`.

**Esquema:** domínio de `tipo_consolidacao` (AGREGACAO, ELIMINACAO, CONVERSAO); prefixos excluídos das eliminações com até 100 caracteres.

## ADR-058 — Administração do sistema

Implementado em `app/Services/Sistema`, a partir de `app_v2.js`, `permissoes.js`, `moedas.js`, `manutencao.js`, `substituir_conta.js`, `company_backup.js` e `mapeamento_massa.js`.

**Acessos:**
- Só um utilizador de acesso total atribui perfis totais, dá acesso a todas as empresas ou define o papel. No legado havia escalada de privilégios.
- O último Super Administrador e a própria conta não se eliminam nem desactivam.
- Desactivar um utilizador ou repor a palavra-passe revoga as sessões.
- Perfis v2 só com chaves do catálogo; a segregação de funções avisa e exige confirmação.
- O `ServicoAuditoria` passou a ler o `empresa_id` dos atributos reais; em modo estrito falhava ao alterar registos globais.

**Empresas e moedas:**
- Empresa nova com as rubricas por omissão.
- INSS 0% passa a ser aceite (no legado `||8` transformava-o em 8%).
- Câmbios com âmbito TODAS ou EMPRESA, únicos por lock; um câmbio em uso não se altera; BAI lido pelo servidor.

**Substituir conta:** altera só configurações, fichas e documentos de tesouraria pendentes, numa transacção auditada. O Diário e os documentos contabilizados nunca são reescritos (ADR-016).

**Manutenção de dados:** pedido → aprovação por outro administrador, na sua sessão e com a palavra-passe → execução em 24 h. Das 13 acções do legado:
- 5 portadas como operações seguras: anular pendentes, estornar, reconciliações órfãs, eliminar empresa vazia;
- 4 substituídas por estornos, validações ou cópias;
- 4 não portadas.

Os campos do pedido passam a jsonb.

**Cópias:**
- Exportação JSON versionada.
- Importação só para empresa nova ou vazia, com ids novos e referências remapeadas (FK, sem FK, polimórficas e em JSON).
- Clone só da estrutura.
- A cópia da empresa 1 (25 432 linhas) reimporta com contagens e D−C iguais.

**Migração:** importações e edição em massa transaccionais, com simulação.

**Unidades de negócio:** implementação única em `/api/sistema/unidades-negocio`, com códigos de erro específicos.

**Rota pública:** `GET /api/sistema/logotipo-login`.

**Validações:** `texto_corrompido_lancamentos`, `reconciliacoes_tesouraria_orfas`.

**Organização do trabalho (ADR-055 a 058):** três agentes em paralelo. O coordenador unificou as unidades de negócio, aplicou o esquema (fotografia do R&C, pedidos jsonb, chave única do cadeado, domínios) e os ganchos (`ServicoLancamentos`, `ServicoAuditoria`, rota pública), e correu a ETL e a suite completa: 253 testes. As 46 validações correm sem erros nas 14 empresas.

## ADR-059 — Painéis, Análise Dinâmica e BI

Implementado em `app/Services/Gestao/Paineis`, a partir de `ui_dashboard.js` (welcome), `ui_painel_modulos.js`, `ui_cubo.js` e `ui_bi.js`. O backend fornece os dados agregados; o React desenha os gráficos.

**Página inicial:** empresa, saudação, os 16 contadores de pendentes (cada um com a permissão do legado), Dica do Dia e comunicado da avaliação 360º.

**Painéis:**
- 13 módulos, com os indicadores, as séries de 12 meses e as tabelas do legado.
- Calculados em SQL agregado com os serviços dos módulos.
- Filtros por ano/mês, unidade de negócio e centro de custo.
- Valores sem IVA por omissão; `iva=com` reproduz o legado.
- Cache de 120 s.

**Correcções face ao legado:**
- O apuramento já não entra nos proveitos e custos; o legado zerava o resultado depois do encerramento.
- Os documentos anulados não contam.
- A Visão Geral só mostra indicadores dos módulos a que o utilizador tem acesso.
- O stock baixo segue o stock mínimo de cada artigo.
- Entradas e saídas de stock classificadas pelo sentido do movimento.
- O RH lê a fotografia do processamento.
- No BI, o nome do diário passa a estar certo.

**Holding e comparação:** só empresas acessíveis; as restantes aparecem agregadas e sem nome.

**Análise dinâmica e BI:**
- O cruzamento (pivot) é feito no servidor, sobre 8 conjuntos de dados e os lançamentos.
- Dimensões, medidas e agregações vêm de uma lista branca e os filtros vão por parâmetro.
- Limite de 20 000 células.

**Dados reais (empresas 3, 6, 18 e 22):**
- Proveitos, custos, resultado e saldos 31/32/43/45 iguais ao balancete ao cêntimo.
- RH igual à fotografia do processamento.
- Painéis entre 4 e 117 ms.

## ADR-060 — Relatórios de gestão e Fluxo de Processos

Implementado em `app/Services/Gestao/Relatorios` e `app/Services/Gestao/Fluxos`, a partir de `relatorios_gestao.js`, `fluxo_processos.js`, `fluxo_*.js`, `fluxo_tabela.js` e `fluxo_narrativa.js`. As narrativas foram extraídas do próprio legado para `narrativas.json`.

**Relatórios de gestão:**
- Período A contra período B (homólogo, anterior, livre ou nenhum).
- 9 módulos, com variação Δ e Δ% e resumo executivo das variações de 20 % ou mais.
- Correcções:
  - a margem usa o custo gravado na venda (ADR-043);
  - o stock já não é valorizado ao preço de venda;
  - o RH lê a fotografia do processamento;
  - na hotelaria, cada factura conta uma vez e só o alojamento.
- Os 84 indicadores coincidem com a fórmula legada nas empresas 3, 6 e 18, salvo a correcção da hotelaria.

**Fluxo de Processos:**
- 14 fluxos.
- Cada processo tem as etapas avaliadas pelos estados reais dos documentos: factos, pendências, acções e narrativa.
- Funil com os processos parados em cada etapa; listas paginadas.
- Os fluxos de imobilizado e projectos reutilizam os dos módulos.

**Apuramento × salários (correcção transversal):**
- No legado, o `period_id` das linhas do diário SAL é o id do processamento salarial (`fluxo_processos.js:100`). Um processamento com id 13 ou 14 era tratado como apuramento e desaparecia dos mapas.
- A regra do apuramento passa a ser «período 13/14 **fora do diário SAL**» em todo o lado: mapas (`FiltroMapas`), Relatório e Contas, painéis, cubo, comparação, relatório de gestão e encerramento.
- O cancelamento do apuramento podia estornar salários; agora não pode.
- Nos dados actuais nenhum processamento tem id 13/14, por isso os números não mudam. Há um teste que fixa a regra.

**Fica para depois:** as facturas de imobilizado por contabilizar (ADR-051) e o rascunho de reconciliação bancária.

**Organização do trabalho (ADR-059/060):** dois agentes em paralelo; o coordenador centralizou a regra do apuramento. Suite: 277 testes. Com estes dois ADR fica concluída a Fase 4 (backend).

## ADR-061 — Fase 5: base do frontend React e módulo piloto (Vendas › Facturação)

**Decisão do utilizador (2026-10-01):** Ant Design + TypeScript. Primeiro a base e um módulo piloto, depois os restantes módulos em paralelo.

**Stack:** React 18, TypeScript estrito, Ant Design 5 (locale pt_PT, dayjs pt), React Router 6, TanStack Query 5, axios, Vite 6, Vitest 3. O build vai para `frontend/dist`, que é servido pelo nginx já existente; o build não é versionado.

**Base:**
- **Cliente HTTP** (`src/api/cliente.ts`):
  - acrescenta o token Bearer (Sanctum) e o `X-Empresa-Id`;
  - desembrulha o envelope `{sucesso, mensagem, dados, metadados}` e lê a paginação de `metadados.paginacao`;
  - converte os erros em `ErroApi` (mensagem do servidor, `codigo`, `erros`);
  - um 401 termina a sessão.
- **Sessão** (`SessaoContexto`):
  - entrar e sair;
  - escolha da empresa (limpa a cache de consultas);
  - `pode(...chaves)` com a mesma semântica do `exigir` do servidor (qualquer das chaves; `*` = acesso total);
  - termina ao fim de `inatividade_minutos` sem actividade, alinhado com o servidor;
  - no navegador só se guardam o token e a empresa activa.
- **Menu:** novo `GET /api/sistema/menu` (`ServicoPermissoes::menu`), que devolve os módulos e ecrãs do catálogo que o utilizador pode ver na empresa activa, com a mesma regra do `podeVer`. O frontend não decide permissões: esconde o que o servidor diz que não se vê, e o servidor volta a validar cada pedido.
- **Rotas:** `/` (início, `GET /api/gestao/inicio`) e `/m/{modulo}/{ecra}/*`. Os ecrãs registam-se em `src/modulos/registo.tsx` pelo id do catálogo; os que ainda não existem mostram «em construção».
- **Componentes:** `TabelaApi` (paginação do servidor, filtros, erros), `CabecalhoPagina`, `notificarErro` (mensagem, detalhes e código), formatação em Kz e datas pt.

**Módulo piloto — Vendas › Facturação:**
- **Listagem** com filtros (tipo, estado, contabilização, período, n.º).
- **Emissão** de FT, FR, NC, OR, PF, NE e GR:
  - cliente por pesquisa e linhas com o catálogo de produtos;
  - FR com a conta de caixa/banco e o meio de pagamento;
  - NC com a factura de origem, o motivo e a devolução de mercadoria.
  - Os totais no ecrã são uma estimativa: quem calcula, numera e sela é o servidor.
- **Detalhe** com converter, anular, contabilizar e descontabilizar, mostrando só as acções que o utilizador pode fazer naquele estado do documento.

**Verificação:**
- verificação de tipos, Vitest e build sem erros;
- o nginx serve o SPA, incluindo ligações profundas (`try_files`);
- com um utilizador temporário só de Vendas (empresa 18): entrar, sessão, menu (só Dashboard e Facturação), início, documentos (54), clientes (348) e catálogo responderam correctamente; o utilizador foi apagado no fim.

## ADR-062 — Fase 5, ronda 1: ecrãs de Vendas, Compras, Armazém, Contabilidade, Tesouraria e RH

Três agentes em paralelo, cada um nas pastas dos seus módulos. O registo de ecrãs passou a um ficheiro por módulo (`src/modulos/<módulo>/ecras.ts`), que o `src/modulos/registo.tsx` junta. A integração, o build e o commit ficaram com o coordenador.

**Ecrãs (65, só sobre a API existente):**
- **Vendas, Compras, Armazém e Inventário (20):**
  - Vendas: clientes; produtos e categorias; relatórios e exportação SAF-T; na Facturação, recibos e facturação electrónica AGT (envio, configuração, séries).
  - Compras: pedidos com deliberação e escalões; prospecção com quadro comparativo e adjudicação; encomendas; recepções; facturas; fornecedores; encomendas de clientes; contratos com marcos.
  - Armazém: níveis de stock com ajustes, transferências e extracto; validar entradas; guias; movimentos; armazéns; POS de armazém (balcão e picking); inventário (sessões, contagem cega, revisão).
- **Contabilidade e Tesouraria (23):**
  - Contabilidade: lançamentos (com D = C, estorno e importação); encerramento em 5 passos com validações e avisos; 7 mapas com filtros, totais e CSV; Relatório e Contas; rotinas e Imposto de Selo; consolidação; tabelas auxiliares e plano de contas; mapeamento contabilístico de salários.
  - Tesouraria: pagamentos e recebimentos; folha de caixa; integração e anulação; reconciliação bancária; conferência de caixa; meios de pagamento; disponibilidades.
- **RH e Salários (22):** colaboradores; contratos; funções; rubricas; coordenadas bancárias; cálculo e processamento (cartas e pagamento na tesouraria); efectividade com importação CSV/XLSX e fecho; produtividade; férias; avaliação e ciclos 360º; portal e pedidos do portal; mapas (remunerações, IRT, INSS, salários a pagar, ordem bancária) e recibos imprimíveis.

**Regras comuns:**
- Cada acção só aparece com `pode(...)` e no estado certo do documento; o servidor valida sempre.
- As mutações mostram a mensagem do servidor e invalidam a cache do módulo.
- Valores em Kz como texto decimal, somados em cêntimos no cliente.

**Correcção no backend (encontrada pelo agente de RH):** os ecrãs de mapas e recibos (`rh_rel_*`) e os de bancário, efectividade, produtividade, férias, avaliação e portal não conseguiam ler os períodos, os colaboradores e as rubricas com as suas próprias permissões. As listas de consulta foram alargadas e há um teste novo.

**Verificação:** `tsc` sem erros no projecto, 90 testes Vitest, build de produção, e contratos confirmados com pedidos GET reais (empresas 3, 6 e 18) feitos por utilizadores temporários entretanto apagados.

**Lacunas de API registadas para a ronda de afinação** (os ecrãs contornam-nas):
1. Paginação: `/api/compras/{pedidos,propostas,encomendas,rececoes,faturas}` e `/api/tesouraria/documentos` devolvem `{itens,total,...}` em `dados`, em vez de `metadados.paginacao`. Uniformizar backend e frontend em conjunto.
2. Nomes nas respostas: `fornecedor`, `terceiro` e `produto` ({id, nome, nif/código}) nas linhas de compras, guias, lançamentos, tesouraria e caixa; nomes nos resultados fotografados da folha; unidade orgânica, unidade de negócio e centro de custo por nome na ficha do colaborador.
3. Filtros e endpoints em falta:
   - filtros: facturas de compra por encomenda e por número; recepções por número; inventários e guias por estado e com paginação;
   - Tesouraria: disponibilidades e extracto próprios (`teso_gestao_mapas_view`) e integração em lote;
   - Vendas: resumo dos relatórios por `vendas_relatorios_view`; erro em JSON antes do ficheiro no SAF-T;
   - Armazém: recalcular valorizações de stock;
   - Portal: as minhas ausências, dependentes e avaliações; utilizadores da empresa para ligar a colaboradores;
   - importações Excel de colaboradores e contratos.
4. Ficaram por fazer no frontend: a avaliação do próprio e da equipa no portal, os botões AGT avançados no detalhe da venda, impressões dedicadas (talões e guias) e a edição em massa de clientes.

## ADR-063 — Fase 5, ronda 2: POS, Activos, Projectos, A&D, Orçamento, CRM, Estrutura, Configurações e Gestão

Três agentes em paralelo, com as mesmas regras da ronda 1 (ADR-062). Com esta ronda, **os 116 ecrãs do catálogo de permissões estão todos registados no frontend.**

**Ecrãs desta ronda (54, só sobre a API existente):**
- **POS (9):**
  - Frente de caixa: venda táctil e por teclado, com leitura de códigos e atalhos F2/F4/F8/F9; pagamento misto com troco só em numerário; relatório X; fecho Z com contagem por notas e moedas e talões TPA.
  - Relatórios; terminais e meios de pagamento; definições e preferências de impressão (guardadas no navegador); integração; desvios; prestação de contas.
  - Lavandaria (ordens, recepção, entrega, reclamações, tabelas) e hotelaria (mapa de quartos, check-in, consumos, check-out com rateio dos pagamentos).
  - Os totais da venda usam uma cópia exacta de `calcularComIva` e batem ao cêntimo com o servidor.
- **Activos (7), Projectos (3, com 9 separadores no detalhe), Acréscimos e diferimentos (3), Orçamento (6):**
  - amortizações com pré-visualização D/C;
  - Gantt e Kanban em CSS, sem dependências novas;
  - grelha mensal do orçamento;
  - importação CSV lida no navegador.
- **CRM (6), Estrutura (3), Configurações (9), Geral (4):**
  - pipeline em Kanban com arrastar e largar e conversão em documento de venda;
  - organigrama em CSS;
  - editor de perfis v2 com segregação de funções e matriz;
  - manutenção de dados com aprovação dupla;
  - cópias de segurança e migração;
  - painéis com gráficos SVG próprios (paleta segura para daltonismo, vista em tabela), análise dinâmica, relatórios de gestão A×B, fluxo de processos e BI.

**Integrações feitas pelo coordenador:**
- **CRM → Vendas:** a emissão de documentos lê os dados da conversão (`location.state.conversaoCrm`), abre pré-preenchida e envia `oportunidade_crm_id`. O documento fica assim ligado à oportunidade (ADR-054).
- **Backend:**
  - `GET /api/pos/hotelaria/estadias` dava erro 500 (mensagem passada no lugar do recurso no `paginado`); foi o único caso no código.
  - Um operador só com permissões do POS, da lavandaria ou da hotelaria passa a poder consultar clientes, catálogo, categorias e o stock do armazém do terminal (`POSPermissoesConsultaTest`). Continua sem poder criar fichas.

**Verificação:** `tsc` sem erros no projecto, 207 testes Vitest em 21 ficheiros, build de produção, e contratos confirmados com pedidos GET reais (empresas 3, 6, 18 e 22) feitos por utilizadores temporários entretanto apagados.

**Lacunas acrescentadas à lista da afinação (ADR-062):**
- **Projectos e Activos:** endpoint dos equipamentos do projecto; nomes de colaboradores, tarefas e rubricas nas respostas de projectos e orçamento; paginação de abates, manutenções e orçamentos.
- **Orçamento:** tipos numéricos inconsistentes (números e texto decimal misturados).
- **Lavandaria:** lista de colaboradores acessível com `lav_ordens`.
- **Estrutura:** mapa de pessoal com massa salarial por unidade e por cargo.
- **Importações:** de ficheiros `.xlsx` no servidor (hoje é colagem ou CSV).
- **Gestão documental:** não aplicável — no legado `window.renderSGD` nunca foi definido (ecrã morto); o aviso foi retirado (ADR-064).
- **Downloads binários:** com 401 não terminam a sessão.
- **Componentes a promover para `src/componentes`:** `PainelPagamentos`, `calcularComIva` do cliente, impressão de talões, gráficos SVG, análise dinâmica, Gantt, grelha mensal, seletores, `useAccao` e `ModalMotivo`.

## ADR-064 — Fase 5, afinação: lacunas de API fechadas em backend e frontend

Três agentes em paralelo, cada um no backend e nos ecrãs dos seus módulos. As rotas novas estão em `routes/api/afinacao_{a,b,c}.php`; o coordenador fez a integração.

**Compras, Tesouraria e Armazém:**
- **Paginação comum** (`RespostaApi::paginado`) nas listas de pedidos, propostas, encomendas, recepções, facturas e contratos de compras, documentos de tesouraria, guias e inventários. No frontend saíram as tabelas próprias; todas usam o `TabelaApi`.
- **Nomes nas respostas**, carregados em conjunto (sem N+1, com um teste que o confirma) e visíveis mesmo com a ficha eliminada:
  - `fornecedor` em propostas, encomendas, facturas e contratos;
  - `produto` nas linhas de compra, recepção e guia, e também no POS de armazém;
  - `terceiro` na tesouraria, na caixa e nas guias;
  - `cliente` como objecto nas encomendas de clientes.
- **Filtros:** facturas por encomenda e por número; pesquisa literal (sem curingas do utilizador) nas listas; guias e inventários por estado.
- **Tesouraria:**
  - disponibilidades e extracto com a permissão do próprio ecrã, iguais ao balancete e ao razão;
  - integração em lote, em que cada documento é integrado na sua própria transacção.
- **Recálculo das valorizações de stock** (`armazem_recalcular`):
  - só simula, salvo pedido expresso para aplicar;
  - nunca altera valores contabilizados, que aparecem no relatório como divergências;
  - recusa aplicar com um inventário em curso.

**Vendas, Contabilidade, Sistema e Gestão:**
- **Vendas:**
  - os indicadores dos relatórios passaram a ser calculados no servidor (`/vendas/relatorios/resumo`), e o ecrã deixou de ler os documentos no navegador;
  - o SAF-T é validado antes de escrever o XML (`/vendas/saft/validar`);
  - acções AGT no detalhe da venda: revalidar, QR e pedido assinado.
- **Contabilidade:** `terceiro` nos lançamentos e no razão.
- **Sistema:**
  - importações em massa e de câmbios com o próprio `.xlsx` do modelo, lido no servidor (`ServicoLeituraFolha`: folha «Template», datas convertidas, linha de exemplo ignorada, número de linha igual ao do Excel);
  - moeda funcional acessível a quem consulta moedas.
- **Frontend:** `descarregar()` em `src/api/cliente.ts`. Nas descargas, um 401 termina a sessão e os erros JSON vindos dentro do ficheiro são lidos.
- **Gestão documental:** confirmado ecrã morto no legado; nada a migrar.

**RH, Projectos, Orçamento, Activos, Lavandaria e Estrutura:**
- **RH:**
  - a folha fotografada traz nome, NIF e INSS;
  - a ficha do colaborador traz unidade orgânica, unidade de negócio e centro de custo por nome;
  - endpoints novos do portal: ausências, dependentes, a minha avaliação e utilizadores da empresa para ligar a colaboradores;
  - o portal passa a ter a avaliação do próprio: comunicado, autoavaliação, 360º, ascendente, tomar conhecimento, contestar e acompanhamento.
- **Estrutura:** mapa de pessoal por unidade e por cargo; a massa salarial só aparece com `est_ver_salarios`.
- **Projectos:** equipamentos do projecto; nomes nas horas e no orçamento; cliente na carteira.
- **Orçamento:**
  - todas as respostas trazem os valores monetários como texto com 2 casas (`FormatoOrcamento`, só à saída);
  - o frontend fazia contas sobre texto (concatenava em vez de somar, e `.toFixed` sobre texto); passou a somar em cêntimos;
  - a lista de orçamentos é paginada e os pedidos de excesso e alertas trazem os nomes.
- **Activos:** abates e manutenções paginados e com filtros.
- **Lavandaria:** colaboradores (só id e nome) para atribuir as ordens.

**Correcção de esquema:** `autoavaliacoes_colaborador.formacao` passou de `varchar(10)` a texto, como no legado («Necessidades de formação», área de texto). A validação passou a 2000 caracteres e o portal usa uma área de texto.

**Verificação:**
- 224 testes Vitest e build de produção;
- suite completa do backend, depois de recarregar a base com a ETL real;
- contratos confirmados com GET reais (empresas 3, 6 e 18) feitos por utilizadores temporários entretanto apagados.

**Fica registado:**
- `GET /rh/avaliacao/avaliacoes/{id}/resultado-360` grava `nota_360` ao ser lido (é um GET com efeitos);
- os valores dentro dos detalhes dos erros de controlo orçamental continuam numéricos;
- **promoção feita a seguir:** `src/utilitarios/decimal.ts` (somas exactas em cêntimos), `src/utilitarios/csv.ts`, `src/componentes/Accoes.tsx` (`useAccao`, `ModalMotivo`) e `src/componentes/graficos/` (gráficos SVG acessíveis). As importações foram reescritas por script (só linhas de import) e o resultado foi validado por `tsc`, pelos 224 testes e pelo build. Os componentes ligados a um domínio (pagamentos do POS, seletores de compras e contabilidade, Gantt, grelha mensal) ficam nos módulos, importáveis por outros.

## ADR-065 — Fase 6: testes ponta-a-ponta, segurança e desempenho, produção e integração contínua

Três agentes em paralelo (E2E, auditoria, produção), em pastas exclusivas; o coordenador integrou as alterações partilhadas. A base de desenvolvimento com os dados reais (`erp_consulvolt`) só foi lida: medições dentro de transacções desfeitas, restauro numa base temporária apagada a seguir.

### Testes ponta-a-ponta

**Ambiente isolado** (`docker-compose.e2e.yml`):
- `app_e2e`: mesma imagem e código, base `erp_consulvolt_e2e`, Redis nas bases lógicas 2/3 com prefixos próprios, filas síncronas, AGT desligada e limites de pedidos altos;
- `web_e2e`: nginx em 127.0.0.1:8081 com o mesmo `frontend/dist`.

**Dados fictícios.** `erp:e2e:preparar` recusa qualquer base cujo nome não termine em `_e2e` (o seeder repete a protecção), faz `migrate:fresh` e carrega `database/seeders/E2E`: duas empresas de demonstração, cinco utilizadores com perfis distintos, plano de contas mínimo, configurações contabilísticas dos módulos, stock, POS e RH. NIF fictícios (5999…). O Playwright recria a base antes de cada execução.

**Cobertura** (Playwright, Chromium, em série — 145 testes):
- autenticação, empresa activa e menu por permissões (o perfil de vendas não vê nem acede aos outros módulos);
- Vendas: FT, FR, NC parcial e contabilização;
- POS: sessão, pagamento misto com troco, X, Z sem desvio e integração;
- Compras: ciclo completo com deliberação por outro utilizador, recepção em stock, factura e contabilização;
- Contabilidade: lançamento manual (gravar bloqueado enquanto desequilibrado), balancete e mapas;
- RH: período, importação dos contratos, cálculo (INSS, IRT, líquido) e mapas;
- Configurações: perfil com conflito de segregação (aviso + confirmação no servidor) e utilizador novo;
- navegação: o menu da API coincide com o catálogo e os 116 ecrãs abrem sem «Sem acesso», notificações de erro, erros na consola, excepções ou respostas 4xx/5xx.

Os testes chamam-se `*.e2e.ts` (separados do Vitest) e correm com `npm run e2e`; relatório e traces fora do Git.

**Defeitos corrigidos:** o resultado do fecho Z desaparecia quando a lista de terminais recarregava (o modal passa a guardar a sessão do fecho e só fecha com «Concluir»); os selectores contabilísticos descartavam o `id` do `Form.Item` (rótulos não ligados aos campos).

### Segurança e desempenho

**Âmbito.** ~766 rotas revistas: autenticação, `exigir`, isolamento entre empresas (~490 consultas brutas), atribuição em massa, SQL dinâmico, uploads, descargas, dados sensíveis e limites de entrada. Não há fugas entre empresas no SQL nem injecção; as 21 regras `exists` filtram a empresa; o cubo usa lista branca e bind; o frontend não tem pontos de XSS e a impressão escapa os campos.

**Domínio do administrador** (os riscos reais estavam nas tabelas globais). Um administrador sem acesso total gere só o seu domínio — as empresas a que está ligado:
- editar um utilizador exige partilhar uma empresa (as ligações às restantes mantêm-se, ADR-058);
- repor a palavra-passe, mudar o estado ou eliminar exigem todas as empresas do alvo;
- contas com acesso a todas as empresas e perfis usados fora do domínio (ou o próprio perfil) ficam reservados a quem tem acesso total;
- a lista e a ficha de utilizadores mostram só o domínio.

**Outras correcções:**
- eliminar contrato exige `contratos_terminate` (o botão no frontend também);
- o mapa de consolidação exige acesso a todas as empresas-membro;
- a comparação de empresas exige as vistas do painel de Contabilidade (o separador só aparece a quem as tem);
- a Manutenção de dados recusa (403 `EMPRESA_SEM_ACESSO`) impacto, pedido, decisão e execução sobre uma empresa a que o actor não tem acesso;
- pedir um excesso orçamental exige uma tarefa de quem grava documentos com controlo orçamental (pedidos, encomendas, facturas de compra, tesouraria, lançamentos) ou `orc_alertas_view`; o pedido pendente de outro utilizador já não é reescrito;
- login com limite também por IP (`ERP_SESSAO_TENTATIVAS_POR_MINUTO_IP`, 60/min) e limite geral da API activo (`throttleApi`, `ERP_API_PEDIDOS_POR_MINUTO`, 300/min por utilizador);
- arrays de entrada com `max`; o cubo recusa medidas desconhecidas com 422 e limita filtros.

**Desempenho.** Rotas GET medidas nas empresas com mais dados, com contagem de consultas e EXPLAIN: quase todas abaixo de 300 ms; as 46 validações em menos de 400 ms nas 14 empresas. Correcções, com resultado provado igual sobre os dados reais e teste (`DesempenhoTest`):
- meses de amortização por calcular deixam de percorrer meses sem quota possível — fluxo do imobilizado de 1,0–1,6 s para ~0,2 s;
- resumo de férias sem N+1 — de 38 para 4 consultas;
- mapa de IVA deixa de ler o histórico anterior ao período.

Os índices existentes cobrem as consultas medidas; não se acrescentou nenhum.

### Produção e integração contínua

**Imagens.** Um único `docker/php/Dockerfile.prod` multi-stage gera, do mesmo commit, `app` (PHP 8.3-FPM sem dependências de desenvolvimento, `route:cache`/`event:cache` no build, OPcache sem revalidação, `www-data`), `web` (nginx-unprivileged com o `frontend/dist`) e `copias` (pg_dump 16 + openssl). O `config:cache` corre no arranque e não no build, para nenhum segredo ficar numa camada. O estágio de dependências usa o caminho final (`/var/www/html`), porque a cache de rotas guarda caminhos absolutos.

**Orquestração** (`docker-compose.prod.yml`): PostgreSQL e Redis sem portas publicadas, Redis com palavra-passe, nginx só em 127.0.0.1 atrás do proxy HTTPS; FS só de leitura, `cap_drop: ALL`, `no-new-privileges` e `init: true` (sem tini o `schedule:work` ignora o SIGTERM e pode prender o mutex do ciclo AGT); logs JSON com rotação; `REDIS_QUEUE_RETRY_AFTER=660` acima do `--timeout=600` do worker.

**nginx.** CSP sem `unsafe-eval` (só o hash do `onclick="window.print()"` da reimpressão POS; `style-src 'unsafe-inline'` para o Ant Design), validada num Chromium em 101 ecrãs com 0 violações; HSTS quando o proxy indica HTTPS; IP real por `X-Forwarded-For` de redes privadas; `limit_req` no login; pedidos até 25 MB (100 MB só em `/api/sistema/copias`); activos com hash em cache 1 ano.

**Saúde.** `GET /api/saude` verifica PostgreSQL, Redis, filas e armazenamento, devolve a versão da imagem e responde 503 se algum componente falhar.

**Cópias.** `backup.sh` (pg_dump verificado, storage, chaves AGT cifradas, `SHA256SUMS`, rotação 7/4/12 agendada); `restaurar.sh` só cria bases novas, verifica as somas e pede confirmação. Restauro testado com a base real: 178 tabelas e 318 141 linhas idênticas, mesmo MD5 do diário.

**Migração definitiva** (`docs/PRODUCAO.md` §13): congelar o legado e registar o SHA-256 do backup final; cópia, simulação e `erp:migrar-backup-legado --substituir --force` com worker e scheduler parados; conferências e critérios de aceitação; retorno só possível até ao go.

**CI** (`.github/workflows/ci.yml`, só dados fictícios): backend (PostgreSQL 16, Redis 7, Pint, PHPUnit), frontend (Node 22, tsc, Vitest, build) e produção (ShellCheck, `compose config`, build das imagens sem publicação).

### Verificação
- backend: 317 testes PHPUnit; frontend: 224 testes Vitest, `tsc` e build; E2E: 145 testes Playwright com o limite da API activo.

### Fica registado
- `config_manutencao_view` continua a bastar para pedir acções de manutenção (mitigado: administrador, palavra-passe e decisor diferente); uma chave própria exigiria rever os perfis migrados;
- validar uma recepção de compras gera lançamentos sem verificar `compras_rec_contabilizar` (confirmar com o legado);
- o papel ADMINISTRADOR deriva do nome do perfil conter «admin» (paridade);
- `GET /api/saude` é público e indica o estado dos componentes (sem mensagens internas);
- aspecto: o POS mostra o nome de utilizador do operador; a factura de compra mostra o id da encomenda em vez do número;
- por decidir pelo utilizador: servidor, domínio e HTTPS, SMTP, destino externo das cópias e guarda das chaves, data do dia D.

## ADR-066 — Responsividade, identidade da empresa, impressão/PDF e pendentes da Fase 6

Pedido do utilizador (2026-10-01): sistema 100 % responsivo; logótipo e nome da empresa na barra do menu e nos PDF; ícones SVG profissionais nos menus; impressões na orientação certa, a caber em A4 ou A3, sem barras de deslocação. Feito com 2 agentes para a base comum e 3 agentes por grupos de módulos; o coordenador integrou.

### Decisões do utilizador
- **Mão de obra nos projectos:** ficam as duas vias — auto de medição e folha de horas (como no legado).
- **Arredondamento AGT:** fica ao cêntimo (o hotel pode dar 40 499,99 em vez de 40 500).

### Identidade da empresa
- `GET /api/sistema/identidade` (qualquer utilizador da empresa activa): nome, NIF, morada, contactos, registo comercial, rodapé e logótipo (só data URI de imagem, validado na gravação). No frontend, `useIdentidade()` (`src/sessao/identidade.ts`), lido uma vez por empresa.
- Barra do menu: logótipo (ou iniciais) e nome da empresa no menu lateral e, em ecrã estreito, na barra superior.

### Layout responsivo e ícones
- Abaixo de 992 px o menu lateral passa a gaveta («Abrir menu»); em telemóvel a barra é compacta e o menu do utilizador fica no avatar; padding adaptativo; a página nunca ganha deslocação horizontal (tabelas e conteúdos largos deslocam-se dentro do seu contentor).
- Base para os ecrãs em `src/componentes/responsivo` (`useEcra`, `BarraFiltros`, `DeslocamentoHorizontal`, `larguraModal`, `scrollTabela`, `COL_CAMPO`, `COLUNAS_DESCRICOES`) e CSS global em `src/estilos/global.css`.
- Ícones `@ant-design/icons` (SVG) por módulo e por tipo de ecrã (`src/componentes/icones/iconesModulos.tsx`); atalhos dos módulos no Início.
- Os 116 ecrãs revistos (grelhas `xs/sm/md`, tabelas com deslocação própria e colunas secundárias escondidas em ecrã pequeno, modais com largura limitada, linhas de documentos em cartões no telemóvel); APIs obsoletas do Ant Design substituídas (`destroyOnHidden`, `prefix/suffix`, `variant`).
- Teste E2E permanente `e2e/responsivo.e2e.ts`: todos os ecrãs do menu a 375 px sem deslocação horizontal da página nem do conteúdo.

### Impressão e PDF
- Motor comum `src/componentes/impressao`: documento numa iframe sem scripts, impresso a partir da janela principal (compatível com a CSP `script-src 'self'`); o PDF é o «Guardar como PDF» do navegador (vectorial, texto seleccionável, nome de ficheiro «Título - Empresa - data»). Sem jsPDF/pdfmake/html2canvas.
- Cabeçalho em todos os documentos: logótipo e nome da empresa, NIF e morada, título, período/filtros, data e utilizador; rodapé com «Página X de Y» e o rodapé da empresa.
- Folha e orientação automáticas pela largura real do conteúdo: A4 retrato → A4 paisagem (aceitando até 85 % de redução, porque muitos postos não têm impressora A3) → A3 paisagem → só então escala (mín. 55 %, depois quebra de texto). Tabelas com 9 ou mais colunas vão logo para paisagem. Nunca se cortam colunas.
- No papel não há barras de deslocação: `overflow: visible`, tabelas Ant Design sem cabeçalho fixo nem corpo com scroll, cabeçalho das tabelas repetido em cada página, linhas e cartões sem quebra a meio.
- `TabelaApi impressao` imprime todas as páginas do endpoint com os filtros actuais (até 5 000 linhas, com aviso); `tabelaHtml` para mapas; clonagem do DOM para documentos visuais.
- Aplicado a todos os mapas, relatórios, listagens principais e detalhes de documentos dos módulos. Documentos comerciais (FT/FR/NC/GR/encomendas/propostas) em A4 retrato no formato do legado (`documento_comercial.js`), com IVA, extenso, série/hash/QR AGT e morada do cliente. Talões térmicos do POS mantêm a largura do rolo, agora com logótipo e nome.
- A CSP de produção deixou de precisar de `'unsafe-hashes'` (já não há handlers inline).

### Pendentes da Fase 6 resolvidos
- **Recepção de compras:** confirmado no legado (`ui_compras_v2.js`, `postPurchaseDeliveryToAccounting` desactivado) que a recepção só é contabilizada na validação do armazém (`armazem_validar`) — o sistema novo já faz isso; `compras_rec_contabilizar` é uma chave sem efeito, mantida por paridade do catálogo.
- **Papel de administrador:** deixa de derivar do nome do perfil conter «admin» (um perfil «Administrativo» dava poderes de aprovação na Manutenção de dados). Passa a explícito, definido só por um Super Administrador; utilizadores novos são UTILIZADOR e os existentes mantêm o papel (os migrados mantêm o do legado).
- **Operador do POS:** sessões, vendas, lavandaria e conferência de caixa guardam o nome completo (ou o nome de utilizador), como o legado (`pos_gestao.js:20`).
- **Factura de compra:** o detalhe mostra o número da encomenda de origem em vez do id.
- **Integração contínua:** acções actualizadas para versões sem Node 20 (`checkout@v7`, `setup-node@v7`, `cache@v6`, `setup-buildx-action@v4`, `build-push-action@v7`); os registos de build Docker deixam de ser publicados como artefactos (repositório público).

### Verificação
- backend: 320 testes PHPUnit; frontend: 300 testes Vitest, `tsc` e build; E2E: 263 testes Playwright (inclui os 116 ecrãs a 375 px e o documento PDF com logótipo e nome).
- PDFs de exemplo verificados (mapas de RH, balancete, extracto, balanço, relatórios POS, mapa de amortizações, orçamento, factura, encomenda, Gantt): logótipo e nome, folha/orientação certas, nada cortado, sem deslocação.

### Fica registado
- No Gantt impresso a linha de cabeçalho não se repete nas páginas seguintes (é feita com `div`, não `thead`).
- O «Página X de Y» usa as caixas de margem `@page` (Chromium/Edge); no Firefox a numeração não aparece.

## ADR-067 — Análise de paridade completa (2026-10-02) e correcções

Pedido do utilizador: confirmar que tudo foi migrado, que as regras de negócio estão no backend, que o Redis funciona a 100 %, que os cálculos e fluxos estão certos, e aproximar o visual do sistema antigo. Antes, cópia integral do projecto e da base de desenvolvimento em `C:\xampp\htdocs\ERP_CONSULVOLT_MEU\v2` (local, não publicada). Três análises só de leitura, cujos relatórios ficam em `docs/paridade/`:
- `ANALISE_PARIDADE_2026-10-02.md` — 279 funcionalidades: 130 migradas, 88 parciais, 45 em falta, 16 não aplicáveis; os 116 ecrãs e as 196 tarefas do catálogo existem; lacunas A-01…A-12 (alta) e M-01…M-20 (média);
- `ANALISE_REGRAS_REDIS_2026-10-02.md` — o backend recalcula totais, IVA, numeração, troco, IRT/INSS e lançamentos; Redis verificado (cache, locks, limitador, filas, agendador); riscos A1–A4, R1–R12, M1–M16;
- `ANALISE_CALCULOS_FLUXOS_2026-10-02.md` — fórmulas legado vs novo com dados reais (63 folhas/413 linhas, 2 002 casos de acréscimos, vendas, POS, stock, amortizações, contabilidade, orçamento, RH): iguais salvo arredondamentos decididos, erros do legado corrigidos e problemas de dados; 10 erros de fluxo/casos-limite.

### Correcções (todas com teste)
- **Salários:** fotografias LEGADO que não fecham ao cêntimo acertam a diferença (≤ 10 Kz) na conta ROUNDING_DIFF, como o legado; notas das demonstrações no lançamento SAL (28 nas 72*, 19 nas 3*).
- **Notas das demonstrações:** compras (11/9/4 como o legado), tesouraria e caixa (nota 10 nas disponibilidades), nota por omissão pelo prefixo da conta nos lançamentos automáticos sem nota (`ServicoNotasPorConta`), e a rotina do legado `recoverDataMapping` portada (`POST /api/contabilidade/tabelas/notas-demonstracao/sincronizar-por-conta`, `config_ferramentas`, simula por omissão, só linhas sem nota, exercícios abertos). Nos dados reais atribuiria nota a 947 linhas — fica para o utilizador correr quando quiser.
- **Tesouraria:** linha ligada a um documento impõe o número desse documento e exige o pendente nessa conta; vendas anuladas recusadas; o valor pago nunca excede o total.
- **Notas de crédito:** proibidas sobre factura paga (regras do legado na emissão e na conversão); linhas presas à factura de origem (produto, preço, taxa, quantidade ainda não creditada); valores pela linha de origem, crédito parcial proporcional.
- **AGT:** a pré-validação recusa (422 `AGT_PRE_VALIDACAO`) antes de reservar o número de série; regras do legado acrescentadas.
- **POS:** preços com no máximo 2 casas; pagamentos e documento coincidem.
- **Stock:** custo médio recalculado nas saídas a custo explícito; saída de ajuste ao custo médio; nenhum movimento em exercício encerrado (verificação dentro da transacção, também nos lançamentos).
- **Compras:** facturas migradas sem valor da transitória já não vão inteiras para diferenças de câmbio; taxas de IVA só das legais (0, 5, 7, 14).
- **Contabilidade:** nota de fluxo obrigatória no lançamento manual de caixa/bancos; lançamento manual em moeda estrangeira (câmbio, equilíbrio na moeda, acerto ≤ 1 Kz).
- **Orçamento:** compromissos só até ao mês do documento; facturas de encomenda por contabilizar contam como compromisso; o erro de excesso traz o necessário para o pedido de aprovação.
- **Produtividade:** repartição sem resíduo.
- **Migração do legado:** códigos sem espaços Unicode nas pontas (+ validação `codigos_com_espacos_nas_pontas`); limpeza da cache depois do COMMIT e no fim da simulação (passo obrigatório no runbook).
- **Unicidade:** números de NE/GR/GD, OR/PF com série, documentos de tesouraria, pedidos, propostas e recepções de compra únicos por empresa (o gerador passou a distinguir nomes de índices parciais com as mesmas colunas).
- **Redis:** invalidação da cache também depois do commit; cópia de empresa invalida plano e catálogo; falha ao agendar o envio AGT já não falha a emissão; `/api/saude` sem limitador (503 com detalhe se o Redis cair) e com batimentos do agendador e do worker; incremento atómico da versão das empresas; `REDIS_QUEUE_RETRY_AFTER=660` também em desenvolvimento.

### Lacunas construídas (ronda 1)
- Ecrã **Validações de dados** (separador da Manutenção de dados).
- **Pedido de aprovação do excesso orçamental** a partir do próprio documento (lançamento manual, pagamento, factura de compra, adjudicação) e aprovação no acto para quem tem `orc_aprovar_excesso`.
- **AGT:** erros e avisos legíveis no detalhe; filtro por estado AGT na lista (`estado_fe`).
- **Saldos históricos:** ecrã, importação e modelo Excel.
- **Folha de caixa:** pagar e receber facturas, notas nas linhas, classificar movimentos, reabrir sessão fechada.
- **Mapa de IRT** com o escalão calculado no servidor (a tabela deixou de existir no frontend).

### Visual e impressão
- Paleta, tipografia (Inter), menu lateral escuro, barra superior, tabelas, botões e separadores do sistema antigo; ecrã de entrada e Início como os do legado (com as imagens de fundo do legado); contraste AA.
- Paginação feita pelo motor de impressão (folhas com «Página X de Y» em qualquer navegador, incluindo Firefox; cabeçalho das tabelas e do Gantt repetido em cada página).

### Decisões pendentes do utilizador
Lista de 27 decisões apresentada em 2026-10-02 (IRT a 150 000 Kz, preço livre, câmbio manual, horas extra automáticas, mesas do restaurante, NC sobre FR, contas de diferenças de câmbio, Power BI, assistente IA, etc.).

### Verificação
- backend: 353 testes PHPUnit; frontend: 342 testes Vitest, `tsc` e build; E2E: 263 testes Playwright; ETL do backup real recarregada (46 folhas fotografadas, 38 iguais ao diário).

## ADR-068 — Ronda 2 das lacunas: visual ecrã a ecrã, lacunas A/M, 27 decisões do utilizador e integrações

**Contexto.** Depois da análise de paridade (ADR-067), a ronda 2 fechou as lacunas altas que faltavam (A-03, A-05, A-07…A-10, A-12) e as médias (M-01…M-20), aplicou as 27 decisões que o utilizador aceitou em 2026-10-02 (todas «conforme a recomendação, com a melhor prática») e acrescentou os câmbios do BAI automáticos. Quatro agentes trabalharam em paralelo, com ficheiros exclusivos, e a integração final foi feita por um quinto. Em todos os ecrãs tocados houve também uma **aproximação visual ao legado, ecrã a ecrã**: títulos, subtítulos, ordem dos separadores, botões e textos do sistema antigo, sempre sem deslocação horizontal a 375 px e com Imprimir/PDF.

### Lacunas construídas, por grupo
- **G1 — Contabilidade, POS, Geral, Acréscimos, Estrutura.**
  - A-05: classificação e notas em massa e edição dos campos não financeiros (`POST /contabilidade/lancamentos/classificacao`), painel de filtros do legado (linhas «sem» nota/UN/CC, contas, referência).
  - A-07: lançamento manual em moeda estrangeira no frontend (moeda, câmbio, «Equilibrar»).
  - M-09: transferir um lançamento para outra empresa (estorno na origem + criação no destino, `ServicoTransferenciaLancamentos`).
  - M-10: Relatório de Gestão no Relatório e Contas (textos automáticos do legado editáveis, KPIs, alertas, impressão).
  - M-15: mesas do restaurante no servidor (`mesas_pos`, `contas_mesa_pos`, bloqueio optimista entre postos, cobrança → factura-recibo).
  - M-16: talões e etiquetas da lavandaria, recibo RC-LAV, talão do check-out do hotel e importação das tabelas da lavandaria.
  - Visual: Acréscimos, Estrutura («Criar estrutura base», «Várias unidades»), POS, Encerramento.
- **G2 — RH/Salários, Activos, Orçamento, Configurações.**
  - A-08: copiar o mês anterior, lote com várias rubricas/horas, editar/eliminar seleccionados, eliminar período aberto, importação Excel.
  - A-09: importação de colaboradores e contratos, contratos e rubricas em massa.
  - A-10: recibo em PDF (2 vias, valor por extenso, IBAN), ZIP com um PDF por colaborador, UN/CC na ficha, assistente dos mapeamentos em falta.
  - M-11 (portal: «A minha equipa», autoavaliações, avaliação das chefias), M-12 (leitura do relógio pelo servidor), M-13 (importação da produtividade; resultados com função, banco, IBAN, UN e CC), M-14 (unidades de negócio nas Configurações gerais e, na integração, também em Contabilidade › Tabelas auxiliares, com o mesmo componente).
- **G3 — Vendas, Compras, Tesouraria, Armazém, Projectos, CRM.**
  - A-03: condições de pagamento (PRONTO/PRAZO/MARCOS) e moeda estrangeira na emissão de vendas; impressão A4 com prestações e contravalor.
  - A-12: importação de documentos de tesouraria com o modelo do legado (com simulação), anular/desintegrar em lote.
  - M-06 (copiar documento; contabilizar/descontabilizar em lote), M-07 (importar produtos e categorias), M-08 (rascunhos da reconciliação em `rascunhos_reconciliacao`, histórico e detalhe, editar linha do extracto), M-17 (editar o IVA da proposta e da encomenda), M-18 (recibo de adiantamento e alocação posterior; factura a partir de várias guias), M-20 (arrastar tarefas na WBS; acções do organigrama).
  - Visual: Facturação com os separadores do legado, barra do módulo de Compras, Operações de Tesouraria, Armazém (estado Esgotado/Ruptura/Disponível), CRM (só com facturas em atraso).
- **G4 — Funcionalidades transversais e integrações** (detalhe abaixo): câmbios do BAI automáticos, M-01 Excel, M-02 Power BI, M-03 assistente IA, M-04 ajuda F1, M-05 operações em segundo plano, M-19 preferências, e os botões do legado em falta («Ajuda», «Procurar» Ctrl+K, favoritos, arrastar módulos, «Modo responsivo», «Voltar»).

### As 27 decisões do utilizador e como foram aplicadas
| # | Decisão | Aplicação |
| :-: | :--- | :--- |
| 1 | IRT: manter o degrau de 12 500 Kz a 150 000 Kz | Tabela de IRT (Grupo A) **configurável** e nacional (`configuracoes_sistema.rh_tabela_irt`, RH › Rubricas › Tabela de IRT, permissão nova `rh_tabela_irt_gerir`); por omissão a do `engine_v2.js`, com a nota legal «confirmar com o Código do IRT em vigor»; o modo LEGADO usa sempre a original |
| 2 | `dias_trabalhados` com 3 casas | Aceite; registado no ADR-036 |
| 3 | Encerrar com avisos de horas extra/faltas não valorizadas | Exige `confirmar_avisos`; sem ela, recusa |
| 4 | Desconto com `calculo_horas = FALTA` como falta | Aceite; registado no ADR-036 |
| 5 | Horas extra automáticas | Mantidas, com aviso visível; segregação encerrar/validar configurável por empresa (`configuracoes_rh`, desligada por omissão) |
| 6 | Feriados | Feriados nacionais de Angola pré-carregáveis (`GET /rh/assiduidade/feriados-nacionais`); o utilizador confirma/edita por empresa |
| 7 | Férias pela Lei Geral do Trabalho | Lei n.º 12/23: 22 dias úteis; no ano de admissão 2 dias × meses completos (máx. 22); transporte do saldo não gozado até 22 dias; aviso antes de 6 meses de serviço (o portal recusa). Parâmetros em `configuracoes_rh` (ver `ServicoFerias`) |
| 8 | Preço livre | Permissão nova `vendas_alterar_preco`, atribuída aos perfis com `vendas_fat_emitir`; o frontend envia o preço só quando alterado |
| 9 | Câmbio manual | Tolerância configurável (por omissão ±5 %, Moedas › «Câmbio manual nos documentos») em vendas, compras e tesouraria; acima, só com `cambio_manual_fora_tolerancia` e com registo na auditoria. **Excepção de controlo (melhor prática aceite pelo utilizador): a permissão é atribuída só aos perfis que gerem moedas (`config_moedas_gerir`) e ao acesso total — não a todos os que emitem documentos** |
| 10 | Arredondamento AGT do POS | Campo próprio `vendas.arredondamento_agt`, separado do desconto (`ServicoDocumentosVenda::descontoEArredondamentoPos`), também na pré-visualização do check-out do hotel e no talão |
| 11 | SAF-T do POS | Preço unitário com a precisão necessária e `SettlementAmount` para os descontos (SAF-T(AO) e AGT) |
| 12 | FT sem linhas | Validação de dados com orientação para anular/corrigir; sem alteração automática |
| 13 | Recibo de venda | Nota de fluxo de caixa «recebimentos de clientes» atribuída automaticamente |
| 14 | Mesas do restaurante | M-15 (acima) |
| 15 | Stock negativo seguido de entrada | A diferença de valorização das unidades vendidas a descoberto vai para o CMV: recepção de compra, regularização de inventário e, na integração, **também nas devoluções GD e NC com devolução de mercadoria** (`itens_venda.acerto_cmv_kz`, linhas no lançamento do documento) |
| 16 | Câmbio na adjudicação | Câmbio da data da adjudicação (salvo câmbio manual da proposta) |
| 17 | Factura de compra com projecto | Conta do produto + imputação analítica ao projecto |
| 18 | Venda de activos | IVA liquidado (`abates_vendas_ativos.taxa_iva/valor_iva/conta_iva`; D terceiro valor + IVA, C IVA) |
| 19 | Diferenças de câmbio | 6621 favoráveis / 7621 desfavoráveis, como o legado; justificação corrigida nos ADR-033/034; configuração pré-preenchida (migração de dados, passo pós-carga da ETL e `erp:tesouraria:diferencas-cambio`) e aviso nas Validações onde faltam |
| 20 | Plano de contas | Diagnóstico nas Validações e correcção assistida antes do primeiro encerramento (`/contabilidade/encerramento/{ano}/plano`) |
| 21 | Arredondamento do banco em moeda | Acerto na última linha |
| 22 | Balancete «sem saldo zero» | Totais sempre de todas as contas |
| 23 | Monitor orçamental no modo NENHUM | EXCEDIDO acima de 100 %, só informativo |
| 24 | Gasto sem orçamento | Desvio desfavorável (`sem_orcamento`) |
| 25 | Power BI | M-02 (abaixo) |
| 26 | Assistente IA | M-03 (abaixo) |
| 27 | NC sobre factura-recibo | Mantida bloqueada como no legado (anular primeiro o recibo); aviso no ecrã de emissão |

### Integrações e funcionalidades transversais
**Câmbios do BAI automáticos.** A rotina manual mantém-se. A obtenção diária agendada (`sistema:cambios-bai`, verificada de minuto a minuto) começa a uma hora fixa configurável no ecrã de Moedas (`config_moedas_gerir`; desligada por omissão; até 3 tentativas por dia com 30 min de intervalo; corre na primeira verificação depois da hora se o agendador esteve parado). O resultado fica em `cambios_bai_pendentes` (data, moeda, compra/venda/média, último registado, variação, alerta > 5 %). **Nada é gravado em `taxas_cambio` sem validação**: validar grava os valores obtidos pelo servidor (nunca vindos do cliente) com as regras da gravação manual; rejeitar descarta; uma obtenção nova substitui as pendentes anteriores; auditoria de quem decidiu. Cada obtenção e as falhas ficam em `execucoes_cambios_bai`, mostradas no ecrã e em `/api/saude` (`dados.informacao.cambios_bai`, só informativo).

**M-01 — Excel comum.** Gerado no cliente, a partir do mesmo conteúdo que o motor de impressão recebe, com um escritor XLSX mínimo próprio (ZIP sem compressão + SpreadsheetML, `src/componentes/impressao/excel.ts`, sem dependências e compatível com a CSP). Fica em todos os ecrãs com Imprimir/PDF sem os alterar um a um (o servidor não conhece as colunas visíveis nem os `render`). Números como números, datas como datas, totais a negrito, cabeçalho da empresa e filtros, títulos fixos; códigos (contas, NIF, n.º de documento) ficam texto. `excel={false}` esconde o botão.

**M-02 — Power BI (decisão 25).** Feed OData v4 de leitura `GET /api/bi/odata[/$metadata|/{conjunto}]` sobre a lista branca do cubo (8 conjuntos, com as regras de cada um; dimensões da holding excluídas), 5 000 linhas por página com `@odata.nextLink`, `$top/$skip/$select/$count`, período por parâmetros; `$filter/$orderby` → 501, declarado no `$metadata`. Autenticação fora do Sanctum por **token de leitura por empresa** (`tokens_bi`: só SHA-256, prefixo visível, conjuntos opcionais, expiração, revogação imediata, último uso), como Bearer ou Básica (palavra-passe = token). Gestão com `config_backup`; 120 pedidos/min por token. Corrige o servidor do legado (sem autenticação, upload aberto, todas as empresas misturadas, CSDL sem tipos).

**M-03 — Assistente IA para lançamentos (decisão 26).**
- Fornecedor Anthropic (Claude), Messages API; modelo `claude-opus-5-5` (configurável em `ERP_IA_MODELO`), pensamento adaptativo, esforço `medium`, saída estruturada em JSON Schema, prompt de sistema em cache.
- Chave só em `ANTHROPIC_API_KEY` no ambiente do servidor. Cliente HTTP do Laravel (uma chamada, sem dependência nova, testável com `Http::fake`); a passagem ao SDK oficial é directa.
- **Desligado por omissão em cada empresa** (activado por `config_empresas_gerir`). **Só propõe**: as propostas são validadas no servidor (diário, contas de movimento, D/C, valores, data, equilíbrio — problemas como avisos) e abertas no formulário normal de lançamento; a gravação é a de sempre.
- Dados enviados, minimizados: o texto/ficheiro escolhido, contas de movimento (código e descrição), diários, regras de negócio da empresa e a data; nunca nome/NIF da empresa, terceiros, saldos ou lançamentos. O conteúdo do documento é tratado como dados.
- Custos: Opus 5.5 a 4 USD / 20 USD por milhão de tokens (entrada/saída; leituras de cache a 0,20 USD) — tipicamente cêntimos por proposta. Cada pedido fica em `utilizacoes_assistente_ia` (motor, modelo, tokens, custo estimado, resultado — nunca o conteúdo); o ecrã mostra o total do mês.
- Motor interno (`regras_internas_ia`, formato do legado) com CRUD (`aux_gerir`); com `motor=auto` é tentado primeiro e não envia nada a terceiros. Corrige o legado (chave no `localStorage`, proxy sem autenticação, JSON aplicado sem validar).

**M-04 — Ajuda F1.** `ajuda.js` convertido para `src/componentes/ajuda/conteudo.json` (74 ecrãs com texto próprio; os restantes usam o do ecrã-pai ou o resumo do módulo, como o legado); painel lateral com pesquisa, botão «Ajuda» e tecla F1.

**M-05 — Operações em segundo plano.** `OperacoesProvider` no layout (painel no canto: progresso, minimizar, cancelar) com dois modos: no cliente (`executar`, ex.: recolha de todas as páginas para imprimir/exportar e, na integração, o ZIP de recibos) e no servidor (`ServicoOperacoes::despachar()` sobre `Bus::batch` + `GET /api/sistema/operacoes/{id}`, só o dono). O ZIP de recibos continua a ser gerado num pedido síncrono: passá-lo para um lote na fila exigiria guardar o ficheiro no servidor e um endpoint de descarga do resultado — fica para quando o volume o justificar.

**M-19 — Preferências no servidor.** `preferencias_utilizador` (`GET/PUT/DELETE /api/sistema/preferencias/{tipo}/{nome}`; tipos `favoritos`, `ordem_modulos`, `visoes_cubo`, `interface`): favoritos (estrela na barra, grupo no menu e no Início), ordem dos módulos no Início (arrastar e largar, Alt+↑/↓), visões guardadas da Análise Dinâmica. No legado ficava tudo no `localStorage`.

### Integração (esquema, permissões e dados)
- **Esquema:** as tabelas e colunas novas passaram das migrações avulsas dos agentes para `ferramentas/gerador/esquema_extra.mjs` (`TABELAS_NOVAS`: `mesas_pos`, `contas_mesa_pos`, `configuracoes_rh`, `rascunhos_reconciliacao`, `cambios_bai_pendentes`, `execucoes_cambios_bai`, `tokens_bi`, `utilizacoes_assistente_ia`, `preferencias_utilizador`; `COLUNAS_NOVAS`: `vendas.arredondamento_agt`, `recibos_venda.tipo_recibo`, `itens_recibo_venda.numero_lan_contabilizacao/data_alocacao`, `abates_vendas_ativos.taxa_iva/valor_iva/conta_iva`, `itens_venda.acerto_cmv_kz`; únicos, CHECK, índices e cascatas). O gerador passou a aceitar, nas colunas de `TABELAS_NOVAS`, um 4.º elemento `{ fk, padrao, nulo }`. As migrações de esquema dos agentes foram apagadas; os models passaram ao padrão Base/concreto (incluindo `ConfiguracaoRH`, antes escrito à mão). As FKs seguem a regra do gerador: RESTRICT, CASCADE só em `mesas_pos.terminal_pos_id` e `preferencias_utilizador.utilizador_id`.
- **Permissões e configuração depois da ETL:** numa base nova as migrações de dados (`rh_tabela_irt_gerir`, `vendas_alterar_preco`, `cambio_manual_fora_tolerancia`, contas 6621/7621) correm antes de a ETL carregar perfis, empresas e planos. A lógica está em `ServicoPermissoesNovas` (usado pelas migrações) e a migração do legado aplica-a de novo depois do COMMIT (`ServicoMigracaoLegado::aplicarPosCarga`, resultado em `relatorio.pos_carga`; repetível com `php artisan erp:migracao:pos-carga`). Só acrescenta chaves em falta a perfis v2; nunca retira.

### Por fazer
- **G1:** lacunas BAIXA da contabilidade e do POS de armazém (pré-visualizações de lançamentos, estornos em lote na contabilidade, talão e visto no picking, código de barras no inventário).
- **G2:** contrato de trabalho em PDF e grupos A/B nos mapas de IRT/INSS (resto de M-13); cópia de tabelas e mapeamentos de salários entre empresas (baixa).
- **G3:** modelos de importação de lançamentos, extracto e câmbios; atalhos (documento de origem, liquidar, processo do documento); colunas AV%/AH% (baixa).
- **G4:** ZIP de recibos como lote na fila (M-05, acima); passagem opcional do cliente da IA ao SDK oficial; `erp:instalar`, modo Zen, registo de navegação e imagens dos produtos (baixa).
- **Integração:** os ficheiros de rotas da ronda (`routes/api/ronda2_g{1,2,3}.php`) mantêm o nome do grupo; podem ser renomeados por módulo numa limpeza futura.

### Verificação
- backend: 414 testes PHPUnit (6 934 asserções), `pint --test` limpo; frontend: 399 testes Vitest, `tsc` (aplicação e E2E) sem erros. O build e os E2E correm depois de recarregar as bases (`migrate:fresh` + ETL do backup real).

## ADR-069 — Fluxos e gráficos do legado, vistas linhas/grade, simulações, recibos em 2 vias, Redis e OWASP

**Contexto.** Depois da ronda 2 (ADR-068), quatro agentes trabalharam em paralelo, com ficheiros exclusivos — **FLX** (fluxos e gráficos), **MOD** (vistas), **SIM** (simulações e impressão) e **RED** (Redis e segurança) — e um quinto fez a integração (patches pendentes, CI, documentação e verificação). Pedidos do utilizador de 2026-10: fluxos com a linguagem visual do sistema antigo, «todas as vistas de cadastro/edição com 2 modos — grade ou linha», simulações antes de gravar, recibos com original e cópia, orientação da página escolhida por quem imprime, o plano de optimização do Redis e uma auditoria OWASP.

### Fluxos de processos e símbolos do legado (FLX)
- O Fluxo de Processos (`modulos/geral/FluxoProcessos.tsx`) foi reescrito na linguagem visual de `js/fluxo_processos.js`, `fluxo_tabela.js` e `fluxo_narrativa.js`: pastilha de estado com ícone, mini-progresso por etapa, diagrama de etapas com nós e linhas de ligação, detalhe da etapa (factos, pendências, acções), narrativa, funil pela etapa actual e legenda. Peças comuns em `src/componentes/fluxos/**` (`ComponentesFluxo`, `estados`, `workflowHtml` para imprimir com cores).
- **Símbolos:** os ícones Font Awesome do legado (nomes da versão 5 usados em `js/fluxo_*.js`) vêm do servidor por fluxo e por etapa (`CatalogoFluxos::ICONES`, 14 fluxos) e são desenhados em **SVG local** (`IconeFa`, `iconesFa.ts`, desenhos do FA 6.4 livre) — sem CDN nem tipo de letra externo, compatível com a CSP `script-src 'self'`.

### Gráficos (FLX + integração)
- `src/componentes/graficos/**` afinado ao Chart.js dos painéis do legado: largura real do contentor, paleta do legado por posição, legenda em cima, rótulos sem sobreposição (inclinação/salto automático), dica com valores e total, alternância gráfico/tabela, animação discreta. As séries com significado próprio trazem a cor do legado do servidor (`Indicadores::CORES_SERIES`, ex.: vendas `#2563eb`, compras `#ea580c`, proveitos `#10b981`, custos `#ef4444`); `sem_preenchimento` para linhas sem área.
- Integração: a «Curva S» dos projectos deixou o SVG próprio e passou ao `GraficoLinhas` comum (`monetario`; cores: previsto `#94a3b8`, realizado `#ef4444`, facturado `#10b981`); na Previsão do CRM, «Valor bruto» `#93c5fd` e «Ponderado» `#1d4ed8` (a mesma família de azuis, o ponderado mais forte).
- **Agrupamento de milhares:** o pt-PT do CLDR só agrupa a partir de 5 algarismos («1000,00» ao lado de «10 000,00» na mesma coluna). `formatarKz`/`formatarNumero` (`utilitarios/formatacao.ts`) passaram a `useGrouping: 'always'` (Intl.NumberFormat v3; navegadores antigos tratam-no como `true`, o comportamento anterior; a lib ES2022 do TypeScript só conhece `boolean`, daí uma conversão documentada). O separador é um espaço inquebrável (U+00A0, ou U+202F conforme o ICU); o `numeroPt` dos E2E e o `lerNumeroPt` do Excel já ignoram ambos. Os formatadores locais de alguns ecrãs (percentagens, taxas, `pos/comum/calculos.ts`) mantêm-se — candidatos a passar ao utilitário comum.

### Vistas «Linhas» / «Grade» (MOD)
- `src/componentes/vistas/**`: `TabelaComModos` (listas locais), `GradeCartoes` (cartões derivados das **mesmas colunas** da tabela — título, subtítulo, etiquetas, valores e acções), `AlternarVista` e `useModoVista`. `TabelaApi` tem a alternância ligada por omissão (`modos={false}` para a desligar — ex.: Logs, Movimentos de stock).
- A escolha fica **por utilizador e por ecrã** numa só preferência do servidor (`interface/vistas_listas`, `{ omissao, ecras }` — uma entrada para não gastar o limite de 200 preferências por tipo), com cópia no `localStorage`. Resolução: escolha do ecrã → omissão do utilizador → omissão do ecrã → automática (grade no telemóvel, linhas a partir de 768 px).
- Aplicada aos cadastros e listas de Configurações, CRM, Estrutura, Contabilidade (tabelas auxiliares), Orçamento, Activos, RH, Tesouraria, Armazém, POS, Vendas, Projectos e Integrações.

### Simulações e pré-visualizações (SIM)
- **Nada é gravado.** Pré-visualização do lançamento antes de contabilizar (legado `showPostingPreview`, `ui_sales.js`, e «Simulação contabilística», `ui_compras_v2.js`): `GET /api/vendas/documentos/{venda}/contabilizacao/pre-visualizacao` (`vendas_fat_contabilizar`) e `GET /api/compras/faturas/{id}/contabilizacao/pre-visualizacao` (`compras_fact_contabilizar`), em `routes/api/simulacoes.php`. Usam **a mesma montagem de linhas** dos serviços de contabilização (`previsualizar()` em `ServicoContabilizacaoVendas`/`Compras`: mesmas validações e contas), sem lançamento, diário nem mudança de estado; o componente `PreVisualizacaoLancamento` mostra-as.
- Simulações no frontend sobre endpoints existentes: RH por colaborador, por período e em massa (`SimulacaoColaborador`, `SimularMassa`, `/rh/contratos/simulacao`), amortizações e revisões de projecto. Impressão com a faixa «SIMULAÇÃO» (`impressao/simulacao.ts`).

### Recibos em 2 vias e orientação escolhida pelo utilizador (SIM)
- Motor de impressão: opção `vias` — original e cópia na mesma folha, cada via com o cabeçalho da empresa, o título e o rótulo, separadas pela linha de corte; vertical = uma por cima da outra (meia folha cada), horizontal = lado a lado; o bloco nunca se parte entre folhas (reduz se preciso). Aplicado aos recibos de vencimento do RH, recibos de venda (RC e adiantamento), tesouraria e lavandaria (A4).
- Selector «Automática ▾» ao lado de Imprimir/PDF: orientação Automática/Vertical/Horizontal e papel automático/A4/A3, lembrado por documento no `localStorage` (preferência de posto, sem valor de negócio); com orientação imposta o motor ajusta papel e escala para caber em largura. Em telemóvel os botões ficam só com ícones e nomes acessíveis.

### Redis — o plano do utilizador e o que foi aplicado (RED)
| Passo do plano de optimização | Decisão | Porquê / medição |
| :--- | :--- | :--- |
| 1. `SESSION_DRIVER=cookie` | **Aplicado** (`.env.example`, `.env.prod.example`, `.env`) | A API é sem estado (Bearer Sanctum, `sanctum.guard=[]`, sem rotas web): nenhuma rota inicia sessão. Cookie cifrado com `APP_KEY`, `HttpOnly`, `SameSite=Lax` e `Secure` por omissão em produção (`config/session.php`) |
| 2. `Redis::OPT_COMPRESSION` na ligação `cache` | **Não aplicado** — substituído por compressão na aplicação | (a) a phpredis 6.3.0 da imagem não tem LZ4/ZSTD/LZF/ZLIB: a cascata proposta resolveria sempre para `NONE`, ganho zero; (b) se tivesse, comprimia também os contadores: `Cache::add('empresas:versao', 1)` + `INCRBY` falha em silêncio e a invalidação global das empresas deixava de funcionar (provado em `CompressaoCacheRedisTest`). Em vez disso, `CacheComprimida` (gzip 6, marcador `\0erpgz1:`, leitura retrocompatível, `allowed_classes=false`) só nos conjuntos > 8 KB (plano de contas, catálogo). **Medição com os dados reais (14 empresas): 3,10 MB → 0,51 MB de `used_memory` (−83 %, acima da meta de 60–75 %)**; por chave ~229 KB → ~29 KB; custo +~2 ms por leitura contra ~30 ms da BD |
| 3. TTLs mais curtos | **Aplicado** (empresas do utilizador 30 min, plano de contas 4 h, catálogo 2 h) | O TTL é só a rede de segurança — a frescura vem das invalidações depois do commit. `permissoes_utilizador` (30 min) e `taxas_cambio` (6 h) ficam documentados como **reservados** (não estão em cache) |
| 4. AOF e memória | **Aplicado** (`--auto-aof-rewrite-percentage 100`, `--auto-aof-rewrite-min-size 64mb`) | Valores iguais aos por omissão do Redis 7: explicitados, sem efeito de memória. `volatile-lru` mantido (só despeja chaves com TTL; filas e batimentos nunca) e explicado: os locks têm TTL, por isso o `maxmemory` precisa de folga (vigiar `evicted_keys` = 0) |
| Restrições (`InvalidacaoCache`, `LimpezaCache`, bases 0/1 distintas, padrão `ChaveCache`) | Respeitadas | `InvalidacaoCacheTest`, `LimpezaCacheTest` verdes |

Corrigido também o risco R8: `fk_referencias` passou a ser versionada pela última migração.

### Regras de cache
O inventário completo (chave, dono, âmbito, TTL, tamanho, invalidação), as regras de ouro e a lista de verificação para um novo `Cache::remember` estão em **`docs/arquitetura/CACHE.md`**: chave sempre com empresa/utilizador (e permissões, quando o resultado depende delas); **autorização fora da cache**; invalidação **depois do commit** (`InvalidacaoCache`); versionar em vez de varrer; nada sensível em cache; > 8 KB comprimido, > 1 MB nunca. `RegrasCacheTest` fecha o inventário (um `Cache::remember` novo fora da lista falha o teste). Na integração, `gestao:comparacao` passou a verificar o acesso às empresas **antes** do `remember` (`ServicoComparacaoEmpresas::garantirAcesso`; antes, quem perdia o acesso via a comparação até 120 s) — teste `GestaoPaineisTest::comparacao_em_cache_deixa_de_ser_vista_logo_que_o_acesso_e_retirado`, que falha sem a correcção.

### OWASP Top 10
Matriz completa, achados e testes em **`docs/arquitetura/SEGURANCA_OWASP.md`** (`OwaspControloAcessoTest` varre todas as rotas com um utilizador sem permissões; `OwaspSegurancaTest`). Achados corrigidos:
- A01: `PUT /api/rh/configuracao` com corpo vazio respondia 200 a quem não tinha permissão; `POST /api/orcamento/verificar` sem permissão e `gestao:comparacao` (integração, ver acima e abaixo).
- A02: cookie de sessão `Secure` por omissão em produção.
- A03: injecção de fórmulas nas folhas geradas no servidor — `ValorSemFormulas` como *value binder* global do PhpSpreadsheet (texto começado por `= + - @` fica texto).
- A04: limitadores `externo` (6/min: BAI, relógio) e `pesado` (30/min: importações, ZIP de recibos).
- A05: `config/cors.php` sem origens por omissão (antes valia `*`); `/api/saude` deixou de mostrar as versões exactas do PostgreSQL/Redis a anónimos.
- A07: enumeração de utilizadores por tempo de resposta — a credencial é sempre verificada contra um hash Argon2id (fictício quando o utilizador não existe).
- A08: CI com auditorias e *actions* por SHA (abaixo).
- A10: SSRF no URL do relógio biométrico — `GuardaUrlSaida` (formas numéricas, loopback/link-local/metadados, IPv4 mapeado, serviços internos, ligação presa aos IP verificados com `CURLOPT_RESOLVE`, corpo limitado a 5 MB).

### Decisões tomadas por melhor prática (integração)
- **`POST /api/orcamento/verificar` com permissão.** A simulação revela orçado e consumido de uma rubrica; passou a exigir a mesma lista *any-of* do pedido de excesso (`compras_ped_criar`, `compras_enc_criar`, `compras_fact_registar`, `teso_doc_emitir`, `lancamentos_post`, `orc_alertas_view` — `OrcamentoController::PERMISSOES_DOCUMENTOS_CONTROLADOS`). Verificado: **nenhum ecrã do frontend chama este endpoint** (o controlo corre na gravação dos documentos e o diálogo de excesso usa `pedidos-excesso`), por isso nenhum perfil perde funcionalidade; saiu da lista de excepções do `OwaspControloAcessoTest`; teste 403 em `OrcamentoControloTest`.
- **react-router 7 adiado.** `npm audit --omit=dev` mostra 2 vulnerabilidades **moderadas** do `react-router` 6 (open redirect com `\` em destinos vindos do utilizador — o frontend só navega para caminhos internos — e hidratação SSR, que não usamos). A correcção exige o 7 (mudança com quebras em todo o roteamento); fica para uma tarefa própria.
- **CI com auditorias e *actions* fixadas.** `composer audit` (falha com avisos de segurança e pacotes abandonados) e `npm audit --omit=dev --audit-level=high` (as 2 moderadas ficam no relatório sem falhar). As 6 *actions* passaram a SHA de commit com a versão em comentário (`actions/checkout` v7.0.1, `actions/cache` v6.1.0, `actions/setup-node` v7.0.0, `shivammathur/setup-php` 2.37.2, `docker/setup-buildx-action` v4.4.1, `docker/build-push-action` v7.4.0): SHAs obtidos com `gh api repos/<dono>/<repo>/commits/<tag>` das releases oficiais, iguais aos das tags maiores que o CI já usava — o comportamento não muda. ShellCheck passa a cobrir `ferramentas/testes/*.sh`.
- **Proxy de produção.** `docs/PRODUCAO.md` §5 explica como restringir `set_real_ip_from` ao endereço do reverse proxy (gateway da rede Docker ou IP do proxy) quando o servidor partilha a LAN com postos de trabalho; não foi alterado no código porque o endereço depende da instalação.

### Problema do `DirectoryIterator` no ambiente local
Na pasta do Windows montada no Docker Desktop, o `DirectoryIterator` do PHP devolve só parte das entradas de pastas grandes, de forma intermitente (`tests/Feature`: 48 de 90 na medição da integração; o RED mediu 45 de 89 e, em `app/Models`, 130 de 177), enquanto `scandir`, `glob`, o `ls` da shell e — na medição da integração — o Finder do Symfony vêem tudo. O PHPUnit descobre os testes iterando a pasta, por isso `php artisan test` localmente corria só ~44 testes e parecia verde. Contorno: **`sh ferramentas/testes/correr_backend.sh`** (lista explícita `tests/Feature/*.php tests/Unit/*.php`, expandida pela shell; argumentos extra seguem para o PHPUnit; funciona no anfitrião e dentro do contentor), documentado no README. O código da aplicação lista pastas com `scandir`/`glob` (`ChavesAgt`, `RegrasCacheTest`, `FaturacaoEletronicaTest`); não há outro uso de `DirectoryIterator`/`Finder`/`File::files` na aplicação nem nos testes. O carregamento de configuração, rotas, migrações e comandos do Laravel foi conferido pasta a pasta (Finder × `scandir`), sem diferenças. O CI e a produção (sistema de ficheiros Linux) não são afectados.

### Verificação
- backend: 450 testes PHPUnit (7 089 asserções) com a lista explícita de ficheiros (`ferramentas/testes/correr_backend.sh`), `pint --test` limpo (895 ficheiros); `composer audit` sem avisos.
- frontend: 441 testes Vitest (65 ficheiros), `tsc` (aplicação e E2E) sem erros; `npm audit --omit=dev --audit-level=high` passa (2 moderadas registadas). O build e os E2E correm a seguir, fora da integração.
