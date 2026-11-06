import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Empty, Form, Input, InputNumber, Modal, Popconfirm, Row, Select, Skeleton, Space, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { larguraModal } from '@/componentes/responsivo';
import { ErroApi } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import {
  CHAVE_CATALOGOS, CHAVE_PLANOS, apagarObjectivo, apagarPerspectiva, apagarPlano, chavePlano, gravarObjectivo, gravarPerspectiva, gravarPlano,
  obterCatalogos, obterPlano, transicaoPlano, type DetalhePlano, type Objectivo, type Perspectiva, type Plano,
} from './api';
import { COR_ESTADO_PLANO, ROTULO_ESTADO_PLANO, trimestresDoCiclo } from './comum';
import { usePedirMotivo, usePlanoEscolhido } from './hooks';
import './estrategia.css';

type MotivoOpc = string | undefined;

/**
 * Plano Estratégico (est_plano): o ciclo, a missão, as perspectivas e os objectivos. Ver: est_plano_view · editar: est_plano_editar ·
 * aprovar, iniciar a execução, reabrir e encerrar: est_plano_aprovar. As regras (estados, congelamento do plano aprovado, quem
 * elabora não aprova, pai sem ciclos) são aplicadas no servidor; aqui só se apresentam e as mensagens do servidor vêm tal e qual.
 */
