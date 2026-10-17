import { Button, Col, Row, Skeleton } from 'antd';
import { CheckCircleFilled, EnvironmentFilled, StarFilled } from '@ant-design/icons';
import dayjs from 'dayjs';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import type { ModuloMenu } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useFavoritos } from '@/componentes/preferencias/favoritos';
import { usePreferencia } from '@/componentes/preferencias/usePreferencias';
import { ecrasDeTopo, rotaEcra } from '@/layout/itensMenu';
import { ResumoHolding, type DadosResumoHolding } from '@/modulos/geral/comum/ResumoHolding';

const SEM_ORDEM: string[] = [];

/** Move `id` para a posição de `destino` (arrastar e largar dos módulos do Início; legado Sortable em ui_dashboard.js:937). */
export function moverModulo(ids: string[], id: string, destino: string): string[] {
  if (id === destino) return ids;
  const sem = ids.filter((x) => x !== id);
  const i = sem.indexOf(destino);
  const original = ids.indexOf(destino);
  sem.splice(ids.indexOf(id) < original ? i + 1 : i, 0, id);
  return sem;
}

/** Módulos do menu pela ordem guardada pelo utilizador (os novos, que não estão na ordem, vão para o fim). */
export function ordenarModulos(menu: ModuloMenu[], ordem: string[]): ModuloMenu[] {
  const pos = new Map(ordem.map((id, i) => [id, i]));
  return [...menu].sort((a, b) => (pos.get(a.id) ?? 1e6 + menu.indexOf(a)) - (pos.get(b.id) ?? 1e6 + menu.indexOf(b)));
}

/**
 * Lista «Módulos» do Início pela ordem, nomes, descrições e ícones do legado (ui_dashboard.js:720-732). Os módulos do
 * sistema novo que o legado não lista (Logística, Acréscimos, Configurações) vêm no fim; «Geral» fica de fora (os seus
 * ecrãs estão no topo do menu e no cartão «Dashboard Global»).
 */
export const MODULOS_INICIO: readonly { id: string; nome: string; icone: string; descricao: string }[] = [
  { id: 'rh', nome: 'Recursos Humanos', icone: 'users', descricao: 'Gestão de pessoal, contratos e processamento.' },
  { id: 'teso', nome: 'Tesouraria', icone: 'university', descricao: 'Pagamentos, recebimentos e conciliação.' },
  { id: 'pos', nome: 'Ponto de Venda (POS)', icone: 'cash-register', descricao: 'Facturação rápida, caixa POS e fecho de turno.' },
  { id: 'vendas', nome: 'Vendas e Clientes', icone: 'shopping-cart', descricao: 'Facturação, clientes e contas a receber.' },
  { id: 'compras', nome: 'Compras e Stocks', icone: 'truck-loading', descricao: 'Aprovisionamento, fornecedores e armazém.' },
  { id: 'contab', nome: 'Contabilidade Geral', icone: 'book', descricao: 'Diários, balancetes e demonstrações financeiras.' },
  { id: 'activos', nome: 'Ativos Fixos', icone: 'couch', descricao: 'Imobilizado, amortizações e manutenção.' },
  { id: 'projectos', nome: 'Projectos e Obras', icone: 'hard-hat', descricao: 'Carteira de projectos, custos analíticos e planeamento Gantt.' },
  { id: 'orcamento', nome: 'Gestão Orçamental', icone: 'file-invoice-dollar', descricao: 'Orçamentos, controlo orçado vs realizado, previsões e cenários.' },
  { id: 'crm', nome: 'CRM', icone: 'handshake', descricao: 'Pipeline comercial, agenda, previsão de vendas e campanhas.' },
  { id: 'estrutura', nome: 'Estrutura Orgânica', icone: 'sitemap', descricao: 'Unidades, cargos e vagas, organigrama e mapa de pessoal.' },
  { id: 'stock', nome: 'Logística', icone: 'warehouse', descricao: 'Níveis de stock, entradas, guias e inventário.' },
  { id: 'acrescimos', nome: 'Acréscimos e Diferimentos', icone: 'exchange-alt', descricao: 'Registos, propostas mensais e regularização.' },
  { id: 'config', nome: 'Configurações', icone: 'cogs', descricao: 'Empresas, utilizadores, perfis e parâmetros do sistema.' },
];

