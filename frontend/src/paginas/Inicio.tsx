import { Alert, Col, Row, Skeleton } from 'antd';
import { AppstoreOutlined, BulbOutlined, CheckCircleFilled, EnvironmentFilled, HomeFilled, LineChartOutlined, UnorderedListOutlined } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { iconeModulo } from '@/componentes/icones/iconesModulos';
import { ecrasDeTopo, rotaEcra } from '@/layout/itensMenu';

interface Pendente {
  id: string;
  rotulo: string;
  quantidade: number;
  vista?: string | null;
}

interface DadosInicio {
  saudacao?: string;
  empresa?: { nome?: string; nif?: string | null };
  pendentes?: Pendente[];
  total_pendentes?: number;
  dica_do_dia?: { titulo?: string; texto?: string } | string | null;
  comunicado_avaliacao?: { titulo?: string; texto?: string } | null;
}

/** Descrição de cada módulo na lista «Módulos» (textos do ecrã de boas-vindas do sistema anterior). */
const DESCRICOES: Readonly<Record<string, string>> = {
  geral: 'Painel de gestão, relatórios e indicadores.',
  rh: 'Gestão de pessoal, contratos e processamento.',
  contab: 'Diários, balancetes e demonstrações financeiras.',
  teso: 'Pagamentos, recebimentos e conciliação.',
  vendas: 'Facturação, clientes e contas a receber.',
  pos: 'Facturação rápida, caixa POS e fecho de turno.',
  compras: 'Aprovisionamento, fornecedores e encomendas.',
  stock: 'Níveis de stock, entradas, guias e inventário.',
  activos: 'Imobilizado, amortizações e manutenção.',
  projectos: 'Carteira de projectos, custos analíticos e planeamento Gantt.',
  crm: 'Pipeline comercial, agenda, previsão de vendas e campanhas.',
  acrescimos: 'Registos, propostas mensais e regularização.',
  orcamento: 'Orçamentos, controlo orçado vs realizado, previsões e cenários.',
  estrutura: 'Unidades, cargos e vagas, organigrama e mapa de pessoal.',
  config: 'Empresas, utilizadores, perfis e parâmetros do sistema.',
};

/**
 * Página inicial (GET /gestao/inicio, ADR-059) com o desenho do ecrã de boas-vindas do sistema anterior
 * (js/ui_dashboard.js → renderWelcome): saudação grande com o nome a azul, cartão escuro da empresa activa (NIF, data
 * de acesso e processos pendentes em «pílulas»), Dica do dia e, à direita, a lista dos módulos com caixa de ícone.
 */