export default function PlanoObjectivos() {
  const { pode } = useSessao();
  const qc = useQueryClient();
  const { planos, plano: escolhido, escolher, carregando } = usePlanoEscolhido();
  const planoId = escolhido?.id ?? 0;
  const { data: det, isLoading } = useQuery({ queryKey: chavePlano(planoId), queryFn: () => obterPlano(planoId), enabled: planoId > 0 });
  const plano = det?.plano;
  const [modalPlano, setModalPlano] = useState<'novo' | 'editar' | null>(null);
  const [perspectivasAbertas, setPerspectivasAbertas] = useState(false);
  const [objectivoAberto, setObjectivoAberto] = useState<{ objectivo?: Objectivo; perspectivaId?: number } | null>(null);

  const aprovador = pode('est_plano_aprovar');
  const encerrado = plano?.estado === 'ENCERRADO';
  const bloqueado = !!plano?.congelado && (!aprovador || encerrado);
  const podeEditar = pode('est_plano_editar') && !!plano && !bloqueado;
  const motivoEstrutura = usePedirMotivo(!!plano?.congelado && aprovador && !encerrado);
  const motivoReabrir = usePedirMotivo(true, 'Reabrir o plano', 'Indique o motivo da reabertura: o plano volta a elaboração e fica registado na auditoria.');
  const motivoExcepcao = usePedirMotivo(true, 'Aprovar um plano que elaborou', 'Quem elaborou ou editou o plano não o aprova. Se não houver outra pessoa, indique o motivo da excepção (mínimo 10 caracteres): fica registado na auditoria.');

  const refrescar = () => { void qc.invalidateQueries({ queryKey: ['estrategia'] }); };
  const transicao = useMutation({
    mutationFn: (v: { accao: 'aprovar' | 'iniciar-execucao' | 'reabrir' | 'encerrar'; motivo?: string; excepcao?: string }) => transicaoPlano(planoId, v.accao, { motivo: v.motivo, excepcao: v.excepcao }),
    onSuccess: () => { message.success('Estado do plano actualizado.'); refrescar(); },
    onError: (e, v) => {
      if (v.accao === 'aprovar' && !v.excepcao && e instanceof ErroApi && e.codigo === 'AUTO_APROVACAO') { motivoExcepcao.pedir((m) => transicao.mutate({ accao: 'aprovar', excepcao: m })); return; }
      notificarErro(e);
    },
  });
  const apagar = useMutation({ mutationFn: () => apagarPlano(planoId), onSuccess: () => { message.success('Plano apagado.'); setModalPlano(null); refrescar(); }, onError: (e) => notificarErro(e) });

  const grupos = useMemo(() => (det ? agrupar(det) : []), [det]);

  return (
    <>
      <CabecalhoModulo titulo="Plano Estratégico" icone="bullseye" subtitulo="O ciclo, a missão, as perspectivas e os objectivos." />
      <Space wrap style={{ marginBottom: 12 }}>
        <Select aria-label="Plano" style={{ minWidth: 260 }} loading={carregando} value={escolhido?.id} placeholder="Sem planos" onChange={escolher}
          options={planos.map((p) => ({ value: p.id, label: `${p.nome} (${p.ano_inicio}-${p.ano_fim})` }))} />
        {plano && <Tag color={COR_ESTADO_PLANO[plano.estado]}>{ROTULO_ESTADO_PLANO[plano.estado] ?? plano.estado}</Tag>}
        {plano && podeEditar && <Button onClick={() => setModalPlano('editar')}>Editar plano</Button>}
        {plano?.estado === 'RASCUNHO' && aprovador && (
          <Popconfirm title="Confirmar" description="Quer aprovar este plano? A estrutura fica congelada." okText="Aprovar" cancelText="Cancelar" onConfirm={() => transicao.mutate({ accao: 'aprovar' })}>
            <Button type="primary" loading={transicao.isPending}>Aprovar plano</Button>
          </Popconfirm>
        )}
        {plano?.estado === 'APROVADO' && aprovador && (
          <>
            <Popconfirm title="Confirmar" description="Quer iniciar a execução deste plano?" okText="Iniciar" cancelText="Cancelar" onConfirm={() => transicao.mutate({ accao: 'iniciar-execucao' })}><Button type="primary">Iniciar execução</Button></Popconfirm>
            <Button onClick={() => motivoReabrir.pedir((m) => transicao.mutate({ accao: 'reabrir', motivo: m }))}>Reabrir</Button>
          </>
        )}
        {(plano?.estado === 'APROVADO' || plano?.estado === 'EM_EXECUCAO') && aprovador && (
          <Popconfirm title="Confirmar" description="Quer encerrar este plano? Fica só de leitura." okText="Encerrar" cancelText="Cancelar" onConfirm={() => transicao.mutate({ accao: 'encerrar' })}><Button danger>Encerrar</Button></Popconfirm>
        )}
        {pode('est_plano_editar') && <Button icon={<PlusOutlined />} onClick={() => setModalPlano('novo')}>Novo plano</Button>}
        {plano && podeEditar && <Button onClick={() => setPerspectivasAbertas(true)}>Perspectivas</Button>}
        {plano && podeEditar && <Button type="primary" icon={<PlusOutlined />} onClick={() => setObjectivoAberto({})}>Novo objectivo</Button>}
      </Space>

      {plano?.congelado && (
        <Alert style={{ marginBottom: 12 }} type={encerrado ? 'info' : 'warning'} showIcon
          title={encerrado ? 'Plano encerrado: só leitura.' : 'Plano aprovado: a estrutura está congelada.'}
          description={encerrado ? undefined : aprovador ? 'Para alterar objectivos, indicadores ou metas é pedido um motivo, que fica registado na auditoria. Ou reabra o plano.' : 'Quem não tem a permissão de aprovação não altera a estrutura: peça a reabertura do plano.'} />
      )}
      {plano?.motivo_reabertura && plano.estado === 'RASCUNHO' && <Alert style={{ marginBottom: 12 }} type="info" showIcon title={`Reaberto: ${plano.motivo_reabertura}`} />}
      {plano?.aprovacao_excepcao && <Alert style={{ marginBottom: 12 }} type="warning" showIcon title={`Aprovado por quem o elaborou, por excepção: ${plano.aprovacao_excepcao}`} />}

      {isLoading || carregando ? <Skeleton active /> : !plano ? (
        <Empty description="Ainda não há planos estratégicos. Crie o primeiro com «Novo plano»." />
      ) : (
        <>
          <Row gutter={[12, 12]}>
            {([['Missão', plano.missao], ['Visão', plano.visao], ['Valores', plano.valores]] as const).map(([t, v]) => (
              <Col xs={24} md={8} key={t}><Card size="small" title={t}><Typography.Paragraph style={{ whiteSpace: 'pre-line', marginBottom: 0 }}>{v || <Typography.Text type="secondary">por preencher</Typography.Text>}</Typography.Paragraph></Card></Col>
            ))}
          </Row>
          <div className="est-grelha" role="list" aria-label="Perspectivas e objectivos">
            {grupos.map((g) => (
              <section key={g.perspectiva.id} role="listitem" className="est-persp" style={{ borderTopColor: g.perspectiva.cor }} aria-label={g.perspectiva.nome}>
                <h3>{g.perspectiva.nome}</h3>
                <small>{g.perspectiva.descricao}</small>
                {g.raizes.length === 0 && <Typography.Text type="secondary">Sem objectivos.</Typography.Text>}
                {g.raizes.map((o) => <NoObjectivo key={o.id} o={o} filhos={g.filhos} indicadores={det!.indicadores_por_objectivo} aoAbrir={(x) => (podeEditar || pode('est_plano_view')) && setObjectivoAberto({ objectivo: x })} />)}
                {podeEditar && <Button size="small" type="dashed" block icon={<PlusOutlined />} style={{ marginTop: 6 }} onClick={() => setObjectivoAberto({ perspectivaId: g.perspectiva.id })}>Objectivo</Button>}
              </section>
            ))}
          </div>
          <Typography.Paragraph type="secondary" style={{ marginTop: 12 }}>
            Um OKR é um objectivo de trimestre pendurado num objectivo do plano — aparece a tracejado e com alvo. Os indicadores definem-se no ecrã «Indicadores e metas».
          </Typography.Paragraph>
        </>
      )}

      {modalPlano && (
        <ModalPlano modo={modalPlano} plano={modalPlano === 'editar' ? plano : undefined} aoFechar={() => setModalPlano(null)} aoApagar={plano?.estado === 'RASCUNHO' ? () => apagar.mutate() : undefined}
          pedirMotivo={motivoEstrutura.pedir} aoGravar={(p) => { setModalPlano(null); refrescar(); void qc.invalidateQueries({ queryKey: CHAVE_PLANOS }); escolher(p.id); }} />
      )}
      {perspectivasAbertas && det && <ModalPerspectivas detalhe={det} pedirMotivo={motivoEstrutura.pedir} aoFechar={() => { setPerspectivasAbertas(false); refrescar(); }} />}
      {objectivoAberto && det && (
        <ModalObjectivo detalhe={det} editavel={podeEditar} objectivo={objectivoAberto.objectivo} perspectivaInicial={objectivoAberto.perspectivaId} pedirMotivo={motivoEstrutura.pedir}
          aoFechar={() => { setObjectivoAberto(null); refrescar(); }} />
      )}
      {motivoEstrutura.dialogo}
      {motivoReabrir.dialogo}
      {motivoExcepcao.dialogo}
    </>
  );
}

