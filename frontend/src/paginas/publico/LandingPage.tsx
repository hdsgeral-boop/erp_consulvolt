import { useState, useRef, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  SafetyCertificateOutlined,
  CheckCircleFilled,
  ShopOutlined,
  CloudUploadOutlined,
  CustomerServiceOutlined,
  RightOutlined,
  LeftOutlined,
  MessageOutlined,
  CloseOutlined,
  TeamOutlined,
  BankOutlined,
  DatabaseOutlined,
  FileProtectOutlined,
  GlobalOutlined
} from '@ant-design/icons';
import { obter } from '@/api/cliente';
import { useQuery } from '@tanstack/react-query';
import './publico.css';

type Idioma = 'AO' | 'PT' | 'EN';

type PlanoApi = {
  id: number;
  codigo: string;
  nome: string;
  descricao: string;
  preco_mensal: number;
  max_utilizadores: number;
  max_empresas: number;
  ativo: boolean;
};

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
  }
];

export function LandingPage() {
  const [tabModulo, setTabModulo] = useState<'faturacao' | 'rh' | 'contabilidade' | 'stocks'>('faturacao');
  const [slideDepoimento, setSlideDepoimento] = useState<number>(0);
  const [faqAberta, setFaqAberta] = useState<number | null>(0);
  const [chatAberto, setChatAberto] = useState(false);
  const [idioma, setIdioma] = useState<Idioma>('AO');
  const [menuIdiomaAberto, setMenuIdiomaAberto] = useState(false);
  const menuIdiomaRef = useRef<HTMLDivElement>(null);

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

  // Consulta dos planos configurados no sistema
  const planosQuery = useQuery({
    queryKey: ['planos-publicos'],
    queryFn: () => obter<PlanoApi[]>('/sistema/planos-publicos'),
    retry: false
  });

  const planosExibicao = (planosQuery.data && planosQuery.data.length > 0)
    ? planosQuery.data
    : PLANOS_SISTEMA_PADRAO;

  const opcoesIdioma = [
    { codigo: 'AO' as Idioma, bandeira: '🇦🇴', rotulo: 'AO · Angola' },
    { codigo: 'PT' as Idioma, bandeira: '🇵🇹', rotulo: 'PT · Portugal' },
    { codigo: 'EN' as Idioma, bandeira: '🇬🇧', rotulo: 'EN · English' },
  ];

  const idiomaSelecionado = opcoesIdioma.find(i => i.codigo === idioma) || opcoesIdioma[0];

  const depoimentos = [
    {
      nome: 'Raquel Lacerda',
      empresa: 'Centro Médico & Bem-estar · Luanda',
      texto: 'O Consulvolt foi a viragem fundamental para a organização da nossa clínica. Controlamos a faturação certificada pela AGT, recibos e o processamento de salários da equipa médica sem complicações.',
      foto: 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=600&q=80'
    },
    {
      nome: 'Augusto Fernandes',
      empresa: 'Grupo Varejo & Distribuição · Talatona',
      texto: 'Precisávamos de um ERP com stock multi-loja e contabilidade em tempo real. A rapidez na emissão de faturas no POS e a conciliação bancária poupam dezenas de horas ao nosso departamento financeiro.',
      foto: 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80'
    },
    {
      nome: 'Carlos Alberto',
      empresa: 'Restaurante & Hotelaria Marginal · Ilha do Cabo',
      texto: 'O sistema é extremamente robusto. Nos picos de atendimento, o POS responde com velocidade absoluta e os relatórios de fecho de caixa ao fim do dia batem certo sem desvios.',
      foto: 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?auto=format&fit=crop&w=600&q=80'
    },
    {
      nome: 'Helena Mendes',
      empresa: 'Mendes Engenharia & Obras, Lda',
      texto: 'A gestão de compras, fornecedores e centros de custo em obras deu-nos uma transparência total sobre a margem de cada projeto. Recomendo vivamente a qualquer empresa angolana.',
      foto: 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=600&q=80'
    },
    {
      nome: 'Mateus Gaspar',
      empresa: 'Logística Express Angola',
      texto: 'O módulo de ativos fixos e a exportação oficial do SAF-T funcionam com total rigor fiscal. O apoio ao cliente em Luanda está sempre presente quando necessitamos.',
      foto: 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=600&q=80'
    }
  ];

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

  const motivosPorquê = [
    {
      titulo: 'Certificação Fiscal AGT',
      desc: 'Emissão de faturas, recibos e notas de crédito com assinatura eletrónica, hash de segurança e exportação automática do ficheiro SAF-T (AO) conforme a lei angolana.',
      icone: <FileProtectOutlined />
    },
    {
      titulo: 'ERP Integrado 360°',
      desc: 'Facturação, POS, RH com folha salarial, contabilidade geral PGC, tesouraria, compras, armazéns e imobilizado num único ecossistema coordenado.',
      icone: <ShopOutlined />
    },
    {
      titulo: 'Suporte Local Especializado',
      desc: 'Equipa técnica sediada em Angola pronta para prestar assistência ágil e contínua em português por chat, telefone e acesso remoto.',
      icone: <CustomerServiceOutlined />
    },
    {
      titulo: 'Tecnologia 100% Cloud Segura',
      desc: 'Aceda no computador, tablet ou smartphone a partir de qualquer local com cópias de segurança automáticas e encriptação avançada.',
      icone: <CloudUploadOutlined />
    }
  ];

  const faqs = [
    {
      pergunta: 'O Consulvolt ERP está em conformidade com as regras fiscais de Angola?',
      resposta: 'Sim. O sistema cumpre rigorosamente as normas tributárias da Administração Geral Tributária (AGT), gerando documentos legalmente válidos com códigos criptográficos e o ficheiro SAF-T de faturação e contabilidade.'
    },
    {
      pergunta: 'É necessário instalar algum programa no computador?',
      resposta: 'Não. O Consulvolt funciona 100% online através de qualquer navegador (Chrome, Edge, Safari, Firefox) no computador, telemóvel ou tablet, sem necessidade de servidores locais dispendiosos.'
    },
    {
      pergunta: 'Posso gerir mais do que uma empresa no mesmo sistema?',
      resposta: 'Sim. Os planos Profissional e Empresarial permitem gerir várias empresas com NIFs distintos e consolidação de dados ou relatórios, facilitando a gestão de grupos e holdings.'
    },
    {
      pergunta: 'Que tipo de impressoras de faturação são suportadas?',
      resposta: 'O sistema suporta impressoras térmicas de talão (58mm e 80mm por USB, rede ou Bluetooth) e qualquer impressora normal de formato A4 para emissão de faturas comerciais e mapas de gestão.'
    },
    {
      pergunta: 'Como funciona a transição a partir de outro software?',
      resposta: 'Pode importar facilmente clientes, fornecedores e artigos a partir de folhas de cálculo ou ficheiros SAF-T da sua aplicação anterior, com apoio da nossa equipa técnica.'
    }
  ];

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
                title="Alterar idioma"
              >
                <GlobalOutlined style={{ fontSize: 13, color: '#b91c1c' }} />
                <span>{idiomaSelecionado.rotulo}</span>
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
              <a href="#modulos">Módulos ERP</a>
              <a href="#solucoes">Soluções</a>
              <a href="#precos">Planos & Preços</a>
              <a href="#porquê">Vantagens</a>
              <a href="#faq">Ajuda</a>
            </nav>
          </div>

          <div className="cv-header-right">
            <div className="cv-header-links">
              <a href="#precos">Preços</a>
              <a href="#faq">Perguntas</a>
            </div>
            <Link to="/entrar" className="cv-btn-pill-outline">Login</Link>
            <Link to="/registar" className="cv-btn-pill-primary">Experimente Grátis</Link>
          </div>
        </div>
      </header>

      {/* ── HERO SECTION COM IMAGEM REAL DO ERP ABERTO NA CONSULVOLT ── */}
      <section className="cv-hero-section">
        <div className="cv-hero-container">
          <div className="cv-hero-content">
            <div className="cv-hero-eyebrow">
              ★ ERP & Gestão Empresarial 360°
            </div>
            <h1 className="cv-hero-title">
              Software de Gestão Empresarial, <span>Facturação Certificada</span> e POS
            </h1>
            <p className="cv-hero-description">
              Controle facturação AGT, recursos humanos e salários, contabilidade, compras, stocks e tesouraria num ERP moderno, completo e intuitivo para o mercado angolano.
            </p>

            <div className="cv-hero-cta-group">
              <Link to="/registar" className="cv-btn-pill-primary large">
                Experimente Grátis 30 Dias
              </Link>
              <span className="cv-hero-subtext">Sem necessidade de cartão de crédito · Ativação imediata</span>
            </div>

            <div className="cv-trust-badge">
              <div className="cv-trust-icon">
                <SafetyCertificateOutlined />
              </div>
              <div>
                <span className="cv-trust-text">Software Certificado AGT · Angola</span>
                <span className="cv-trust-sub">Em total conformidade com as regras fiscais vigentes</span>
              </div>
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
                  alt="Ecrã de Início do Consulvolt ERP aberto na empresa Consulvolt"
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
            Tudo o que a sua empresa precisa num único sistema integrado
          </h2>

          <div className="cv-tabs-bar">
            <button
              className={`cv-tab-item ${tabModulo === 'faturacao' ? 'active' : ''}`}
              onClick={() => setTabModulo('faturacao')}
            >
              Facturação & POS
            </button>
            <button
              className={`cv-tab-item ${tabModulo === 'rh' ? 'active' : ''}`}
              onClick={() => setTabModulo('rh')}
            >
              Recursos Humanos & Salários
            </button>
            <button
              className={`cv-tab-item ${tabModulo === 'contabilidade' ? 'active' : ''}`}
              onClick={() => setTabModulo('contabilidade')}
            >
              Contabilidade & Tesouraria
            </button>
            <button
              className={`cv-tab-item ${tabModulo === 'stocks' ? 'active' : ''}`}
              onClick={() => setTabModulo('stocks')}
            >
              Compras & Armazéns
            </button>
          </div>

          <div className="cv-solution-grid">
            <div className="cv-solution-info">
              {tabModulo === 'faturacao' && (
                <>
                  <h3>Facturação Certificada AGT e Ponto de Venda de Alta Produtividade</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Emissão rápida de Faturas, Faturas-Recibo, Proformas e Guias de Transporte</li>
                    <li><span className="cv-dot"></span> POS ágil para retalho, restauração e serviços com suporte a gavetas de dinheiro e leitores de código de barras</li>
                    <li><span className="cv-dot"></span> Assinatura digital RSA, código QR de validação e exportação de SAF-T (AO)</li>
                    <li><span className="cv-dot"></span> Contas correntes de clientes, gestão de crédito e histórico de liquidações</li>
                  </ul>
                  <div className="cv-solution-target-label">Ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Lojas de Retalho</span>
                    <span className="cv-target-pill">Supermercados</span>
                    <span className="cv-target-pill">Restaurantes & Bares</span>
                    <span className="cv-target-pill">Prestadores de Serviços</span>
                  </div>
                </>
              )}

              {tabModulo === 'rh' && (
                <>
                  <h3>Gestão Completa de Colaboradores e Processamento Salarial</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Fichas cadastrais completas, contratos de trabalho, funções e departamentos</li>
                    <li><span className="cv-dot"></span> Processamento mensal de salários com cálculo automático de IRT e INSS de Angola</li>
                    <li><span className="cv-dot"></span> Emissão de recibos de vencimento, mapa de transferências bancárias e folha salarial</li>
                    <li><span className="cv-dot"></span> Portal autónomo do colaborador para consulta de recibos e marcação de férias</li>
                  </ul>
                  <div className="cv-solution-target-label">Ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Departamentos de RH</span>
                    <span className="cv-target-pill">Empresas com Equipas em Turnos</span>
                    <span className="cv-target-pill">Escritórios Corporativos</span>
                  </div>
                </>
              )}

              {tabModulo === 'contabilidade' && (
                <>
                  <h3>Contabilidade Geral (PGC) e Gestão de Tesouraria Integrada</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Plano Geral de Contas de Angola totalmente pré-configurado e personalizável</li>
                    <li><span className="cv-dot"></span> Lançamentos em diários automáticos a partir das vendas, compras e salários</li>
                    <li><span className="cv-dot"></span> Balancetes analíticos, demonstração de resultados e balanço patrimonial</li>
                    <li><span className="cv-dot"></span> Controlo de contas bancárias, caixas, transferências e conciliação bancária</li>
                  </ul>
                  <div className="cv-solution-target-label">Ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Gabinetes de Contabilidade</span>
                    <span className="cv-target-pill">Diretores Financeiros</span>
                    <span className="cv-target-pill">Médias e Grandes Empresas</span>
                  </div>
                </>
              )}

              {tabModulo === 'stocks' && (
                <>
                  <h3>Compras a Fornecedores, Gestão de Armazéns e Lotes</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Encomendas a fornecedores, registo de faturas de compra e notas de crédito</li>
                    <li><span className="cv-dot"></span> Gestão de múltiplos armazéns com transferências internas e guias de remessa</li>
                    <li><span className="cv-dot"></span> Alertas automáticos de rutura de stock e ponto de encomenda</li>
                    <li><span className="cv-dot"></span> Rastreio por lotes, números de série e datas de validade</li>
                  </ul>
                  <div className="cv-solution-target-label">Ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Distribuidores e Grossistas</span>
                    <span className="cv-target-pill">Armazéns Farmacêuticos</span>
                    <span className="cv-target-pill">Indústria e Construção</span>
                  </div>
                </>
              )}
            </div>

            {/* Destaque visual dos módulos */}
            <div style={{ background: '#f8fafc', padding: 32, borderRadius: 20, border: '1px solid #e2e8f0' }}>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 16 }}>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <ShopOutlined style={{ fontSize: 28, color: '#b91c1c', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>Vendas & POS</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>Faturação certificada em conformidade com a AGT.</p>
                </div>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <TeamOutlined style={{ fontSize: 28, color: '#f59e0b', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>RH & Salários</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>Processamento salarial, IRT, INSS e colaboradores.</p>
                </div>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <BankOutlined style={{ fontSize: 28, color: '#0f172a', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>Contabilidade</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>Plano Geral de Contas, diários e balancetes.</p>
                </div>
                <div style={{ background: '#ffffff', padding: 20, borderRadius: 12, border: '1px solid #e2e8f0', boxShadow: '0 4px 10px rgba(0,0,0,0.03)' }}>
                  <DatabaseOutlined style={{ fontSize: 28, color: '#b91c1c', marginBottom: 8 }} />
                  <h4 style={{ margin: '0 0 4px', fontSize: 16, fontWeight: 700 }}>Stocks & Compras</h4>
                  <p style={{ margin: 0, fontSize: 13, color: '#64748b' }}>Gestão multi-armazém, lotes e fornecedores.</p>
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
            <h2>A plataforma de gestão corporativa de confiança para a sua empresa</h2>
            <p>
              Centenas de organizações em Angola utilizam o Consulvolt ERP todos os dias para simplificar as suas operações fiscais, comerciais e administrativas com rigor e segurança.
            </p>
            <Link to="/registar" className="cv-btn-pill-primary large">
              Criar Conta Gratuita
            </Link>
          </div>

          <div className="cv-metrics-right">
            <div className="cv-metric-block">
              <strong>100%</strong>
              <span>Conforme com a regulamentação fiscal e normas da AGT</span>
            </div>
            <div className="cv-metric-block">
              <strong>30 Dias</strong>
              <span>Período de avaliação gratuito e sem qualquer compromisso</span>
            </div>
            <div className="cv-metric-block">
              <strong>7 Módulos</strong>
              <span>Facturação, POS, RH, Contabilidade, Stocks, Tesouraria e Activos</span>
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 4: PORQUÊ ESCOLHER O CONSULVOLT ERP? ───────────────── */}
      <section id="porquê" className="cv-why-section">
        <div className="cv-why-container">
          <div style={{ textAlign: 'center', maxWidth: 740, margin: '0 auto' }}>
            <span style={{ color: '#b91c1c', fontWeight: 700, fontSize: 13, letterSpacing: '0.06em', textTransform: 'uppercase' }}>
              PORQUÊ A CONSULVOLT?
            </span>
            <h2 className="cv-section-title" style={{ marginTop: 8, marginBottom: 16 }}>
              A tecnologia mais sólida e eficiente para impulsionar o seu negócio
            </h2>
          </div>

          <div className="cv-why-grid">
            {motivosPorquê.map((m, idx) => (
              <div key={idx} className="cv-why-card">
                <div className="cv-why-icon-wrap">{m.icone}</div>
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
            <h2>Planos transparentes e ajustados à dimensão da sua empresa</h2>
            <p>
              Comece agora a gerir o seu negócio com 30 dias grátis. Os valores indicados refletem a configuração real do sistema.
            </p>
          </div>

          <div className="cv-pricing-cards">
            {planosExibicao.map((p) => {
              const ePopular = p.codigo === 'profissional';
              const precoFormatado = Number(p.preco_mensal).toLocaleString('pt-AO');

              return (
                <div key={p.id} className={`cv-plan-card ${ePopular ? 'popular' : ''}`}>
                  {ePopular && <span className="cv-plan-popular-badge">Mais Escolhido</span>}
                  <h3 className="cv-plan-name">{p.nome}</h3>
                  <div className="cv-plan-subtitle">{p.descricao}</div>

                  <div className="cv-plan-price-box">
                    <span className="cv-plan-price">{precoFormatado} Kz</span>
                    <span className="cv-plan-period">/ Mês</span>
                  </div>

                  <div className="cv-plan-limits-note">
                    Até <strong>{p.max_utilizadores} utilizadores</strong> · <strong>{p.max_empresas} empresa{p.max_empresas > 1 ? 's' : ''}</strong>
                  </div>

                  <Link to={`/registar?plano=${p.codigo}`} className="cv-btn-pill-primary cv-plan-btn">
                    Experimentar 30 Dias Grátis
                  </Link>

                  <ul className="cv-plan-features">
                    <li className="cv-plan-feature">
                      <span className="cv-plan-check"><CheckCircleFilled /></span>
                      <span>Facturação Certificada <strong>AGT</strong></span>
                    </li>
                    <li className="cv-plan-feature">
                      <span className="cv-plan-check"><CheckCircleFilled /></span>
                      <span>Ponto de Venda <strong>(POS)</strong> Web e Móvel</span>
                    </li>
                    <li className="cv-plan-feature">
                      <span className="cv-plan-check"><CheckCircleFilled /></span>
                      <span>Exportação automática de <strong>SAF-T (AO)</strong></span>
                    </li>
                    <li className="cv-plan-feature">
                      <span className="cv-plan-check"><CheckCircleFilled /></span>
                      <span>Controlo de <strong>Stocks e Tesouraria</strong></span>
                    </li>
                    {p.codigo !== 'basico' && (
                      <>
                        <li className="cv-plan-feature">
                          <span className="cv-plan-check"><CheckCircleFilled /></span>
                          <span>Processamento Salarial & <strong>Recursos Humanos</strong></span>
                        </li>
                        <li className="cv-plan-feature">
                          <span className="cv-plan-check"><CheckCircleFilled /></span>
                          <span><strong>Contabilidade Geral</strong> e Plano de Contas</span>
                        </li>
                      </>
                    )}
                    {p.codigo === 'empresarial' && (
                      <>
                        <li className="cv-plan-feature">
                          <span className="cv-plan-check"><CheckCircleFilled /></span>
                          <span>Gestão de <strong>Activos Fixos & Imobilizado</strong></span>
                        </li>
                        <li className="cv-plan-feature">
                          <span className="cv-plan-check"><CheckCircleFilled /></span>
                          <span>Suporte multi-empresa para <strong>Holdings</strong></span>
                        </li>
                      </>
                    )}
                  </ul>
                </div>
              );
            })}
          </div>

          <div style={{ textAlign: 'center', marginTop: 36, fontSize: 13, color: '#64748b' }}>
            Os preços indicados são em Kwanzas (AOA) e incluem todas as atualizações fiscais e legais da AGT.
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 6: TESTEMUNHOS COM CARROSSEL FUNCIONAL E IMAGENS ENQUADRADAS ── */}
      <section className="cv-testimonials-section">
        <div className="cv-testimonials-container">
          <div className="cv-testimonials-header">
            <div className="cv-testimonials-title-box">
              <h2>O que dizem os nossos clientes em Angola?</h2>
              <p>Conheça os testemunhos de gestores e empresários que confiam diariamente no Consulvolt ERP.</p>
            </div>
            <div className="cv-carousel-controls">
              <button
                type="button"
                className="cv-carousel-btn prev"
                onClick={() => setSlideDepoimento((prev) => (prev > 0 ? prev - 1 : totalPaginas - 1))}
                aria-label="Página anterior de testemunhos"
              >
                <LeftOutlined />
              </button>
              <button
                type="button"
                className="cv-carousel-btn next"
                onClick={() => setSlideDepoimento((prev) => (prev < totalPaginas - 1 ? prev + 1 : 0))}
                aria-label="Próxima página de testemunhos"
              >
                <RightOutlined />
              </button>
            </div>
          </div>

          <div className="cv-testimonials-grid">
            {depoimentosExibidos.map((dep, index) => (
              <div key={index} className="cv-testimonial-card">
                <div className="cv-testimonial-image-box">
                  <img src={dep.foto} alt={`Fotografia de ${dep.nome}`} />
                  <div className="cv-testimonial-video-badge">
                    <span className="cv-testimonial-video-dot"></span>
                    <span>Cliente Verificado</span>
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
            <h2>Perguntas Frequentes</h2>
            <div className="cv-faq-accordion">
              {faqs.map((f, i) => (
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
            <h3>Deseja uma demonstração personalizada para a sua empresa?</h3>
            <p>
              Os nossos especialistas em Luanda estão disponíveis para demonstrar todos os módulos do ERP e ajudar na migração dos seus dados sem paragens no seu negócio.
            </p>
            <Link to="/registar" className="cv-btn-pill-primary" style={{ width: '100%', textAlign: 'center' }}>
              Falar com um Consultor
            </Link>
          </div>
        </div>
      </section>

      {/* ── FOOTER MULTI-COLUNA ─────────────────────────────────────── */}
      <footer className="cv-footer">
        <div className="cv-footer-container">
          <div className="cv-footer-grid">
            <div className="cv-footer-brand">
              <h3>CONSULVOLT <span>ERP</span></h3>
              <p>
                Software de gestão empresarial e facturação certificado pela AGT. Solução integrada para comércio, serviços, recursos humanos e contabilidade em Angola.
              </p>
              <div style={{ display: 'flex', gap: 10 }}>
                <span className="cv-country-badge" style={{ background: '#1e293b', color: '#cbd5e1', border: 'none' }}>
                  Angola 🇦🇴
                </span>
                <span className="cv-country-badge" style={{ background: '#1e293b', color: '#cbd5e1', border: 'none' }}>
                  Certificado AGT
                </span>
              </div>
            </div>

            <div className="cv-footer-col">
              <h4>Módulos ERP</h4>
              <ul className="cv-footer-links">
                <li><a href="#modulos">Facturação & POS</a></li>
                <li><a href="#modulos">Recursos Humanos</a></li>
                <li><a href="#modulos">Contabilidade Geral</a></li>
                <li><a href="#modulos">Compras & Stocks</a></li>
                <li><a href="#modulos">Gestão de Tesouraria</a></li>
              </ul>
            </div>

            <div className="cv-footer-col">
              <h4>Plataforma</h4>
              <ul className="cv-footer-links">
                <li><a href="#precos">Planos e Preços</a></li>
                <li><a href="#porquê">Vantagens & Benefícios</a></li>
                <li><a href="#faq">Perguntas Frequentes</a></li>
                <li><Link to="/entrar">Aceder à Conta (Login)</Link></li>
                <li><Link to="/recuperar-password">Recuperar Palavra-passe</Link></li>
                <li><Link to="/admin/login">Portal de Administração</Link></li>
              </ul>
            </div>

            <div className="cv-footer-col">
              <h4>Suporte & Legal</h4>
              <ul className="cv-footer-links">
                <li><a href="#faq">Centro de Apoio</a></li>
                <li><Link to="/registar">Criar Conta Grátis</Link></li>
                <li><a href="#">Termos de Utilização</a></li>
                <li><a href="#">Política de Privacidade</a></li>
                <li><a href="#">Conformidade Fiscal AGT</a></li>
              </ul>
            </div>
          </div>

          <div className="cv-footer-bottom">
            <div>
              © {new Date().getFullYear()} Consulvolt ERP. Todos os direitos reservados. Software de Gestão e Facturação.
            </div>
            <div>
              Tecnologia 100% Cloud · Alta Disponibilidade e Segurança
            </div>
          </div>
        </div>
      </footer>

      {/* ── BOTÃO FLUTUANTE DE CHAT ─────────────────────────────────── */}
      <button
        type="button"
        className="cv-floating-chat"
        onClick={() => setChatAberto(!chatAberto)}
        aria-label="Abrir apoio ao cliente"
      >
        {chatAberto ? <CloseOutlined /> : <MessageOutlined />}
      </button>

      {chatAberto && (
        <div style={{
          position: 'fixed',
          bottom: 88,
          right: 24,
          width: 320,
          background: '#ffffff',
          borderRadius: 16,
          boxShadow: '0 20px 40px rgba(0,0,0,0.18)',
          border: '1px solid #e2e8f0',
          zIndex: 99999,
          overflow: 'hidden'
        }}>
          <div style={{ background: '#b91c1c', color: '#ffffff', padding: 16 }}>
            <strong style={{ display: 'block', fontSize: 15 }}>Apoio Consulvolt</strong>
            <small style={{ color: 'rgba(255,255,255,0.85)' }}>Especialistas online em Luanda</small>
          </div>
          <div style={{ padding: 16, fontSize: 13, color: '#334155' }}>
            <p style={{ margin: '0 0 12px' }}>
              Olá! Tem dúvidas sobre a certificação AGT, os planos de subscrição ou gostaria de começar o teste de 30 dias grátis?
            </p>
            <Link
              to="/registar"
              className="cv-btn-pill-primary"
              style={{ display: 'block', textAlign: 'center', fontSize: 13, padding: '8px 16px' }}
              onClick={() => setChatAberto(false)}
            >
              Criar Conta Gratuita
            </Link>
          </div>
        </div>
      )}
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
        <img src={logo.data.logotipo} alt="Consulvolt" style={{ maxHeight: 38 }} />
      ) : (
        <span className="cv-brand-text">
          consulvolt<span>ERP</span>
        </span>
      )}
    </Link>
  );
}
