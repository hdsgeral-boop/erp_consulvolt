import { useState, useRef, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  CheckCircleFilled,
  ShopOutlined,
  CloudUploadOutlined,
  CustomerServiceOutlined,
  RightOutlined,
  LeftOutlined,
  TeamOutlined,
  BankOutlined,
  DatabaseOutlined,
  FileProtectOutlined,
  GlobalOutlined,
  EnvironmentOutlined,
  PhoneOutlined,
  MailOutlined,
  LinkedinFilled,
  MinusOutlined,
  MenuOutlined,
  CloseOutlined
} from '@ant-design/icons';
import { obter } from '@/api/cliente';
import { useQuery } from '@tanstack/react-query';
import './publico.css';

type Idioma = 'PT' | 'EN';

type PlanoApi = {
  id: number;
  codigo: string;
  nome: string;
  descricao: string;
  preco_mensal: number;
  max_utilizadores: number;
  max_empresas: number;
  ativo: boolean;
  modulos: string[];
};

// Dados de contacto reais da Consulvolt Angola (consulvolt.pt / consulvolt.ao)
const WHATSAPP_NUMERO = '244923012143';
const WHATSAPP_MENSAGEM = 'Olá Consulvolt! Gostaria de informações sobre o ERP e os planos disponíveis.';
const WHATSAPP_URL = `https://wa.me/${WHATSAPP_NUMERO}?text=${encodeURIComponent(WHATSAPP_MENSAGEM)}`;
const LINKEDIN_URL = 'https://linkedin.com/company/consulvolt-angola';
const EMAIL_CONTACTO = 'global@consulvolt.pt';
const TELEFONE_1 = '+244 923 012 143';
const TELEFONE_2 = '+244 923 692 943';
/** Localização real "Consulvolt Soluções" no Google Maps (Plus Code 352V+42 Luanda, Angola). */
const MORADA_SEDE = 'Urbanização Lar do Patriota, Luanda, Angola · 352V+42';
const MAPA_LAT = -8.9496337;
const MAPA_LNG = 13.1925567;
const MAPA_EMBED_URL = 'https://www.google.com/maps?q=' + encodeURIComponent(`${MAPA_LAT},${MAPA_LNG}(Consulvolt Soluções)`) + '&output=embed';
const MAPA_DIRECOES_URL = `https://www.google.com/maps/dir/?api=1&destination=${MAPA_LAT},${MAPA_LNG}`;

/** Descrição comercial de cada módulo do catálogo (erp.planos_catalogo.modulos no backend) — a chave é o código real. */
const DESCRICOES_MODULOS: Record<string, string> = {
  vendas: 'Facturação Certificada AGT e Vendas',
  pos: 'Ponto de Venda (POS) Web e Móvel',
  stock: 'Controlo de Stocks e Armazéns',
  teso: 'Tesouraria e Conciliação Bancária',
  compras: 'Compras e Gestão de Fornecedores',
  contab: 'Contabilidade Geral (PGC) e Plano de Contas',
  rh: 'Recursos Humanos e Processamento Salarial',
  activos: 'Activos Fixos e Imobilizado',
  crm: 'CRM Comercial',
  projectos: 'Gestão de Projectos',
  orcamento: 'Gestão Orçamental',
};

const DESCRICOES_MODULOS_EN: Record<string, string> = {
  vendas: 'AGT-Certified Invoicing & Sales',
  pos: 'Web & Mobile Point of Sale (POS)',
  stock: 'Stock & Warehouse Control',
  teso: 'Treasury & Bank Reconciliation',
  compras: 'Purchasing & Supplier Management',
  contab: 'General Accounting (PGC) & Chart of Accounts',
  rh: 'Human Resources & Payroll Processing',
  activos: 'Fixed Assets',
  crm: 'Sales CRM',
  projectos: 'Project Management',
  orcamento: 'Budget Management',
};

/** Ordem de exibição da tabela de comparação — os mesmos códigos que o backend usa para bloquear o acesso por plano. */
const ORDEM_MODULOS = ['vendas', 'pos', 'stock', 'teso', 'compras', 'contab', 'rh', 'activos', 'crm', 'projectos', 'orcamento'];

const PLANOS_SISTEMA_PADRAO: PlanoApi[] = [
  {
    id: 1,
    codigo: 'basico',
    nome: 'Plano Básico',
    descricao: 'Para profissionais liberais e microempresas. Gestão essencial de faturação certificada AGT, ponto de venda (POS), stock e tesouraria.',
    preco_mensal: 9500,
    max_utilizadores: 3,
    max_empresas: 1,
    ativo: true,
    modulos: ['vendas', 'pos', 'stock', 'teso'],
  },
  {
    id: 2,
    codigo: 'profissional',
    nome: 'Plano Profissional',
    descricao: 'Para pequenas e médias empresas. Gestão integrada com compras, contabilidade geral, processamento salarial e RH.',
    preco_mensal: 15000,
    max_utilizadores: 10,
    max_empresas: 3,
    ativo: true,
    modulos: ['vendas', 'pos', 'compras', 'stock', 'teso', 'contab', 'rh'],
  },
  {
    id: 3,
    codigo: 'empresarial',
    nome: 'Plano Empresarial',
    descricao: 'Para médias e grandes empresas ou grupos empresariais. Todos os módulos do ERP disponíveis (projetos, ativos, orçamento e CRM) com limites alargados.',
    preco_mensal: 21000,
    max_utilizadores: 50,
    max_empresas: 20,
    ativo: true,
    modulos: ['vendas', 'pos', 'compras', 'stock', 'teso', 'contab', 'rh', 'activos', 'crm', 'projectos', 'orcamento'],
  }
];

