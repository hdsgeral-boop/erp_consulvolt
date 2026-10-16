import { Alert, Button, Checkbox, Col, DatePicker, Form, Input, InputNumber, Modal, Radio, Row, Select, Space, Spin, Tag, Typography, message, theme } from 'antd';
import { DownOutlined, RightOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useId, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { ModalMotivo } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { dataApi } from '@/utilitarios/formatacao';
import { notificarErro } from '@/utilitarios/erros';
import { avisoPrazo, chaveResp, DELIB, ESTADOS, CORES_ESTADO, LOCAIS, METODOS, MODOS_PROJECTO, tarefasDosRecursos, TIPOS_RESP, validar } from './regras';
import type { Anexo, Aprovador, Assunto, DadosAssunto, LinhaPedido, LocalTipo, MetodoResolucao, ModoProjecto, OpcoesAssunto, Responsavel, TipoResponsavel } from './tipos';

const CHAVE = ['metodo', 'assuntos'];

interface Campos {
  titulo: string;
  descricao?: string;
  data_inicio?: Dayjs | null;
  data_fim?: Dayjs | null;
  local_tipo?: LocalTipo;
  unidade_negocio_id?: number;
  cliente_id?: number;
  encomenda?: string;
  morada?: string;
  fundamentacao?: string;
  causa_raiz?: string;
  recursos?: string;
  custo_estimado?: number;
  codigo_moeda?: string;
  metodo_resolucao: MetodoResolucao;
  projeto_modo?: ModoProjecto;
  projeto_id?: number;
  centro_custo_id?: number;
  encomenda_venda_id?: number;
  gerar_pedido_compra?: boolean;
}

type Motivo = { tipo: 'DEVOLVER' | 'RECUSAR' | 'CANCELAR' } | null;

/**
 * Bloco de uma das seis perguntas (bloco, assuntos_ui.js:192-197), com o aspecto do legado (moldura, cabeçalho claro e
 * etiqueta escura). O cabeçalho é um botão que recolhe/abre o bloco (`aria-expanded`); o conteúdo continua montado
 * quando recolhido, para os campos manterem os valores e as regras. Todos abrem por omissão, como no legado.
 */
export function Bloco({ n, titulo, sub, children, recolhido = false }: { n: string; titulo: string; sub: string; children: ReactNode; recolhido?: boolean }) {
  const { token } = theme.useToken();
  const [fechado, setFechado] = useState(recolhido);
  const id = useId();
  return (
    <section style={{ border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, marginBottom: 14 }}>
      <button type="button" aria-expanded={!fechado} aria-controls={id} onClick={() => setFechado((f) => !f)}
        style={{ width: '100%', background: token.colorFillAlter, border: 'none', borderBottom: fechado ? 'none' : `1px solid ${token.colorBorderSecondary}`, padding: '6px 12px',
          display: 'flex', gap: 8, alignItems: 'baseline', cursor: 'pointer', font: 'inherit', color: token.colorText, textAlign: 'left' }}>
        {fechado ? <RightOutlined style={{ fontSize: 10 }} /> : <DownOutlined style={{ fontSize: 10 }} />}
        <span style={{ background: token.colorText, color: token.colorBgContainer, borderRadius: 3, padding: '1px 7px', fontSize: 11, fontWeight: 700, letterSpacing: '0.05em' }}>{n}</span>
        <strong>{titulo}</strong><Typography.Text type="secondary" style={{ fontSize: 12 }}>{sub}</Typography.Text>
      </button>
      <div id={id} hidden={fechado} style={{ padding: 12 }}>{children}</div>
    </section>
  );
}

/**
 * Formulário 5W1H (abrirFormulario, assuntos_ui.js:188-530): WHAT, WHO, WHEN, WHERE, WHY, HOW; painel da deliberação;
 * submeter, aprovar / devolver / recusar, concluir, cancelar e reabrir.
 */
export function FormularioAssunto({ id, aoFechar, aoCriar }: { id: number | 'novo' | null; aoFechar: () => void; aoCriar: (id: number) => void }) {
  const [form] = Form.useForm<Campos>();
  const { token } = theme.useToken();
  const cliente = useQueryClient();
  const navegar = useNavigate();
  const aberto = id !== null;
  const existente = typeof id === 'number' ? id : null;
  const [resp, setResp] = useState<Responsavel[]>([]);
  const [principal, setPrincipal] = useState<string | null>(null);
  const [anexos, setAnexos] = useState<Anexo[]>([]);
  const [linhas, setLinhas] = useState<LinhaPedido[]>([]);
  const [novoResp, setNovoResp] = useState<{ tipo: TipoResponsavel; ref?: number; livre?: string }>({ tipo: 'COLABORADOR' });
  const [novoAnexo, setNovoAnexo] = useState<Anexo>({ nome: '', url: '' });
  const [textoArtigo, setTextoArtigo] = useState('');
  const [carregando, setCarregando] = useState(false);
  const [motivo, setMotivo] = useState<Motivo>(null);

  const opcoes = useQuery({ queryKey: [...CHAVE, 'opcoes'], enabled: aberto, queryFn: () => obter<OpcoesAssunto>('/metodo/assuntos/opcoes') });
  const det = useQuery({ queryKey: [...CHAVE, 'detalhe', existente], enabled: existente !== null, queryFn: () => obter<Assunto>(`/metodo/assuntos/${existente}`) });
  const aprovador = useQuery({ queryKey: [...CHAVE, 'aprovador'], enabled: aberto, queryFn: () => obter<Aprovador>('/metodo/assuntos/aprovador') });
  const a = existente !== null ? det.data : undefined;

  const local = Form.useWatch('local_tipo', form);
  const metodo = Form.useWatch('metodo_resolucao', form);
  const modo = Form.useWatch('projeto_modo', form);
  const clienteId = Form.useWatch('cliente_id', form);
  const ini = Form.useWatch('data_inicio', form);
  const fim = Form.useWatch('data_fim', form);
  const fund = Form.useWatch('fundamentacao', form) ?? '';
  const recursos = Form.useWatch('recursos', form);
  const titulo = Form.useWatch('titulo', form) ?? '';
  const gerarPc = Form.useWatch('gerar_pedido_compra', form);

  const projetos = useQuery({ queryKey: [...CHAVE, 'projetos', existente], enabled: aberto && metodo === 'PROJECTO',
    queryFn: () => obter<{ id: number; codigo: string; nome: string; estado: string }[]>('/metodo/assuntos/projetos-disponiveis', { assunto_id: existente ?? undefined }) });
  const encomendas = useQuery({ queryKey: [...CHAVE, 'encomendas', clienteId], enabled: aberto && local === 'EXTERNO' && !!clienteId && metodo === 'PROJECTO',
    queryFn: () => obter<{ id: number; numero_documento: string; data_emissao: string }[]>('/metodo/assuntos/encomendas', { cliente_id: clienteId }) });
  const artigos = useQuery({ queryKey: [...CHAVE, 'artigos', textoArtigo], enabled: aberto && !!gerarPc,
    queryFn: () => obter<{ id: number; codigo: string; nome: string; custo_medio: string | null }[]>('/metodo/assuntos/artigos', { texto: textoArtigo || undefined }) });

  // carregar o assunto (ou limpar para um novo)
  useEffect(() => {
    if (!aberto) return;
    if (existente === null) {
      form.resetFields();
      form.setFieldsValue({ metodo_resolucao: 'ACCAO', projeto_modo: 'NOVO', codigo_moeda: 'AOA', gerar_pedido_compra: false });
      setResp([]); setPrincipal(null); setAnexos([]); setLinhas([]);
      return;
    }
    if (!a) return;
    form.resetFields();
    form.setFieldsValue({
      titulo: a.titulo ?? '', descricao: a.descricao ?? undefined,
      data_inicio: a.data_inicio ? dayjs(a.data_inicio) : null, data_fim: a.data_fim ? dayjs(a.data_fim) : null,
      local_tipo: a.local_tipo ?? undefined, unidade_negocio_id: a.unidade_negocio_id ?? undefined, cliente_id: a.cliente_id ?? undefined,
      encomenda: a.encomenda ?? undefined, morada: a.morada ?? undefined, fundamentacao: a.fundamentacao ?? undefined, causa_raiz: a.causa_raiz ?? undefined,
      recursos: a.recursos ?? undefined, custo_estimado: a.custo_estimado ? Number(a.custo_estimado) : undefined, codigo_moeda: a.codigo_moeda ?? 'AOA',
      metodo_resolucao: a.metodo_resolucao ?? 'ACCAO', projeto_modo: a.projeto_modo ?? 'NOVO', projeto_id: a.projeto_id ?? undefined,
      centro_custo_id: a.centro_custo_id ?? undefined, encomenda_venda_id: a.encomenda_venda_id ?? undefined, gerar_pedido_compra: !!a.gerar_pedido_compra,
    });
    setResp(a.responsaveis ?? []); setPrincipal(a.responsavel_principal); setAnexos(a.anexos ?? []);
    setLinhas((a.linhas_pedido_compra ?? []).map((l) => ({ ...l, quantidade: Number(l.quantidade), preco_unitario: l.preco_unitario != null ? Number(l.preco_unitario) : null })));
  }, [aberto, existente, a, form]);

  const perm = a?.permissoes ?? { ver: true, editar: !!opcoes.data?.pode_criar, submeter: false, decidir: false, concluir: false, cancelar: false, reabrir: false, gestor: false };
  const so = !perm.editar;
  const rascunho = !a || a.estado === 'RASCUNHO';
  const temFornecedor = resp.some((r) => r.tipo === 'FORNECEDOR');

  // fornecedor em WHO ⇒ pedido de compra (notaPC, :411-419)
  useEffect(() => {
    if (temFornecedor && !so && rascunho && !form.getFieldValue('gerar_pedido_compra')) form.setFieldValue('gerar_pedido_compra', true);
  }, [temFornecedor, so, rascunho, form]);

  const recolher = (validarTudo = false): DadosAssunto => {
    const v = form.getFieldsValue(true) as Campos;
    return {
      titulo: v.titulo ?? '', descricao: v.descricao ?? null, responsaveis: resp, responsavel_principal: principal,
      data_inicio: dataApi(v.data_inicio ?? null) ?? null, data_fim: dataApi(v.data_fim ?? null) ?? null,
      local_tipo: v.local_tipo ?? null, unidade_negocio_id: v.unidade_negocio_id ?? null, cliente_id: v.cliente_id ?? null,
      encomenda: v.encomenda ?? null, morada: v.morada ?? null, fundamentacao: v.fundamentacao ?? null, causa_raiz: v.causa_raiz ?? null,
      recursos: v.recursos ?? null, custo_estimado: v.custo_estimado ?? null, codigo_moeda: v.codigo_moeda || 'AOA',
      metodo_resolucao: v.metodo_resolucao ?? 'ACCAO', projeto_modo: v.projeto_modo ?? 'NOVO', projeto_id: v.projeto_id ?? null,
      centro_custo_id: v.centro_custo_id ?? null, encomenda_venda_id: v.encomenda_venda_id ?? null, gerar_pedido_compra: !!v.gerar_pedido_compra,
      linhas_pedido_compra: v.gerar_pedido_compra ? linhas.map(({ rotulo: _r, ...l }) => l) : [], anexos,
      ...(validarTudo ? { validar_tudo: true } : {}),
    };
  };

  const correr = async (f: () => Promise<string | void>, fechar = true) => {
    setCarregando(true);
    try {
      const msg = await f();
      if (msg) message.success(msg);
      void cliente.invalidateQueries({ queryKey: CHAVE });
      if (fechar) aoFechar();
    } catch (e) {
      notificarErro(e);
    } finally {
      setCarregando(false);
    }
  };

  const gravar = async (validarTudo = false) => {
    const r = await enviar<{ assunto: Assunto; avisos: string[] }>(existente ? 'put' : 'post', existente ? `/metodo/assuntos/${existente}` : '/metodo/assuntos', recolher(validarTudo));
    r.dados.avisos.forEach((x) => message.warning(x));
    return r.dados.assunto;
  };

  const accaoGravar = () => correr(async () => {
    const novo = await gravar();
    if (!existente) aoCriar(novo.id);
    return 'Assunto gravado.';
  }, !!existente);
  const accaoSubmeter = () => correr(async () => {
    if (perm.editar) await gravar(true);
    return (await enviar('post', `/metodo/assuntos/${existente}/submeter`)).mensagem;
  });
  const accaoDecidir = (accao: 'APROVAR' | 'DEVOLVER' | 'RECUSAR', nota?: string) => correr(async () => (await enviar('post', `/metodo/assuntos/${existente}/decidir`, { accao, nota })).mensagem);
  const accaoEstado = (estado: 'CONCLUIDO' | 'CANCELADO' | 'RASCUNHO', nota?: string) => correr(async () => {
    if (perm.editar && estado !== 'RASCUNHO') await gravar();   // não se perde o que está no ecrã
    return (await enviar('post', `/metodo/assuntos/${existente}/estado`, { estado, nota })).mensagem;
  });

  const acrescentarResp = () => {
    const o = opcoes.data;
    let novo: Responsavel | null = null;
    if (novoResp.tipo === 'EXTERNO') novo = novoResp.livre?.trim() ? { tipo: 'EXTERNO', ref_id: null, nome: novoResp.livre.trim() } : null;
    else if (novoResp.ref) {
      const nome = novoResp.tipo === 'COLABORADOR' ? o?.colaboradores.find((c) => c.id === novoResp.ref)?.nome_completo : o?.fornecedores.find((f) => f.id === novoResp.ref)?.nome;
      novo = nome ? { tipo: novoResp.tipo, ref_id: novoResp.ref, nome } : null;
    }
    if (!novo) { message.warning('Escolha ou escreva quem é o responsável.'); return; }
    if (resp.some((r) => chaveResp(r) === chaveResp(novo))) { message.warning('Esse responsável já está na lista.'); return; }
    setResp([...resp, novo]);
    if (!principal) setPrincipal(chaveResp(novo));
    setNovoResp({ tipo: novoResp.tipo });
  };

  const prazo = avisoPrazo(dataApi(ini ?? null), dataApi(fim ?? null));
  const problemas = aberto ? validar(recolher(), false) : { erros: [], avisos: [] };
  const d = a?.deliberacao;
  const o = opcoes.data;
  const nTarefas = tarefasDosRecursos(recursos, titulo).length;

  return (
    <Modal open={aberto} onCancel={aoFechar} width={920} destroyOnHidden maskClosable={false}
      title={<Space><IconeFa nome="clipboard-list" />{a ? `Assunto ${a.numero}` : 'Novo assunto'}{a && <Tag color={CORES_ESTADO[a.estado]}>{ESTADOS[a.estado]}</Tag>}</Space>}
      footer={
        <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: 8 }}>
          <Space wrap>
            {a && perm.submeter && <Button type="primary" icon={<IconeFa nome="gavel" />} loading={carregando} onClick={accaoSubmeter}>Submeter a aprovação</Button>}
            {a && perm.concluir && <Button icon={<IconeFa nome="check" />} loading={carregando} onClick={() => accaoEstado('CONCLUIDO')}>Concluir</Button>}
            {a && perm.cancelar && <Button danger icon={<IconeFa nome="ban" />} onClick={() => setMotivo({ tipo: 'CANCELAR' })}>Cancelar assunto</Button>}
            {a && perm.reabrir && <Button icon={<IconeFa nome="undo" />} loading={carregando} onClick={() => accaoEstado('RASCUNHO')}>Reabrir</Button>}
          </Space>
          <Space>
            <Button onClick={aoFechar}>Fechar</Button>
            {perm.editar && <Button type="primary" icon={<IconeFa nome="save" />} loading={carregando} onClick={accaoGravar}>Gravar</Button>}
          </Space>
        </div>
      }>
      <Spin spinning={(existente !== null && det.isLoading) || opcoes.isLoading}>
        <>
          {a && so && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={d?.estado === 'PENDENTE'
            ? 'Só de leitura: o assunto aguarda aprovação.' : 'Só de leitura: neste estado, a edição cabe ao criador (em rascunho) ou ao responsável (em curso).'} />}
          {a?.motivo_cancelamento && a.estado === 'CANCELADO' && <Alert type="error" style={{ marginBottom: 12 }} message={`Cancelado: ${a.motivo_cancelamento}`} />}
          <Form form={form} layout="vertical" disabled={so}>
            <Bloco n="WHAT" titulo="O quê?" sub="projecto ou tarefa a realizar">
              <Form.Item name="titulo" label="Título (máx. 120)" rules={[{ required: true, message: 'Indique o título do assunto.' }, { max: 120 }]}><Input maxLength={120} /></Form.Item>
              <Form.Item name="descricao" label="Descrição" style={{ marginBottom: 0 }}><Input.TextArea rows={3} /></Form.Item>
            </Bloco>

            <Bloco n="WHO" titulo="Quem?" sub="responsáveis pela execução">
              {!so && (
                <Row gutter={8} align="bottom" style={{ marginBottom: 8 }}>
                  <Col span={7}>
                    <Typography.Text type="secondary">Tipo</Typography.Text>
                    <Select style={{ width: '100%' }} value={novoResp.tipo} onChange={(t) => setNovoResp({ tipo: t })} aria-label="Tipo de responsável"
                      options={Object.entries(TIPOS_RESP).map(([value, label]) => ({ value, label }))} />
                  </Col>
                  <Col span={14}>
                    <Typography.Text type="secondary">Pessoa / entidade</Typography.Text>
                    {novoResp.tipo === 'EXTERNO'
                      ? <Input placeholder="Nome do prestador externo" value={novoResp.livre} onChange={(e) => setNovoResp({ ...novoResp, livre: e.target.value })} />
                      : <Select style={{ width: '100%' }} showSearch optionFilterProp="label" value={novoResp.ref} onChange={(v) => setNovoResp({ ...novoResp, ref: v })}
                          placeholder={novoResp.tipo === 'COLABORADOR' ? 'Colaborador' : 'Fornecedor'} aria-label="Pessoa ou entidade"
                          options={(novoResp.tipo === 'COLABORADOR' ? o?.colaboradores.map((c) => ({ value: c.id, label: c.nome_completo })) : o?.fornecedores.map((f) => ({ value: f.id, label: f.nome }))) ?? []} />}
                  </Col>
                  <Col span={3}><Button block icon={<IconeFa nome="plus" />} onClick={acrescentarResp} aria-label="Acrescentar responsável" /></Col>
                </Row>
              )}
              {resp.length ? (
                <Radio.Group value={principal} onChange={(e) => setPrincipal(e.target.value as string)} style={{ width: '100%' }} disabled={so}>
                  {resp.map((r, i) => (
                    <div key={chaveResp(r)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '4px 8px', border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, marginBottom: 4 }}>
                      <Radio value={chaveResp(r)} title="Responsável principal" />
                      <div style={{ flex: 1 }}><strong>{r.nome}</strong> <Typography.Text type="secondary">{TIPOS_RESP[r.tipo]}</Typography.Text></div>
                      {!so && <Button size="small" danger icon={<IconeFa nome="times" />} aria-label={`Retirar ${r.nome}`} onClick={() => {
                        const resto = resp.filter((_, j) => j !== i);
                        setResp(resto);
                        if (principal === chaveResp(r)) setPrincipal(resto[0] ? chaveResp(resto[0]) : null);
                      }} />}
                    </div>
                  ))}
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>O botão redondo marca o responsável principal.</Typography.Text>
                </Radio.Group>
              ) : <Typography.Text type="secondary">Sem responsáveis. Acrescente pelo menos um.</Typography.Text>}
            </Bloco>

            <Bloco n="WHEN" titulo="Quando?" sub="prazo de execução">
              <Row gutter={12}>
                <Col span={12}><Form.Item name="data_inicio" label="Data de início" style={{ marginBottom: 0 }}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
                <Col span={12}><Form.Item name="data_fim" label="Data de fim" style={{ marginBottom: 0 }}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
              </Row>
              {prazo && <div style={{ fontSize: 12, marginTop: 6, color: prazo.tipo === 'erro' ? token.colorError : prazo.tipo === 'aviso' ? token.colorWarningText : token.colorTextSecondary }}>{prazo.texto}</div>}
            </Bloco>

            <Bloco n="WHERE" titulo="Onde?" sub="localização do assunto">
              <Form.Item name="local_tipo" label="Localização"><Select allowClear placeholder="— escolher —" options={Object.entries(LOCAIS).map(([value, label]) => ({ value, label }))} /></Form.Item>
              {local === 'INTERNO' && (
                <Form.Item name="unidade_negocio_id" label="Unidade de negócio (departamento)">
                  <Select showSearch optionFilterProp="label" options={o?.unidades_negocio.map((u) => ({ value: u.id, label: `${u.codigo} — ${u.nome}` })) ?? []} />
                </Form.Item>
              )}
              {local === 'EXTERNO' && (
                <Row gutter={12}>
                  <Col span={14}><Form.Item name="cliente_id" label="Cliente"><Select showSearch optionFilterProp="label" options={o?.clientes.map((c) => ({ value: c.id, label: c.nome })) ?? []} /></Form.Item></Col>
                  <Col span={10}><Form.Item name="encomenda" label="Encomenda"><Input /></Form.Item></Col>
                </Row>
              )}
              <Form.Item name="morada" label="Local físico / endereço (opcional)" style={{ marginBottom: 0 }}><Input /></Form.Item>
            </Bloco>

            <Bloco n="WHY" titulo="Porquê?" sub="fundamentação">
              <Form.Item name="fundamentacao" label="Fundamentação (mín. 50 caracteres)"
                extra={<span style={{ color: fund.trim().length >= 50 ? token.colorSuccessText : token.colorError }}>{fund.trim().length >= 50 ? `${fund.trim().length} caracteres` : `${fund.trim().length} de 50 caracteres`}</span>}>
                <Input.TextArea rows={3} maxLength={4000} />
              </Form.Item>
              <Form.Item name="causa_raiz" label="Causa raiz (opcional — 5 porquês)" style={{ marginBottom: 0 }}><Input.TextArea rows={2} maxLength={4000} /></Form.Item>
            </Bloco>

            <Bloco n="HOW" titulo="Como?" sub="recursos e custo">
              <Form.Item name="recursos" label="Recursos envolvidos (uma linha por recurso)"><Input.TextArea rows={3} /></Form.Item>
              <Row gutter={12}>
                <Col span={12}><Form.Item name="custo_estimado" label="Custo estimado"><InputNumber min={0} step={0.01} style={{ width: '100%' }} /></Form.Item></Col>
                <Col span={12}><Form.Item name="codigo_moeda" label="Moeda"><Input maxLength={3} style={{ textTransform: 'uppercase' }} /></Form.Item></Col>
              </Row>
              <Form.Item name="metodo_resolucao" label="Método de resolução"
                extra={metodo === 'PROJECTO'
                  ? (modo === 'EXISTENTE'
                    ? 'Ao ser aprovado, o assunto fica ligado ao projecto escolhido. O assunto não encerra enquanto esse projecto não estiver encerrado.'
                    : `Ao ser aprovado é criado um projecto em Projectos e Obras: uma fase com o prazo do assunto, ${nTarefas} tarefa(s) a partir dos recursos, a equipa com os responsáveis e o custo estimado como orçamento. O assunto não encerra enquanto o projecto não estiver encerrado.`)
                  : 'O assunto resolve-se com uma acção directa, sem abrir projecto.'}>
                <Select disabled={so || !rascunho} options={Object.entries(METODOS).map(([value, label]) => ({ value, label }))} />
              </Form.Item>
              {metodo === 'PROJECTO' && (
                <Row gutter={12}>
                  <Col span={10}><Form.Item name="projeto_modo" label="Projecto"><Select disabled={so || !rascunho} options={Object.entries(MODOS_PROJECTO).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
                  {modo === 'EXISTENTE' ? (
                    <Col span={14}><Form.Item name="projeto_id" label="Qual">
                      <Select disabled={so || !rascunho} showSearch optionFilterProp="label" placeholder={projetos.data?.length === 0 ? '— não há projectos disponíveis —' : '— escolher —'}
                        options={projetos.data?.map((p) => ({ value: p.id, label: `${p.codigo} — ${p.nome} (${p.estado})` })) ?? []} />
                    </Form.Item></Col>
                  ) : local === 'EXTERNO' ? (
                    <Col span={14}><Form.Item name="encomenda_venda_id" label="Nota de encomenda (NE) do cliente">
                      <Select disabled={so || !rascunho} placeholder={clienteId ? '— escolher —' : 'Escolha primeiro o cliente'} options={encomendas.data?.map((e) => ({ value: e.id, label: e.numero_documento })) ?? []} />
                    </Form.Item></Col>
                  ) : (
                    <Col span={14}><Form.Item name="centro_custo_id" label="Centro de custo do projecto">
                      <Select disabled={so || !rascunho} showSearch optionFilterProp="label" options={o?.centros_custo.map((c) => ({ value: c.id, label: `${c.codigo} — ${c.descricao}` })) ?? []} />
                    </Form.Item></Col>
                  )}
                </Row>
              )}
              {a?.projeto && (
                <Alert type="info" style={{ marginBottom: 8 }} message={<span><IconeFa nome="hard-hat" /> Projecto de resolução <strong>{a.projeto.codigo}</strong> — {a.projeto.nome} ({a.projeto.estado}){' '}
                  <Button type="link" size="small" onClick={() => navegar(`/m/projectos/projectos_carteira/${a.projeto?.id}`)}>abrir o projecto</Button></span>} />
              )}
              <Form.Item name="gerar_pedido_compra" valuePropName="checked" style={{ marginBottom: 4 }}><Checkbox disabled={so || !rascunho}>Gerar pedido de compra</Checkbox></Form.Item>
              <Typography.Text type={gerarPc && !temFornecedor ? 'danger' : 'secondary'} style={{ fontSize: 12 }}>
                {gerarPc ? (temFornecedor ? 'Ao ser aprovado é criado o pedido em Compras. O assunto não encerra enquanto o pedido não for aprovado.' : 'Falta indicar um fornecedor em WHO.') : 'Sem pedido de compra associado.'}
              </Typography.Text>
              {gerarPc && (
                <div style={{ marginTop: 8 }}>
                  <Typography.Text strong style={{ fontSize: 13 }}>Artigos do pedido de compra</Typography.Text>
                  {linhas.map((l, i) => (
                    <Row key={`${l.produto_id}-${i}`} gutter={8} align="middle" style={{ marginTop: 4 }}>
                      <Col span={11}>{l.rotulo ?? `Artigo #${l.produto_id}`}</Col>
                      <Col span={5}><InputNumber min={0.001} value={l.quantidade} disabled={so || !rascunho} style={{ width: '100%' }} aria-label="Quantidade"
                        onChange={(v) => setLinhas(linhas.map((x, j) => (j === i ? { ...x, quantidade: Number(v ?? 0) } : x)))} /></Col>
                      <Col span={6}><InputNumber min={0} step={0.01} value={l.preco_unitario ?? undefined} disabled={so || !rascunho} style={{ width: '100%' }} placeholder="Preço" aria-label="Preço unitário"
                        onChange={(v) => setLinhas(linhas.map((x, j) => (j === i ? { ...x, preco_unitario: v == null ? null : Number(v) } : x)))} /></Col>
                      <Col span={2}>{!so && rascunho && <Button size="small" danger icon={<IconeFa nome="times" />} aria-label="Retirar artigo" onClick={() => setLinhas(linhas.filter((_, j) => j !== i))} />}</Col>
                    </Row>
                  ))}
                  {!so && rascunho && (
                    <Select style={{ width: '100%', marginTop: 6 }} showSearch filterOption={false} onSearch={setTextoArtigo} value={null} placeholder="Acrescentar artigo (código ou nome)"
                      aria-label="Acrescentar artigo" loading={artigos.isFetching}
                      options={artigos.data?.map((p) => ({ value: p.id, label: `${p.codigo} — ${p.nome}` })) ?? []}
                      onChange={(v: number | null) => {
                        const p = artigos.data?.find((x) => x.id === v);
                        if (p) setLinhas([...linhas, { produto_id: p.id, quantidade: 1, preco_unitario: p.custo_medio ? Number(p.custo_medio) : null, rotulo: `${p.codigo} — ${p.nome}` }]);
                      }} />
                  )}
                </div>
              )}
              {a?.pedido_compra && <div style={{ marginTop: 8, fontSize: 13 }}><strong>Pedido de compra {a.pedido_compra.numero}</strong> ({a.pedido_compra.estado}){' '}
                <Button type="link" size="small" onClick={() => navegar('/m/compras/compras_pedidos')}>abrir em Compras</Button></div>}

              <div style={{ marginTop: 12 }}>
                <Typography.Text strong style={{ fontSize: 13 }}>Anexos <Typography.Text type="secondary" style={{ fontWeight: 400 }}>(orçamentos, facturas, propostas — por endereço)</Typography.Text></Typography.Text>
                {anexos.map((x, i) => (
                  <div key={`${x.url}-${i}`} style={{ display: 'flex', gap: 8, alignItems: 'center', padding: '3px 8px', border: `1px solid ${token.colorBorderSecondary}`, borderRadius: 4, marginTop: 4 }}>
                    <IconeFa nome="paperclip" />
                    {/^https?:\/\//i.test(x.url) ? <a href={x.url} target="_blank" rel="noopener noreferrer" style={{ flex: 1 }}>{x.nome}</a> : <span style={{ flex: 1 }}>{x.nome}</span>}
                    {!so && <Button size="small" danger icon={<IconeFa nome="times" />} aria-label={`Retirar ${x.nome}`} onClick={() => setAnexos(anexos.filter((_, j) => j !== i))} />}
                  </div>
                ))}
                {!so && (
                  <Row gutter={8} style={{ marginTop: 6 }}>
                    <Col span={8}><Input placeholder="Designação" value={novoAnexo.nome} onChange={(e) => setNovoAnexo({ ...novoAnexo, nome: e.target.value })} /></Col>
                    <Col span={13}><Input placeholder="https://..." value={novoAnexo.url} onChange={(e) => setNovoAnexo({ ...novoAnexo, url: e.target.value })} /></Col>
                    <Col span={3}><Button block icon={<IconeFa nome="plus" />} aria-label="Acrescentar anexo" onClick={() => {
                      const url = novoAnexo.url.trim();
                      if (!/^https?:\/\//i.test(url)) { message.warning('Indique um endereço que comece por http:// ou https://.'); return; }
                      setAnexos([...anexos, { nome: novoAnexo.nome.trim() || url, url }]);
                      setNovoAnexo({ nome: '', url: '' });
                    }} /></Col>
                  </Row>
                )}
              </div>
            </Bloco>
          </Form>

          <PainelDeliberacao assunto={a} aprovador={aprovador.data} carregando={carregando}
            aoAprovar={() => accaoDecidir('APROVAR')} aoDevolver={() => setMotivo({ tipo: 'DEVOLVER' })} aoRecusar={() => setMotivo({ tipo: 'RECUSAR' })} />

          {!so && rascunho && problemas.erros.length > 0 && (
            <Alert type="info" showIcon message="Para submeter a aprovação ainda falta:" description={<ul style={{ margin: 0, paddingLeft: 18 }}>{problemas.erros.map((e) => <li key={e}>{e}</li>)}</ul>} />
          )}
        </>
      </Spin>

      <ModalMotivo aberto={motivo !== null} carregando={carregando}
        titulo={motivo?.tipo === 'DEVOLVER' ? 'Devolver para correcção' : motivo?.tipo === 'RECUSAR' ? 'Recusar o assunto' : 'Cancelar o assunto'}
        textoOk={motivo?.tipo === 'DEVOLVER' ? 'Devolver' : motivo?.tipo === 'RECUSAR' ? 'Recusar' : 'Cancelar assunto'}
        aviso={motivo?.tipo === 'RECUSAR' ? 'O assunto fica cancelado com este motivo (pode ser reaberto mais tarde).' : motivo?.tipo === 'DEVOLVER' ? 'O assunto volta a rascunho para ser corrigido e submetido de novo.' : undefined}
        aoFechar={() => setMotivo(null)}
        aoConfirmar={(m) => {
          const t = motivo?.tipo;
          setMotivo(null);
          if (t === 'CANCELAR') void accaoEstado('CANCELADO', m);
          else if (t) void accaoDecidir(t, m);
        }} />
    </Modal>
  );
}

/** Painel da deliberação (assuntos_ui.js:317-339): estado da aprovação e a decisão, para quem a tem de tomar. */
function PainelDeliberacao({ assunto: a, aprovador, carregando, aoAprovar, aoDevolver, aoRecusar }: {
  assunto?: Assunto; aprovador?: Aprovador; carregando: boolean; aoAprovar: () => void; aoDevolver: () => void; aoRecusar: () => void;
}) {
  if (!a) return null;
  const d = a.deliberacao;
  if (!d || d.estado === 'DEVOLVIDO') {
    if (a.estado !== 'RASCUNHO') return null;
    return (
      <Alert type={d ? 'warning' : 'info'} style={{ marginBottom: 12 }} icon={<IconeFa nome="gavel" />} showIcon
        message={d ? <span><strong>{DELIB.DEVOLVIDO}</strong>{d.nota ? ` — ${d.nota}` : ''}</span> : <strong>Aprovação</strong>}
        description={<>Para o assunto avançar tem de ser aprovado pelo responsável do nível acima no organigrama.
          {aprovador && <> Ao submeter, a decisão cabe a <strong>{aprovador.nome}</strong>{aprovador.proprio ? ' (aprovação automática)' : ''}. {aprovador.motivo}</>}</>} />
    );
  }
  const tipo = d.estado === 'APROVADO' ? 'success' : d.estado === 'RECUSADO' ? 'error' : 'warning';
  return (
    <Alert type={tipo} style={{ marginBottom: 12 }} icon={<IconeFa nome="gavel" />} showIcon
      message={<span><strong>{DELIB[d.estado]}</strong>{d.aprovador_nome ? <> — {d.automatica ? 'por' : 'cabe a'} <strong>{d.aprovador_nome}</strong>{d.aprovador_utilizador ? ` (${d.aprovador_utilizador})` : ''}</> : null}</span>}
      description={<>
        {d.motivo_aprovador && <div>{d.motivo_aprovador}</div>}
        {d.nota && <div><strong>Nota:</strong> {d.nota}</div>}
        {a.permissoes.decidir && (
          <Space style={{ marginTop: 8 }} wrap>
            <Button type="primary" size="small" icon={<IconeFa nome="check" />} loading={carregando} onClick={aoAprovar}>Aprovar e pôr em curso</Button>
            <Button size="small" icon={<IconeFa nome="undo" />} onClick={aoDevolver}>Devolver</Button>
            <Button size="small" danger icon={<IconeFa nome="times" />} onClick={aoRecusar}>Recusar</Button>
          </Space>
        )}
      </>} />
  );
}
