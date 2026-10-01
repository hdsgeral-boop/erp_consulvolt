import { Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Divider, Drawer, Flex, Form, Input, InputNumber, Modal, Rate, Row, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarNumero } from '@/utilitarios/formatacao';
import { FASES_AVALIACAO, PERIODOS_AVALIACAO, type Avaliacao as AvaliacaoT, type Colaborador, type ItemAvaliacao, type PeriodoAvaliacao } from './api';
import { CadastroSimples } from './comum/CadastroSimples';
import { contem, EstadoTag, FaseTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useColaboradores } from './comum/consultas';
import { classificar, itensAplicaveis } from './comum/regras';

interface Linha {
  colaborador: Colaborador;
  avaliacao: AvaliacaoT | null;
}

/** RH › Avaliação de desempenho (ecrã rh_avaliacao): avaliar, concluir, reabrir, eliminar e itens de avaliação. */
export default function Avaliacao() {
  const { pode } = useSessao();
  const [ano, setAno] = useState(dayjs().year());
  const [periodo, setPeriodo] = useState<PeriodoAvaliacao>('ANUAL');
  return (
    <>
      <CabecalhoPagina titulo="Avaliação de desempenho" subtitulo="Critérios (1 a 5) e objectivos; pontuação = critérios × (1 − P) + objectivos × P"
        accoes={<Space><InputNumber addonBefore="Ano" min={2000} max={2100} value={ano} onChange={(v) => v && setAno(v)} style={{ width: 150 }} /><Select value={periodo} onChange={setPeriodo} options={PERIODOS_AVALIACAO} style={{ width: 170 }} /></Space>} />
      <Tabs items={[
        { key: 'avaliacoes', label: 'Avaliações', children: <ListaAvaliacoes ano={ano} periodo={periodo} /> },
        ...(pode('rh_avaliacao_itens') ? [{ key: 'itens', label: 'Itens de avaliação', children: <Itens /> }] : []),
      ]} />
    </>
  );
}

