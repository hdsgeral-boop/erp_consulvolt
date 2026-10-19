# Manual de Gestão SaaS, Gateways de Pagamento e Notificações

## 1. Gestão de Activação / Desligamento do SaaS & RBAC

### 1.1 Modelo RBAC e Segurança
- **Nível Plataforma (Superadministrador)**:
  - Protegido pelo middleware backend `ExigirPlataforma`.
  - Acesso exclusivo às rotas `/api/plataforma/*`.
  - Menu no Frontend: **Sistema > Plataforma SaaS > Gestão de Activação SaaS** e **Plataforma: Definições**.
- **Nível Empresa (Administrador do Cliente)**:
  - Protegido por `ExigirAdministradorCliente` e isolamento RLS.
  - Acesso à renovação da própria licença em **Sistema > Plataforma SaaS > Licença e plano**.
  - Acesso às notificações e contador no cabeçalho.

### 1.2 Funcionalidades do Ecrã «Gestão de Activação SaaS» (`/config/plataforma/saas-gestao`)
- **Controlos Globais da Plataforma**:
  - **Modo SaaS Activo**: Activa ou desliga a infraestrutura SaaS para novas adesões e acessos.
  - **Forçar Validação de Licenças**: Quando ligado, bloqueia empresas cuja licença tenha expirado. Quando desligado, opera em modo de contingência/manutenção.
  - **Dias de Teste Padrão**: Define a quantidade de dias concedida a uma nova empresa criada no ERP (ex: 15, 30 ou 60 dias).
- **Gestão Individual por Empresa**:
  - Lista em tempo real de todas as empresas e respectivos tenants.
  - Visualização de estado (`ativa`, `em_teste`, `suspensa`, `expirada`, `vitalicia`), dias restantes e cobertura RLS.
  - **Acções Rápidas por Empresa**:
    - **Adicionar Dias**: Extensão rápida de +30, +90 ou +365 dias à licença atual.
    - **Tornar Vitalícia**: Concede licença sem data de expiração (`licenca_fim = null`).
    - **Suspender / Reactivar**: Desativa temporariamente o acesso da empresa mantendo os dados preservados.
    - **Revogar Licença**: Expira imediatamente a licença da empresa.
    - **Modal de Edição Detalhada**: Permite ajustar data de início, data de término, modalidade (`plataforma_ativa`, `em_teste`) e notas de auditoria.

---

## 2. Métodos de Pagamento e Gateways (Referência Multicaixa e Express)