function agrupar(det: DetalhePlano) {
  return det.perspectivas.map((p) => {
    const deste = det.objectivos.filter((o) => o.perspectiva_id === p.id);
    const ids = new Set(deste.map((o) => o.id));
    const raizes = deste.filter((o) => !o.objectivo_pai_id || !ids.has(o.objectivo_pai_id));
    const filhos = new Map<number, Objectivo[]>();
    deste.forEach((o) => { if (o.objectivo_pai_id && ids.has(o.objectivo_pai_id)) filhos.set(o.objectivo_pai_id, [...(filhos.get(o.objectivo_pai_id) ?? []), o]); });
    return { perspectiva: p, raizes, filhos };
  });
}

function NoObjectivo({ o, filhos, indicadores, aoAbrir, nivel = 0 }: { o: Objectivo; filhos: Map<number, Objectivo[]>; indicadores: DetalhePlano['indicadores_por_objectivo']; aoAbrir: (o: Objectivo) => void; nivel?: number }) {
  const n = indicadores[String(o.id)]?.activos ?? 0;
  const okr = o.horizonte === 'TRIMESTRE';
  return (
    <div className={nivel ? 'est-filhos' : undefined}>
      <button type="button" className={`est-obj${okr ? ' est-okr' : ''}`} onClick={() => aoAbrir(o)} aria-label={`Abrir o objectivo ${o.nome}`}>
        <strong>{okr ? '◎ ' : ''}{o.nome}</strong>
        <span className="est-meta">
          {okr ? `OKR · ${o.periodo_rotulo} · ` : ''}{n} indicador(es){o.responsavel_externo ? ` · ${o.responsavel_externo}` : ''}{o.estado !== 'ACTIVO' ? ` · ${o.estado_rotulo}` : ''}
        </span>
        {n === 0 && <span className="est-aviso">sem indicador — não é medido</span>}
      </button>
      {(filhos.get(o.id) ?? []).map((f) => <NoObjectivo key={f.id} o={f} filhos={filhos} indicadores={indicadores} aoAbrir={aoAbrir} nivel={nivel + 1} />)}
    </div>
  );
}