/** Textos estáticos da Landing Page, em português e inglês — o selector de idioma troca este objecto inteiro. */
const TRADUCOES_PT = {
  alterarIdioma: 'Alterar idioma',
  abrirMenu: 'Abrir menu',
  fecharMenu: 'Fechar menu',
  nav: { modulos: 'Módulos ERP', solucoes: 'Soluções', precos: 'Planos & Preços', vantagens: 'Vantagens', ajuda: 'Ajuda' },
  login: 'Login',
  experimenteGratis: 'Experimente Grátis',
  hero: {
    tituloPre: 'Software de Gestão Empresarial, ',
    tituloDestaque: 'Facturação Certificada',
    tituloPos: ' e POS',
    descricao: 'Controle facturação AGT, recursos humanos e salários, contabilidade, compras, stocks e tesouraria num ERP moderno, completo e intuitivo para o mercado angolano.',
    ctaDias: (d: number) => `Experimente Grátis ${d} Dias`,
    subtexto: 'Sem necessidade de cartão de crédito · Ativação imediata',
    imgAlt: 'Ecrã de Início do Consulvolt ERP aberto na empresa Consulvolt',
  },
  modulosSecao: {
    titulo: 'Tudo o que a sua empresa precisa num único sistema integrado',
    tabs: { faturacao: 'Facturação & POS', rh: 'Recursos Humanos & Salários', contabilidade: 'Contabilidade & Tesouraria', stocks: 'Compras & Armazéns' },
    conteudo: {
      faturacao: {
        titulo: 'Facturação Certificada AGT e Ponto de Venda de Alta Produtividade',
        bullets: [
          'Emissão rápida de Faturas, Faturas-Recibo, Proformas e Guias de Transporte',
          'POS ágil para retalho, restauração e serviços com suporte a gavetas de dinheiro e leitores de código de barras',
          'Assinatura digital RSA, código QR de validação e exportação de SAF-T (AO)',
          'Contas correntes de clientes, gestão de crédito e histórico de liquidações',
        ],
        idealPara: 'Ideal para:',
        pills: ['Lojas de Retalho', 'Supermercados', 'Restaurantes & Bares', 'Prestadores de Serviços'],
      },
      rh: {
        titulo: 'Gestão Completa de Colaboradores e Processamento Salarial',
        bullets: [
          'Fichas cadastrais completas, contratos de trabalho, funções e departamentos',
          'Processamento mensal de salários com cálculo automático de IRT e INSS de Angola',
          'Emissão de recibos de vencimento, mapa de transferências bancárias e folha salarial',
          'Portal autónomo do colaborador para consulta de recibos e marcação de férias',
        ],
        idealPara: 'Ideal para:',
        pills: ['Departamentos de RH', 'Empresas com Equipas em Turnos', 'Escritórios Corporativos'],
      },
      contabilidade: {
        titulo: 'Contabilidade Geral (PGC) e Gestão de Tesouraria Integrada',
        bullets: [
          'Plano Geral de Contas de Angola totalmente pré-configurado e personalizável',
          'Lançamentos em diários automáticos a partir das vendas, compras e salários',
          'Balancetes analíticos, demonstração de resultados e balanço patrimonial',
          'Controlo de contas bancárias, caixas, transferências e conciliação bancária',
        ],
        idealPara: 'Ideal para:',
        pills: ['Gabinetes de Contabilidade', 'Diretores Financeiros', 'Médias e Grandes Empresas'],
      },
      stocks: {
        titulo: 'Compras a Fornecedores, Gestão de Armazéns e Lotes',
        bullets: [
          'Encomendas a fornecedores, registo de faturas de compra e notas de crédito',
          'Gestão de múltiplos armazéns com transferências internas e guias de remessa',
          'Alertas automáticos de rutura de stock e ponto de encomenda',
          'Rastreio por lotes, números de série e datas de validade',
        ],
        idealPara: 'Ideal para:',
        pills: ['Distribuidores e Grossistas', 'Armazéns Farmacêuticos', 'Indústria e Construção'],
      },
    },
    destaques: [
      { titulo: 'Vendas & POS', desc: 'Faturação certificada em conformidade com a AGT.' },
      { titulo: 'RH & Salários', desc: 'Processamento salarial, IRT, INSS e colaboradores.' },
      { titulo: 'Contabilidade', desc: 'Plano Geral de Contas, diários e balancetes.' },
      { titulo: 'Stocks & Compras', desc: 'Gestão multi-armazém, lotes e fornecedores.' },
    ],
  },
  metrics: {
    titulo: 'A plataforma de gestão corporativa de confiança para a sua empresa',
    descricao: 'Centenas de organizações em Angola utilizam o Consulvolt ERP todos os dias para simplificar as suas operações fiscais, comerciais e administrativas com rigor e segurança.',
    cta: 'Criar Conta Gratuita',
    bloco1: 'Conforme com a regulamentação fiscal e normas da AGT',
    bloco2: (d: number) => `${d} Dias`,
    bloco2desc: 'Período de demonstração gratuito e sem qualquer compromisso',
    bloco3: '7 Módulos',
    bloco3desc: 'Facturação, POS, RH, Contabilidade, Stocks, Tesouraria e Activos',
  },
  why: {
    eyebrow: 'PORQUÊ A CONSULVOLT?',
    titulo: 'A tecnologia mais sólida e eficiente para impulsionar o seu negócio',
    motivos: [
      { titulo: 'Certificação Fiscal AGT', desc: 'Emissão de faturas, recibos e notas de crédito com assinatura eletrónica, hash de segurança e exportação automática do ficheiro SAF-T (AO) conforme a lei angolana.' },
      { titulo: 'ERP Integrado 360°', desc: 'Facturação, POS, RH com folha salarial, contabilidade geral PGC, tesouraria, compras, armazéns e imobilizado num único ecossistema coordenado.' },
      { titulo: 'Suporte Local Especializado', desc: 'Equipa técnica sediada em Angola pronta para prestar assistência ágil e contínua em português por chat, telefone e acesso remoto.' },
      { titulo: 'Tecnologia 100% Cloud Segura', desc: 'Aceda no computador, tablet ou smartphone a partir de qualquer local com cópias de segurança automáticas e encriptação avançada.' },
    ],
  },
  pricing: {
    titulo: 'Planos transparentes e ajustados à dimensão da sua empresa',
    descricao: (d: number) => `Comece agora a gerir o seu negócio com ${d} dias grátis. Os valores indicados refletem a configuração real do sistema.`,
    maisEscolhido: 'Mais Escolhido',
    porMes: '/ Mês',
    ctaDias: (d: number) => `Experimentar ${d} Dias Grátis`,
    tabelaModulo: 'Módulo',
    rodape: 'Os preços indicados são em Kwanzas (AOA) e incluem todas as atualizações fiscais e legais da AGT.',
  },
  testemunhos: {
    titulo: 'O que dizem os nossos clientes em Angola?',
    descricao: 'Conheça os testemunhos de gestores e empresários que confiam diariamente no Consulvolt ERP.',
    anterior: 'Página anterior de testemunhos',
    proxima: 'Próxima página de testemunhos',
    clienteVerificado: 'Cliente Verificado',
    lista: [
      {
        nome: 'Raquel Lacerda',
        empresa: 'Centro Médico & Bem-estar · Luanda',
        texto: 'O Consulvolt foi a viragem fundamental para a organização da nossa clínica. Controlamos a faturação certificada pela AGT, recibos e o processamento de salários da equipa médica sem complicações.',
        foto: 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Augusto Fernandes',
        empresa: 'Grupo Varejo & Distribuição · Talatona',
        texto: 'Precisávamos de um ERP com stock multi-loja e contabilidade em tempo real. A rapidez na emissão de faturas no POS e a conciliação bancária poupam dezenas de horas ao nosso departamento financeiro.',
        foto: 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Carlos Alberto',
        empresa: 'Restaurante & Hotelaria Marginal · Ilha do Cabo',
        texto: 'O sistema é extremamente robusto. Nos picos de atendimento, o POS responde com velocidade absoluta e os relatórios de fecho de caixa ao fim do dia batem certo sem desvios.',
        foto: 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Helena Mendes',
        empresa: 'Mendes Engenharia & Obras, Lda',
        texto: 'A gestão de compras, fornecedores e centros de custo em obras deu-nos uma transparência total sobre a margem de cada projeto. Recomendo vivamente a qualquer empresa angolana.',
        foto: 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Mateus Gaspar',
        empresa: 'Logística Express Angola',
        texto: 'O módulo de ativos fixos e a exportação oficial do SAF-T funcionam com total rigor fiscal. O apoio ao cliente em Luanda está sempre presente quando necessitamos.',
        foto: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=600&q=80',
      },
    ],
  },
  faq: {
    titulo: 'Perguntas Frequentes',
    lista: [
      { pergunta: 'O Consulvolt ERP está em conformidade com as regras fiscais de Angola?', resposta: 'Sim. O sistema cumpre rigorosamente as normas tributárias da Administração Geral Tributária (AGT), gerando documentos legalmente válidos com códigos criptográficos e o ficheiro SAF-T de faturação e contabilidade.' },
      { pergunta: 'É necessário instalar algum programa no computador?', resposta: 'Não. O Consulvolt funciona 100% online através de qualquer navegador (Chrome, Edge, Safari, Firefox) no computador, telemóvel ou tablet, sem necessidade de servidores locais dispendiosos.' },
      { pergunta: 'Posso gerir mais do que uma empresa no mesmo sistema?', resposta: 'Sim. Os planos Profissional e Empresarial permitem gerir várias empresas com NIFs distintos e consolidação de dados ou relatórios, facilitando a gestão de grupos e holdings.' },
      { pergunta: 'Que tipo de impressoras de faturação são suportadas?', resposta: 'O sistema suporta impressoras térmicas de talão (58mm e 80mm por USB, rede ou Bluetooth) e qualquer impressora normal de formato A4 para emissão de faturas comerciais e mapas de gestão.' },
      { pergunta: 'Como funciona a transição a partir de outro software?', resposta: 'Pode importar facilmente clientes, fornecedores e artigos a partir de folhas de cálculo ou ficheiros SAF-T da sua aplicação anterior, com apoio da nossa equipa técnica.' },
    ],
    contactoTitulo: 'Deseja uma demonstração personalizada para a sua empresa?',
    contactoDesc: 'Os nossos especialistas em Luanda estão disponíveis para demonstrar todos os módulos do ERP e ajudar na migração dos seus dados sem paragens no seu negócio.',
    contactoCta: 'Falar com um Consultor',
  },
  localizacao: {
    eyebrow: 'FALE CONNOSCO',
    titulo: 'Visite-nos ou fale connosco agora mesmo',
    descricao: 'Equipa técnica sediada em Luanda, disponível para reuniões presenciais ou por videoconferência.',
    baseOperacional: 'Base Operacional',
    telefoneLabel: 'Telefone & WhatsApp',
    emailLabel: 'Email',
    comoChegar: 'Como chegar (Google Maps)',
    whatsappCta: 'Falar agora no WhatsApp',
    mapaTitulo: 'Localização da Consulvolt — Luanda, Angola',
  },
  footer: {
    brandDescricao: 'Software de gestão empresarial e facturação certificado pela AGT. Solução integrada para comércio, serviços, recursos humanos e contabilidade em Angola.',
    badgeAngola: 'Angola 🇦🇴',
    badgeAgt: 'Certificado AGT',
    colModulos: { titulo: 'Módulos ERP', faturacaoPos: 'Facturação & POS', rh: 'Recursos Humanos', contabilidade: 'Contabilidade Geral', comprasStocks: 'Compras & Stocks', tesouraria: 'Gestão de Tesouraria' },
    colPlataforma: { titulo: 'Plataforma', planos: 'Planos e Preços', vantagens: 'Vantagens & Benefícios', faq: 'Perguntas Frequentes', contactos: 'Contactos e Localização', login: 'Aceder à Conta (Login)', recuperar: 'Recuperar Palavra-passe' },
    colSuporte: { titulo: 'Suporte & Legal', apoio: 'Centro de Apoio', criarConta: 'Criar Conta Grátis', termos: 'Termos de Utilização', privacidade: 'Política de Privacidade', fiscal: 'Conformidade Fiscal AGT' },
    copyright: (ano: number) => `© ${ano} Consulvolt ERP. Todos os direitos reservados. Software de Gestão e Facturação.`,
    tecnologia: 'Tecnologia 100% Cloud · Alta Disponibilidade e Segurança',
  },
  whatsappFloating: (tel: string) => `Falar no WhatsApp (${tel})`,
  whatsappAria: 'Falar no WhatsApp',
};