### 2.1 Arquitectura e Preparação para API Real
O sistema foi concebido segundo o padrão *Strategy/Gateway*, permitindo alternar de modo sandbox/simulação para a **API Real** (EMIS, AppyPay, ProxyPay, BFA Net, etc.) sem alterar código:
- [GatewayReferenciaMulticaixa.php](file:///c:/xampp/htdocs/ERP_CONSULVOLT_MEU/erp_laravel/backend/app/Services/Pagamentos/GatewayReferenciaMulticaixa.php): Gera Entidade e Referência de 9 dígitos. Quando as credenciais reais forem preenchidas, basta descomentar a chamada HTTP à API da EMIS/Parceiro.
- [GatewayMulticaixaExpress.php](file:///c:/xampp/htdocs/ERP_CONSULVOLT_MEU/erp_laravel/backend/app/Services/Pagamentos/GatewayMulticaixaExpress.php): Envia solicitação de débito direto (Push Notification) ao número de telemóvel do cliente associado ao Multicaixa Express.
- [ProcessadorPagamentos.php](file:///c:/xampp/htdocs/ERP_CONSULVOLT_MEU/erp_laravel/backend/app/Services/Pagamentos/ProcessadorPagamentos.php): Orquestra as transações, valida webhooks e efectua a extensão imediata da licença da empresa, gerando recibo e notificação no sino.

### 2.2 Configuração dos Gateways no Frontend
Aceda a **Sistema > Plataforma SaaS > Plataforma: Definições** no separador **Gateways de Pagamento & Finanças**:
1. **Dados da Empresa / Plataforma**:
   - Razão Social / Nome do Titular.
   - NIF da Empresa.
   - Banco e IBAN Angolano (AO06...).
2. **Pagamento por Referência Multicaixa**:
   - Activar Gateway (Sim/Não).
   - Modo Sandbox / Testes (Sim/Não).
   - Entidade Multicaixa (ex: 00123).
   - Subentidade Multicaixa.
   - Chave de API / Token da EMIS.
   - Endpoint da API do Gateway.
3. **Multicaixa Express (MCX Express)**:
   - Activar Gateway (Sim/Não).
   - Chave de API / Client Secret.
   - Segredo do Webhook (para assinatura HMAC).
   - Endpoint da API MCX.
   - Tempo Limite do Prompt (segundos).

### 2.3 Fluxo de Compra e Renovação pelo Cliente
No ecrã **Licença e plano** (`/config/licenca`), o cliente clica em **«Subscrever Plano»** ou **«Renovar Licença»**:
- Seleciona o ciclo (Mensal ou Anual com 10% de desconto).
- Escolhe o método de pagamento:
  1. **Multicaixa Express**: Introduz o número de telemóvel (+244 9XX XXX XXX) e recebe a notificação no seu telemóvel para aprovar com o PIN.
  2. **Referência Multicaixa**: Recebe no ecrã a Entidade, Referência e Valor exacto para pagar no Multicaixa Físico ou Internet Banking.
  3. **Transferência Bancária**: Consulta o IBAN da plataforma e anexa o comprovativo bancário.
- **Activação Instantânea**: Quando o gateway notifica o webhook (`/api/pagamentos/webhook/{gateway}`), a licença da empresa é automaticamente estendida pelo número de dias correspondente ao ciclo contratado.

---

## 3. Componentes no Cabeçalho (Header)

### 3.1 Contador de Tempo de Licença (`ContadorLicencaHeader.tsx`)
Localizado na barra de ferramentas superior da aplicação:
- **Visual e Estados**:
  - **Licença Vitalícia**: Badge azul com ícone de escudo e texto "Licença Vitalícia".
  - **Mais de 15 dias**: Badge verde com texto "X dias restantes".
  - **15 dias ou menos (Alerta)**: Badge amarelo de aviso avisando que a licença expira em breve.
  - **Expirada**: Badge vermelho com alerta de bloqueio.
- **Interacção**:
  - Ao clicar no badge, abre-se uma janela modal detalhando:
    - Plano comercial subscrito.
    - Data de início e data de fim da vigência.
    - Estado actual e tipo de licença.
    - Botão directo **«Renovar / Alterar Licença»** que navega para o ecrã de subscrições.

### 3.2 Sino de Notificações Isolado por Empresa (`NotificacoesSinoHeader.tsx`)
- **Isolamento Multi-Tenant**:
  - As notificações são gravadas na tabela `notificacoes_sistema`, protegida por **Row-Level Security (RLS)** através da coluna `empresa_id`.
  - Usuários da **Empresa A** nunca têm acesso aos alertas e eventos de utilizadores da **Empresa B**.
- **Eventos Registados Automaticamente**:
  - Operações financeiras e comerciais (emissão de faturas, anulação de recibos).
  - Alterações de estado do sistema e renovações de subscrições.
  - Modificações cadastrais relevantes realizadas por outros colaboradores da mesma empresa.
- **Experiência do Utilizador (UX)**:
  - Contador numérico vermelho indicando notificações não lidas.
  - Painel suspenso (Popover) com separadores: **Todas** e **Não lidas**.
  - Distinção por cores e ícones (Informação, Sucesso, Aviso, Erro).
  - Indicação do autor da operação e data relativa amigável (ex: "há 5 minutos", "ontem").
  - Botão **«Marcar todas como lidas»**.
  - Clique na notificação abre directamente o ecrã ou documento correspondente e marca a notificação como lida.
