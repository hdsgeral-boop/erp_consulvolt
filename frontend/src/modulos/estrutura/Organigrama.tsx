import { Button, Input, Modal, Select, Skeleton, message } from 'antd';
import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useRef, useState, type KeyboardEvent, type MouseEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar } from '@/api/cliente';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotaoImprimir } from '@/componentes/impressao';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { useColaboradores, useCargos } from '@/modulos/rh/comum/consultas';
import { TIPOS_INFO, aplanar, construirArvore, tipoInfo, type NoUnidade } from './comum/arvore';
import { caminho, estadoVagas, idsEmColuna, idsEmpilharUltimos, idsRecolher, nomePosto, ocupantesPorPosto, proximoZoom, type ColaboradorEstrutura } from './comum/legado';
import { useEstrutura } from './Estrutura';
import './comum/estrutura.css';

type Vista = 'funcional' | 'nominal';
type Orientacao = 'vertical' | 'horizontal';
const CHAVE_ORIENTACAO = 'est_org_orientacao';

/** Orientação memorizada no navegador (como no antigo); sem armazenamento fica só nesta sessão. */
function lerOrientacao(): Orientacao {
  try {
    return window.localStorage.getItem(CHAVE_ORIENTACAO) === 'horizontal' ? 'horizontal' : 'vertical';
  } catch {
    return 'vertical';
  }
}
function gravarOrientacao(v: Orientacao) {
  try {
    window.localStorage.setItem(CHAVE_ORIENTACAO, v);
  } catch {
    /* sem armazenamento */
  }
}

const premido = (v: boolean) => (v ? 'true' : 'false');

/**
 * Estrutura orgânica › Organigrama (est_organigrama), com os controlos do sistema anterior: Editar estrutura,
 * Funcional / Nominal, Vertical / Horizontal, A partir de, Procurar, zoom (− 100% +), Expandir / Recolher,
 * Empilhar últimos níveis / Tudo lado a lado (disposição gravada por unidade), Imprimir e Ecrã inteiro.
 */