type TraducaoLandingPage = typeof TRADUCOES_PT;

const TRADUCOES_EN: TraducaoLandingPage = {
  alterarIdioma: 'Change language',
  abrirMenu: 'Open menu',
  fecharMenu: 'Close menu',
  nav: { modulos: 'ERP Modules', solucoes: 'Solutions', precos: 'Plans & Pricing', vantagens: 'Benefits', ajuda: 'Help' },
  login: 'Log In',
  experimenteGratis: 'Try for Free',
  hero: {
    tituloPre: 'Business Management Software, ',
    tituloDestaque: 'Certified Invoicing',
    tituloPos: ' and POS',
    descricao: 'Manage AGT-certified invoicing, human resources and payroll, accounting, purchasing, stock and treasury in one modern, complete and intuitive ERP built for the Angolan market.',
    ctaDias: (d: number) => `Try ${d} Days Free`,
    subtexto: 'No credit card required · Instant activation',
    imgAlt: 'Consulvolt ERP home screen, open at the Consulvolt company',
  },
  modulosSecao: {
    titulo: 'Everything your business needs in one integrated system',
    tabs: { faturacao: 'Invoicing & POS', rh: 'HR & Payroll', contabilidade: 'Accounting & Treasury', stocks: 'Purchasing & Warehouses' },
    conteudo: {
      faturacao: {
        titulo: 'AGT-Certified Invoicing and High-Performance Point of Sale',
        bullets: [
          'Fast issuing of Invoices, Invoice-Receipts, Proformas and Delivery Notes',
          'Agile POS for retail, food service and service businesses, with support for cash drawers and barcode scanners',
          'RSA digital signature, validation QR code and SAF-T (AO) export',
          'Customer running accounts, credit management and settlement history',
        ],
        idealPara: 'Ideal for:',
        pills: ['Retail Stores', 'Supermarkets', 'Restaurants & Bars', 'Service Providers'],
      },
      rh: {
        titulo: 'Complete Employee Management and Payroll Processing',
        bullets: [
          'Full employee records, employment contracts, roles and departments',
          "Monthly payroll processing with automatic calculation of Angola's IRT and INSS",
          'Payslip issuing, bank transfer schedules and payroll reports',
          'Self-service employee portal for payslips and leave requests',
        ],
        idealPara: 'Ideal for:',
        pills: ['HR Departments', 'Shift-Based Teams', 'Corporate Offices'],
      },
      contabilidade: {
        titulo: 'General Accounting (PGC) and Integrated Treasury Management',
        bullets: [
          "Angola's General Chart of Accounts, fully pre-configured and customisable",
          'Automatic journal entries generated from sales, purchases and payroll',
          'Trial balances, income statements and balance sheets',
          'Bank account, cash and transfer control with bank reconciliation',
        ],
        idealPara: 'Ideal for:',
        pills: ['Accounting Firms', 'Finance Directors', 'Mid-Size and Large Companies'],
      },
      stocks: {
        titulo: 'Supplier Purchasing, Warehouse and Batch Management',
        bullets: [
          'Purchase orders, supplier invoice registration and credit notes',
          'Multi-warehouse management with internal transfers and delivery notes',
          'Automatic low-stock and reorder point alerts',
          'Batch, serial number and expiry date tracking',
        ],
        idealPara: 'Ideal for:',
        pills: ['Distributors & Wholesalers', 'Pharmaceutical Warehouses', 'Industry & Construction'],
      },
    },
    destaques: [
      { titulo: 'Sales & POS', desc: 'AGT-compliant certified invoicing.' },
      { titulo: 'HR & Payroll', desc: 'Payroll processing, IRT, INSS and employees.' },
      { titulo: 'Accounting', desc: 'Chart of accounts, journals and trial balances.' },
      { titulo: 'Stock & Purchasing', desc: 'Multi-warehouse, batch and supplier management.' },
    ],
  },
  metrics: {
    titulo: 'The trusted corporate management platform for your business',
    descricao: 'Hundreds of organisations in Angola use Consulvolt ERP every day to streamline their tax, commercial and administrative operations with rigour and security.',
    cta: 'Create Free Account',
    bloco1: 'Compliant with AGT tax regulations and standards',
    bloco2: (d: number) => `${d} Days`,
    bloco2desc: 'Free trial period with no commitment whatsoever',
    bloco3: '7 Modules',
    bloco3desc: 'Invoicing, POS, HR, Accounting, Stock, Treasury and Fixed Assets',
  },
  why: {
    eyebrow: 'WHY CONSULVOLT?',
    titulo: 'The most solid and efficient technology to drive your business forward',
    motivos: [
      { titulo: 'AGT Tax Certification', desc: 'Issuing of invoices, receipts and credit notes with digital signature, security hash and automatic SAF-T (AO) file export, in compliance with Angolan law.' },
      { titulo: '360° Integrated ERP', desc: 'Invoicing, POS, HR with payroll, general accounting (PGC), treasury, purchasing, warehouses and fixed assets in a single coordinated ecosystem.' },
      { titulo: 'Specialised Local Support', desc: 'Technical team based in Angola, ready to provide fast, ongoing assistance in Portuguese via chat, phone and remote access.' },
      { titulo: '100% Secure Cloud Technology', desc: 'Access from computer, tablet or smartphone, from anywhere, with automatic backups and advanced encryption.' },
    ],
  },
  pricing: {
    titulo: 'Transparent plans, scaled to the size of your business',
    descricao: (d: number) => `Start managing your business now with ${d} days free. The prices shown reflect the system's actual configuration.`,
    maisEscolhido: 'Most Popular',
    porMes: '/ Month',
    ctaDias: (d: number) => `Try ${d} Days Free`,
    tabelaModulo: 'Module',
    rodape: 'Prices are shown in Angolan Kwanzas (AOA) and include all AGT tax and legal updates.',
  },
  testemunhos: {
    titulo: 'What do our clients in Angola say?',
    descricao: 'Hear from managers and business owners who rely on Consulvolt ERP every day.',
    anterior: 'Previous testimonials page',
    proxima: 'Next testimonials page',
    clienteVerificado: 'Verified Client',
    lista: [
      {
        nome: 'Raquel Lacerda',
        empresa: 'Medical & Wellness Centre · Luanda',
        texto: 'Consulvolt was the turning point for organising our clinic. We now handle AGT-certified invoicing, receipts and medical staff payroll without any hassle.',
        foto: 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Augusto Fernandes',
        empresa: 'Retail & Distribution Group · Talatona',
        texto: 'We needed an ERP with multi-store stock and real-time accounting. The speed of issuing POS invoices and bank reconciliation save our finance department dozens of hours.',
        foto: 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Carlos Alberto',
        empresa: 'Marginal Restaurant & Hospitality · Ilha do Cabo',
        texto: 'The system is extremely robust. During peak hours the POS responds with absolute speed, and the end-of-day cash closing reports always balance with no discrepancies.',
        foto: 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Helena Mendes',
        empresa: 'Mendes Engineering & Construction, Lda',
        texto: 'Managing purchasing, suppliers and project cost centres gave us complete visibility into the margin of every project. I highly recommend it to any Angolan company.',
        foto: 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=600&q=80',
      },
      {
        nome: 'Mateus Gaspar',
        empresa: 'Logística Express Angola',
        texto: 'The fixed assets module and the official SAF-T export work with complete tax accuracy. Customer support in Luanda is always there when we need it.',
        foto: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=600&q=80',
      },
    ],
  },
  faq: {
    titulo: 'Frequently Asked Questions',
    lista: [
      { pergunta: "Is Consulvolt ERP compliant with Angola's tax rules?", resposta: 'Yes. The system strictly complies with the tax regulations of the General Tax Administration (AGT), generating legally valid documents with cryptographic codes and the SAF-T invoicing and accounting file.' },
      { pergunta: 'Do I need to install any software on my computer?', resposta: 'No. Consulvolt runs 100% online through any browser (Chrome, Edge, Safari, Firefox) on computer, phone or tablet, with no need for costly local servers.' },
      { pergunta: 'Can I manage more than one company in the same system?', resposta: 'Yes. The Professional and Enterprise plans let you manage several companies with different tax IDs, with data consolidation and reporting, making it easy to manage groups and holdings.' },
      { pergunta: 'What type of invoice printers are supported?', resposta: 'The system supports thermal receipt printers (58mm and 80mm, via USB, network or Bluetooth) and any standard A4 printer for issuing commercial invoices and management reports.' },
      { pergunta: 'How does switching from other software work?', resposta: 'You can easily import customers, suppliers and items from spreadsheets or SAF-T files from your previous application, with support from our technical team.' },
    ],
    contactoTitulo: 'Would you like a personalised demo for your business?',
    contactoDesc: 'Our specialists in Luanda are available to demonstrate every ERP module and help migrate your data without disrupting your business.',
    contactoCta: 'Talk to a Consultant',
  },
  localizacao: {
    eyebrow: 'GET IN TOUCH',
    titulo: 'Visit us or get in touch right now',
    descricao: 'Technical team based in Luanda, available for in-person meetings or video calls.',
    baseOperacional: 'Operating Base',
    telefoneLabel: 'Phone & WhatsApp',
    emailLabel: 'Email',
    comoChegar: 'Get directions (Google Maps)',
    whatsappCta: 'Chat Now on WhatsApp',
    mapaTitulo: 'Consulvolt location — Luanda, Angola',
  },
  footer: {
    brandDescricao: 'AGT-certified business management and invoicing software. An integrated solution for trade, services, human resources and accounting in Angola.',
    badgeAngola: 'Angola 🇦🇴',
    badgeAgt: 'AGT Certified',
    colModulos: { titulo: 'ERP Modules', faturacaoPos: 'Invoicing & POS', rh: 'Human Resources', contabilidade: 'General Accounting', comprasStocks: 'Purchasing & Stock', tesouraria: 'Treasury Management' },
    colPlataforma: { titulo: 'Platform', planos: 'Plans & Pricing', vantagens: 'Benefits & Advantages', faq: 'Frequently Asked Questions', contactos: 'Contact & Location', login: 'Access Account (Log In)', recuperar: 'Recover Password' },
    colSuporte: { titulo: 'Support & Legal', apoio: 'Help Centre', criarConta: 'Create Free Account', termos: 'Terms of Use', privacidade: 'Privacy Policy', fiscal: 'AGT Tax Compliance' },
    copyright: (ano: number) => `© ${ano} Consulvolt ERP. All rights reserved. Management and Invoicing Software.`,
    tecnologia: '100% Cloud Technology · High Availability and Security',
  },
  whatsappFloating: (tel: string) => `Chat on WhatsApp (${tel})`,
  whatsappAria: 'Chat on WhatsApp',
};

