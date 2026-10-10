import { Alert, Button, Checkbox, Col, ColorPicker, Flex, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Skeleton, Space, Tabs } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { TabelaComModos } from '@/componentes/vistas';
import { formatarKz } from '@/utilitarios/formatacao';
import { useColaboradores, useCargos } from '@/modulos/rh/comum/consultas';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { ModalVariasUnidades, useEstruturaBase } from './comum/ModaisCriacao';
import { TIPOS_UNIDADE, aplanar, construirArvore, descendentes, tipoInfo, type Estrutura as DadosEstrutura, type NoUnidade, type Posto, type Unidade } from './comum/arvore';
import { caminho, estadoVagas, linhasMembros, mapaSalarios, nomePosto, ocupantesPorPosto, type ColaboradorEstrutura, type SalariosApi } from './comum/legado';
import './comum/estrutura.css';

const CHAVES = [['rh', 'estrutura'], ['rh', 'colaboradores'], ['rh', 'cargos'], ['gestao']];
/** máximo de colaboradores por pedido de afectação (o servidor aceita até 500) */
const LOTE_AFECTACAO = 500;

export function useEstrutura() {
  return useQuery({ queryKey: ['rh', 'estrutura'], queryFn: () => obter<DadosEstrutura>('/rh/estrutura') });
}

/** Salários dos colaboradores activos (ilíquido do último processamento fechado) — só com est_ver_salarios. */
export function useSalariosEstrutura() {
  const { pode } = useSessao();
  const ver = pode('est_ver_salarios');
  const q = useQuery({ queryKey: ['rh', 'estrutura', 'salarios'], queryFn: () => obter<SalariosApi>('/estrutura/salarios'), enabled: ver });
  const mapa = useMemo(() => (ver ? mapaSalarios(q.data) : null), [ver, q.data]);
  return { ver, mapa, periodo: q.data?.periodo_salarial ?? null };
}

/** Caminho «A › B › C» de cada unidade (para as listas de escolha, como no antigo). */
function caminhos(arvore: NoUnidade[]): Map<number, string> {
  const m = new Map<number, string>();
  const ir = (nos: NoUnidade[], antes: string[]) => nos.forEach((n) => {
    const c = [...antes, n.unidade.nome];
    m.set(n.unidade.id, c.join(' › '));
    ir(n.filhos, c);
  });
  ir(arvore, []);
  return m;
}

const Icone = ({ nome, cor }: { nome: string; cor?: string }) => <IconeFa nome={nome} style={cor ? { color: cor } : undefined} />;

/** Estrutura orgânica › Unidades, cargos e afectação (est_estrutura) — disposição do sistema anterior. */
export default function Estrutura() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const estrutura = useEstrutura();
  const editar = pode('est_editar');
  const [parametros] = useSearchParams();
  // «Abrir na estrutura» do organigrama: ?unidade=<id> (só um id numérico, nunca dados pessoais)
  const [seleccionada, setSeleccionada] = useState<number | null>(() => {
    const n = Number(parametros.get('unidade'));
    return Number.isInteger(n) && n > 0 ? n : null;
  });
  const [edicao, setEdicao] = useState<{ unidade: Unidade | 'nova'; pai: number | null } | null>(null);
  const [varias, setVarias] = useState<{ pai: number | null } | null>(null);
  const [emMassa, setEmMassa] = useState<{ unidade: number | null; soSem: boolean } | null>(null);
  const arvore = useMemo(() => construirArvore(estrutura.data?.unidades ?? []), [estrutura.data]);

  return (
    <>
      <CabecalhoModulo
        titulo="Estrutura orgânica"
        subtitulo="Unidades da empresa (órgãos sociais, direcções, departamentos, secções), responsáveis, cargos com vagas e colaboradores afectos. Cada unidade pode ligar-se a um centro de custo e unidade de negócio."
        accoes={
          <div className="est-accoes">
            <Button icon={<Icone nome="sitemap" />} onClick={() => navegar('../est_organigrama')}>Organigrama</Button>
            <Button icon={<Icone nome="table" />} onClick={() => navegar('../est_mapa')}>Mapa de pessoal</Button>
            {editar && (
              <>
                <Button icon={<Icone nome="user-tag" />} onClick={() => setEmMassa({ unidade: seleccionada, soSem: false })}>Afectar colaboradores</Button>
                <Button icon={<Icone nome="layer-group" />} title="Criar várias unidades no mesmo nível de uma só vez" onClick={() => setVarias({ pai: seleccionada })}>Várias unidades</Button>
                <Button type="primary" icon={<Icone nome="plus" />} onClick={() => setEdicao({ unidade: 'nova', pai: seleccionada })}>Nova unidade</Button>
              </>
            )}
          </div>
        }
      />
      <Tabs
        size="small"
        items={[
          {
            key: 'estrutura',
            label: 'Unidades e cargos',
            children: (
              <PainelEstrutura
                arvore={arvore}
                dados={estrutura.data}
                aCarregar={estrutura.isLoading}
                seleccionada={seleccionada}
                seleccionar={setSeleccionada}
                editarUnidade={(unidade, pai) => setEdicao({ unidade, pai })}
                variasUnidades={(pai) => setVarias({ pai })}
                afectarEmMassa={(unidade, soSem) => setEmMassa({ unidade, soSem })}
              />
            ),
          },
          { key: 'cargos', label: 'Cargos e funções', children: <Cargos /> },
        ]}
      />
      <FormUnidade edicao={edicao} arvore={arvore} aoFechar={(id) => { setEdicao(null); if (id) setSeleccionada(id); }} />
      {varias && <ModalVariasUnidades aberto unidades={estrutura.data?.unidades ?? []} paiInicial={varias.pai} aoFechar={() => setVarias(null)} />}
      {emMassa && (
        <ModalAfectarEmMassa
          arvore={arvore}
          unidadeInicial={emMassa.unidade}
          soSemUnidade={emMassa.soSem}
          aoFechar={(id) => { setEmMassa(null); if (id) setSeleccionada(id); }}
        />
      )}
    </>
  );
}

