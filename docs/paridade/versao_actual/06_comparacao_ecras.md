# Comparação ecrã a ecrã no Chrome — Versão Actual (8091) × erp_laravel (8080)

**Data:** 2026-10-13. **Empresa:** EMPRESA PARA TESTE DE QUALIDADE (id 18) nos três sistemas.

**Dados:**
- **8091:** o backup de 13/10 (`wstb_payroll_backup_2026-10-13.json`, Dexie 1277, 172 tabelas, 221 909 linhas), restaurado pelo próprio ecrã depois da correcção do ciclo em `manutencao.js`.
- **8080:** a migração da cópia de 22/09.

Os números diferem porque os dados de 13/10 são mais recentes. Compara-se a **estrutura**: ecrãs, campos, colunas e acções.

## Correcção feita no legado novo para o restauro funcionar
`Versões/Versão Actual/ERP/js/manutencao.js`:
- **Problema:** a guarda da Manutenção de dados e a guarda das permissões (`permissoes.js`, que nesta versão passou a proteger `importDatabase`, `wipeDatabase` e `deleteCompany`) chamavam-se uma à outra. A execução aprovada falhava com «Maximum call stack size exceeded».
- **Correcção:** o embrulho de permissões fica por fora da guarda e nunca substitui a função verdadeira. A versão do script foi actualizada em `index_arrumado.html` e `portal.html`.
- **No erp_laravel:** não se aplica. As permissões e a manutenção são verificadas no servidor.

## Resultado por ecrã

| Ecrã | Versão Actual (8091) | erp_laravel (8080) | Estado |
|---|---|---|---|
| Vendas › **Séries** | Separador próprio em Gestão de Vendas. Cartões FT / FR / NC com a série actual. «+ Nova série». Tabela: Tipo, Ano, Origem, Código, Início n.º, Próximo documento, Emitidos, Último doc., Estado, AGT | As séries só existem dentro de «Facturação electrónica», sem número inicial | PARCIAL |
| Vendas › Facturação › nova factura | Bloco **«Unidades desta venda»** (critério de repartição, «+ Juntar unidade»). Colunas **UN. Negócio** e **C. Custo** em cada linha | Só UN e CC no cabeçalho | FALTA |
| Contabilidade › Terceiros | Lista: **Tipo** (ex.: «Fornecedor + Cliente»), **Conta Cliente**, **Conta Fornecedor** («por definir» quando falta), **Banco**. Formulário: **Contas bancárias** com «Adicionar conta bancária» | Uma só «Conta contabilística». Lista: ID, NIF, Nome, Tipo. Sem contas bancárias | FALTA |
| Contabilidade › Terceiros — **endereço** | Rua, N.º Porta, Bairro, Município, Província, País (**já existia no legado antigo**) | Um só campo «Endereço» | **Diferença antiga por corrigir** |
| Contabilidade › Unidades de Negócio | Cartão novo **«Critérios de repartição»**: Código, Nome, Forma de repartir, Como obter o peso, «+ Novo critério» | Sem critérios | FALTA |
| Tesouraria › **Cartas de Pagamento** (ecrã novo) | Botões «Cartas emitidas» e «Modelo de texto». Lista: Número, Data, Tipo, Banco/Conta, Movimento, Benef., Total, Estado. Imprimir e anular | Só cartas de salários, dentro do RH | FALTA (PARCIAL no RH) |
| RH › **Empréstimos** (ecrã novo) | Aviso «Por configurar» (conta de empréstimos, contrapartida, diário, rubrica de desconto). Botões «Empréstimos» e «Configurações». Os pedidos chegam do Portal | Não existe | FALTA |
| RH › Efectividade › Resumo do mês | Cartões: dias úteis, colaboradores, horas extra, horas de falta, com faltas por justificar. Colunas **Atraso**, **Saída antecipada**, **Compensadas**, **Faltas (n)**, **Avisos** (com as mensagens legais: 5 faltas injustificadas no mês / 12 no ano). Estado do mês junto ao mês | Menos colunas; sem avisos nem atrasos | PARCIAL |
| RH › Efectividade › **Escala do mês** (separador novo) | Cartões: Colaboradores, Com escala, Escalas prioritárias, Turnos previstos, Sem escala. «Criar ou alterar escala»: **Migrar do contrato**, **Inserir manualmente**, **Copiar escala**, **Importar Excel**, **Limpar**. Período e colaboradores. Grelha do mês | Não existe | FALTA |
| RH › Contratos | Botão **«Produtividade em massa»**. Acções novas em cada contrato: **Suspender o contrato** e **Cessar o contrato de trabalho**. Coluna «Dias · Horas» | Sem suspender nem cessar; massa só para rubricas | FALTA |
| RH › Produtividade | Separador novo **«Execução de projectos»**. Coluna «Origem» nos registos | Sem o separador | FALTA |
| RH › Funções | Colunas **Objectivo Geral do Cargo**, **Principais Responsabilidades**, **Cargos na estrutura orgânica** | ID, Nome, Descrição | PARCIAL |
| Projectos › Planeamento | Vista **«Mapa de quantidades»**. Botões **«Tarefas por quantidade»** e **«Folha de medição»**. Colunas **Precedência** e **Estrutura orgânica**. Ordenação automática por data | WBS, Gantt e Kanban, por ordem manual | FALTA |
| Projectos › **Execução** (separador novo) | Período de/até, «Folha de medição», **«Enviar para Produtividade (RH)»**. Cartões de horas, quantidades e tarefas concluídas. Por colaborador, folhas de horas, quantidades medidas | Não existe | FALTA |
| Activos › **Edifícios e Locais** (separador novo) | «Folhas de inventário», «+ Novo Edifício/Local», aviso de bens sem porta atribuída | Não existe | FALTA |
| Activos › Manutenções | Subseparadores **Ordens de manutenção**, **Planos**, **Requisições ao armazém**, Histórico. Filtros por estado. «Abrir ordens em Planos» | Só o histórico | FALTA |
| **Método de Trabalho** › Registo de Assuntos 5W1H (grupo de menu novo) | Vistas Todos / Por departamento / Por cliente / Por responsável / Por prazo / Por estado. «+ Novo assunto» | Não existe | FALTA |
| Menu e barra superior | Caixa **«Procurar operação…»** no topo do menu. Botões de **aspecto do menu** (completo, só símbolos, só nomes). Botão **«Tela nocturna»** | Pesquisa Ctrl+K em janela; menu só recolhe; sem tema escuro. («Modo Responsivo» foi retirado do erp_laravel por decisão do utilizador.) | PARCIAL / FALTA |

## Notas para a construção
- Tudo o que está FALTA ou PARCIAL coincide com os relatórios 02 a 05. O plano por fases está em [01_RESUMO.md](01_RESUMO.md).
- **Diferença antiga a corrigir já:** o endereço estruturado dos terceiros.
- A migração definitiva passa a usar o backup de **13/10** (Dexie 1277). As 18 tabelas novas e os 5 esquemas alterados têm de entrar no ETL (`esquema.json`, `mapa_de_para.json`, `ServicoMigracaoLegado`) à medida que cada fase cria as tabelas.
