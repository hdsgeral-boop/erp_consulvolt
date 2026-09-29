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