export default function Organigrama() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const cliente = useQueryClient();
  const estrutura = useEstrutura();
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const editar = pode('est_editar');
  const [vista, setVista] = useState<Vista>('funcional');
  const [orientacao, setOrientacao] = useState<Orientacao>(lerOrientacao);
  const [raiz, setRaiz] = useState<number | null>(null);
  const [zoom, setZoom] = useState(1);
  const [fechados, setFechados] = useState<Set<number>>(new Set());
  const [texto, setTexto] = useState('');
  const [procura, setProcura] = useState('');
  const [detalhe, setDetalhe] = useState<NoUnidade | null>(null);
  const [aGravar, setAGravar] = useState(false);
  const palco = useRef<HTMLDivElement>(null);
  const area = useRef<HTMLDivElement>(null);

  const arvore = useMemo(() => construirArvore(estrutura.data?.unidades ?? []), [estrutura.data]);
  const nos = useMemo(() => aplanar(arvore), [arvore]);
  const lista = colaboradores.lista as ColaboradorEstrutura[];
  const ocupantes = useMemo(() => ocupantesPorPosto(lista), [lista]);
  const membrosDe = useMemo(() => {
    const m = new Map<number, ColaboradorEstrutura[]>();
    lista.filter((c) => c.estado === 'ACTIVO' && c.unidade_organica_id).forEach((c) => m.set(c.unidade_organica_id!, [...(m.get(c.unidade_organica_id!) ?? []), c]));
    return m;
  }, [lista]);
  const visivel = raiz ? nos.filter((n) => n.unidade.id === raiz) : arvore;
  const termo = procura.trim().toLocaleLowerCase('pt');
  const combina = (n: NoUnidade) => {
    if (!termo) return false;
    const resp = n.unidade.colaborador_responsavel_id ? colaboradores.mapa.get(n.unidade.colaborador_responsavel_id) : undefined;
    return n.unidade.nome.toLocaleLowerCase('pt').includes(termo)
      || (membrosDe.get(n.unidade.id) ?? []).some((c) => c.nome_completo.toLocaleLowerCase('pt').includes(termo))
      || !!resp?.nome_completo.toLocaleLowerCase('pt').includes(termo);
  };

  // procura com atraso (250 ms) e abertura dos ramos que levam às caixas encontradas
  useEffect(() => {
    const t = window.setTimeout(() => setProcura(texto), 250);
    return () => window.clearTimeout(t);
  }, [texto]);
  useEffect(() => {
    if (!termo) return;
    const abrir = new Set<number>();
    nos.filter(combina).forEach((n) => caminho(arvore, n.unidade.id).forEach((a) => abrir.add(a.unidade.id)));
    if (abrir.size) setFechados((f) => new Set([...f].filter((id) => !abrir.has(id))));
    window.setTimeout(() => palco.current?.querySelector('.org-caixa.realce')?.scrollIntoView?.({ block: 'center', inline: 'center', behavior: 'smooth' }), 50);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [termo, arvore]);

  const alternar = (id: number) => setFechados((f) => {
    const n = new Set(f);
    if (n.has(id)) n.delete(id);
    else n.add(id);
    return n;
  });
  const mudarOrientacao = (v: Orientacao) => {
    setOrientacao(v);
    gravarOrientacao(v);
  };
  const gravarDisposicao = async (ids: number[], disposicao: 'COLUNA' | null) => {
    setAGravar(true);
    try {
      await enviar('post', '/estrutura/disposicao', { ids, disposicao });
      await cliente.invalidateQueries({ queryKey: ['rh', 'estrutura'] });
    } catch (e) {
      notificarErro(e, 'Não foi possível gravar a disposição');
    } finally {
      setAGravar(false);
    }
  };
  const dispTodos = (modo: 'finais' | 'linha') => {
    const ids = modo === 'finais' ? idsEmpilharUltimos(arvore) : idsEmColuna(arvore);
    if (!ids.length) return void message.info(modo === 'finais' ? 'Não há unidades com várias subunidades de último nível.' : 'Todas as subunidades já estão lado a lado.');
    void gravarDisposicao(ids, modo === 'finais' ? 'COLUNA' : null);
  };
  const ecraInteiro = () => {
    if (document.fullscreenElement) void document.exitFullscreen?.().catch(() => undefined);
    else if (palco.current?.requestFullscreen) palco.current.requestFullscreen().catch(() => message.warning('O navegador não permitiu o ecrã inteiro.'));
  };

  const caixa = (n: NoUnidade) => {
    const u = n.unidade;
    const ti = tipoInfo(u.tipo);
    const fechado = fechados.has(u.id);
    const membros = membrosDe.get(u.id) ?? [];
    const resp = u.colaborador_responsavel_id ? colaboradores.mapa.get(u.colaborador_responsavel_id) : undefined;
    const vagas = u.postos.reduce((s, p) => s + (p.vagas ?? 0), 0);
    const emAberto = u.postos.reduce((s, p) => s + Math.max(0, (p.vagas ?? 0) - p.ocupados), 0);
    const acima = u.postos.reduce((s, p) => s + ((p.vagas ?? 0) ? Math.max(0, p.ocupados - (p.vagas ?? 0)) : 0), 0);
    const abrir = () => setDetalhe(n);
    let corpo;
    if (vista === 'funcional') {
      corpo = u.postos.length ? (
        <ul className="lst">
          {u.postos.slice(0, 8).map((p) => {
            const v = p.vagas ?? 0;
            const cor = v && p.ocupados < v ? '#b45309' : v && p.ocupados > v ? '#b91c1c' : '#166534';
            return <li key={p.id}><span>{nomePosto(p, cargos.nome)}</span><span className="v" style={{ color: cor }}>{p.ocupados}/{v || '—'}</span></li>;
          })}
          {u.postos.length > 8 && <li><span>+ {u.postos.length - 8} cargos</span><span /></li>}
        </ul>
      ) : <ul className="lst"><li><span style={{ color: '#94a3b8' }}>Sem cargos definidos</span><span /></li></ul>;
    } else {
      const outros = membros.filter((c) => c.id !== resp?.id);
      corpo = (
        <>
          {resp ? (
            <div className="resp"><IconeFa nome="user-tie" /> {resp.nome_completo}<small>{resp.cargo_funcao_id ? cargos.nome(resp.cargo_funcao_id) : 'Responsável'}</small></div>
          ) : <div className="resp" style={{ color: '#b45309' }}>Responsável por designar</div>}
          {outros.length > 0 && (
            <ul className="lst">
              {outros.slice(0, 7).map((c) => {
                const p = u.postos.find((x) => x.id === c.posto_trabalho_id);
                return <li key={c.id}><span>{c.nome_completo}</span><span style={{ color: '#64748b' }}>{p ? nomePosto(p, cargos.nome) : c.cargo_funcao_id ? cargos.nome(c.cargo_funcao_id) : ''}</span></li>;
              })}
              {outros.length > 7 && <li><span>+ {outros.length - 7} pessoas</span><span /></li>}
            </ul>
          )}
        </>
      );
    }
    const pararE = (ev: MouseEvent, f: () => void) => { ev.stopPropagation(); f(); };
    return (
      <div
        className={`org-caixa${u.apoio ? ' apoio' : ''}${combina(n) ? ' realce' : ''}${u.ativo ? '' : ' inactiva'}`}
        style={{ ['--cor' as string]: u.cor || ti.cor }}
        role="button"
        tabIndex={0}
        aria-label={u.nome}
        onClick={abrir}
        onKeyDown={(e: KeyboardEvent) => { if (e.key === 'Enter') abrir(); }}
      >
        <div className="t">{ti.nome}{u.apoio ? ' · apoio' : ''}</div>
        <div className="nm">{u.nome}</div>
        {corpo}
        <div className="rod">
          <span><IconeFa nome="users" /> {membros.length}{n.membrosTotal !== membros.length ? ` (${n.membrosTotal})` : ''}</span>
          <span>
            {vagas ? [
              emAberto > 0 ? <b key="a" style={{ color: '#b45309' }}>{emAberto} em aberto</b> : null,
              acima ? <b key="x" style={{ color: '#b91c1c' }}>+{acima} acima</b> : null,
              emAberto <= 0 && !acima ? <span key="c">vagas completas</span> : null,
            ].filter(Boolean).flatMap((e, i) => (i ? [' · ', e] : [e])) : null}
          </span>
        </div>
        {n.filhos.length >= 2 && orientacao !== 'horizontal' && editar && (
          <button type="button" className="dsp" aria-pressed={premido(u.disposicao === 'COLUNA')} disabled={aGravar}
            title={u.disposicao === 'COLUNA' ? 'Subunidades lado a lado' : 'Subunidades umas por baixo das outras'}
            aria-label={`${u.disposicao === 'COLUNA' ? 'Pôr lado a lado as subunidades de' : 'Empilhar as subunidades de'} ${u.nome}`}
            onClick={(ev) => pararE(ev, () => void gravarDisposicao([u.id], u.disposicao === 'COLUNA' ? null : 'COLUNA'))}>
            <IconeFa nome={u.disposicao === 'COLUNA' ? 'grip-lines' : 'grip-lines-vertical'} />
          </button>
        )}
        {n.filhos.length > 0 && (
          <button type="button" className="tgl" aria-label={`${fechado ? 'Expandir' : 'Recolher'} ${u.nome}`} onClick={(ev) => pararE(ev, () => alternar(u.id))}>
            {fechado ? '+' : '−'}
          </button>
        )}
      </div>
    );
  };

  const vertical = orientacao !== 'horizontal';
  const ramo = (lista: NoUnidade[], coluna = false) => (
    <ul className={coluna ? 'coluna' : undefined}>
      {lista.map((n) => {
        // unidades de apoio primeiro (junto à chefia)
        const filhos = [...n.filhos].sort((a, b) => (b.unidade.apoio ? 1 : 0) - (a.unidade.apoio ? 1 : 0));
        return (
          <li key={n.unidade.id}>
            {caixa(n)}
            {filhos.length > 0 && !fechados.has(n.unidade.id) && ramo(filhos, vertical && n.unidade.disposicao === 'COLUNA')}
          </li>
        );
      })}
    </ul>
  );
  const nomeRaiz = raiz ? nos.find((n) => n.unidade.id === raiz)?.unidade.nome : null;
  const titulo = `Organigrama ${vista === 'funcional' ? 'funcional' : 'nominal'}${orientacao === 'horizontal' ? ' (horizontal)' : ''}`;

  return (
    <>
      <CabecalhoModulo
        titulo="Organigrama"
        subtitulo={<><b>Funcional</b>: unidades e cargos com vagas ocupadas/previstas. <b>Nominal</b>: responsáveis e pessoas de cada unidade. Clique numa caixa para ver o detalhe; use o botão ± para recolher ramos.</>}
        accoes={<Button icon={<IconeFa nome="edit" />} onClick={() => navegar('../est_estrutura')}>Editar estrutura</Button>}
      />
      <div className="org-barra imp-nao-imprimir">
        <div className="org-grupo">
          <span className="org-rot">Vista</span>
          <div className="org-seg" role="group" aria-label="Vista">
            <button type="button" aria-pressed={premido(vista === 'funcional')} onClick={() => setVista('funcional')}><IconeFa nome="briefcase" /> Funcional</button>
            <button type="button" aria-pressed={premido(vista === 'nominal')} onClick={() => setVista('nominal')}><IconeFa nome="user-friends" /> Nominal</button>
          </div>
        </div>
        <div className="org-grupo">
          <span className="org-rot">Orientação</span>
          <div className="org-seg" role="group" aria-label="Orientação">
            <button type="button" aria-pressed={premido(orientacao === 'vertical')} title="De cima para baixo" onClick={() => mudarOrientacao('vertical')}><IconeFa nome="sitemap" /> Vertical</button>
            <button type="button" aria-pressed={premido(orientacao === 'horizontal')} title="Da esquerda para a direita" onClick={() => mudarOrientacao('horizontal')}><IconeFa nome="sitemap" style={{ transform: 'rotate(-90deg)' }} /> Horizontal</button>
          </div>
        </div>
        <div className="org-grupo">
          <label className="org-rot" htmlFor="org-raiz">A partir de</label>
          <Select id="org-raiz" style={{ minWidth: 200, maxWidth: '100%' }} value={raiz ?? ''} onChange={(v: number | '') => setRaiz(v === '' ? null : v)}
            options={[{ value: '', label: 'Toda a empresa' }, ...nos.filter((n) => n.filhos.length).map((n) => ({ value: n.unidade.id, label: caminho(arvore, n.unidade.id).map((x) => x.unidade.nome).join(' › ') }))]} />
        </div>
        <div className="org-grupo" style={{ flex: 1, minWidth: 180 }}>
          <label className="org-rot" htmlFor="org-proc">Procurar unidade ou pessoa</label>
          <Input id="org-proc" value={texto} onChange={(e) => setTexto(e.target.value)} maxLength={100} />
        </div>
        <div className="org-grupo">
          <span className="org-rot">Zoom</span>
          <div className="org-seg">
            <button type="button" aria-label="Diminuir" onClick={() => setZoom((z) => proximoZoom(z, -1))}><IconeFa nome="search-minus" /></button>
            <button type="button" aria-label="Ajustar" onClick={() => setZoom(1)}>{Math.round(zoom * 100)}%</button>
            <button type="button" aria-label="Aumentar" onClick={() => setZoom((z) => proximoZoom(z, 1))}><IconeFa nome="search-plus" /></button>
          </div>
        </div>
        <div className="org-grupo">
          <span className="org-rot">Ramos</span>
          <div className="org-seg">
            <button type="button" onClick={() => setFechados(new Set())}>Expandir</button>
            <button type="button" onClick={() => setFechados(new Set(idsRecolher(arvore)))}>Recolher</button>
          </div>
        </div>
        {editar && vertical && (
          <div className="org-grupo">
            <span className="org-rot">Disposição (vertical)</span>
            <div className="org-seg">
              <button type="button" disabled={aGravar} title="Nas unidades cujas subunidades não têm mais níveis, pôr as subunidades umas por baixo das outras" onClick={() => dispTodos('finais')}>
                <IconeFa nome="grip-lines" /> Empilhar últimos níveis
              </button>
              <button type="button" disabled={aGravar} title="Todas as subunidades lado a lado" onClick={() => dispTodos('linha')}>
                <IconeFa nome="grip-lines-vertical" /> Tudo lado a lado
              </button>
            </div>
          </div>
        )}
        <div className="est-accoes" style={{ marginLeft: 'auto' }}>
          <BotaoImprimir
            desactivado={!visivel.length}
            chave="estrutura/organigrama"
            obterPedido={() => (area.current && visivel.length ? {
              titulo,
              filtros: nomeRaiz ? `A partir de: ${nomeRaiz}` : 'Toda a empresa',
              orientacao: 'paisagem',
              conteudo: area.current,
            } : null)}
          />
          <Button icon={<IconeFa nome="expand" />} onClick={ecraInteiro}>Ecrã inteiro</Button>
        </div>
      </div>
      <div className="org-palco" ref={palco} tabIndex={0} aria-label="Organigrama">
        {estrutura.isLoading || colaboradores.isLoading ? (
          <Skeleton active />
        ) : !visivel.length ? (
          <div style={{ textAlign: 'center', padding: '2rem', color: '#64748b' }}>
            Ainda não há estrutura orgânica. <button type="button" className="est-ligacao" onClick={() => navegar('../est_estrutura')}>Criar a estrutura</button>
          </div>
        ) : (
          <div ref={area} className={`org-arvore organigrama${orientacao === 'horizontal' ? ' horiz' : ''}`} style={{ transform: `scale(${zoom})` }}>
            {ramo(visivel)}
          </div>
        )}
      </div>
      <div className="org-legenda">
        {Object.values(TIPOS_INFO).map((t) => <span key={t.nome}><i style={{ background: t.cor }} />{t.nome}</span>)}
        <span style={{ marginLeft: 'auto' }}>Funcional: ocupados/vagas · <b style={{ color: '#b45309' }}>âmbar</b> vagas em aberto · <b style={{ color: '#b91c1c' }}>vermelho</b> acima das vagas</span>
      </div>
      {detalhe && (
        <DetalheCaixa
          no={detalhe}
          membros={membrosDe.get(detalhe.unidade.id) ?? []}
          ocupantes={ocupantes}
          nomeCargo={cargos.nome}
          responsavel={detalhe.unidade.colaborador_responsavel_id ? colaboradores.nome(detalhe.unidade.colaborador_responsavel_id) : '—'}
          aoFechar={() => setDetalhe(null)}
          abrirNaEstrutura={() => navegar(`../est_estrutura?unidade=${detalhe.unidade.id}`)}
        />
      )}
    </>
  );
}

