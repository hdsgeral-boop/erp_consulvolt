# Versão Actual do legado × erp_laravel — análise (2026-10-13)

**Fonte comparada:** `Versões/Versão Actual/ERP` (legado novo, mesma estrutura JS/Dexie) contra `payroll_system_web` (legado antigo, base da migração) e contra o `erp_laravel`.

**Dimensão da diferença:** o legado novo acrescenta 30 ficheiros JS e altera 49. As versões Dexie novas vão da 1268 à 1277: são 21 tabelas novas e várias tabelas existentes ganham campos. Há 5 ecrãs novos no menu e um grupo de menu novo («Método de Trabalho»).

**Conclusão:** o `erp_laravel` está em paridade com o legado **antigo**. Quase tudo o que é novo no legado novo **ainda não existe** no `erp_laravel`. O motor de cálculo salarial (`engine_v2.js`) **não mudou**: as diferenças no RH estão nos dados que entram no cálculo (quem é processado, empréstimos, horas da escala, produtividade, repartição).

| Área | Funcionalidades novas | Existe | Parcial | Falta | Detalhe |
|---|---:|---:|---:|---:|---|
| Projectos e produtividade de projectos | 26 | 0 | 6 | 20 | [02_projectos.md](02_projectos.md) |
| Activos (edifícios, inventário físico, manutenção), Método de Trabalho 5W1H, repartição por UN, cubo e relatórios de gestão | 25 | 1 | 6 | 16 (+2 N/A) | [03_activos_metodo_reparticao.md](03_activos_metodo_reparticao.md) |
| RH / salários / estrutura / portal | 18 | 2 | 7 | 9 | [04_rh_salarios.md](04_rh_salarios.md) |
| Vendas, compras, tesouraria, contabilidade, IA e núcleo | 27 | 5 | 8 | 10 (+4 N/A) | [05_vendas_compras_tesouraria_nucleo.md](05_vendas_compras_tesouraria_nucleo.md) |

## Ecrãs novos no menu
- Vendas › **Séries de Numeração** (`vendas_series`)
- RH › **Empréstimos** (`rh_emprestimos`)
- **Método de Trabalho** › Registo de Assuntos 5W1H (`mt_assuntos`): módulo novo
- Tesouraria › **Cartas de Pagamento** (`teso_cartas`)
- Activos › **Edifícios e Locais** (`activos_edificios`); a Manutenção ganha planos, ordens e requisições
- Tesouraria › Folha de Caixa: já existe no `erp_laravel`

## Funcionalidades maiores em falta
1. **Repartição por unidades de negócio:** 6 critérios; alocação em colaboradores, pedidos, facturas, vendas e recibos; UN/CC por linha; repartição na contabilização de salários, compras e vendas; botão «Repartir linha»; Balanço e DR por UN.
2. **Contas de cliente e de fornecedor no mesmo terceiro** (31 e 32/37.0), com contas bancárias dos terceiros e dados bancários nas contas 42/43. A tesouraria passa a movimentar só 42/43 e o numerário só pela Folha de Caixa.
3. **RH:**
   - escala do mês e horário do contrato (1 ou 2 períodos), que passam a definir as horas previstas para faltas e horas extra;
   - empréstimos (pedido no portal → chefia → RH → plano francês → desconto no salário);
   - suspensão e cessação do contrato (Lei 12/23, com compensação dos arts. 307.º, 308.º e 310.º);
   - portal autónomo.
4. **Projectos:**
   - metas por quantidade e execução medida;
   - dependências FS/SS/FF;
   - mapa de quantidades;
   - orçamento mapeado pelas quantidades;
   - carga da equipa e ordem de trabalho;
   - separador Execução e ponte para a Produtividade do RH.
5. **Activos:** edifícios › andares › portas, inventário físico, e manutenção com planos (por tempo ou por contador), ordens e requisições ao armazém.
6. **Método de Trabalho 5W1H:** aprovação pela chefia no organigrama; dá origem a um pedido de compra ou a um projecto.
7. **Tesouraria › Cartas de pagamento** a partir de um pagamento gravado, para terceiros e salários, com modelos de texto.
8. **Assistente IA com regras em ficheiro JSON.** A chave fica **sempre no servidor**: não se copia a chave guardada no navegador do legado novo.
9. **Pequenas:**
   - número inicial da série;
   - cancelar os recebimentos antes de emitir a NC;
   - motivo na anulação;
   - rótulos «Anular»;
   - tema escuro (tela nocturna);
   - aspecto do menu (completo, só ícones, só nomes);
   - busca dentro do menu;
   - textos novos de ajuda.

## Plano proposto (por dependências)
| Fase | Conteúdo | Porquê primeiro |
|---|---|---|
| A | Contas por papel no terceiro, contas bancárias, dados bancários 42/43, tesouraria só por bancos, número inicial da série, cancelar recebimentos antes da NC, motivo e rótulos «Anular» | Base contabilística de que dependem cartas, fluxos e importação |
| B | RH: suspensão e cessação → horário e escala → apuramento → empréstimos → portal (separadores e portal autónomo) | Muda quem é processado e as horas da folha |
| C | Repartição por UN (critérios → alocação → UN/CC na linha → contabilização → repartir linha → Balanço/DR por UN), incluindo a repartição do custo salarial | Transversal a vendas, compras, salários e relatórios |
| D | Tesouraria › Cartas de pagamento (terceiros + salários) | Depende das fases A e C |
| E | Projectos: esquema (calendário, metas, `concluida_em`, execuções) → execução → dependências → orçamento por quantidades → Execução e ponte para o RH → Gantt, mapa e carga | Bloco grande, mas autónomo |
| F | Activos: edifícios e inventário físico → manutenção (planos, ordens, requisições) | Depende de compras e stock, que já existem |
| G | Método de Trabalho 5W1H | Depende de projectos (E) e do processo de compras |
| H | IA com regras em ficheiro; núcleo (tema escuro, aspecto e busca no menu, ajuda); cubo (dimensões novas, cubo de rubricas) | Independentes; menor risco |

**Migração de dados:** o `esquema.json` e o `ServicoMigracaoLegado` foram feitos a partir do backup **antigo**. Cada fase tem de acrescentar ao ETL as tabelas e os campos novos do Dexie (versões 1268 a 1277) e as conversões dos formatos antigos (`mapa_qtd`, `account_code` → conta de cliente / conta de fornecedor, `team_member_id`).

**Decisões em aberto para o utilizador:**
- Cartas de pagamento: nascem de um pagamento já gravado (legado novo) ou geram o pagamento (`erp_laravel` actual)?
- Cubo: expor o NIF de clientes, fornecedores e colaboradores como dimensão?
- Assistente IA: confirmar que a chave fica no servidor. O legado novo guarda-a no navegador, o que não é seguro.