interface PropsPainel {
  arvore: NoUnidade[];
  dados: DadosEstrutura | undefined;
  aCarregar: boolean;
  seleccionada: number | null;
  seleccionar: (id: number) => void;
  editarUnidade: (u: Unidade | 'nova', pai: number | null) => void;
  variasUnidades: (pai: number | null) => void;
  afectarEmMassa: (unidade: number | null, soSem: boolean) => void;
}

/** Árvore à esquerda e detalhe da unidade seleccionada à direita (estrutura_ui.js desenharEstrutura). */
function PainelEstrutura({ arvore, dados, aCarregar, seleccionada, seleccionar, editarUnidade, variasUnidades, afectarEmMassa }: PropsPainel) {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const salarios = useSalariosEstrutura();
  const cc = useTabelaAux('centros-custo', true);
  const un = useUnidadesNegocio(true);
  const base = useEstruturaBase();
  const accao = useAccao({ invalidar: CHAVES });
  const [fechados, setFechados] = useState<Set<number>>(new Set());
  const [posto, setPosto] = useState<Posto | 'novo' | null>(null);
  const [mover, setMover] = useState<number | null>(null);
  const editar = pode('est_editar');
  const eliminar = pode('est_eliminar');
  const nos = useMemo(() => aplanar(arvore), [arvore]);
  const lista = colaboradores.lista as ColaboradorEstrutura[];
  const ocupantes = useMemo(() => ocupantesPorPosto(lista), [lista]);
  const semUnidade = useMemo(() => {
    const ids = new Set(nos.map((n) => n.unidade.id));
    return lista.filter((c) => c.estado === 'ACTIVO' && (!c.unidade_organica_id || !ids.has(c.unidade_organica_id)));
  }, [lista, nos]);

  // sem selecção (ou a seleccionada desapareceu): a primeira raiz, como no antigo
  const actual = nos.find((n) => n.unidade.id === seleccionada) ?? arvore[0] ?? null;
  useEffect(() => {
    if (actual && actual.unidade.id !== seleccionada) seleccionar(actual.unidade.id);
  }, [actual, seleccionada, seleccionar]);

  if (aCarregar) return <Skeleton active />;

  if (!arvore.length) {
    return (
      <div className="est-painel" style={{ textAlign: 'center', padding: '2rem' }}>
        <IconeFa nome="sitemap" style={{ fontSize: '2rem', color: '#94a3b8' }} />
        <h3 style={{ margin: '0.6rem 0 0.3rem' }}>Ainda não há estrutura orgânica</h3>
        <p style={{ color: '#64748b', margin: '0 0 1rem' }}>
          Crie as unidades uma a uma ou comece com uma estrutura base (Conselho de Administração, Direcção-Geral, Direcções e Departamentos) e ajuste-a.
        </p>
        {editar && (
          <Space wrap style={{ justifyContent: 'center' }}>
            <Button icon={<Icone nome="magic" />} loading={base.aCriar} onClick={() => base.criar(dados?.unidades.length ?? 0)}>Criar estrutura base</Button>
            <Button icon={<Icone nome="layer-group" />} onClick={() => variasUnidades(null)}>Criar várias unidades</Button>
            <Button type="primary" icon={<Icone nome="plus" />} onClick={() => editarUnidade('nova', null)}>Criar a primeira unidade</Button>
          </Space>
        )}
      </div>
    );
  }
  if (!actual) return null;

  const u = actual.unidade;
  const ti = tipoInfo(u.tipo);
  const cam = caminho(arvore, u.id);
  const membros = linhasMembros(actual, lista, cargos.nome, salarios.mapa);
  const resp = u.colaborador_responsavel_id ? colaboradores.mapa.get(u.colaborador_responsavel_id) : undefined;
  const ccU = (cc.data ?? []).find((c) => c.id === u.centro_custo_id);
  const unU = (un.data ?? []).find((x) => x.id === u.unidade_negocio_id);
  const todosPostos = nos.flatMap((n) => n.unidade.postos);
  const massaRamo = salarios.mapa
    ? aplanar([actual]).reduce((s, n) => s + lista.filter((c) => c.estado === 'ACTIVO' && c.unidade_organica_id === n.unidade.id).reduce((t, c) => t + (salarios.mapa!.get(c.id) ?? 0), 0), 0)
    : 0;
  const vagasUnidade = u.postos.reduce((s, p) => s + (p.vagas ?? 0), 0);
  const membrosAct = membros.length;

  const alternar = (id: number) => setFechados((f) => {
    const n = new Set(f);
    if (n.has(id)) n.delete(id);
    else n.add(id);
    return n;
  });
  const ramo = (lista: NoUnidade[]) => (
    <ul>
      {lista.map((n) => {
        const fechado = fechados.has(n.unidade.id);
        const t = tipoInfo(n.unidade.tipo);
        return (
          <li key={n.unidade.id}>
            <div
              className={`est-no${n.unidade.ativo ? '' : ' inactiva'}`}
              role="button"
              tabIndex={0}
              aria-current={n.unidade.id === u.id}
              onClick={() => seleccionar(n.unidade.id)}
              onKeyDown={(e) => { if (e.key === 'Enter') seleccionar(n.unidade.id); }}
            >
              {n.filhos.length ? (
                <button type="button" className="tg" aria-label={fechado ? 'Expandir' : 'Recolher'} onClick={(e) => { e.stopPropagation(); alternar(n.unidade.id); }}>
                  <IconeFa nome={fechado ? 'caret-right' : 'caret-down'} />
                </button>
              ) : <span className="tg" />}
              <span className="ic"><IconeFa nome={t.icone} style={{ color: n.unidade.cor || t.cor }} /></span>
              <span>{n.unidade.nome}</span>
              <span className="n" title="Pessoas (incluindo subunidades) · vagas em aberto">
                {n.membrosTotal}{n.livresTotal ? <> · <b style={{ color: '#b45309' }}>{n.livresTotal}</b></> : null}
              </span>
            </div>
            {n.filhos.length > 0 && !fechado && ramo(n.filhos)}
          </li>
        );
      })}
    </ul>
  );

  return (
    <>
      {semUnidade.length > 0 && (
        <div className="est-aviso">
          <IconeFa nome="user-slash" /> {semUnidade.length} colaborador(es) activos sem unidade: {semUnidade.slice(0, 6).map((c) => c.nome_completo).join(', ')}{semUnidade.length > 6 ? '…' : ''}.
          {editar && <> <button type="button" className="est-ligacao" onClick={() => afectarEmMassa(u.id, true)}>Afectar agora</button></>}
        </div>
      )}
      <div className="est-layout">
        <nav className="est-arvore" aria-label="Unidades">{ramo(arvore)}</nav>
        <section className="est-painel" aria-live="polite">
          <div style={{ fontSize: '0.76rem', color: '#64748b' }}>{cam.map((x) => x.unidade.nome).join(' › ')}</div>
          <Flex justify="space-between" gap={8} wrap align="flex-start">
            <div>
              <h3 style={{ margin: '0.1rem 0 0', fontSize: '1.15rem' }}>
                <IconeFa nome={ti.icone} style={{ color: u.cor || ti.cor }} /> {u.nome}{' '}
                {u.codigo && <span className="est-tag neutro">{u.codigo}</span>}{' '}
                {!!u.apoio && <span className="est-tag info">apoio</span>}{' '}
                {!u.ativo && <span className="est-tag neutro">inactiva</span>}
              </h3>
              <div style={{ fontSize: '0.82rem', color: '#475569' }}>
                {ti.nome} · Responsável: <b>{resp ? resp.nome_completo : '—'}</b>{resp?.cargo_funcao_id ? ` (${cargos.nome(resp.cargo_funcao_id)})` : ''}
              </div>
              <div style={{ fontSize: '0.78rem', color: '#64748b' }}>
                {ccU ? `CC ${ccU.codigo} ${ccU.descricao ?? ''}` : 'Sem centro de custo'} · {unU ? `UN ${unU.nome}` : 'Sem unidade de negócio'}
              </div>
            </div>
            {editar && (
              <div className="est-accoes">
                <Button size="small" icon={<Icone nome="plus" />} onClick={() => editarUnidade('nova', u.id)}>Subunidade</Button>
                <Button size="small" icon={<Icone nome="layer-group" />} title="Criar várias subunidades desta unidade de uma só vez" onClick={() => variasUnidades(u.id)}>Várias subunidades</Button>
                <Button size="small" icon={<Icone nome="edit" />} onClick={() => editarUnidade(u, u.unidade_organica_pai_id)}>Editar</Button>
                {eliminar && (
                  <Popconfirm title="Eliminar unidade" description="Eliminar esta unidade e os seus cargos?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                    onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/estrutura/unidades/${u.id}` }).then(() => seleccionar(u.unidade_organica_pai_id ?? arvore[0]?.unidade.id ?? 0))}>
                    <Button size="small" danger icon={<Icone nome="trash" />} aria-label="Eliminar" />
                  </Popconfirm>
                )}
              </div>
            )}
          </Flex>
          <div className="est-kpis">
            <div className="est-kpi"><span>Pessoas (unidade · total)</span><strong>{membrosAct} · {actual.membrosTotal}</strong></div>
            <div className="est-kpi"><span>Vagas (unidade · total)</span><strong>{vagasUnidade} · {actual.vagasTotal}</strong></div>
            <div className="est-kpi"><span>Vagas em aberto (total)</span><strong style={{ color: actual.livresTotal ? '#b45309' : undefined }}>{actual.livresTotal}</strong></div>
            <div className="est-kpi"><span>Subunidades</span><strong>{aplanar(actual.filhos).length}</strong></div>
            {salarios.ver && <div className="est-kpi"><span>Massa salarial mensal (total)</span><strong>{formatarKz(massaRamo.toFixed(2))}</strong></div>}
          </div>
          {u.missao && <><h4 className="est-h4">Missão</h4><div className="est-texto">{u.missao}</div></>}
          {u.atribuicoes && <><h4 className="est-h4">Atribuições</h4><div className="est-texto">{u.atribuicoes}</div></>}

          <h4 className="est-h4"><span>Cargos e vagas</span>{editar && <Button size="small" icon={<Icone nome="plus" />} onClick={() => setPosto('novo')}>Cargo</Button>}</h4>
          {u.postos.length ? (
            <div style={{ overflowX: 'auto' }}>
              <table className="est-tabela">
                <thead><tr><th>Cargo</th><th>Reporta a</th><th>Ocupação</th><th>Ocupantes</th>{salarios.ver && <th className="n">Massa salarial</th>}<th /></tr></thead>
                <tbody>
                  {u.postos.map((p) => {
                    const rep = todosPostos.find((x) => x.id === p.posto_superior_id);
                    const ocup = ocupantes.get(p.id) ?? [];
                    const ev = estadoVagas(p.vagas ?? 0, p.ocupados);
                    return (
                      <tr key={p.id}>
                        <td>
                          <b>{nomePosto(p, cargos.nome)}</b> {!!p.chefia && <span className="est-tag info">chefia</span>}
                          {p.responsabilidades && <div style={{ fontSize: '0.74rem', color: '#64748b', whiteSpace: 'pre-wrap' }}>{p.responsabilidades.slice(0, 160)}{p.responsabilidades.length > 160 ? '…' : ''}</div>}
                        </td>
                        <td style={{ fontSize: '0.78rem' }}>{rep ? nomePosto(rep, cargos.nome) : '—'}</td>
                        <td><span className={`est-tag ${ev.tipo}`}>{ev.texto}</span></td>
                        <td style={{ fontSize: '0.8rem' }}>{ocup.map((c) => c.nome_completo).join(', ') || '—'}</td>
                        {salarios.ver && <td className="n">{formatarKz(ocup.reduce((s, c) => s + (salarios.mapa?.get(c.id) ?? 0), 0).toFixed(2))}</td>}
                        <td style={{ whiteSpace: 'nowrap' }}>
                          {editar && <Button size="small" icon={<Icone nome="edit" />} aria-label="Editar cargo" onClick={() => setPosto(p)} />}{' '}
                          {eliminar && (
                            <Popconfirm title="Eliminar cargo" description="Eliminar este cargo?" okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/estrutura/postos/${p.id}` })}>
                              <Button size="small" danger icon={<Icone nome="trash" />} aria-label="Eliminar cargo" />
                            </Popconfirm>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : <p style={{ color: '#64748b', fontSize: '0.84rem' }}>Sem cargos definidos. Acrescente os cargos (a partir das funções) e o número de vagas.</p>}

          <h4 className="est-h4"><span>Colaboradores afectos ({membros.length})</span>{editar && <Button size="small" icon={<Icone nome="user-plus" />} onClick={() => afectarEmMassa(u.id, false)}>Afectar</Button>}</h4>
          {membros.length ? (
            <div style={{ overflowX: 'auto' }}>
              <table className="est-tabela">
                <thead><tr><th>Colaborador</th><th>Função</th><th>Cargo</th><th>Chefia directa</th>{salarios.ver && <th className="n">Salário mensal</th>}<th /></tr></thead>
                <tbody>
                  {membros.map((m) => (
                    <tr key={m.id}>
                      <td><b>{m.nome}</b>{m.responsavel && <> <span className="est-tag info">responsável</span></>}</td>
                      <td style={{ fontSize: '0.8rem' }}>{m.funcao}</td>
                      <td style={{ fontSize: '0.8rem' }}>{m.cargo ?? <span className="est-tag aberto">sem cargo</span>}</td>
                      <td style={{ fontSize: '0.8rem' }}>{m.chefiaDeduzida ? <span style={{ color: '#64748b' }}>{m.chefia}</span> : m.chefia}</td>
                      {salarios.ver && <td className="n">{formatarKz((m.salario ?? 0).toFixed(2))}</td>}
                      <td>{editar && <Button size="small" icon={<Icone nome="exchange-alt" />} onClick={() => setMover(m.id)}>Alterar</Button>}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : <p style={{ color: '#64748b', fontSize: '0.84rem' }}>Sem colaboradores afectos.</p>}

          {actual.filhos.length > 0 && (
            <>
              <h4 className="est-h4">Subunidades</h4>
              <Flex gap={6} wrap>
                {actual.filhos.map((f) => (
                  <Button key={f.unidade.id} size="small" icon={<Icone nome={tipoInfo(f.unidade.tipo).icone} />} onClick={() => seleccionar(f.unidade.id)}>
                    {f.unidade.nome} · {f.membrosTotal}
                  </Button>
                ))}
              </Flex>
            </>
          )}
        </section>
      </div>
      <FormPosto posto={posto} unidade={u} arvore={arvore} aoFechar={() => setPosto(null)} />
      {mover !== null && <ModalAfectarUm colaborador={mover} arvore={arvore} aoFechar={() => setMover(null)} />}
    </>
  );
}

function FormUnidade({ edicao, arvore, aoFechar }: { edicao: { unidade: Unidade | 'nova'; pai: number | null } | null; arvore: NoUnidade[]; aoFechar: (id?: number) => void }) {
  const [form] = Form.useForm();
  const unidade = edicao?.unidade ?? null;
  const un = useUnidadesNegocio(!!unidade);
  const cc = useTabelaAux('centros-custo', !!unidade);
  const accao = useAccao<{ id: number }>({ invalidar: CHAVES, aoSucesso: (d) => aoFechar(d?.id) });
  const nova = unidade === 'nova';
  const proibidos = unidade && unidade !== 'nova' ? descendentes(arvore, unidade.id) : new Set<number>();
  const cams = useMemo(() => caminhos(arvore), [arvore]);
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    form.setFieldsValue(edicao.unidade === 'nova' ? { unidade_organica_pai_id: edicao.pai, tipo: 'DEPARTAMENTO', ativo: true, apoio: false } : { ...edicao.unidade, apoio: !!edicao.unidade.apoio });
  }, [edicao, form]);
  return (
    <Modal open={!!unidade} title={nova ? 'Nova unidade orgânica' : `Editar ${(unidade as Unidade | null)?.nome ?? ''}`} onCancel={() => aoFechar()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(860)} destroyOnHidden>
      <Form
        form={form}
        layout="vertical"
        onFinish={(v) => accao.mutate({ metodo: nova ? 'post' : 'put', url: nova ? '/rh/estrutura/unidades' : `/rh/estrutura/unidades/${(unidade as Unidade).id}`, dados: { ...v, cor: typeof v.cor === 'string' ? v.cor : null } })}
      >
        <Row gutter={[16, 0]}>
          <Col xs={24} sm={12}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="codigo" label="Código"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="tipo" label="Tipo" rules={[{ required: true, message: 'Indique o tipo.' }]}><Select options={Object.entries(TIPOS_UNIDADE).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
          <Col xs={24} sm={12}>
            <Form.Item name="unidade_organica_pai_id" label="Unidade superior">
              <Select allowClear showSearch optionFilterProp="label" placeholder="— topo da estrutura —" options={aplanar(arvore).filter((n) => !proibidos.has(n.unidade.id)).map((n) => ({ value: n.unidade.id, label: cams.get(n.unidade.id) ?? n.unidade.nome }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}><Form.Item name="colaborador_responsavel_id" label="Responsável (colaborador)"><SeletorColaborador apenasActivos style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="centro_custo_id" label="Centro de custo"><Select allowClear showSearch optionFilterProp="label" options={(cc.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} ${c.descricao ?? ''}` }))} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="unidade_negocio_id" label="Unidade de negócio"><Select allowClear showSearch optionFilterProp="label" options={(un.data ?? []).map((x) => ({ value: x.id, label: x.nome }))} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="missao" label="Missão"><Input.TextArea rows={2} maxLength={5000} /></Form.Item></Col>
          <Col xs={24}><Form.Item name="atribuicoes" label="Atribuições (uma por linha)"><Input.TextArea rows={4} maxLength={5000} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="cor" label="Cor no organigrama" getValueFromEvent={(c) => c?.toHexString?.() ?? c}><ColorPicker /></Form.Item></Col>
          <Col xs={24} sm={8}><Form.Item name="apoio" valuePropName="checked" label=" "><Checkbox>Unidade de apoio (staff / assessoria)</Checkbox></Form.Item></Col>
          <Col xs={24} sm={4}><Form.Item name="ativo" valuePropName="checked" label=" "><Checkbox>Activa</Checkbox></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

/** Cargo da unidade (no sistema novo: posto de trabalho com vagas). */
function FormPosto({ posto, unidade, arvore, aoFechar }: { posto: Posto | 'novo' | null; unidade: Unidade; arvore: NoUnidade[]; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const cargos = useCargos();
  const accao = useAccao({ invalidar: CHAVES, aoSucesso: () => aoFechar() });
  const novo = posto === 'novo';
  useEffect(() => {
    if (!posto) return;
    form.resetFields();
    form.setFieldsValue(posto === 'novo' ? { vagas: 1, chefia: false } : { ...posto, chefia: !!posto.chefia });
  }, [posto, form]);
  const outros = aplanar(arvore).flatMap((n) => n.unidade.postos.map((p) => ({ p, unidade: n.unidade.nome }))).filter((x) => posto === 'novo' || x.p.id !== posto?.id);
  return (
    <Modal open={!!posto} title={novo ? `Novo cargo — ${unidade.nome}` : 'Editar cargo'} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(760)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: novo ? 'post' : 'put', url: novo ? '/rh/estrutura/postos' : `/rh/estrutura/postos/${(posto as Posto).id}`, dados: { ...v, unidade_organica_id: novo ? unidade.id : (posto as Posto).unidade_organica_id } })}>
        <Row gutter={[16, 0]}>
          <Col xs={24} sm={12}><Form.Item name="cargo_funcao_id" label="Função"><Select allowClear showSearch optionFilterProp="label" placeholder="—" options={cargos.lista.map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="titulo" label="Título do cargo (se diferente da função)"><Input maxLength={255} placeholder="Ex.: Director Financeiro" /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="vagas" label="Vagas"><InputNumber min={0} max={9999} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} sm={6}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={12}>
            <Form.Item name="posto_superior_id" label="Reporta ao cargo">
              <Select allowClear showSearch optionFilterProp="label" placeholder="— responsável da unidade —" options={outros.map((x) => ({ value: x.p.id, label: `${nomePosto(x.p, cargos.nome)} · ${x.unidade}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24}><Form.Item name="chefia" valuePropName="checked"><Checkbox>Cargo de chefia da unidade</Checkbox></Form.Item></Col>
          <Col xs={24}><Form.Item name="responsabilidades" label="Responsabilidades / descrição de funções"><Input.TextArea rows={4} maxLength={5000} /></Form.Item></Col>
        </Row>
      </Form>
    </Modal>
  );
}

/** «Alterar» (afectarUm do antigo): unidade, cargo e chefia directa de um colaborador. */
function ModalAfectarUm({ colaborador, arvore, aoFechar }: { colaborador: number; arvore: NoUnidade[]; aoFechar: () => void }) {
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const c = colaboradores.mapa.get(colaborador);
  const [unidade, setUnidade] = useState<number | null>(c?.unidade_organica_id ?? null);
  const [posto, setPosto] = useState<number | null>(c?.posto_trabalho_id ?? null);
  const [gestor, setGestor] = useState<number | null>(c?.colaborador_gestor_id ?? null);
  const accao = useAccao<{ avisos: string[] }>({
    invalidar: CHAVES,
    aoSucesso: (d) => {
      if (d?.avisos?.length) Modal.warning({ title: 'Afectação gravada', content: <ul style={{ paddingLeft: 18 }}>{d.avisos.map((a) => <li key={a}>{a}</li>)}</ul> });
      aoFechar();
    },
  });
  const cams = useMemo(() => caminhos(arvore), [arvore]);
  const postos = aplanar(arvore).find((n) => n.unidade.id === unidade)?.unidade.postos ?? [];
  if (!c) return null;
  return (
    <Modal open title={`Afectação — ${c.nome_completo}`} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(640)} destroyOnHidden
      onOk={() => accao.mutate({ url: '/rh/estrutura/afectacao', dados: { colaboradores: [c.id], unidade_organica_id: unidade, posto_trabalho_id: postos.some((p) => p.id === posto) ? posto : null, colaborador_gestor_id: gestor } })}>
      <Form layout="vertical" component="div">
        <Form.Item label="Unidade">
          <Select allowClear showSearch optionFilterProp="label" placeholder="— sem unidade —" aria-label="Unidade" value={unidade ?? undefined}
            onChange={(v?: number) => { setUnidade(v ?? null); setPosto(null); }}
            options={aplanar(arvore).map((n) => ({ value: n.unidade.id, label: cams.get(n.unidade.id) ?? n.unidade.nome }))} />
        </Form.Item>
        <Row gutter={[16, 0]}>
          <Col xs={24} sm={12}>
            <Form.Item label="Cargo">
              <Select allowClear placeholder="— sem cargo —" aria-label="Cargo" value={postos.some((p) => p.id === posto) ? posto ?? undefined : undefined} onChange={(v?: number) => setPosto(v ?? null)}
                options={postos.map((p) => ({ value: p.id, label: `${nomePosto(p, cargos.nome)}${p.vagas ? ` (${p.vagas} vaga(s))` : ''}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item label="Chefia directa">
              <Select allowClear showSearch optionFilterProp="label" placeholder="— responsável da unidade —" aria-label="Chefia directa" value={gestor ?? undefined} onChange={(v?: number) => setGestor(v ?? null)}
                options={colaboradores.lista.filter((x) => x.id !== c.id && x.estado === 'ACTIVO').map((x) => ({ value: x.id, label: x.nome_completo }))} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}

/** «Afectar colaboradores» (afectarEmMassa do antigo): vários colaboradores a uma unidade e cargo. */
function ModalAfectarEmMassa({ arvore, unidadeInicial, soSemUnidade, aoFechar }: { arvore: NoUnidade[]; unidadeInicial: number | null; soSemUnidade: boolean; aoFechar: (unidade?: number) => void }) {
  const colaboradores = useColaboradores();
  const cargos = useCargos();
  const nos = aplanar(arvore);
  const cams = useMemo(() => caminhos(arvore), [arvore]);
  const [unidade, setUnidade] = useState<number | null>(unidadeInicial ?? nos[0]?.unidade.id ?? null);
  const [posto, setPosto] = useState<number | null>(null);
  const [filtro, setFiltro] = useState<'sem' | 'todos'>(soSemUnidade ? 'sem' : 'todos');
  const [procura, setProcura] = useState('');
  const [marcados, setMarcados] = useState<number[]>([]);
  const [aGravar, setAGravar] = useState(false);
  const [erro, setErro] = useState<string | null>(null);
  const accao = useAccao<{ avisos: string[] }>({ invalidar: CHAVES });
  const postos = nos.find((n) => n.unidade.id === unidade)?.unidade.postos ?? [];
  const ids = new Set(nos.map((n) => n.unidade.id));
  const q = procura.trim().toLocaleLowerCase('pt');
  const visiveis = colaboradores.lista.filter((c) => c.estado === 'ACTIVO' && (filtro === 'todos' || !c.unidade_organica_id || !ids.has(c.unidade_organica_id)) && (!q || c.nome_completo.toLocaleLowerCase('pt').includes(q)));

  if (!nos.length) {
    return <Modal open title="Afectar colaboradores" onCancel={() => aoFechar()} footer={null}><Alert type="warning" showIcon message="Crie primeiro as unidades." /></Modal>;
  }
  const gravar = async () => {
    setErro(null);
    if (!unidade) return setErro('Escolha a unidade.');
    if (!marcados.length) return setErro('Seleccione os colaboradores.');
    setAGravar(true);
    const avisos: string[] = [];
    try {
      for (let i = 0; i < marcados.length; i += LOTE_AFECTACAO) {
        const r = await accao.mutateAsync({ url: '/rh/estrutura/afectacao', dados: { colaboradores: marcados.slice(i, i + LOTE_AFECTACAO), unidade_organica_id: unidade, posto_trabalho_id: posto } });
        avisos.push(...(r.dados?.avisos ?? []));
      }
      if (avisos.length) Modal.warning({ title: `${marcados.length} colaborador(es) afectado(s)`, content: <ul style={{ paddingLeft: 18 }}>{[...new Set(avisos)].map((a) => <li key={a}>{a}</li>)}</ul> });
      aoFechar(unidade);
    } catch {
      /* o erro já foi notificado por useAccao */
    } finally {
      setAGravar(false);
    }
  };
  return (
    <Modal open title="Afectar colaboradores" onCancel={() => aoFechar()} width={larguraModal(760)} destroyOnHidden
      footer={[
        <Button key="c" onClick={() => aoFechar()}>Cancelar</Button>,
        <Button key="ok" type="primary" icon={<Icone nome="check" />} loading={aGravar} onClick={() => void gravar()}>Afectar seleccionados</Button>,
      ]}>
      <Form layout="vertical" component="div">
      <Row gutter={[12, 0]}>
        <Col xs={24} sm={12}>
          <Form.Item label="Afectar à unidade" required>
            <Select showSearch optionFilterProp="label" aria-label="Afectar à unidade" value={unidade ?? undefined} onChange={(v: number) => { setUnidade(v); setPosto(null); }}
              options={nos.map((n) => ({ value: n.unidade.id, label: cams.get(n.unidade.id) ?? n.unidade.nome }))} />
          </Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item label="Cargo">
            <Select allowClear placeholder="— sem cargo —" aria-label="Cargo" value={posto ?? undefined} onChange={(v?: number) => setPosto(v ?? null)}
              options={postos.map((p) => ({ value: p.id, label: `${nomePosto(p, cargos.nome)}${p.vagas ? ` (${p.vagas} vaga(s))` : ''}` }))} />
          </Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item label="Mostrar">
            <Select aria-label="Mostrar" value={filtro} onChange={setFiltro} options={[{ value: 'sem', label: 'Sem unidade' }, { value: 'todos', label: 'Todos os activos' }]} />
          </Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item label="Procurar"><Input aria-label="Procurar" value={procura} onChange={(e) => setProcura(e.target.value)} maxLength={100} /></Form.Item>
        </Col>
      </Row>
      </Form>
      <div style={{ maxHeight: '45vh', overflow: 'auto', border: '1px solid #e2e8f0', borderRadius: 4 }}>
        <table className="est-tabela">
          <thead>
            <tr>
              <th style={{ width: 32 }}>
                <Checkbox aria-label="Todos" checked={!!visiveis.length && visiveis.every((c) => marcados.includes(c.id))}
                  onChange={(e) => setMarcados(e.target.checked ? [...new Set([...marcados, ...visiveis.map((c) => c.id)])] : marcados.filter((id) => !visiveis.some((c) => c.id === id)))} />
              </th>
              <th>Colaborador</th>
              <th>Unidade actual</th>
            </tr>
          </thead>
          <tbody>
            {visiveis.length ? visiveis.map((c) => (
              <tr key={c.id}>
                <td><Checkbox aria-label={c.nome_completo} checked={marcados.includes(c.id)} onChange={(e) => setMarcados(e.target.checked ? [...marcados, c.id] : marcados.filter((x) => x !== c.id))} /></td>
                <td>{c.nome_completo}</td>
                <td style={{ fontSize: '0.8rem', color: '#64748b' }}>{c.unidade_organica_id ? nos.find((n) => n.unidade.id === c.unidade_organica_id)?.unidade.nome ?? '—' : '—'}</td>
              </tr>
            )) : <tr><td colSpan={3} style={{ color: '#64748b' }}>Nenhum colaborador.</td></tr>}
          </tbody>
        </table>
      </div>
      {erro && <Alert style={{ marginTop: 8 }} type="warning" showIcon message={erro} />}
    </Modal>
  );
}

function Cargos() {
  const { pode } = useSessao();
  const cargos = useCargos();
  const [edicao, setEdicao] = useState<{ id: number; nome: string; descricao?: string | null } | 'novo' | null>(null);
  const [form] = Form.useForm<{ nome: string; descricao?: string }>();
  const accao = useAccao({ invalidar: CHAVES, aoSucesso: () => setEdicao(null) });
  const editar = pode('rh_funcoes_gerir', 'est_editar');
  const eliminar = pode('rh_funcao_del', 'est_eliminar');
  useEffect(() => {
    if (!edicao) return;
    form.resetFields();
    if (edicao !== 'novo') form.setFieldsValue({ nome: edicao.nome, descricao: edicao.descricao ?? '' });
  }, [edicao, form]);
  return (
    <div className="est-painel">
      {editar && <Flex justify="end" style={{ marginBottom: 12 }}><Button type="primary" icon={<Icone nome="plus" />} onClick={() => setEdicao('novo')}>Novo cargo</Button></Flex>}
      <TabelaComModos<{ id: number; nome: string; descricao?: string | null }> idVista="cargos"
        scroll={scrollTabela()}
        rowKey="id"
        size="small"
        loading={cargos.isLoading}
        dataSource={cargos.lista as { id: number; nome: string; descricao?: string | null }[]}
        impressao={{ titulo: 'Cargos e funções' }}
        columns={[
          { title: 'Cargo / função', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
        ]}
        accoes={(c) => [
          { icone: 'edit', titulo: 'Editar', oculto: !editar, onClick: () => setEdicao(c) },
          { icone: 'trash', titulo: 'Eliminar', variante: 'perigo', oculto: !eliminar, confirmar: `Eliminar «${c.nome}»?`, onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/cargos/${c.id}` }) },
        ]}
      />
      <Modal open={!!edicao} title={edicao === 'novo' ? 'Novo cargo' : 'Editar cargo'} onCancel={() => setEdicao(null)} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} width={larguraModal(560)} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ metodo: edicao === 'novo' ? 'post' : 'put', url: edicao === 'novo' ? '/rh/cargos' : `/rh/cargos/${(edicao as { id: number }).id}`, dados: v })}>
          <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
          <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={4} maxLength={5000} /></Form.Item>
        </Form>
      </Modal>
    </div>
  );
}