function DetalheCaixa({ no, membros, ocupantes, nomeCargo, responsavel, aoFechar, abrirNaEstrutura }: {
  no: NoUnidade;
  membros: ColaboradorEstrutura[];
  ocupantes: Map<number, ColaboradorEstrutura[]>;
  nomeCargo: (id: number | null) => string;
  responsavel: string;
  aoFechar: () => void;
  abrirNaEstrutura: () => void;
}) {
  const u = no.unidade;
  const ti = tipoInfo(u.tipo);
  const idsPostos = new Set(u.postos.map((p) => p.id));
  const semCargo = membros.filter((c) => !(c.posto_trabalho_id && idsPostos.has(c.posto_trabalho_id)));
  return (
    <Modal open title={<><IconeFa nome={ti.icone} /> {u.nome}</>} onCancel={aoFechar} width={larguraModal(720)}
      footer={[
        <Button key="f" onClick={aoFechar}>Fechar</Button>,
        <Button key="ir" type="primary" icon={<IconeFa nome="edit" />} onClick={abrirNaEstrutura}>Abrir na estrutura</Button>,
      ]}>
      <div style={{ fontSize: '0.84rem', color: '#475569', marginBottom: '0.5rem' }}>{ti.nome} · Responsável: <b>{responsavel}</b></div>
      {u.missao && <div className="est-texto" style={{ marginBottom: '0.5rem' }}>{u.missao}</div>}
      <table className="est-tabela">
        <thead><tr><th>Cargo</th><th>Ocupação</th><th>Pessoas</th></tr></thead>
        <tbody>
          {u.postos.map((p) => {
            const ev = estadoVagas(p.vagas ?? 0, p.ocupados);
            return (
              <tr key={p.id}>
                <td>{nomePosto(p, nomeCargo)}</td>
                <td><span className={`est-tag ${ev.tipo}`}>{ev.texto}</span></td>
                <td style={{ fontSize: '0.8rem' }}>{(ocupantes.get(p.id) ?? []).map((c) => c.nome_completo).join(', ') || '—'}</td>
              </tr>
            );
          })}
          {semCargo.length > 0 && <tr><td><i>Sem cargo</i></td><td>—</td><td style={{ fontSize: '0.8rem' }}>{semCargo.map((c) => c.nome_completo).join(', ')}</td></tr>}
          {!u.postos.length && !semCargo.length && <tr><td colSpan={3} style={{ color: '#64748b' }}>Sem cargos nem pessoas.</td></tr>}
        </tbody>
      </table>
      {u.atribuicoes && <><h4 className="est-h4">Atribuições</h4><div className="est-texto">{u.atribuicoes}</div></>}
    </Modal>
  );
}