// ---- plano -----------------------------------------------------------------------------------------------------

function ModalPlano({ modo, plano, aoFechar, aoGravar, aoApagar, pedirMotivo }: {
  modo: 'novo' | 'editar'; plano?: Plano; aoFechar: () => void; aoGravar: (p: Plano) => void; aoApagar?: () => void; pedirMotivo: (f: (m?: string) => void) => void;
}) {
  const [form] = Form.useForm();
  const ano = new Date().getFullYear();
  const inicial = modo === 'editar' && plano
    ? { nome: plano.nome, ano_inicio: plano.ano_inicio, ano_fim: plano.ano_fim, missao: plano.missao ?? '', visao: plano.visao ?? '', valores: plano.valores ?? '' }
    : { nome: `Plano Estratégico ${ano}-${ano + 4}`, ano_inicio: ano, ano_fim: ano + 4, missao: '', visao: '', valores: '' };
  const gravar = useMutation({
    mutationFn: (v: { valores: Record<string, unknown>; motivo: MotivoOpc }) => gravarPlano(modo === 'editar' && plano ? plano.id : null, { ...v.valores, motivo_excepcao: v.motivo }),
    onSuccess: (p) => { message.success(modo === 'novo' ? 'Plano criado com as quatro perspectivas do Balanced Scorecard.' : 'Plano gravado.'); aoGravar(p); },
    onError: (e) => notificarErro(e),
  });
  return (
    <Modal open title={modo === 'novo' ? 'Novo plano estratégico' : 'Editar plano estratégico'} width={larguraModal(620)} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar" confirmLoading={gravar.isPending} destroyOnHidden
      onOk={() => form.validateFields().then((valores) => (modo === 'editar' ? pedirMotivo((m) => gravar.mutate({ valores, motivo: m })) : gravar.mutate({ valores, motivo: undefined }))).catch(() => undefined)}
      footer={(_, { OkBtn, CancelBtn }) => (
        <Space wrap>
          {aoApagar && modo === 'editar' && <Popconfirm title="Apagar este plano?" description="Só se apaga um plano em elaboração sem objectivos nem indicadores." okText="Apagar" cancelText="Cancelar" onConfirm={aoApagar}><Button danger icon={<DeleteOutlined />}>Apagar</Button></Popconfirm>}
          <CancelBtn /><OkBtn />
        </Space>
      )}>
      <Form form={form} layout="vertical" initialValues={inicial}>
        <Form.Item name="nome" label="Nome do plano" rules={[{ required: true, whitespace: true, message: 'Dê um nome ao plano (ex.: «Plano Estratégico 2027-2031»).' }]}><Input maxLength={200} /></Form.Item>
        <Row gutter={12}>
          <Col xs={24} sm={12}><Form.Item name="ano_inicio" label="Ano de início" rules={[{ required: true, message: 'Indique o ano de início.' }]}><InputNumber min={1901} max={2200} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} sm={12}>
            <Form.Item name="ano_fim" label="Ano de fim" dependencies={['ano_inicio']} extra="No máximo 15 anos."
              rules={[{ required: true, message: 'Indique o ano de fim.' }, ({ getFieldValue }) => ({ validator: (_r, v) => (v >= getFieldValue('ano_inicio') ? Promise.resolve() : Promise.reject(new Error('Indique o ano de início e um ano de fim igual ou posterior.'))) })]}>
              <InputNumber min={1901} max={2200} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <Form.Item name="missao" label="Missão" extra="Para que existe a organização, em quem serve e com que propósito."><Input.TextArea rows={2} maxLength={5000} placeholder="Para que existimos" /></Form.Item>
        <Form.Item name="visao" label="Visão" extra="Uma imagem concreta do fim do ciclo — deve ser possível dizer se foi atingida."><Input.TextArea rows={2} maxLength={5000} placeholder="Onde queremos chegar no fim do ciclo" /></Form.Item>
        <Form.Item name="valores" label="Valores" extra="Uma linha por valor."><Input.TextArea rows={3} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

// ---- perspectivas ----------------------------------------------------------------------------------------------

interface LinhaPersp { id: number | null; ordem: number; nome: string; descricao: string; cor: string }

function ModalPerspectivas({ detalhe, aoFechar, pedirMotivo }: { detalhe: DetalhePlano; aoFechar: () => void; pedirMotivo: (f: (m?: string) => void) => void }) {
  const planoId = detalhe.plano.id;
  const [linhas, setLinhas] = useState<LinhaPersp[]>([]);
  useEffect(() => setLinhas(detalhe.perspectivas.map((p: Perspectiva) => ({ id: p.id, ordem: p.ordem, nome: p.nome, descricao: p.descricao ?? '', cor: p.cor }))), [detalhe]);
  const mexer = (i: number, parte: Partial<LinhaPersp>) => setLinhas((ls) => ls.map((l, k) => (k === i ? { ...l, ...parte } : l)));
  const gravar = useMutation({
    mutationFn: async (motivo: MotivoOpc) => { for (const l of linhas) await gravarPerspectiva(planoId, l.id, { nome: l.nome, descricao: l.descricao, ordem: l.ordem, cor: l.cor, motivo_excepcao: motivo }); },
    onSuccess: () => { message.success('Perspectivas gravadas.'); aoFechar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({
    mutationFn: async (v: { id: number; motivo: MotivoOpc }) => apagarPerspectiva(planoId, v.id, v.motivo),
    onSuccess: (_r, v) => setLinhas((ls) => ls.filter((l) => l.id !== v.id)),
    onError: (e) => notificarErro(e),
  });
  return (
    <Modal open title="Perspectivas" width={larguraModal(700)} onCancel={aoFechar} cancelText="Fechar" okText="Gravar" confirmLoading={gravar.isPending} destroyOnHidden
      onOk={() => (linhas.some((l) => !l.nome.trim()) ? message.error('Dê um nome à perspectiva.') : pedirMotivo((m) => gravar.mutate(m)))}>
      {linhas.map((l, i) => (
        <Row key={l.id ?? `n${i}`} gutter={8} align="bottom" style={{ marginBottom: 8 }}>
          <Col xs={6} sm={3}><label>Ordem<InputNumber aria-label={`Ordem da perspectiva ${i + 1}`} value={l.ordem} onChange={(v) => mexer(i, { ordem: Number(v ?? 0) })} style={{ width: '100%' }} /></label></Col>
          <Col xs={18} sm={7}><label>Nome<Input aria-label={`Nome da perspectiva ${i + 1}`} value={l.nome} maxLength={120} onChange={(e) => mexer(i, { nome: e.target.value })} /></label></Col>
          <Col xs={16} sm={9}><label>Descrição<Input aria-label={`Descrição da perspectiva ${i + 1}`} value={l.descricao} maxLength={300} onChange={(e) => mexer(i, { descricao: e.target.value })} /></label></Col>
          <Col xs={4} sm={3}><label>Cor<input type="color" aria-label={`Cor da perspectiva ${i + 1}`} value={l.cor} onChange={(e) => mexer(i, { cor: e.target.value })} style={{ width: '100%', height: 32 }} /></label></Col>
          <Col xs={4} sm={2}>
            <Popconfirm title="Apagar esta perspectiva?" okText="Apagar" cancelText="Cancelar" disabled={l.id === null}
              onConfirm={() => (l.id === null ? undefined : pedirMotivo((m) => apagar.mutate({ id: l.id as number, motivo: m })))}>
              <Button danger icon={<DeleteOutlined />} aria-label={`Apagar a perspectiva ${l.nome || i + 1}`} onClick={() => l.id === null && setLinhas((ls) => ls.filter((_x, k) => k !== i))} />
            </Popconfirm>
          </Col>
        </Row>
      ))}
      <Button icon={<PlusOutlined />} onClick={() => setLinhas((ls) => [...ls, { id: null, ordem: (ls.at(-1)?.ordem ?? 0) + 10, nome: '', descricao: '', cor: '#475569' }])}>Perspectiva</Button>
    </Modal>
  );
}

// ---- objectivo -------------------------------------------------------------------------------------------------

function ModalObjectivo({ detalhe, objectivo, perspectivaInicial, editavel, aoFechar, pedirMotivo }: {
  detalhe: DetalhePlano; objectivo?: Objectivo; perspectivaInicial?: number; editavel: boolean; aoFechar: () => void; pedirMotivo: (f: (m?: string) => void) => void;
}) {
  const [form] = Form.useForm();
  const { data: cat } = useQuery({ queryKey: CHAVE_CATALOGOS, queryFn: obterCatalogos, staleTime: 5 * 60_000 });
  const plano = detalhe.plano;
  const horizonte = Form.useWatch('horizonte', form) ?? objectivo?.horizonte ?? 'PLANO';
  const inicial = objectivo
    ? { nome: objectivo.nome, descricao: objectivo.descricao ?? '', perspectiva_id: objectivo.perspectiva_id, horizonte: objectivo.horizonte, periodo: objectivo.periodo ?? undefined, objectivo_pai_id: objectivo.objectivo_pai_id ?? undefined,
      unidade_organica_id: objectivo.unidade_organica_id ?? undefined, responsavel_colaborador_id: objectivo.responsavel_colaborador_id ?? undefined, responsavel_externo: objectivo.responsavel_externo ?? '', estado: objectivo.estado }
    : { nome: '', descricao: '', perspectiva_id: perspectivaInicial ?? detalhe.perspectivas[0]?.id, horizonte: 'PLANO', estado: 'ACTIVO' };
  const gravar = useMutation({
    mutationFn: (v: { valores: Record<string, unknown>; motivo: MotivoOpc }) => gravarObjectivo(plano.id, objectivo?.id ?? null, { ...v.valores, motivo_excepcao: v.motivo }),
    onSuccess: () => { message.success('Objectivo gravado.'); aoFechar(); },
    onError: (e) => notificarErro(e),
  });
  const apagar = useMutation({
    mutationFn: (motivo: MotivoOpc) => apagarObjectivo(plano.id, objectivo!.id, motivo),
    onSuccess: () => { message.success('Objectivo apagado.'); aoFechar(); },
    onError: (e) => notificarErro(e),
  });
  const submeter = () => form.validateFields().then((v) => {
    const valores = { ...v, horizonte: v.horizonte ?? 'PLANO', periodo: v.horizonte === 'TRIMESTRE' ? v.periodo : null, objectivo_pai_id: v.objectivo_pai_id ?? null, unidade_organica_id: v.unidade_organica_id ?? null, responsavel_colaborador_id: v.responsavel_colaborador_id ?? null };
    pedirMotivo((m) => gravar.mutate({ valores, motivo: m }));
  }).catch(() => undefined);
  return (
    <Modal open title={objectivo ? 'Editar objectivo' : 'Novo objectivo'} width={larguraModal(640)} onCancel={aoFechar} destroyOnHidden confirmLoading={gravar.isPending} okText="Gravar" cancelText="Cancelar"
      okButtonProps={{ disabled: !editavel }} onOk={submeter}
      footer={(_, { OkBtn, CancelBtn }) => (
        <Space wrap>
          {objectivo && editavel && <Popconfirm title="Apagar este objectivo?" okText="Apagar" cancelText="Cancelar" onConfirm={() => pedirMotivo((m) => apagar.mutate(m))}><Button danger icon={<DeleteOutlined />}>Apagar</Button></Popconfirm>}
          <CancelBtn />{editavel && <OkBtn />}
        </Space>
      )}>
      <Form form={form} layout="vertical" disabled={!editavel} initialValues={inicial}>
        <Form.Item name="nome" label="Objectivo" extra="Escreva-o como um resultado a atingir, não como uma tarefa." rules={[{ required: true, whitespace: true, message: 'Dê um nome ao objectivo.' }]}><Input maxLength={300} placeholder="Ex.: Crescer a margem dos serviços" /></Form.Item>
        <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        <Row gutter={12}>
          <Col xs={24} sm={12}><Form.Item name="perspectiva_id" label="Perspectiva" rules={[{ required: true, message: 'Escolha uma perspectiva do plano.' }]}><Select options={detalhe.perspectivas.map((p) => ({ value: p.id, label: p.nome }))} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="horizonte" label="Horizonte"><Select options={[{ value: 'PLANO', label: 'Objectivo do plano' }, { value: 'TRIMESTRE', label: 'OKR do trimestre' }]} /></Form.Item></Col>
        </Row>
        {horizonte === 'TRIMESTRE' && (
          <Form.Item name="periodo" label="Trimestre do OKR" rules={[{ required: true, message: 'Um OKR tem de dizer a que trimestre pertence.' }]}><Select options={trimestresDoCiclo(plano.ano_inicio, plano.ano_fim)} placeholder="Escolha o trimestre" /></Form.Item>
        )}
        <Form.Item name="objectivo_pai_id" label="Depende de" extra="Num OKR, aponte para o objectivo do plano que ele serve.">
          <Select allowClear showSearch optionFilterProp="label" placeholder="— nenhum —"
            options={detalhe.objectivos.filter((o) => o.id !== objectivo?.id).map((o) => ({ value: o.id, label: `${o.nome}${o.horizonte === 'TRIMESTRE' ? ' (OKR)' : ''}` }))} />
        </Form.Item>
        <Row gutter={12}>
          <Col xs={24} sm={12}><Form.Item name="unidade_organica_id" label="Unidade responsável"><Select allowClear showSearch optionFilterProp="label" placeholder="— empresa —" options={(cat?.unidades ?? []).map((u) => ({ value: u.id, label: u.nome }))} /></Form.Item></Col>
          <Col xs={24} sm={12}><Form.Item name="responsavel_colaborador_id" label="Responsável"><Select allowClear showSearch optionFilterProp="label" placeholder="— ninguém —" options={(cat?.colaboradores ?? []).map((c) => ({ value: c.id, label: c.nome }))} /></Form.Item></Col>
        </Row>
        <Form.Item name="responsavel_externo" label="Responsável externo (se não for colaborador)"><Input maxLength={150} /></Form.Item>
        <Form.Item name="estado" label="Estado"><Select options={(cat?.estados_objectivo ?? [{ id: 'ACTIVO', nome: 'Activo' }]).map((e) => ({ value: e.id, label: e.nome }))} /></Form.Item>
      </Form>
    </Modal>
  );
}
