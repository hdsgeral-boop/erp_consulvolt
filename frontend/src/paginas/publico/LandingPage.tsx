import { useState } from 'react';
import { Link } from 'react-router-dom';
import {
  SafetyCertificateOutlined,
  CheckCircleFilled,
  ShopOutlined,
  CloudUploadOutlined,
  CustomerServiceOutlined,
  SwapOutlined,
  RightOutlined,
  LeftOutlined,
  MessageOutlined,
  CloseOutlined,
  DesktopOutlined,
  MobileOutlined,
  TabletOutlined
} from '@ant-design/icons';
import { obter } from '@/api/cliente';
import { useQuery } from '@tanstack/react-query';
import './publico.css';

export function LandingPage() {
  const [tabSolucao, setTabSolucao] = useState<'faturacao' | 'retalho' | 'restauracao'>('faturacao');
  const [slideDepoimento, setSlideDepoimento] = useState<number>(0);
  const [faqAberta, setFaqAberta] = useState<number | null>(0);
  const [demoTab, setDemoTab] = useState<'tablet' | 'desktop' | 'mobile'>('tablet');
  const [chatAberto, setChatAberto] = useState(false);

  const depoimentos = [
    {
      nome: 'Raquel Lacerda',
      empresa: 'Centro NPP · Saúde e Bem-estar',
      texto: 'Definitivamente o Consulvolt foi uma ferramenta fundamental para eu sentir o profissionalismo que sinto neste momento no atendimento aos nossos pacientes e na faturação rigorosa.',
      foto: 'https://images.unsplash.com/photo-1573496359142-b8d87734a5a2?auto=format&fit=crop&w=600&q=80'
    },
    {
      nome: 'Augusto Fernandes',
      empresa: 'Shanti Moda & Retalho',
      texto: 'Mudamos para o Consulvolt porque sentíamos limitações no software que usávamos. A facilidade com que controlamos os tamanhos, cores e stocks entre as lojas é incomparável.',
      foto: 'https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=600&q=80'
    },
    {
      nome: 'Carlos Alberto',
      empresa: 'Restaurante & Lounge Marginal',
      texto: 'O POS de restauração responde instantaneamente nos picos de movimento. A comunicação de pedidos com a cozinha e o fecho de contas funcionam com estabilidade total.',
      foto: 'https://images.unsplash.com/photo-1519085360753-af0119f7cbe7?auto=format&fit=crop&w=600&q=80'
    }
  ];

  const motivosPorquê = [
    {
      titulo: 'É simples, prático e intuitivo.',
      desc: 'Estes são os principais adjetivos usados pelos nossos clientes. Se procura uma solução descomplicada, onde qualquer operador emite faturas em segundos, o Consulvolt é ideal.',
      icone: <ShopOutlined />
    },
    {
      titulo: 'Sem contratos, nem burocracias.',
      desc: 'No Consulvolt pode cancelar ou alterar o seu plano a qualquer momento. A verdadeira fidelização advém da satisfação e da qualidade diária do serviço que prestamos.',
      icone: <SwapOutlined />
    },
    {
      titulo: 'Melhor Suporte ao Cliente em Angola.',
      desc: 'Dispomos de uma equipa local e dedicada pronta para responder quase de imediato às suas solicitações. E sim: o suporte técnico é ilimitado, em português e gratuito.',
      icone: <CustomerServiceOutlined />
    },
    {
      titulo: 'Migração Rápida e Gratuita.',
      desc: 'Mudar de software é rápido e sem dor de cabeça. Pode importar facilmente os seus clientes e catálogo de artigos a partir do ficheiro SAF-T da sua aplicação anterior.',
      icone: <CloudUploadOutlined />
    }
  ];

  const faqs = [
    {
      pergunta: 'É necessário instalar algum programa no computador ou telemóvel?',
      resposta: 'Não. O Consulvolt funciona 100% online na cloud através do seu navegador habitual (Chrome, Safari, Edge, Firefox) em qualquer computador Windows, Mac, tablet ou telemóvel Android e iOS.'
    },
    {
      pergunta: 'O software está devidamente validado e certificado pela AGT?',
      resposta: 'Sim. O Consulvolt ERP cumpre todas as normas fiscais da República de Angola, incluindo emissão de faturas válidas com código de validação, hash criptográfico e exportação oficial do ficheiro SAF-T (AO).'
    },
    {
      pergunta: 'Posso utilizar impressoras térmicas de talão ou formato A4?',
      resposta: 'Sim. O Consulvolt permite emitir faturas e recibos em impressoras térmicas de talão (58mm e 80mm por USB, Rede ou Bluetooth) e também em impressoras padrão de formato A4 com o seu logótipo oficial.'
    },
    {
      pergunta: 'Existe algum período de fidelização obrigatório?',
      resposta: 'Não. Acreditamos que a retenção dos nossos clientes se conquista pela qualidade da experiência. Pode subscrever mensalmente ou anualmente e cancelar a qualquer momento sem penalizações.'
    },
    {
      pergunta: 'Como funciona o suporte técnico em Angola?',
      resposta: 'O suporte técnico do Consulvolt está disponível por chat integrado, e-mail e telefone, com especialistas locais familiarizados com a legislação e os hábitos do mercado angolano.'
    }
  ];

  return (
    <div className="cv-landing">
      {/* ── HEADER / NAVBAR ────────────────────────────────────────── */}
      <header className="cv-header">
        <div className="cv-header-container">
          <div className="cv-header-left">
            <Marca />
            <div className="cv-country-badge" title="Angola">
              <span>AO</span>
              <span role="img" aria-label="Bandeira de Angola">🇦🇴</span>
              <svg width="10" height="7" viewBox="0 0 10 7" fill="none">
                <path d="M1 1l4 4 4-4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </div>
            <nav className="cv-nav-menu">
              <a href="#solucoes" onClick={() => setTabSolucao('faturacao')}>Faturação</a>
              <a href="#solucoes" onClick={() => setTabSolucao('retalho')}>Retalho</a>
              <a href="#solucoes" onClick={() => setTabSolucao('restauracao')}>Restauração</a>
            </nav>
          </div>

          <div className="cv-header-right">
            <div className="cv-header-links">
              <a href="#precos">Preços</a>
              <a href="#porquê">Vantagens</a>
              <a href="#faq">Ajuda</a>
            </div>
            <Link to="/entrar" className="cv-btn-pill-outline">Login</Link>
            <Link to="/registar" className="cv-btn-pill-orange">Experimente Grátis</Link>
          </div>
        </div>
      </header>

      {/* ── HERO SECTION (AZUL CEGID VENDUS) ─────────────────────────── */}
      <section className="cv-hero-section">
        <div className="cv-hero-container">
          <div className="cv-hero-content">
            <div className="cv-hero-eyebrow">Software de</div>
            <h1 className="cv-hero-title">Faturação Online e POS</h1>
            <p className="cv-hero-description">
              Fature em segundos e acompanhe as suas vendas no computador, telemóvel ou tablet, de forma simples e prática!
            </p>

            <div className="cv-hero-cta-group">
              <Link to="/registar" className="cv-btn-pill-orange large">
                Experimente Grátis
              </Link>
              <span className="cv-hero-subtext">30 dias gratuitos sem compromisso</span>
            </div>

            <div className="cv-trust-badge">
              <div className="cv-trust-icon">
                <SafetyCertificateOutlined />
              </div>
              <div>
                <span className="cv-trust-text">Software Validado Nº 142/AGT</span>
                <span className="cv-trust-sub">Conforme normas fiscais de Angola</span>
              </div>
            </div>
          </div>

          {/* MOCKUP DO POS / TERMINAL DE VENDA */}
          <div className="cv-hero-visual">
            <div className="cv-hero-card-frame">
              <div className="cv-pos-mockup">
                <div className="cv-pos-bar">
                  <span>CONSULVOLT POS · Caixa 01</span>
                  <span>Operador: Mariana V. · Conectado</span>
                </div>
                <div className="cv-pos-body">
                  <div className="cv-pos-products">
                    <div className="cv-pos-item">
                      <div className="cv-pos-item-icon">☕</div>
                      <span className="cv-pos-item-name">Café Expresso</span>
                      <span className="cv-pos-item-price">650 Kz</span>
                    </div>
                    <div className="cv-pos-item">
                      <div className="cv-pos-item-icon">💧</div>
                      <span className="cv-pos-item-name">Água Mineral</span>
                      <span className="cv-pos-item-price">450 Kz</span>
                    </div>
                    <div className="cv-pos-item">
                      <div className="cv-pos-item-icon">🥪</div>
                      <span className="cv-pos-item-name">Tosta Mista</span>
                      <span className="cv-pos-item-price">1.800 Kz</span>
                    </div>
                    <div className="cv-pos-item">
                      <div className="cv-pos-item-icon">🍔</div>
                      <span className="cv-pos-item-name">Menu Almoço</span>
                      <span className="cv-pos-item-price">4.500 Kz</span>
                    </div>
                    <div className="cv-pos-item">
                      <div className="cv-pos-item-icon">🍹</div>
                      <span className="cv-pos-item-name">Sumo Natural</span>
                      <span className="cv-pos-item-price">1.200 Kz</span>
                    </div>
                    <div className="cv-pos-item">
                      <div className="cv-pos-item-icon">📑</div>
                      <span className="cv-pos-item-name">Serviço Geral</span>
                      <span className="cv-pos-item-price">12.000 Kz</span>
                    </div>
                  </div>

                  <div className="cv-pos-cart">
                    <div className="cv-pos-cart-title">Venda em Curso #0042</div>
                    <div>
                      <div className="cv-pos-cart-line">
                        <span>1x Café Expresso</span>
                        <strong>650 Kz</strong>
                      </div>
                      <div className="cv-pos-cart-line">
                        <span>1x Menu Almoço</span>
                        <strong>4.500 Kz</strong>
                      </div>
                      <div className="cv-pos-cart-line">
                        <span>1x Sumo Natural</span>
                        <strong>1.200 Kz</strong>
                      </div>
                    </div>
                    <div className="cv-pos-cart-total">
                      <div className="cv-pos-total-row">
                        <span>Total (c/ IVA):</span>
                        <span>6.350 Kz</span>
                      </div>
                      <Link to="/registar" className="cv-pos-btn-pay" style={{ textAlign: 'center', textDecoration: 'none' }}>
                        + Emitir Fatura / Talão
                      </Link>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 2: ABAS DE SOLUÇÕES (FATURAÇÃO, RETALHO, RESTAURAÇÃO) ─ */}
      <section id="solucoes" className="cv-solutions-section">
        <div className="cv-solutions-container">
          <h2 className="cv-section-title">
            Software de faturação online ideal para escritórios, retalho e restauração!
          </h2>

          <div className="cv-tabs-bar">
            <button
              className={`cv-tab-item ${tabSolucao === 'faturacao' ? 'active' : ''}`}
              onClick={() => setTabSolucao('faturacao')}
            >
              Faturação
            </button>
            <button
              className={`cv-tab-item ${tabSolucao === 'retalho' ? 'active' : ''}`}
              onClick={() => setTabSolucao('retalho')}
            >
              Retalho
            </button>
            <button
              className={`cv-tab-item ${tabSolucao === 'restauracao' ? 'active' : ''}`}
              onClick={() => setTabSolucao('restauracao')}
            >
              Restauração
            </button>
          </div>

          <div className="cv-solution-grid">
            {/* Visual em smartphone */}
            <div className="cv-phone-mockup-wrapper">
              <div className="cv-phone-card">
                <div className="cv-phone-screen">
                  <div className="cv-phone-header">
                    <span>CONSULVOLT MOBILE</span>
                    <span>14:30 📶</span>
                  </div>
                  <div className="cv-phone-chart">
                    <span style={{ fontSize: 11, color: '#64748b' }}>Faturação Hoje</span>
                    <div className="cv-phone-metric">
                      {tabSolucao === 'faturacao' ? '248.500 Kz' : tabSolucao === 'retalho' ? '512.400 Kz' : '389.200 Kz'}
                    </div>
                    <span style={{ fontSize: 11, color: '#10b981', fontWeight: 600 }}>↑ +14.2% face a ontem</span>
                  </div>
                  <div>
                    <div className="cv-phone-list-item">
                      <span>FT 2026/102</span>
                      <strong style={{ color: '#0052ff' }}>34.500 Kz</strong>
                    </div>
                    <div className="cv-phone-list-item">
                      <span>FT 2026/101</span>
                      <strong style={{ color: '#0052ff' }}>12.800 Kz</strong>
                    </div>
                    <div className="cv-phone-list-item">
                      <span>FT 2026/100</span>
                      <strong style={{ color: '#0052ff' }}>85.000 Kz</strong>
                    </div>
                    <div className="cv-phone-list-item">
                      <span>FT 2026/099</span>
                      <strong style={{ color: '#0052ff' }}>23.400 Kz</strong>
                    </div>
                  </div>
                </div>
              </div>

              <div className="cv-phone-card secondary">
                <div className="cv-phone-screen" style={{ height: 320, padding: 14 }}>
                  <div className="cv-phone-header">
                    <span>DOCUMENTO</span>
                    <span style={{ color: '#10b981', fontWeight: 700 }}>CERTIFICADO</span>
                  </div>
                  <div style={{ textAlign: 'center', padding: '16px 0', borderBottom: '1px dashed #cbd5e1' }}>
                    <div style={{ fontSize: 14, fontWeight: 800 }}>FATURA Nº 2026/102</div>
                    <small style={{ color: '#64748b' }}>Consulvolt ERP · AGT 142</small>
                  </div>
                  <div style={{ padding: '12px 0', fontSize: 12 }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                      <span>Serviços Prestados</span>
                      <span>30.000 Kz</span>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                      <span>IVA (14%)</span>
                      <span>4.500 Kz</span>
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 800, marginTop: 8, fontSize: 13 }}>
                      <span>Total Pago</span>
                      <span style={{ color: '#0052ff' }}>34.500 Kz</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            {/* Conteúdo textual da aba */}
            <div className="cv-solution-info">
              {tabSolucao === 'faturacao' && (
                <>
                  <h3>Faturação simples para si, rápida para os seus clientes!</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Compatível com qualquer computador, tablet e smartphone</li>
                    <li><span className="cv-dot"></span> Impressão em A4 e fatura eletrónica assinada digitalmente</li>
                    <li><span className="cv-dot"></span> Pagamentos a prazo e gestão de avenças automáticas</li>
                    <li><span className="cv-dot"></span> Conta corrente de clientes e histórico completo</li>
                    <li><span className="cv-dot"></span> Relatórios detalhados e exportação imediata do SAF-T</li>
                  </ul>
                  <div className="cv-solution-target-label">A solução ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Escritórios</span>
                    <span className="cv-target-pill">Agências</span>
                    <span className="cv-target-pill">Trabalhadores Independentes & Freelancers</span>
                    <span className="cv-target-pill">Consultorias</span>
                  </div>
                </>
              )}

              {tabSolucao === 'retalho' && (
                <>
                  <h3>O Ponto de Venda (POS) pensado para o seu negócio!</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Emissão rápida de faturas, orçamentos, recibos e guias</li>
                    <li><span className="cv-dot"></span> Gestão avançada de tamanhos, cores e referências</li>
                    <li><span className="cv-dot"></span> Múltiplas vendas em simultâneo com suspensão de talão</li>
                    <li><span className="cv-dot"></span> Controlo de stocks em tempo real com alertas de rutura</li>
                    <li><span className="cv-dot"></span> Gestão integrada de múltiplas lojas e caixas físicas</li>
                  </ul>
                  <div className="cv-solution-target-label">A solução ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Sapatarias</span>
                    <span className="cv-target-pill">Lojas de Roupa & Acessórios</span>
                    <span className="cv-target-pill">Minimercados & Mercearias</span>
                    <span className="cv-target-pill">Lojas de Informática</span>
                  </div>
                </>
              )}

              {tabSolucao === 'restauracao' && (
                <>
                  <h3>O POS que garante a gestão de todo o seu negócio numa só aplicação!</h3>
                  <ul className="cv-solution-bullets">
                    <li><span className="cv-dot"></span> Gestão de salas e mesas com vista visual do espaço</li>
                    <li><span className="cv-dot"></span> Menus compostos (bebida + prato + café) e suplementos</li>
                    <li><span className="cv-dot"></span> Envio automático de pedidos para impressoras de cozinha</li>
                    <li><span className="cv-dot"></span> Consultas de mesa em tempo real e divisão de conta</li>
                    <li><span className="cv-dot"></span> Controlo rigoroso de abertura, fecho e movimentos de caixa</li>
                  </ul>
                  <div className="cv-solution-target-label">A solução ideal para:</div>
                  <div className="cv-target-pills">
                    <span className="cv-target-pill">Restaurantes</span>
                    <span className="cv-target-pill">Cafés & Pastelarias</span>
                    <span className="cv-target-pill">Bares & Discotecas</span>
                    <span className="cv-target-pill">Take-away & Food Trucks</span>
                  </div>
                </>
              )}
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 3: NÚMEROS E MÉTRICAS (PROVA SOCIAL) ───────────────── */}
      <section className="cv-metrics-section">
        <div className="cv-metrics-container">
          <div className="cv-metrics-left">
            <h2>A solução que precisa para gerir o seu negócio, sem complicações!</h2>
            <p>
              Mais de 15.000 empresas e profissionais confiam no Consulvolt ERP como o seu software de faturação online e POS de eleição. A verdadeira fidelização advém da satisfação e, por essa razão, descomplicamos ao máximo.
            </p>
            <Link to="/registar" className="cv-btn-pill-orange large">
              Experimente Grátis
            </Link>
          </div>

          <div className="cv-metrics-right">
            <div className="cv-metric-block">
              <strong>+15k</strong>
              <span>Clientes escolhem faturar com o Consulvolt ERP</span>
            </div>
            <div className="cv-metric-block">
              <strong>+40M</strong>
              <span>de documentos são emitidos anualmente</span>
            </div>
            <div className="cv-metric-block">
              <strong>+1M</strong>
              <span>de transações concluídas sem falhas</span>
            </div>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 4: PORQUÊ ESCOLHER O CONSULVOLT ERP? ───────────────── */}
      <section id="porquê" className="cv-why-section">
        <div className="cv-why-container">
          <div style={{ textAlign: 'center', maxWidth: 740, margin: '0 auto' }}>
            <span style={{ color: '#0052ff', fontWeight: 700, fontSize: 14, letterSpacing: '0.04em', textTransform: 'uppercase' }}>
              PORQUÊ O CONSULVOLT ERP?
            </span>
            <h2 className="cv-section-title" style={{ marginTop: 8, marginBottom: 16 }}>
              A tecnologia mais ágil e fiável para o comércio em Angola
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

      {/* ── SECÇÃO 5: PLANOS E PREÇOS (MATCHING SCREENSHOT 4) ──────────── */}
      <section id="precos" className="cv-pricing-section">
        <div className="cv-pricing-container">
          <div className="cv-pricing-header">
            <h2>Crie já a sua conta Consulvolt e Experimente durante 30 dias Grátis</h2>
            <p>
              Escolha o melhor plano para o seu negócio e comece já a testar o Software de Faturação Online utilizado por milhares de empresas e profissionais em todo o país.
            </p>
          </div>

          <div className="cv-pricing-cards">
            {/* Card 1: Base */}
            <div className="cv-plan-card">
              <h3 className="cv-plan-name">Base</h3>
              <div className="cv-plan-subtitle">Faturação e Serviços</div>
              <div className="cv-plan-price-box">
                <span className="cv-plan-price">25.000 Kz</span>
                <span className="cv-plan-period">/ Mês</span>
              </div>
              <div className="cv-plan-annual-note">
                Ao subscrever anualmente por 250.000 Kz
              </div>
              <Link to="/registar?plano=basico" className="cv-btn-pill-orange cv-plan-btn">
                Experimente Grátis
              </Link>
              <ul className="cv-plan-features">
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>Faturação e Gestão</strong> Comercial</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>2</strong> Utilizadores incluídos</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Documentos <strong>Ilimitados</strong></span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>App</strong> Android e iOS</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Avenças e Contas Correntes</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Exportação automática <strong>SAF-T</strong></span>
                </li>
              </ul>
              <a href="#solucoes" className="cv-plan-bottom-link">Ver todas as funcionalidades</a>
            </div>

            {/* Card 2: Flex (Popular) */}
            <div className="cv-plan-card popular">
              <span className="cv-plan-popular-badge">Popular</span>
              <h3 className="cv-plan-name">Flex</h3>
              <div className="cv-plan-subtitle">Retalho & Negócios Locais</div>
              <div className="cv-plan-price-box">
                <span className="cv-plan-price">55.000 Kz</span>
                <span className="cv-plan-period">/ Mês</span>
              </div>
              <div className="cv-plan-annual-note">
                Ao subscrever anualmente por 550.000 Kz
              </div>
              <Link to="/registar?plano=profissional" className="cv-btn-pill-orange cv-plan-btn">
                Experimente Grátis
              </Link>
              <ul className="cv-plan-features">
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>Inclui funcionalidades do Base</strong></span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>5</strong> Utilizadores incluídos</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>POS Retalho</strong> Web, Android e iOS</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Impressão em <strong>Talão</strong> e <strong>A4</strong></span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Gestão de Autoconsumo e Quebras</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>API</strong> e Integrações Webservice</span>
                </li>
              </ul>
              <a href="#solucoes" className="cv-plan-bottom-link">Ver todas as funcionalidades</a>
            </div>

            {/* Card 3: Pro */}
            <div className="cv-plan-card">
              <h3 className="cv-plan-name">Pro</h3>
              <div className="cv-plan-subtitle">Restauração & Retalho Avançado</div>
              <div className="cv-plan-price-box">
                <span className="cv-plan-price">95.000 Kz</span>
                <span className="cv-plan-period">/ Mês</span>
              </div>
              <div className="cv-plan-annual-note">
                Ao subscrever anualmente por 950.000 Kz
              </div>
              <Link to="/registar?plano=empresarial" className="cv-btn-pill-orange cv-plan-btn">
                Experimente Grátis
              </Link>
              <ul className="cv-plan-features">
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>Inclui funcionalidades do Base e Flex</strong></span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Utilizadores <strong>Ilimitados</strong></span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>POS Restaurante</strong> Web, Android e iOS</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>Terminais</strong> e <strong>ecrãs</strong> de cozinha</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span><strong>Variações</strong> / Cores e Tamanhos</span>
                </li>
                <li className="cv-plan-feature">
                  <span className="cv-plan-check"><CheckCircleFilled /></span>
                  <span>Gestão de <strong>Stocks</strong>, Lotes e Compras</span>
                </li>
              </ul>
              <a href="#solucoes" className="cv-plan-bottom-link">Ver todas as funcionalidades</a>
            </div>
          </div>

          <div style={{ textAlign: 'center', marginTop: 32, fontSize: 13, color: '#64748b' }}>
            Os preços indicados incluem IVA à taxa legal em vigor na República de Angola.
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 6: TESTEMUNHOS DE CLIENTES (MATCHING SCREENSHOT 5) ── */}
      <section className="cv-testimonials-section">
        <div className="cv-testimonials-container">
          <div className="cv-testimonials-header">
            <div className="cv-testimonials-title-box">
              <h2>O que dizem os nossos clientes?</h2>
              <p>Conheça a opinião de clientes que confiam no Consulvolt diariamente para gerir o negócio de forma simples, prática e intuitiva.</p>
            </div>
            <div className="cv-carousel-controls">
              <button
                className="cv-carousel-btn prev"
                onClick={() => setSlideDepoimento((prev) => (prev > 0 ? prev - 1 : depoimentos.length - 1))}
                aria-label="Anterior"
              >
                <LeftOutlined />
              </button>
              <button
                className="cv-carousel-btn next"
                onClick={() => setSlideDepoimento((prev) => (prev < depoimentos.length - 1 ? prev + 1 : 0))}
                aria-label="Seguinte"
              >
                <RightOutlined />
              </button>
            </div>
          </div>

          <div className="cv-testimonials-grid">
            {[...depoimentos.slice(slideDepoimento), ...depoimentos.slice(0, slideDepoimento)].map((dep, index) => (
              <div key={index} className="cv-testimonial-card">
                <div className="cv-testimonial-image-box">
                  <img src={dep.foto} alt={dep.nome} />
                  <div className="cv-testimonial-video-badge">
                    <span className="cv-testimonial-video-dot"></span>
                    <span>▶ Play</span>
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
            <h3>Tem dúvidas sobre a faturação online da sua empresa?</h3>
            <p>
              A nossa equipa de apoio a clientes em Luanda está disponível para fazer uma demonstração personalizada e tirar todas as suas dúvidas.
            </p>
            <Link to="/registar" className="cv-btn-pill-orange" style={{ width: '100%', textAlign: 'center' }}>
              Fale Connosco
            </Link>
          </div>
        </div>
      </section>

      {/* ── SECÇÃO 8: DEMONSTRAÇÃO (5 SEGUNDOS PARA ESPREITAR) ────────── */}
      <section className="cv-demo-section">
        <div className="cv-demo-container">
          <h2>Será que o Consulvolt é para si? Pedimos-lhe apenas 5 segundos para espreitar!</h2>

          <div className="cv-demo-tabs">
            <button
              className={`cv-demo-tab-btn ${demoTab === 'tablet' ? 'active' : ''}`}
              onClick={() => setDemoTab('tablet')}
            >
              <TabletOutlined /> Tablet
            </button>
            <button
              className={`cv-demo-tab-btn ${demoTab === 'desktop' ? 'active' : ''}`}
              onClick={() => setDemoTab('desktop')}
            >
              <DesktopOutlined /> Desktop
            </button>
            <button
              className={`cv-demo-tab-btn ${demoTab === 'mobile' ? 'active' : ''}`}
              onClick={() => setDemoTab('mobile')}
            >
              <MobileOutlined /> Mobile
            </button>
          </div>

          <div className="cv-demo-mockup-frame">
            <div style={{ background: '#1e293b', padding: '10px 16px', display: 'flex', alignItems: 'center', gap: 8 }}>
              <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#ef4444', display: 'inline-block' }}></span>
              <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#f59e0b', display: 'inline-block' }}></span>
              <span style={{ width: 10, height: 10, borderRadius: '50%', background: '#10b981', display: 'inline-block' }}></span>
              <span style={{ color: '#94a3b8', fontSize: 12, marginLeft: 12 }}>consulvolt.ao · Painel em Tempo Real</span>
            </div>
            <div className="cv-demo-screen-content">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 20 }}>
                <div>
                  <h3 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: '#0f172a' }}>Painel de Gestão & POS</h3>
                  <small style={{ color: '#64748b' }}>Empresa Modelo, Lda · NIF: 5418291024</small>
                </div>
                <span style={{ background: '#e0e7ff', color: '#0052ff', padding: '6px 14px', borderRadius: 9999, fontSize: 12, fontWeight: 700 }}>
                  AGT Validado
                </span>
              </div>
              <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16 }}>
                <div style={{ background: '#ffffff', padding: 16, borderRadius: 12, border: '1px solid #e2e8f0' }}>
                  <small style={{ color: '#64748b' }}>Vendas do Dia</small>
                  <div style={{ fontSize: 22, fontWeight: 800, color: '#0052ff', marginTop: 4 }}>1.450.000 Kz</div>
                </div>
                <div style={{ background: '#ffffff', padding: 16, borderRadius: 12, border: '1px solid #e2e8f0' }}>
                  <small style={{ color: '#64748b' }}>Documentos Emitidos</small>
                  <div style={{ fontSize: 22, fontWeight: 800, color: '#0f172a', marginTop: 4 }}>84 faturas</div>
                </div>
                <div style={{ background: '#ffffff', padding: 16, borderRadius: 12, border: '1px solid #e2e8f0' }}>
                  <small style={{ color: '#64748b' }}>Saldo em Caixa</small>
                  <div style={{ fontSize: 22, fontWeight: 800, color: '#10b981', marginTop: 4 }}>320.500 Kz</div>
                </div>
              </div>
            </div>
          </div>

          <div className="cv-demo-footer-cta">
            <h3>Gostaria de continuar a explorar o Consulvolt ERP?</h3>
            <p>Registe-se hoje mesmo e usufrua de 30 dias gratuitos, sem qualquer compromisso!</p>
            <Link to="/registar" className="cv-btn-pill-orange large">
              Experimente Grátis
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
                Software de faturação online certificado pela AGT. Gestão simples e eficiente para retalho, restauração e prestação de serviços em Angola.
              </p>
              <div style={{ display: 'flex', gap: 10 }}>
                <span className="cv-country-badge" style={{ background: '#1e293b', color: '#cbd5e1' }}>
                  Angola 🇦🇴
                </span>
                <span className="cv-country-badge" style={{ background: '#1e293b', color: '#cbd5e1' }}>
                  Validado AGT Nº 142
                </span>
              </div>
            </div>

            <div className="cv-footer-col">
              <h4>Soluções</h4>
              <ul className="cv-footer-links">
                <li><a href="#solucoes">Faturação e Gestão</a></li>
                <li><a href="#solucoes">POS para Retalho</a></li>
                <li><a href="#solucoes">POS para Restauração</a></li>
                <li><a href="#solucoes">API & Webservice</a></li>
                <li><a href="#solucoes">Exportação SAF-T</a></li>
              </ul>
            </div>

            <div className="cv-footer-col">
              <h4>Plataforma</h4>
              <ul className="cv-footer-links">
                <li><a href="#precos">Planos e Preços</a></li>
                <li><a href="#porquê">Vantagens & Benefícios</a></li>
                <li><a href="#faq">Centro de Ajuda</a></li>
                <li><Link to="/entrar">Aceder à Conta (Login)</Link></li>
                <li><Link to="/admin/login">Portal de Administração</Link></li>
              </ul>
            </div>

            <div className="cv-footer-col">
              <h4>Suporte & Legal</h4>
              <ul className="cv-footer-links">
                <li><a href="#faq">Perguntas Frequentes</a></li>
                <li><a href="#faq">Contactar Apoio</a></li>
                <li><a href="#">Termos e Condições</a></li>
                <li><a href="#">Política de Privacidade</a></li>
                <li><a href="#">Obrigações Fiscais AGT</a></li>
              </ul>
            </div>
          </div>

          <div className="cv-footer-bottom">
            <div>
              © {new Date().getFullYear()} Consulvolt ERP. Todos os direitos reservados. Software de Gestão e Faturação.
            </div>
            <div>
              Tecnologia 100% Cloud · Servidores de Alta Disponibilidade
            </div>
          </div>
        </div>
      </footer>

      {/* ── BOTÃO FLUTUANTE DE CHAT (IGUAL AO VENDUS) ────────────────── */}
      <button
        className="cv-floating-chat"
        onClick={() => setChatAberto(!chatAberto)}
        aria-label="Abrir suporte por chat"
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
          <div style={{ background: '#0052ff', color: '#ffffff', padding: 16 }}>
            <strong style={{ display: 'block', fontSize: 15 }}>Apoio Consulvolt</strong>
            <small style={{ color: 'rgba(255,255,255,0.85)' }}>Estamos online para ajudar</small>
          </div>
          <div style={{ padding: 16, fontSize: 13, color: '#334155' }}>
            <p style={{ margin: '0 0 12px' }}>
              Olá! Gostaria de esclarecer dúvidas sobre os planos, certificação AGT ou começar a testar gratuitamente?
            </p>
            <Link
              to="/registar"
              className="cv-btn-pill-orange"
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