/** Módulos do menu do utilizador na ordem do legado e com os nomes do legado (os que não estão na lista vão para o fim). */
export function modulosDoInicio(menu: ModuloMenu[]): ModuloMenu[] {
  const conhecidos = MODULOS_INICIO.flatMap((d) => {
    const m = menu.find((x) => x.id === d.id);
    return m ? [{ ...m, nome: d.nome }] : [];
  });
  return [...conhecidos, ...menu.filter((m) => m.id !== 'geral' && !MODULOS_INICIO.some((d) => d.id === m.id))];
}

/** Ícone e cor de cada pendente (legado: pílulas com ícone colorido, ui_dashboard.js:782-794). */
export const ICONES_PENDENTES: Readonly<Record<string, readonly [string, string]>> = {
  teso_docs: ['sync', '#fbbf24'],
  teso_conciliacao: ['check-double', '#60a5fa'],
  vendas_por_liquidar: ['file-invoice', '#34d399'],
  compras_pedidos: ['clipboard-list', '#fb923c'],
  compras_encomendas: ['truck', '#f472b6'],
  rh_periodos_abertos: ['calculator', '#a78bfa'],
  ativos_inventariar: ['couch', '#f9a8d4'],
  ativos_sem_amortizacao: ['percent', '#67e8f9'],
  ativos_manutencoes: ['tools', '#fde68a'],
  orc_por_aprovar: ['file-signature', '#93c5fd'],
  orc_excessos: ['bell', '#fca5a5'],
  acrescimos_regularizar: ['exchange-alt', '#5eead4'],
  crm_atraso: ['calendar-times', '#fdba74'],
  estrutura_vagas: ['user-plus', '#c4b5fd'],
  cambios_bai: ['coins', '#fcd34d'],
  portal_pedidos: ['inbox', '#fcd34d'],
  avaliacao_360: ['star-half-alt', '#a5b4fc'],
  consolidacao_desactualizada: ['layer-group', '#c4b5fd'],
  consolidacao_divergencias: ['exclamation-triangle', '#fca5a5'],
};

/** Texto do comunicado da avaliação (o servidor devolve { nome, comunicado } ou { titulo, texto }); nulo se não houver texto. */
export function textoComunicado(c: DadosInicio['comunicado_avaliacao']): { titulo: string; texto: string } | null {
  if (!c) return null;
  const corpo = c.comunicado;
  const texto = String(c.texto ?? (typeof corpo === 'string' ? corpo : corpo?.texto ?? corpo?.mensagem ?? '')).trim();
  if (!texto) return null;
  const tituloCorpo = typeof corpo === 'object' && corpo ? corpo.titulo : undefined;
  const titulo = c.titulo || tituloCorpo || (c.nome ? `Avaliação de desempenho — ${c.nome}` : 'Avaliação de desempenho');
  return { titulo, texto };
}

interface Pendente {
  id: string;
  rotulo: string;
  quantidade: number;
  vista?: string | null;
}