const TRADUCOES: Record<Idioma, TraducaoLandingPage> = { PT: TRADUCOES_PT, EN: TRADUCOES_EN };

export function LandingPage() {
  const [tabModulo, setTabModulo] = useState<'faturacao' | 'rh' | 'contabilidade' | 'stocks'>('faturacao');
  const [slideDepoimento, setSlideDepoimento] = useState<number>(0);
  const [faqAberta, setFaqAberta] = useState<number | null>(0);
  const [idioma, setIdioma] = useState<Idioma>('PT');
  const [menuIdiomaAberto, setMenuIdiomaAberto] = useState(false);
  const [menuMobileAberto, setMenuMobileAberto] = useState(false);
  const menuIdiomaRef = useRef<HTMLDivElement>(null);

  const L = TRADUCOES[idioma];

  // Fecha dropdown de idioma ao clicar fora
  useEffect(() => {
    const fecharAoClicarFora = (e: MouseEvent) => {
      if (menuIdiomaRef.current && !menuIdiomaRef.current.contains(e.target as Node)) {
        setMenuIdiomaAberto(false);
      }
    };
    document.addEventListener('mousedown', fecharAoClicarFora);
    return () => document.removeEventListener('mousedown', fecharAoClicarFora);
  }, []);

  // Logótipo da empresa (reutilizado no cabeçalho e no rodapé)
  const logo = useQuery({
    queryKey: ['logotipo-login'],
    queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'),
    retry: false
  });

  // Consulta dos planos configurados no sistema
  const planosQuery = useQuery({
    queryKey: ['planos-publicos'],
    queryFn: () => obter<PlanoApi[]>('/sistema/planos-publicos'),
    retry: false
  });

  const planosExibicao = (planosQuery.data && planosQuery.data.length > 0)
    ? planosQuery.data
    : PLANOS_SISTEMA_PADRAO;

  // Dias de demonstração gratuita e catálogo de módulos: lidos da plataforma para a promessa comercial e a tabela
  // de planos nunca divergirem do que o sistema realmente aplica (ModulosPlano, backend).
  const definicoesQuery = useQuery({
    queryKey: ['definicoes-publicas'],
    queryFn: () => obter<{ dias_teste_padrao: number; modulos_catalogo: Record<string, string> }>('/sistema/definicoes-publicas'),
    retry: false,
    staleTime: 5 * 60_000
  });
  const diasTeste = definicoesQuery.data?.dias_teste_padrao ?? 30;
  const descricoesModulos = idioma === 'EN' ? DESCRICOES_MODULOS_EN : DESCRICOES_MODULOS;
  const rotuloModulo = (codigo: string) => descricoesModulos[codigo] ?? definicoesQuery.data?.modulos_catalogo?.[codigo] ?? codigo;

  // Animação de entrada ao descer a página: observa todos os elementos .cv-reveal e revela-os quando entram no ecrã.
  useEffect(() => {
    const elementos = document.querySelectorAll('.cv-reveal');
    const observador = new IntersectionObserver(
      (entradas) => {
        entradas.forEach((entrada) => {
          if (entrada.isIntersecting) {
            entrada.target.classList.add('is-visible');
            observador.unobserve(entrada.target);
          }
        });
      },
      { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
    );
    elementos.forEach((el) => observador.observe(el));
    return () => observador.disconnect();
  }, [planosExibicao.length]);

  const opcoesIdioma = [
    { codigo: 'PT' as Idioma, bandeira: '🇦🇴', curto: 'PT', rotulo: 'PT · Português' },
    { codigo: 'EN' as Idioma, bandeira: '🇬🇧', curto: 'EN', rotulo: 'EN · English' },
  ];

  const idiomaSelecionado = opcoesIdioma.find(i => i.codigo === idioma) || opcoesIdioma[0];

  const depoimentos = L.testemunhos.lista;

  // Número de itens visíveis por página no carrossel (3 no desktop)
  const itensPorSlide = 3;
  const totalPaginas = Math.ceil(depoimentos.length / itensPorSlide);
  const depoimentosExibidos = [
    ...depoimentos.slice(slideDepoimento * itensPorSlide, (slideDepoimento + 1) * itensPorSlide)
  ];
  // Se faltar para completar 3 no último slide, preenche com os primeiros para harmonia
  if (depoimentosExibidos.length < itensPorSlide) {
    depoimentosExibidos.push(...depoimentos.slice(0, itensPorSlide - depoimentosExibidos.length));
  }

  const iconesPorque = [<FileProtectOutlined key="fiscal" />, <ShopOutlined key="erp" />, <CustomerServiceOutlined key="suporte" />, <CloudUploadOutlined key="cloud" />];

  return (
    <div className="cv-landing">
      {/* ── HEADER / NAVBAR ────────────────────────────────────────── */}
      <header className="cv-header">
        <div className="cv-header-container">
          <div className="cv-header-left">
            <Marca />

            {/* Selector Interativo de Idioma */}
            <div className="cv-language-container" ref={menuIdiomaRef}>
              <button
                type="button"
                className="cv-country-badge"
                onClick={() => setMenuIdiomaAberto(!menuIdiomaAberto)}
                title={L.alterarIdioma}
              >
                <GlobalOutlined style={{ fontSize: 13, color: '#b91c1c' }} />
                <span>{idiomaSelecionado.curto}</span>
                <svg width="10" height="7" viewBox="0 0 10 7" fill="none">
                  <path d="M1 1l4 4 4-4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
              </button>

              {menuIdiomaAberto && (
                <div className="cv-language-dropdown">
                  {opcoesIdioma.map(op => (
                    <div
                      key={op.codigo}
                      className={`cv-language-option ${idioma === op.codigo ? 'active' : ''}`}
                      onClick={() => {
                        setIdioma(op.codigo);
                        setMenuIdiomaAberto(false);
                      }}
                    >
                      <span style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span>{op.bandeira}</span>
                        <span>{op.rotulo}</span>
                      </span>
                      {idioma === op.codigo && <span style={{ color: '#b91c1c', fontWeight: 800 }}>✓</span>}
                    </div>
                  ))}
                </div>
              )}
            </div>

            <nav className="cv-nav-menu">
              <a href="#modulos">{L.nav.modulos}</a>
              <a href="#solucoes">{L.nav.solucoes}</a>
              <a href="#precos">{L.nav.precos}</a>
              <a href="#porquê">{L.nav.vantagens}</a>
              <a href="#faq">{L.nav.ajuda}</a>
            </nav>
          </div>

          <div className="cv-header-right">
            <Link to="/entrar" className="cv-btn-pill-outline">{L.login}</Link>
            <Link to="/registar" className="cv-btn-pill-primary">{L.experimenteGratis}</Link>
            <button
              type="button"
              className="cv-mobile-menu-toggle"
              onClick={() => setMenuMobileAberto(!menuMobileAberto)}
              aria-label={menuMobileAberto ? L.fecharMenu : L.abrirMenu}
              aria-expanded={menuMobileAberto}
            >
              {menuMobileAberto ? <CloseOutlined /> : <MenuOutlined />}
            </button>
          </div>
        </div>

        {menuMobileAberto && (
          <nav className="cv-mobile-menu-panel">
            <a href="#modulos" onClick={() => setMenuMobileAberto(false)}>{L.nav.modulos}</a>
            <a href="#solucoes" onClick={() => setMenuMobileAberto(false)}>{L.nav.solucoes}</a>
            <a href="#precos" onClick={() => setMenuMobileAberto(false)}>{L.nav.precos}</a>
            <a href="#porquê" onClick={() => setMenuMobileAberto(false)}>{L.nav.vantagens}</a>
            <a href="#faq" onClick={() => setMenuMobileAberto(false)}>{L.nav.ajuda}</a>
            <div className="cv-mobile-menu-divider" />
            <Link to="/entrar" className="cv-btn-pill-outline" onClick={() => setMenuMobileAberto(false)}>{L.login}</Link>
            <Link to="/registar" className="cv-btn-pill-primary" onClick={() => setMenuMobileAberto(false)}>{L.experimenteGratis}</Link>
          </nav>
        )}
      </header>

      {/* ── HERO SECTION COM IMAGEM REAL DO ERP ABERTO NA CONSULVOLT ── */}
      <section className="cv-hero-section">
        <div className="cv-hero-container">
          <div className="cv-hero-content">
            <h1 className="cv-hero-title">
              {L.hero.tituloPre}<span>{L.hero.tituloDestaque}</span>{L.hero.tituloPos}
            </h1>
            <p className="cv-hero-description">
              {L.hero.descricao}
            </p>

            <div className="cv-hero-cta-group">
              <Link to="/registar" className="cv-btn-pill-primary large">
                {L.hero.ctaDias(diasTeste)}
              </Link>
              <span className="cv-hero-subtext">{L.hero.subtexto}</span>
            </div>
          </div>

          {/* MOCKUP COM A CAPTURA REAL DO SISTEMA ABERTO NA CONSULVOLT */}
          <div className="cv-hero-visual">
            <div className="cv-hero-browser-wrapper">
              <div className="cv-hero-browser-card">
                <div className="cv-hero-browser-header">
                  <div className="cv-hero-browser-dots">
                    <span className="cv-hero-browser-dot red"></span>
                    <span className="cv-hero-browser-dot yellow"></span>
                    <span className="cv-hero-browser-dot green"></span>
                  </div>
                  <div className="cv-hero-browser-url">
                    <span style={{ color: '#10b981' }}>🔒</span>
                    <span>consulvolt.ao/inicio</span>
                    <span style={{ color: '#94a3b8', fontSize: 10, marginLeft: 'auto' }}>CONSULVOLT ERP</span>
                  </div>
                </div>
                <img
                  src="/imagens/ecra-inicio-erp.png"
                  alt={L.hero.imgAlt}
                  className="cv-hero-browser-image"
                />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 2: ABAS DE MÓDULOS REAIS DO ERP ─────────────────────── */}
      <section id="modulos" className="cv-solutions-section">
        <div className="cv-solutions-container">
          <h2 className="cv-section-title">
            {L.modulosSecao.titulo}
          </h2>

          <div className="cv-tabs-bar">
            <button
              className={`cv-tab-item ${tabModulo === 'faturacao' ? 'active' : ''}`}
              onClick={() => setTabModulo('faturacao')}
            >
              {L.modulosSecao.tabs.faturacao}
            </button>
            <button
              className={`cv-tab-item ${tabModulo === 'rh' ? 'active' : ''}`}
              onClick={() => setTabModulo('rh')}
            >
              {L.modulosSecao.tabs.rh}
            </button>
            <button
              className={`cv-tab-item ${tabModulo === 'contabilidade' ? 'active' : ''}`}
              onClick={() => setTabModulo('contabilidade')}
            >
              {L.modulosSecao.tabs.contabilidade}
            </button>
            <button
              className={`cv-tab-item ${tabModulo === 'stocks' ? 'active' : ''}`}
              onClick={() => setTabModulo('stocks')}
            >
              {L.modulosSecao.tabs.stocks}
            </button>
          </div>

          <div className="cv-solution-grid cv-reveal">
            <div className="cv-solution-info">
              {(['faturacao', 'rh', 'contabilidade', 'stocks'] as const).map((chave) => {
                if (tabModulo !== chave) return null;
                const conteudo = L.modulosSecao.conteudo[chave];
                return (
                  <div key={chave}>
                    <h3>{conteudo.titulo}</h3>
                    <ul className="cv-solution-bullets">
                      {conteudo.bullets.map((bullet, i) => (
                        <li key={i}><span className="cv-dot"></span> {bullet}</li>
                      ))}
                    </ul>
                    <div className="cv-solution-target-label">{conteudo.idealPara}</div>
                    <div className="cv-target-pills">
                      {conteudo.pills.map((pill, i) => (
                        <span className="cv-target-pill" key={i}>{pill}</span>
                      ))}
                    </div>
                  </div>
                );
              })}
            </div>

            {/* Destaque visual dos módulos */}
            <div style={{ background: '#f8fafc', padding: 32, borderRadius: 20, border: '1px solid #e2e8f0' }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16 }}>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <ShopOutlined style={{ fontSize: 28, color: '#b91c1c', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>{L.modulosSecao.destaques[0].titulo}</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>{L.modulosSecao.destaques[0].desc}</p>
                </div>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <TeamOutlined style={{ fontSize: 28, color: '#f59e0b', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>{L.modulosSecao.destaques[1].titulo}</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>{L.modulosSecao.destaques[1].desc}</p>
                </div>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <BankOutlined style={{ fontSize: 28, color: '#0f172a', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>{L.modulosSecao.destaques[2].titulo}</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>{L.modulosSecao.destaques[2].desc}</p>
                </div>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <DatabaseOutlined style={{ fontSize: 28, color: '#b91c1c', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>{L.modulosSecao.destaques[3].titulo}</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>{L.modulosSecao.destaques[3].desc}</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 3: NÚMEROS E MÉTRICAS ──────────────────────────────── */}
      <section className="cv-metrics-section">
        <div className="cv-metrics-container">
          <div className="cv-metrics-left">
            <h2>{L.metrics.titulo}</h2>
            <p>
              {L.metrics.descricao}
            </p>
            <Link to="/registar" className="cv-btn-pill-primary large">
              {L.metrics.cta}
            </Link>
          </div>

          <div className="cv-metrics-right">
            <div className="cv-metric-block">
              <strong>100%</strong>
              <span>{L.metrics.bloco1}</span>
            </div>
            <div className="cv-metric-block">
              <strong>{L.metrics.bloco2(diasTeste)}</strong>
              <span>{L.metrics.bloco2desc}</span>
            </div>
            <div className="cv-metric-block">
              <strong>{L.metrics.bloco3}</strong>
              <span>{L.metrics.bloco3desc}</span>
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 4: PORQUÊ ESCOLHER O CONSULVOLT ERP? ───────────────── */}
      <section id="porquê" className="cv-why-section">
        <div className="cv-why-container">
          <div style={{ textAlign: 'center', maxWidth: 740, margin: '0 auto' }}>
            <span style={{ color: '#b91c1c', fontWeight: 700, fontSize: 13, letterSpacing: '0.06em', textTransform: 'uppercase' }}>
              {L.why.eyebrow}
            </span>
            <h2 className="cv-section-title" style={{ marginTop: 8, marginBottom: 16 }}>
              {L.why.titulo}
            </h2>
          </div>

          <div className="cv-why-grid cv-reveal">
            {L.why.motivos.map((m, idx) => (
              <div key={idx} className="cv-why-card">
                <div className="cv-why-icon-wrap">{iconesPorque[idx]}</div>
                <h3>{m.titulo}</h3>
                <p>{m.desc}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 5: PLANOS E PREÇOS REAIS DO SISTEMA ─────────────────── */}
      <section id="precos" className="cv-pricing-section">
        <div className="cv-pricing-container">
          <div className="cv-pricing-header">
            <h2>{L.pricing.titulo}</h2>
            <p>
              {L.pricing.descricao(diasTeste)}
            </p>
          </div>

          <div className="cv-pricing-cards cv-reveal">
            {planosExibicao.map((p) => {
              const ePopular = p.codigo === 'profissional';
              const precoFormatado = Number(p.preco_mensal).toLocaleString('pt-AO');

              return (
                <div key={p.id} className={`cv-plan-card ${ePopular ? 'popular' : ''}`}>
                  {ePopular && <span className="cv-plan-popular-badge">{L.pricing.maisEscolhido}</span>}
                  <h3 className="cv-plan-name">{p.nome}</h3>
                  <div className="cv-plan-subtitle">{p.descricao}</div>

                  <div className="cv-plan-price-box">
                    <span className="cv-plan-price">{precoFormatado} Kz</span>
                    <span className="cv-plan-period">{L.pricing.porMes}</span>
                  </div>

                  <div className="cv-plan-limits-note">
                    {idioma === 'EN' ? (
                      <>Up to <strong>{p.max_utilizadores} users</strong> · <strong>{p.max_empresas} compan{p.max_empresas > 1 ? 'ies' : 'y'}</strong></>
                    ) : (
                      <>Até <strong>{p.max_utilizadores} utilizadores</strong> · <strong>{p.max_empresas} empresa{p.max_empresas > 1 ? 's' : ''}</strong></>
                    )}
                  </div>

                  <Link to="/registar" className="cv-btn-pill-primary cv-plan-btn">
                    {L.pricing.ctaDias(diasTeste)}
                  </Link>

                  {/* Módulos reais do plano (ModulosPlano no backend) — a mesma lista que bloqueia o menu e a API: nunca diverge do que o sistema aplica a sério. */}
                  <ul className="cv-plan-features">
                    <li className="cv-plan-feature">
                      <span className="cv-plan-check"><CheckCircleFilled /></span>
                      <span>{idioma === 'EN' ? <>Automatic <strong>SAF-T (AO)</strong> export</> : <>Exportação automática de <strong>SAF-T (AO)</strong></>}</span>
                    </li>
                    {p.modulos.map((codigo) => (
                      <li className="cv-plan-feature" key={codigo}>
                        <span className="cv-plan-check"><CheckCircleFilled /></span>
                        <span>{rotuloModulo(codigo)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              );
            })}
          </div>

          {/* ── TABELA DE COMPARAÇÃO: QUE MÓDULOS CADA PLANO INCLUI ────── */}
          <div className="cv-compare-wrap cv-reveal">
            <table className="cv-compare-table">
              <thead>
                <tr>
                  <th>{L.pricing.tabelaModulo}</th>
                  {planosExibicao.map((p) => (
                    <th key={p.id} className={p.codigo === 'profissional' ? 'popular' : ''}>{p.nome.replace('Plano ', '')}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {ORDEM_MODULOS.map((codigo) => (
                  <tr key={codigo}>
                    <td>{rotuloModulo(codigo)}</td>
                    {planosExibicao.map((p) => (
                      <td key={p.id}>
                        {p.modulos.includes(codigo)
                          ? <CheckCircleFilled className="cv-compare-check" />
                          : <MinusOutlined className="cv-compare-dash" />}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div style={{ textAlign: 'center', marginTop: 36, fontSize: 13, color: '#64748b' }}>
            {L.pricing.rodape}
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 6: TESTEMUNHOS COM CARROSSEL FUNCIONAL E IMAGENS ENQUADRADAS ── */}
      <section className="cv-testimonials-section">
        <div className="cv-testimonials-container">
          <div className="cv-testimonials-header">
            <div className="cv-testimonials-title-box">
              <h2>{L.testemunhos.titulo}</h2>
              <p>{L.testemunhos.descricao}</p>
            </div>
            <div className="cv-carousel-controls">
              <button
                type="button"
                className="cv-carousel-btn prev"
                onClick={() => setSlideDepoimento((prev) => (prev > 0 ? prev - 1 : totalPaginas - 1))}
                aria-label={L.testemunhos.anterior}
              >
                <LeftOutlined />
              </button>
              <button
                type="button"
                className="cv-carousel-btn next"
                onClick={() => setSlideDepoimento((prev) => (prev < totalPaginas - 1 ? prev + 1 : 0))}
                aria-label={L.testemunhos.proxima}
              >
                <RightOutlined />
              </button>
            </div>
          </div>

          <div className="cv-testimonials-grid cv-reveal">
            {depoimentosExibidos.map((dep, index) => (
              <div key={index} className="cv-testimonial-card">
                <div className="cv-testimonial-image-box">
                  <img src={dep.foto} alt={dep.nome} />
                  <div className="cv-testimonial-video-badge">
                    <span className="cv-testimonial-video-dot"></span>
                    <span>{L.testemunhos.clienteVerificado}</span>
                  </div>
                </div>
                <div className="cv-testimonial-body">
                  <div className="cv-testimonial-quote-icon">“</div>
                  <p className="cv-testimonial-quote">{dep.texto}</p>
                  <div className="cv-testimonial-author">
                    <span className="cv-testimonial-name">{dep.nome}</span>
                    <span className="cv-testimonial-company">{dep.empresa}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div className="cv-carousel-dots">
            {Array.from({ length: totalPaginas }).map((_, i) => (
              <span
                key={i}
                className={`cv-carousel-dot ${slideDepoimento === i ? 'active' : ''}`}
                onClick={() => setSlideDepoimento(i)}
              />
            ))}
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 7: FAQ (PERGUNTAS FREQUENTES) ───────────────────────── */}
      <section id="faq" className="cv-faq-section">
        <div className="cv-faq-container">
          <div className="cv-faq-left">
            <h2>{L.faq.titulo}</h2>
            <div className="cv-faq-accordion">
              {L.faq.lista.map((f, i) => (
                <div key={i} className="cv-faq-item">
                  <button
                    className="cv-faq-button"
                    onClick={() => setFaqAberta(faqAberta === i ? null : i)}
                  >
                    <span>{f.pergunta}</span>
                    <span className="cv-faq-icon">{faqAberta === i ? '−' : '+'}</span>
                  </button>
                  {faqAberta === i && (
                    <div className="cv-faq-answer">
                      {f.resposta}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>

          <div className="cv-faq-contact-card">
            <h3>{L.faq.contactoTitulo}</h3>
            <p>
              {L.faq.contactoDesc}
            </p>
            <Link to="/registar" className="cv-btn-pill-primary" style={{ width: '100%', textAlign: 'center' }}>
              {L.faq.contactoCta}
            </Link>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 8: LOCALIZAÇÃO E CONTACTO ───────────────────────────── */}
      <section id="contactos" className="cv-location-section">
        <div className="cv-location-container">
          <div style={{ textAlign: 'center', maxWidth: 740, margin: '0 auto' }} className="cv-reveal">
            <span style={{ color: '#b91c1c', fontWeight: 700, fontSize: 13, letterSpacing: '0.06em', textTransform: 'uppercase' }}>
              {L.localizacao.eyebrow}
            </span>
            <h2 className="cv-section-title" style={{ marginTop: 8, marginBottom: 16 }}>
              {L.localizacao.titulo}
            </h2>
            <p style={{ color: 'var(--cv-slate-600)', fontSize: 15 }}>
              {L.localizacao.descricao}
            </p>
          </div>

          <div className="cv-location-grid">
            <div className="cv-location-cards cv-reveal">
              <div className="cv-location-card">
                <span className="cv-location-icon"><EnvironmentOutlined /></span>
                <div>
                  <h4>{L.localizacao.baseOperacional}</h4>
                  <p>{MORADA_SEDE}</p>
                  <a href={MAPA_DIRECOES_URL} target="_blank" rel="noopener noreferrer" style={{ display: 'inline-block', marginTop: 4, fontWeight: 600 }}>
                    {L.localizacao.comoChegar}
                  </a>
                </div>
              </div>
              <div className="cv-location-card">
                <span className="cv-location-icon"><PhoneOutlined /></span>
                <div>
                  <h4>{L.localizacao.telefoneLabel}</h4>
                  <a href={`tel:${TELEFONE_1.replace(/\s/g, '')}`}>{TELEFONE_1}</a>
                  {' / '}
                  <a href={`tel:${TELEFONE_2.replace(/\s/g, '')}`}>{TELEFONE_2}</a>
                </div>
              </div>
              <div className="cv-location-card">
                <span className="cv-location-icon"><MailOutlined /></span>
                <div>
                  <h4>{L.localizacao.emailLabel}</h4>
                  <a href={`mailto:${EMAIL_CONTACTO}`}>{EMAIL_CONTACTO}</a>
                </div>
              </div>
              <a href={WHATSAPP_URL} target="_blank" rel="noopener noreferrer" className="cv-btn-pill-primary" style={{ textAlign: 'center', background: '#25d366', borderColor: '#25d366' }}>
                {L.localizacao.whatsappCta}
              </a>
            </div>

            <div className="cv-map-frame cv-reveal">
              <iframe
                src={MAPA_EMBED_URL}
                title={L.localizacao.mapaTitulo}
                loading="lazy"
                referrerPolicy="no-referrer-when-downgrade"
                allowFullScreen
              />
            </div>
          </div>
        </div>
      </section>

      {/* ── FOOTER MULTI-COLUNA ─────────────────────────────────────── */}
      <footer className="cv-footer">
        <div className="cv-footer-container">
          <div className="cv-footer-grid">
            <div className="cv-footer-brand">
              <div className="cv-footer-brand-logo">
                {logo.data?.logotipo && <img src={logo.data.logotipo} alt="Consulvolt" className="cv-footer-logo-img" />}
                <h3>CONSULVOLT <span>ERP</span></h3>
              </div>
              <p>
                {L.footer.brandDescricao}
              </p>
              <div style={{ display: 'flex', gap: 10 }}>
                <span className="cv-country-badge" style={{ background: '#1e293b', color: '#ffffff', border: 'none' }}>
                  {L.footer.badgeAngola}
                </span>
                <span className="cv-country-badge" style={{ background: '#1e293b', color: '#ffffff', border: 'none' }}>
                  {L.footer.badgeAgt}
                </span>
              </div>
              <div className="cv-social-row">
                <a href={LINKEDIN_URL} target="_blank" rel="noopener noreferrer" className="cv-social-icon" title="LinkedIn Oficial Consulvolt Angola">
                  <LinkedinFilled />
                </a>
                <a href={WHATSAPP_URL} target="_blank" rel="noopener noreferrer" className="cv-social-icon" title="WhatsApp Direto">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2.05 22l4.98-1.307A9.956 9.956 0 0 0 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18.2a8.17 8.17 0 0 1-4.166-1.14l-.299-.177-3.098.812.826-3.021-.194-.31A8.19 8.19 0 1 1 20.2 12 8.21 8.21 0 0 1 12 20.2zm4.49-6.14c-.246-.123-1.46-.72-1.687-.803-.226-.082-.39-.123-.555.124-.164.246-.636.802-.78.967-.144.164-.287.185-.533.062-.246-.123-1.04-.383-1.98-1.222-.732-.653-1.226-1.46-1.37-1.706-.144-.246-.015-.379.108-.502.111-.11.246-.287.37-.43.123-.144.164-.247.246-.41.082-.165.041-.31-.02-.432-.062-.123-.555-1.338-.761-1.833-.2-.48-.404-.415-.555-.423-.144-.007-.308-.009-.473-.009a.91.91 0 0 0-.657.308c-.226.246-.863.843-.863 2.058s.883 2.388 1.006 2.553c.123.164 1.74 2.656 4.215 3.724.589.254 1.048.406 1.407.52.591.188 1.129.161 1.554.098.474-.071 1.46-.597 1.666-1.173.205-.577.205-1.07.144-1.173-.062-.103-.226-.164-.472-.287z"/></svg>
                </a>
                <a href={`mailto:${EMAIL_CONTACTO}`} className="cv-social-icon" title="Email Institucional">
                  <MailOutlined />
                </a>
              </div>
            </div>

            <div className="cv-footer-col">
              <h4>{L.footer.colModulos.titulo}</h4>
              <ul className="cv-footer-links">
                <li><a href="#modulos">{L.footer.colModulos.faturacaoPos}</a></li>
                <li><a href="#modulos">{L.footer.colModulos.rh}</a></li>
                <li><a href="#modulos">{L.footer.colModulos.contabilidade}</a></li>
                <li><a href="#modulos">{L.footer.colModulos.comprasStocks}</a></li>
                <li><a href="#modulos">{L.footer.colModulos.tesouraria}</a></li>
              </ul>
            </div>

            <div className="cv-footer-col">
              <h4>{L.footer.colPlataforma.titulo}</h4>
              <ul className="cv-footer-links">
                <li><a href="#precos">{L.footer.colPlataforma.planos}</a></li>
                <li><a href="#porquê">{L.footer.colPlataforma.vantagens}</a></li>
                <li><a href="#faq">{L.footer.colPlataforma.faq}</a></li>
                <li><a href="#contactos">{L.footer.colPlataforma.contactos}</a></li>
                <li><Link to="/entrar">{L.footer.colPlataforma.login}</Link></li>
                <li><Link to="/recuperar-password">{L.footer.colPlataforma.recuperar}</Link></li>
              </ul>
            </div>

            <div className="cv-footer-col">
              <h4>{L.footer.colSuporte.titulo}</h4>
              <ul className="cv-footer-links">
                <li><a href="#faq">{L.footer.colSuporte.apoio}</a></li>
                <li><Link to="/registar">{L.footer.colSuporte.criarConta}</Link></li>
                <li><Link to="/termos">{L.footer.colSuporte.termos}</Link></li>
                <li><Link to="/privacidade">{L.footer.colSuporte.privacidade}</Link></li>
                <li><Link to="/conformidade-fiscal">{L.footer.colSuporte.fiscal}</Link></li>
              </ul>
            </div>
          </div>

          <div className="cv-footer-bottom">
            <div>
              {L.footer.copyright(new Date().getFullYear())}
            </div>
            <div>
              {L.footer.tecnologia}
            </div>
          </div>
        </div>
      </footer>

      {/* ── BOTÃO FLUTUANTE DE WHATSAPP ─────────────────────────────── */}
      <a
        href={WHATSAPP_URL}
        target="_blank"
        rel="noopener noreferrer"
        className="cv-whatsapp-floating"
        title={L.whatsappFloating(TELEFONE_1)}
        aria-label={L.whatsappAria}
      >
        <svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2.05 22l4.98-1.307A9.956 9.956 0 0 0 12 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 18.2a8.17 8.17 0 0 1-4.166-1.14l-.299-.177-3.098.812.826-3.021-.194-.31A8.19 8.19 0 1 1 20.2 12 8.21 8.21 0 0 1 12 20.2zm4.49-6.14c-.246-.123-1.46-.72-1.687-.803-.226-.082-.39-.123-.555.124-.164.246-.636.802-.78.967-.144.164-.287.185-.533.062-.246-.123-1.04-.383-1.98-1.222-.732-.653-1.226-1.46-1.37-1.706-.144-.246-.015-.379.108-.502.111-.11.246-.287.37-.43.123-.144.164-.247.246-.41.082-.165.041-.31-.02-.432-.062-.123-.555-1.338-.761-1.833-.2-.48-.404-.415-.555-.423-.144-.007-.308-.009-.473-.009a.91.91 0 0 0-.657.308c-.226.246-.863.843-.863 2.058s.883 2.388 1.006 2.553c.123.164 1.74 2.656 4.215 3.724.589.254 1.048.406 1.407.52.591.188 1.129.161 1.554.098.474-.071 1.46-.597 1.666-1.173.205-.577.205-1.07.144-1.173-.062-.103-.226-.164-.472-.287z"/></svg>
      </a>

    </div>
  );
}

function Marca() {
  const logo = useQuery({
    queryKey: ['logotipo-login'],
    queryFn: () => obter<{ logotipo: string | null }>('/sistema/logotipo-login'),
    retry: false
  });

  return (
    <Link to="/" className="cv-brand">
      {logo.data?.logotipo ? (
        <>
          <img src={logo.data.logotipo} alt="Consulvolt" style={{ maxHeight: 38 }} />
          <span className="cv-brand-nome">Consulvolt ERP</span>
        </>
      ) : (
        <span className="cv-brand-text">
          consulvolt<span>ERP</span>
        </span>
      )}
    </Link>
  );
}