export function Inicio() {
  const { menu, empresa, utilizador } = useSessao();
  const identidade = useIdentidade();
  const navegar = useNavigate();
  const consulta = useQuery({ queryKey: ['inicio'], queryFn: () => obter<DadosInicio>('/gestao/inicio') });

  // a vista do pendente aponta para o ecrã do catálogo; procura-se o módulo no menu do utilizador
  const rotaDaVista = (vista?: string | null) => {
    if (!vista) return null;
    for (const m of menu) {
      const e = m.ecras.find((x) => x.id === vista || x.vistas.includes(vista));
      if (e) return `/m/${m.id}/${e.id}`;
    }
    return null;
  };

  if (consulta.isLoading) return <Skeleton active />;
  const d = consulta.data ?? {};
  const dica = typeof d.dica_do_dia === 'string' ? { texto: d.dica_do_dia } : d.dica_do_dia;
  const nomeUtilizador = utilizador?.nome_completo || utilizador?.nome_utilizador || '';
  const nomeEmpresa = d.empresa?.nome ?? identidade.data?.nome ?? empresa?.nome ?? '';
  const nif = d.empresa?.nif ?? identidade.data?.nif ?? empresa?.nif ?? null;
  const pendentes = (d.pendentes ?? []).filter((p) => p.quantidade > 0);
  const total = d.total_pendentes ?? pendentes.reduce((s, p) => s + p.quantidade, 0);
  const painel = menu.flatMap((m) => m.ecras.filter((e) => /dashboard|painel/.test(e.id)).map((e) => rotaEcra(m.id, e.id)))[0] ?? null;

  return (
    <div className="erp-inicio">
      <Row gutter={[24, 24]} align="top">
        <Col xs={24} xl={16}>
          <h1 className="erp-inicio-saudacao">
            {d.saudacao ?? 'Bem-vindo'}
            {nomeUtilizador && (
              <>
                , <span className="erp-inicio-saudacao-nome">{nomeUtilizador}</span>!
              </>
            )}
          </h1>
          <p className="erp-inicio-subtitulo">Bem-vindo ao seu ecossistema de gestão empresarial.</p>
          {d.comunicado_avaliacao && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message={d.comunicado_avaliacao.titulo} description={d.comunicado_avaliacao.texto} />}

          <section className="erp-inicio-empresa" aria-label="Empresa activa">
            <span className="erp-inicio-cracha">Empresa activa</span>
            <h2 className="erp-inicio-empresa-nome">{nomeEmpresa}</h2>
            {identidade.data?.morada && (
              <div className="erp-inicio-empresa-morada">
                <EnvironmentFilled aria-hidden /> {identidade.data.morada}
              </div>
            )}
            <div className="erp-inicio-dados">
              <div>
                <div className="erp-inicio-dado-rotulo">NIF</div>
                <div className="erp-inicio-dado-valor">{nif || '—'}</div>
              </div>
              <div>
                <div className="erp-inicio-dado-rotulo">Data de acesso</div>
                <div className="erp-inicio-dado-valor">{dayjs().format('DD/MM/YYYY')}</div>
              </div>
            </div>
            <div className="erp-inicio-pendentes">
              <div className="erp-inicio-pendentes-cabeca">
                <UnorderedListOutlined aria-hidden style={{ opacity: 0.75 }} />
                <h3 className="erp-inicio-pendentes-titulo" style={{ margin: 0 }}>
                  Processos pendentes
                </h3>
                <span className="erp-inicio-total" style={{ background: total > 0 ? '#dc2626' : '#047857' }} aria-label={`${total} processos pendentes`}>
                  {total}
                </span>
              </div>
              {pendentes.length ? (
                <ul className="erp-inicio-pilulas" style={{ listStyle: 'none', margin: 0, padding: 0 }}>
                  {pendentes.map((p) => {
                    const rota = rotaDaVista(p.vista);
                    return (
                      <li key={p.id}>
                        <button type="button" className="erp-inicio-pilula" disabled={!rota} onClick={rota ? () => navegar(rota) : undefined}>
                          <span className="erp-inicio-pilula-numero">{p.quantidade}</span> {p.rotulo}
                        </button>
                      </li>
                    );
                  })}
                </ul>
              ) : (
                <div style={{ opacity: 0.85, fontSize: 13 }}>
                  <CheckCircleFilled aria-hidden style={{ color: '#34d399' }} /> Tudo em dia — sem processos pendentes.
                </div>
              )}
            </div>
            <HomeFilled className="erp-inicio-empresa-marca" aria-hidden />
          </section>

          <Row gutter={[16, 16]} style={{ marginTop: 24 }}>
            <Col xs={24} md={painel ? 12 : 24}>
              <div className="erp-inicio-mini">
                <span className="erp-caixa-icone" style={{ background: '#eff6ff', color: '#2563eb' }} aria-hidden>
                  <BulbOutlined />
                </span>
                <div style={{ minWidth: 0 }}>
                  <h3 className="erp-inicio-mini-titulo">{dica?.titulo || 'Dica do dia'}</h3>
                  <p className="erp-inicio-mini-texto">{dica?.texto ?? '—'}</p>
                </div>
              </div>
            </Col>
            {painel && (
              <Col xs={24} md={12}>
                <button type="button" className="erp-inicio-mini" onClick={() => navegar(painel)}>
                  <span className="erp-caixa-icone" style={{ background: '#f0fdf4', color: '#047857' }} aria-hidden>
                    <LineChartOutlined />
                  </span>
                  <span style={{ minWidth: 0 }}>
                    <span className="erp-inicio-mini-titulo" style={{ display: 'block' }}>
                      Painel de gestão
                    </span>
                    <span className="erp-inicio-mini-texto" style={{ display: 'block' }}>
                      Clique para ver a performance detalhada.
                    </span>
                  </span>
                </button>
              </Col>
            )}
          </Row>
        </Col>

        {menu.length > 0 && (
          <Col xs={24} xl={8}>
            <nav className="erp-inicio-modulos" aria-label="Atalhos dos módulos">
              <h2 className="erp-inicio-modulos-titulo">
                <AppstoreOutlined aria-hidden style={{ color: '#2563eb' }} /> Módulos
              </h2>
              <div className="erp-inicio-lista-modulos">
                {menu.map((m) => {
                  const primeiro = ecrasDeTopo(m)[0];
                  return (
                    <button
                      key={m.id}
                      type="button"
                      className="erp-modulo-botao"
                      disabled={!primeiro}
                      aria-label={`Abrir ${m.nome}`}
                      onClick={primeiro ? () => navegar(rotaEcra(m.id, primeiro.id)) : undefined}
                    >
                      <span className="erp-caixa-icone" aria-hidden>
                        {iconeModulo(m.id)}
                      </span>
                      <span className="erp-modulo-textos">
                        <span className="erp-modulo-nome">{m.nome}</span>
                        <span className="erp-modulo-descricao">{DESCRICOES[m.id] ?? (m.ecras.length === 1 ? '1 ecrã' : `${m.ecras.length} ecrãs`)}</span>
                      </span>
                    </button>
                  );
                })}
              </div>
            </nav>
          </Col>
        )}
      </Row>
    </div>
  );
}