interface DadosInicio {
  saudacao?: string;
  empresa?: { nome?: string; nif?: string | null; holding?: boolean };
  /** Só numa holding: resumo consolidado e comparação das empresas do grupo. */
  holding?: DadosResumoHolding | null;
  pendentes?: Pendente[];
  total_pendentes?: number;
  dica_do_dia?: { titulo?: string; texto?: string } | string | null;
  comunicado_avaliacao?: {
    titulo?: string;
    texto?: string;
    nome?: string;
    comunicado?: string | { titulo?: string; texto?: string; mensagem?: string } | null;
  } | null;
}

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
  const { favoritos, eFavorito, alternar } = useFavoritos();
  // ordem dos módulos guardada no servidor por utilizador (legado: localStorage welcome_modules_order)
  const [ordem, definirOrdem] = usePreferencia<string[]>('ordem_modulos', 'inicio', SEM_ORDEM);
  const modulosOrdenados = useMemo(() => ordenarModulos(modulosDoInicio(menu), ordem), [menu, ordem]);
  const [arrastado, setArrastado] = useState<string | null>(null);
  const [alvo, setAlvo] = useState<string | null>(null);

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
          {/* O comunicado da avaliação 360º não aparece aqui: no legado só está no Portal do Colaborador › Avaliação (com
              confirmação de leitura); no Início conta apenas na pílula «Avaliação de desempenho: acções pendentes». */}

          <section className="erp-inicio-empresa" aria-label="Empresa activa">
            <span className="erp-inicio-cracha">{d.empresa?.holding ? 'Holding · contas consolidadas' : 'Empresa activa'}</span>
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
                <IconeFa nome="tasks" style={{ opacity: 0.75 }} />
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
                    const [ic, cor] = ICONES_PENDENTES[p.id] ?? ['circle-regular', '#cbd5e1'];
                    return (
                      <li key={p.id}>
                        <button type="button" className="erp-inicio-pilula" disabled={!rota} onClick={rota ? () => navegar(rota) : undefined}>
                          <IconeFa nome={ic} className="erp-inicio-pilula-icone" style={{ color: cor }} />
                          <span>
                            <span className="erp-inicio-pilula-numero">{p.quantidade}</span> {p.rotulo}
                          </span>
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
            <IconeFa nome="city" className="erp-inicio-empresa-marca" />
          </section>

          <Row gutter={[16, 16]} style={{ marginTop: 24 }}>
            <Col xs={24} md={painel ? 12 : 24}>
              <div className="erp-inicio-mini">
                <span className="erp-caixa-icone" style={{ background: '#eff6ff', color: '#2563eb' }} aria-hidden>
                  <IconeFa nome="lightbulb" />
                </span>
                <div style={{ minWidth: 0 }}>
                  <h3 className="erp-inicio-mini-titulo">{dica?.titulo || 'Dica do Dia'}</h3>
                  <p className="erp-inicio-mini-texto">{dica?.texto ?? '—'}</p>
                </div>
              </div>
            </Col>
            {painel && (
              <Col xs={24} md={12}>
                <button type="button" className="erp-inicio-mini" onClick={() => navegar(painel)}>
                  <span className="erp-caixa-icone" style={{ background: '#f0fdf4', color: '#047857' }} aria-hidden>
                    <IconeFa nome="chart-pie" />
                  </span>
                  <span style={{ minWidth: 0 }}>
                    <span className="erp-inicio-mini-titulo" style={{ display: 'block' }}>
                      Dashboard Global
                    </span>
                    <span className="erp-inicio-mini-texto" style={{ display: 'block' }}>
                      Clique para ver performance detalhada.
                    </span>
                  </span>
                </button>
              </Col>
            )}
          </Row>

          {d.holding && (
            <ResumoHolding
              dados={d.holding}
              rotaPermitida={(rota) => {
                const [, , modulo, ecra] = rota.split('?')[0].split('/');
                return menu.some((m) => m.id === modulo && m.ecras.some((e) => e.id === ecra));
              }}
            />
          )}

          <section className="erp-inicio-mini" style={{ marginTop: 16, display: 'block' }} aria-label="Favoritos">
            <h3 className="erp-inicio-mini-titulo">
              <StarFilled aria-hidden style={{ color: '#f59e0b' }} /> Favoritos
            </h3>
            {favoritos.length ? (
              <div className="erp-inicio-favoritos">
                {favoritos.map((f) => (
                  <Button key={f.rota} size="small" onClick={() => navegar(f.rota)} title={f.nomeModulo}>
                    {f.nome}
                  </Button>
                ))}
              </div>
            ) : (
              <p className="erp-inicio-mini-texto" style={{ margin: 0 }}>
                Ainda não tem favoritos: abra um ecrã e clique na estrela ☆ da barra superior. Use Ctrl+K para procurar um ecrã pelo nome.
              </p>
            )}
          </section>
        </Col>

        {menu.length > 0 && (
          <Col xs={24} xl={8}>
            <nav className="erp-inicio-modulos" aria-label="Atalhos dos módulos">
              <div className="erp-inicio-modulos-cabeca">
                <h2 className="erp-inicio-modulos-titulo">
                  <IconeFa nome="th-large" style={{ color: '#2563eb' }} /> Módulos
                </h2>
                <span className="erp-inicio-arrastar" title="Arraste os módulos para os reordenar (Alt+↑/↓ com o teclado)">
                  <IconeFa nome="arrows-alt" /> Arrastar
                </span>
              </div>
              <div className="erp-inicio-lista-modulos">
                {modulosOrdenados.map((m) => {
                  const primeiro = ecrasDeTopo(m)[0];
                  const def = MODULOS_INICIO.find((x) => x.id === m.id);
                  return (
                    <div
                      key={m.id}
                      className={`erp-modulo-arrastavel${arrastado === m.id ? ' a-arrastar' : ''}${alvo === m.id && arrastado !== m.id ? ' alvo' : ''}`}
                      draggable
                      onDragStart={(e) => {
                        setArrastado(m.id);
                        e.dataTransfer.effectAllowed = 'move';
                        e.dataTransfer.setData('text/plain', m.id);
                      }}
                      onDragOver={(e) => {
                        e.preventDefault();
                        setAlvo(m.id);
                      }}
                      onDragEnd={() => {
                        setArrastado(null);
                        setAlvo(null);
                      }}
                      onDrop={(e) => {
                        e.preventDefault();
                        if (arrastado) definirOrdem(moverModulo(modulosOrdenados.map((x) => x.id), arrastado, m.id));
                        setArrastado(null);
                        setAlvo(null);
                      }}
                    >
                      <button
                        type="button"
                        className="erp-modulo-botao"
                        disabled={!primeiro}
                        aria-label={`Abrir ${m.nome}`}
                        title="Clique para abrir; arraste para reordenar (Alt+↑/↓ com o teclado)"
                        onClick={primeiro ? () => navegar(rotaEcra(m.id, primeiro.id)) : undefined}
                        onKeyDown={(e) => {
                          if (e.altKey && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
                            e.preventDefault();
                            const ids = modulosOrdenados.map((x) => x.id);
                            const i = ids.indexOf(m.id);
                            const j = e.key === 'ArrowUp' ? i - 1 : i + 1;
                            if (j >= 0 && j < ids.length) definirOrdem(moverModulo(ids, m.id, ids[j]));
                          }
                        }}
                      >
                        <span className="erp-caixa-icone erp-caixa-icone-modulo" aria-hidden>
                          <IconeFa nome={def?.icone ?? 'circle-regular'} />
                        </span>
                        <span className="erp-modulo-textos">
                          <span className="erp-modulo-nome">{m.nome}</span>
                          <span className="erp-modulo-descricao">{def?.descricao ?? (m.ecras.length === 1 ? '1 ecrã' : `${m.ecras.length} ecrãs`)}</span>
                        </span>
                        <IconeFa nome="grip-vertical" className="erp-modulo-pega" />
                      </button>
                      {primeiro && (
                        <button
                          type="button"
                          className={`erp-modulo-estrela${eFavorito(m.id, primeiro.id) ? ' activa' : ''}`}
                          title="Adicionar/Remover Favorito"
                          aria-label={`${eFavorito(m.id, primeiro.id) ? 'Remover' : 'Adicionar'} ${m.nome} dos favoritos`}
                          aria-pressed={eFavorito(m.id, primeiro.id)}
                          onClick={() => alternar(m.id, primeiro.id)}
                        >
                          <IconeFa nome={eFavorito(m.id, primeiro.id) ? 'star' : 'star-regular'} />
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
              {ordem.length > 0 && (
                <Button type="link" size="small" onClick={() => definirOrdem([])} style={{ paddingInline: 0, marginTop: 8 }}>
                  Repor a ordem original dos módulos
                </Button>
              )}
            </nav>
          </Col>
        )}
      </Row>
    </div>
  );
}