function ListaAvaliacoes({ ano, periodo }: { ano: number; periodo: PeriodoAvaliacao }) {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const [termo, setTermo] = useState('');
  const [fase, setFase] = useState<string>();
  const [aberta, setAberta] = useState<Linha | null>(null);
  const q = useQuery({ queryKey: ['rh', 'avaliacao', 'avaliacoes', ano, periodo], queryFn: () => obter<AvaliacaoT[]>('/rh/avaliacao/avaliacoes', { ano, periodo }) });
  useAvisarErro(q.error);
  const accao = useAccaoRh();
  const porColab = new Map((q.data ?? []).map((a) => [a.colaborador_id, a]));
  const linhas: Linha[] = colaboradores.lista
    .filter((c) => c.estado === 'ACTIVO' || porColab.has(c.id))
    .map((c) => ({ colaborador: c, avaliacao: porColab.get(c.id) ?? null }))
    .filter((l) => contem(l.colaborador.nome_completo, termo) && (!fase || (l.avaliacao?.fase ?? 'POR_AVALIAR') === fase));

  const colunas: ColumnsType<Linha> = [
    { title: 'Colaborador', render: (_, l) => <strong>{l.colaborador.nome_completo}</strong> },
    { title: 'Fase', render: (_, l) => <FaseTag fase={l.avaliacao?.fase ?? 'POR_AVALIAR'} /> },
    { title: 'Pontuação', align: 'right', render: (_, l) => (l.avaliacao?.pontuacao ? formatarNumero(l.avaliacao.pontuacao) : '—') },
    { title: 'Nota 360º', align: 'right', render: (_, l) => (l.avaliacao?.nota_360 ? formatarNumero(l.avaliacao.nota_360) : '—') },
    { title: 'Nota final', align: 'right', render: (_, l) => (l.avaliacao?.nota_final !== null && l.avaliacao?.nota_final !== undefined ? <strong>{formatarNumero(l.avaliacao.nota_final)}</strong> : '—') },
    { title: 'Classificação', render: (_, l) => l.avaliacao?.classificacao_360 ?? l.avaliacao?.classificacao ?? classificar(l.avaliacao?.nota_final) ?? '—' },
    { title: 'Avaliador', render: (_, l) => l.avaliacao?.avaliador ?? '—' },
    {
      title: '',
      key: 'accoes',
      render: (_, l) => {
        const a = l.avaliacao;
        return (
          <Space size={4}>
            <Button size="small" onClick={() => setAberta(l)}>{!a || a.estado === 'RASCUNHO' ? (pode('rh_avaliacao_edit') ? 'Avaliar' : 'Ver') : 'Ver'}</Button>
            {a?.estado === 'CONCLUIDA' && pode('rh_avaliacao_edit') && (
              <Button size="small" onClick={() => Modal.confirm({ title: 'Reabrir a avaliação?', okText: 'Reabrir', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'post', url: `/rh/avaliacao/avaliacoes/${a.id}/reabrir` }) })}>Reabrir</Button>
            )}
            {a && pode('rh_avaliacao_edit') && !a.conhecimento && (
              <Button size="small" danger onClick={() => Modal.confirm({ title: 'Eliminar a avaliação?', okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/avaliacao/avaliacoes/${a.id}` }) })}>Eliminar</Button>
            )}
          </Space>
        );
      },
    },
  ];

  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <PesquisaLocal aoMudar={setTermo} placeholder="Nome" />
        <Select placeholder="Fase" allowClear style={{ width: 220 }} value={fase} onChange={setFase} options={Object.entries(FASES_AVALIACAO).map(([k, f]) => ({ value: k, label: f.rotulo }))} />
      </Flex>
      <Table<Linha> rowKey={(l) => l.colaborador.id} size="small" loading={q.isFetching || colaboradores.isFetching} columns={colunas} dataSource={linhas} pagination={{ pageSize: 50 }} scroll={{ x: 'max-content' }} />
      {aberta && <FormularioAvaliacao linha={aberta} ano={ano} periodo={periodo} aoFechar={() => setAberta(null)} />}
    </Card>
  );
}

interface ValoresAvaliacao {
  criterios: { chave: string; nota?: number | null; comentario?: string }[];
  objetivos: { chave: string; atingido?: number | null; nota_qual?: number | null; comentario?: string }[];
  peso_objetivos?: number | null;
  avaliador?: string;
  data_avaliacao?: Dayjs | null;
  pontos_fortes?: string;
  pontos_melhorar?: string;
  plano_desenvolvimento?: string;
}

function FormularioAvaliacao({ linha, ano, periodo, aoFechar }: { linha: Linha; ano: number; periodo: PeriodoAvaliacao; aoFechar: () => void }) {
  const { pode } = useSessao();
  const itens = useQuery({ queryKey: ['rh', 'avaliacao', 'itens'], queryFn: () => obter<ItemAvaliacao[]>('/rh/avaliacao/itens') });
  const [form] = Form.useForm<ValoresAvaliacao>();
  const [conhecimento, setConhecimento] = useState(false);
  const [obs, setObs] = useState('');
  const accao = useAccaoRh(() => { setConhecimento(false); aoFechar(); });
  const a = linha.avaliacao;
  const editavel = pode('rh_avaliacao_edit') && (!a || (a.estado === 'RASCUNHO' && !a.conhecimento));
  const cid = linha.colaborador.id;
  const criterios = itensAplicaveis(itens.data ?? [], cid, 'CRITERIO');
  const objetivos = itensAplicaveis(itens.data ?? [], cid, 'OBJECTIVO');

  const iniciais: ValoresAvaliacao = {
    criterios: criterios.map((i) => { const x = a?.criterios?.find((c) => c.chave === i.chave); return { chave: i.chave, nota: x?.nota ?? null, comentario: x?.comentario ?? undefined }; }),
    objetivos: objetivos.map((i) => { const x = a?.objetivos?.find((o) => o.chave === i.chave); return { chave: i.chave, atingido: x?.atingido ?? null, nota_qual: x?.nota_qual ?? null, comentario: x?.comentario ?? undefined }; }),
    peso_objetivos: a?.peso_objetivos ? Number(a.peso_objetivos) : 30,
    avaliador: a?.avaliador ?? undefined,
    data_avaliacao: a?.data_avaliacao ? dayjs(a.data_avaliacao) : dayjs(),
    pontos_fortes: a?.pontos_fortes ?? undefined,
    pontos_melhorar: a?.pontos_melhorar ?? undefined,
    plano_desenvolvimento: a?.plano_desenvolvimento ?? undefined,
  };

  const enviarAvaliacao = (concluir: boolean) => form.validateFields().then((v) => accao.mutate({
    metodo: 'post',
    url: '/rh/avaliacao/avaliacoes',
    dados: { ...v, colaborador_id: cid, ano, periodo, data_avaliacao: dataApi(v.data_avaliacao ?? null) ?? null, concluir },
  })).catch(() => undefined);

  return (
    <Drawer title={`Avaliação — ${linha.colaborador.nome_completo} (${ano} · ${PERIODOS_AVALIACAO.find((p) => p.value === periodo)?.label})`} open width={820} onClose={aoFechar} destroyOnClose
      extra={<Space>
        {a?.fase === 'AGUARDA_CONHECIMENTO' && pode('rh_avaliacao_edit') && <Button onClick={() => setConhecimento(true)}>Registar conhecimento (RH)</Button>}
        {editavel && <Button loading={accao.isPending} onClick={() => void enviarAvaliacao(false)}>Gravar rascunho</Button>}
        {editavel && <Button type="primary" loading={accao.isPending} onClick={() => Modal.confirm({ title: 'Concluir a avaliação?', content: 'Exige todas as notas e resultados, o avaliador e a data. Depois de concluída não se altera (salvo reabertura).', okText: 'Concluir', cancelText: 'Cancelar', onOk: () => enviarAvaliacao(true) })}>Concluir</Button>}
      </Space>}>
      {a && (
        <Descriptions size="small" column={3} bordered style={{ marginBottom: 16 }}>
          <Descriptions.Item label="Estado"><EstadoTag estado={a.estado} /></Descriptions.Item>
          <Descriptions.Item label="Fase"><FaseTag fase={a.fase} /></Descriptions.Item>
          <Descriptions.Item label="Classificação">{a.classificacao ?? '—'}</Descriptions.Item>
          <Descriptions.Item label="Critérios">{a.pontuacao_criterios ? formatarNumero(a.pontuacao_criterios) : '—'}</Descriptions.Item>
          <Descriptions.Item label="Objectivos">{a.pontuacao_objetivos ? formatarNumero(a.pontuacao_objetivos) : '—'}</Descriptions.Item>
          <Descriptions.Item label="Pontuação">{a.pontuacao ? formatarNumero(a.pontuacao) : '—'}</Descriptions.Item>
          {a.conhecimento && <Descriptions.Item label="Conhecimento" span={3}>{formatarData(a.conhecimento.em)} {a.comentario_colaborador ? `— «${a.comentario_colaborador}»` : ''}</Descriptions.Item>}
          {a.contestacao?.fundamentacao && <Descriptions.Item label="Contestação" span={3}>{a.contestacao.fundamentacao}</Descriptions.Item>}
          {a.contestacao?.decisao && <Descriptions.Item label="Decisão" span={3}>{a.contestacao.decisao.resultado === 'ALTERADA' ? `Alterada (nota ${a.contestacao.decisao.nota})` : 'Mantida'} — {a.contestacao.decisao.justificacao}</Descriptions.Item>}
        </Descriptions>
      )}
      {itens.isLoading ? <Card loading bordered={false} /> : (
        <Form form={form} layout="vertical" disabled={!editavel} initialValues={iniciais}>
          <Typography.Title level={5}>Critérios</Typography.Title>
          {criterios.length === 0 && <Alert type="info" message="Sem critérios: na primeira utilização o servidor cria os 8 critérios padrão." />}
          <Form.List name="criterios">
            {(campos) => campos.map(({ key, name }) => {
              const i = criterios[name];
              return (
                <Row key={key} gutter={8} align="middle">
                  <Col span={9}><Typography.Text strong>{i?.nome}</Typography.Text>{i?.peso ? <Typography.Text type="secondary"> (peso {formatarNumero(i.peso)})</Typography.Text> : null}</Col>
                  <Col span={6}><Form.Item name={[name, 'nota']} style={{ marginBottom: 8 }}><Rate count={5} /></Form.Item></Col>
                  <Col span={9}><Form.Item name={[name, 'comentario']} style={{ marginBottom: 8 }}><Input placeholder="Comentário" maxLength={2000} /></Form.Item></Col>
                  <Form.Item name={[name, 'chave']} hidden><Input /></Form.Item>
                </Row>
              );
            })}
          </Form.List>
          <Divider />
          <Flex justify="space-between" align="center">
            <Typography.Title level={5} style={{ margin: 0 }}>Objectivos</Typography.Title>
            <Form.Item name="peso_objetivos" label="Peso dos objectivos (%)" style={{ marginBottom: 0 }}><InputNumber min={0} max={100} /></Form.Item>
          </Flex>
          <Form.List name="objetivos">
            {(campos) => campos.map(({ key, name }) => {
              const i = objetivos[name];
              const qual = i?.natureza === 'QUALITATIVO';
              return (
                <Row key={key} gutter={8} align="middle" style={{ marginTop: 8 }}>
                  <Col span={9}>
                    <Typography.Text strong>{i?.nome}</Typography.Text>
                    <div><Typography.Text type="secondary">{qual ? 'Qualitativo' : `Meta ${formatarNumero(i?.meta)} ${i?.unidade ?? ''} (${i?.sentido === 'MENOR' ? 'menor é melhor' : 'maior é melhor'})`}</Typography.Text></div>
                  </Col>
                  <Col span={6}>
                    {qual
                      ? <Form.Item name={[name, 'nota_qual']} style={{ marginBottom: 8 }}><Rate count={5} /></Form.Item>
                      : <Form.Item name={[name, 'atingido']} style={{ marginBottom: 8 }}><InputNumber placeholder="Atingido" style={{ width: '100%' }} /></Form.Item>}
                  </Col>
                  <Col span={9}><Form.Item name={[name, 'comentario']} style={{ marginBottom: 8 }}><Input placeholder="Comentário" maxLength={2000} /></Form.Item></Col>
                  <Form.Item name={[name, 'chave']} hidden><Input /></Form.Item>
                </Row>
              );
            })}
          </Form.List>
          <Divider />
          <Row gutter={12}>
            <Col span={12}><Form.Item name="avaliador" label="Avaliador"><Input maxLength={255} /></Form.Item></Col>
            <Col span={12}><Form.Item name="data_avaliacao" label="Data da avaliação"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Form.Item name="pontos_fortes" label="Pontos fortes"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
          <Form.Item name="pontos_melhorar" label="Pontos a melhorar"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
          <Form.Item name="plano_desenvolvimento" label="Plano de desenvolvimento"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        </Form>
      )}
      <Modal title="Registar a tomada de conhecimento pelo RH" open={conhecimento} onCancel={() => setConhecimento(false)} okText="Registar" cancelText="Cancelar" confirmLoading={accao.isPending}
        onOk={() => a && accao.mutate({ metodo: 'post', url: `/rh/avaliacao/avaliacoes/${a.id}/conhecimento`, dados: { observacao: obs || null } })}>
        <Typography.Paragraph type="secondary">Para colaboradores sem acesso ao portal (ex.: assinatura em papel).</Typography.Paragraph>
        <Input.TextArea rows={3} maxLength={1000} placeholder="Observação" value={obs} onChange={(e) => setObs(e.target.value)} />
      </Modal>
    </Drawer>
  );
}

function Itens() {
  const colaboradores = useColaboradores();
  return (
    <CadastroSimples<ItemAvaliacao>
      url="/rh/avaliacao/itens"
      chave={['rh', 'avaliacao', 'itens']}
      nomeItem="item de avaliação"
      podeGerir
      podeEliminar
      larguraModal={720}
      pesquisa={(r) => `${r.nome} ${r.descricao ?? ''}`}
      valoresNovos={{ ambito: 'COMUM', tipo: 'CRITERIO', ativo: true, natureza: 'QUANTITATIVO', sentido: 'MAIOR' }}
      paraFormulario={(r) => ({ ...r, peso: r.peso !== null ? Number(r.peso) : null, meta: r.meta !== null ? Number(r.meta) : null })}
      colunas={[
        { title: 'Nome', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
        { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => (t === 'CRITERIO' ? 'Critério' : 'Objectivo'), filters: [{ text: 'Critério', value: 'CRITERIO' }, { text: 'Objectivo', value: 'OBJECTIVO' }], onFilter: (v, r) => r.tipo === v },
        { title: 'Âmbito', render: (_, r) => (r.ambito === 'COMUM' ? 'Comum' : `Específico — ${colaboradores.nome(r.colaborador_id)}`) },
        { title: 'Peso', dataIndex: 'peso', align: 'right', render: (v: string | null) => (v !== null ? formatarNumero(v) : '—') },
        { title: 'Natureza / meta', render: (_, r) => (r.tipo === 'OBJECTIVO' ? (r.natureza === 'QUALITATIVO' ? 'Qualitativo' : `${formatarNumero(r.meta)} ${r.unidade ?? ''}`) : '') },
        { title: 'Activo', dataIndex: 'ativo', render: (v: boolean) => (v ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
      ]}
      campos={<CamposItem />}
    />
  );
}

function CamposItem() {
  const form = Form.useFormInstance();
  const ambito = Form.useWatch('ambito', form);
  const tipo = Form.useWatch('tipo', form);
  const natureza = Form.useWatch('natureza', form);
  return (
    <Row gutter={12}>
      <Col span={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={[{ value: 'CRITERIO', label: 'Critério' }, { value: 'OBJECTIVO', label: 'Objectivo' }]} /></Form.Item></Col>
      <Col span={8}><Form.Item name="ambito" label="Âmbito" rules={[{ required: true }]}><Select options={[{ value: 'COMUM', label: 'Comum a todos' }, { value: 'ESPECIFICO', label: 'Específico' }]} /></Form.Item></Col>
      <Col span={8}><Form.Item name="ativo" label=" " valuePropName="checked"><Checkbox>Activo</Checkbox></Form.Item></Col>
      {ambito === 'ESPECIFICO' && <Col span={24}><Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true }]}><SeletorColaborador style={{ width: '100%' }} /></Form.Item></Col>}
      <Col span={16}><Form.Item name="nome" label="Nome" rules={[{ required: true }, { max: 255 }]}><Input /></Form.Item></Col>
      <Col span={4}><Form.Item name="peso" label="Peso"><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item></Col>
      <Col span={4}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
      <Col span={24}><Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={2000} /></Form.Item></Col>
      {tipo === 'OBJECTIVO' && (
        <>
          <Col span={8}><Form.Item name="natureza" label="Natureza"><Select options={[{ value: 'QUANTITATIVO', label: 'Quantitativo' }, { value: 'QUALITATIVO', label: 'Qualitativo' }]} /></Form.Item></Col>
          {natureza !== 'QUALITATIVO' && (
            <>
              <Col span={6}><Form.Item name="meta" label="Meta"><InputNumber min={0.0001} style={{ width: '100%' }} /></Form.Item></Col>
              <Col span={4}><Form.Item name="unidade" label="Unidade"><Input maxLength={50} /></Form.Item></Col>
              <Col span={6}><Form.Item name="sentido" label="Sentido"><Select options={[{ value: 'MAIOR', label: 'Maior é melhor' }, { value: 'MENOR', label: 'Menor é melhor' }]} /></Form.Item></Col>
            </>
          )}
        </>
      )}
    </Row>
  );
}
