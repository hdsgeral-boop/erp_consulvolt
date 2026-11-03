import { Alert, Button, Card, Col, DatePicker, Form, InputNumber, Popconfirm, Row, Select, Space, Statistic, Table, Tag, Tooltip, Typography, message } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { scrollTabela } from '@/componentes/responsivo';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import type { ConfiguracaoRH } from '@/modulos/rh/api';
import { useAccaoRh, useAvisarErro, useInfotipos } from '@/modulos/rh/comum/consultas';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { aplicarMulta, CHAVE_MULTAS, dispensarMulta, listarMultas, proporMultas, reporMulta } from '../faseK/api';
import { accoesMulta, ESTADO_MULTA, nomeMulta, textoProposta, totaisMultas, validarTectoMulta } from '../faseK/regras';
import type { EstadoMulta, MultaEntregavel, ResultadoProposta } from '../faseK/tipos';
import { ModalMotivoCurto, useMutacao } from '../tarefas/comum';

const INVALIDAR = [CHAVE_MULTAS, ['rh', 'estrutura', 'atrasos'], ['rh', 'estrutura', 'tarefas'], ['rh', 'portal', 'aprovacoes']];

/**
 * Estrutura orgânica › Multas de entregáveis (K3, `est_multas_gerir`): as multas nascem PROPOSTAS dos atrasos com multa
 * configurada e só descontam depois de aplicadas por uma pessoa (nunca pelo penalizado). Dispensar exige motivo. O
 * lançamento na folha faz-se em RH › Cálculo › «Importar Multas».
 */
export default function Multas() {
  const { pode } = useSessao();
  const gerir = pode('est_multas_gerir');
  const [estado, setEstado] = useState<EstadoMulta | undefined>();
  const [colaborador, setColaborador] = useState<number | undefined>();
  const [periodo, setPeriodo] = useState<[string, string] | null>(null);
  const filtro = { estado, colaborador_id: colaborador, de: periodo?.[0], ate: periodo?.[1] };
  const q = useQuery({ queryKey: [...CHAVE_MULTAS, filtro], queryFn: () => listarMultas(filtro), retry: false });
  useAvisarErro(q.error, 'Não foi possível carregar as multas');
  const [dispensar, setDispensar] = useState<MultaEntregavel | null>(null);
  const [avisos, setAvisos] = useState<string[]>([]);
  const propor = useMutacao((_: void) => proporMultas(), { invalidar: INVALIDAR, silenciosa: true, aoSucesso: (r: ResultadoProposta) => { message.success(textoProposta(r)); setAvisos(r?.avisos ?? []); } });
  const aplicar = useMutacao((id: number) => aplicarMulta(id), { invalidar: INVALIDAR });
  const repor = useMutacao((id: number) => reporMulta(id), { invalidar: INVALIDAR });
  const dispensa = useMutacao((p: { id: number; motivo: string }) => dispensarMulta(p.id, p.motivo), { invalidar: INVALIDAR, aoSucesso: () => setDispensar(null) });
  const lista = Array.isArray(q.data) ? q.data : [];
  const totais = totaisMultas(lista);

  const colunas: ColumnsType<MultaEntregavel> = [
    { title: 'Entregável', key: 'e', render: (_, m) => <><b>{nomeMulta.entregavel(m)}</b><div style={{ fontSize: '0.74rem', color: '#64748b' }}>{nomeMulta.unidade(m)}</div></> },
    { title: 'Penalizado', key: 'c', render: (_, m) => nomeMulta.colaborador(m) },
    { title: 'Período', key: 'p', responsive: ['md'], render: (_, m) => <span style={{ whiteSpace: 'nowrap' }}>{formatarData(m.periodo_de)} a {formatarData(m.periodo_ate)}</span> },
    { title: 'Atraso', key: 'a', responsive: ['md'], render: (_, m) => `${m.dias_atraso} dia(s) após ${formatarData(m.limite)}` },
    { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <b>{formatarKz(v, true)}</b> },
    {
      title: 'Estado', key: 's',
      render: (_, m) => (
        <>
          <Tooltip title={ESTADO_MULTA[m.estado]?.dica}><Tag color={ESTADO_MULTA[m.estado]?.cor}>{ESTADO_MULTA[m.estado]?.rotulo ?? m.estado}</Tag></Tooltip>
          {m.decidido_por && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{m.decidido_por}{m.decidido_em ? ` · ${formatarData(m.decidido_em)}` : ''}</div>}
          {m.motivo_decisao && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{m.motivo_decisao}</div>}
        </>
      ),
    },
    {
      title: '', key: 'x', align: 'right',
      render: (_, m) => {
        const a = accoesMulta(m, null);
        if (!gerir) return null;
        return (
          <Space size={2} wrap>
            {a.aplicar && (
              <Popconfirm title="Aplicar esta multa?" description="Passa a ser descontada na folha quando for lançada." okText="Aplicar" cancelText="Cancelar" onConfirm={() => aplicar.mutateAsync(m.id)}>
                <Button size="small" type="text" style={{ color: '#b91c1c' }} aria-label={`Aplicar multa ${m.id}`} title="Aplicar a multa" icon={<IconeFa nome="gavel" />} />
              </Popconfirm>
            )}
            {a.dispensar && <Button size="small" type="text" aria-label={`Dispensar multa ${m.id}`} title="Dispensar, com motivo" icon={<IconeFa nome="ban" />} onClick={() => setDispensar(m)} />}
            {a.repor && <Button size="small" type="text" aria-label={`Repor multa ${m.id}`} title="Voltar a proposta" icon={<IconeFa nome="undo" />} onClick={() => repor.mutate(m.id)} />}
          </Space>
        );
      },
    },
  ];

  return (
    <>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Descontar uma multa do salário exige decisão de uma pessoa"
        description="As multas só são descontadas depois de aplicadas, nunca pelo próprio penalizado, e podem ter um tecto em % do salário base (configuração abaixo). Descontos por penalização pecuniária podem estar limitados pela Lei Geral do Trabalho: valide juridicamente antes de os activar." />
      <Row gutter={[12, 12]} style={{ marginBottom: 12 }}>
        {(['PROPOSTA', 'APLICADA', 'DISPENSADA', 'DESCONTADA'] as EstadoMulta[]).map((e) => (
          <Col key={e} xs={12} md={6}><Card size="small"><Statistic title={`${ESTADO_MULTA[e].rotulo} (${totais[e].n})`} value={formatarKz(totais[e].valor, true)} styles={{ content: { fontSize: '1.1rem' } }} /></Card></Col>
        ))}
      </Row>
      <Card size="small" style={{ marginBottom: 12 }}>
        <Space wrap>
          <Select<EstadoMulta> allowClear placeholder="Estado" style={{ width: 160 }} value={estado} onChange={setEstado} options={(Object.keys(ESTADO_MULTA) as EstadoMulta[]).map((e) => ({ value: e, label: ESTADO_MULTA[e].rotulo }))} />
          <SeletorColaborador placeholder="Penalizado" style={{ width: 240 }} value={colaborador} onChange={setColaborador} />
          <DatePicker.RangePicker format="DD/MM/YYYY" onChange={(v) => setPeriodo(v && v[0] && v[1] ? [dataApi(v[0])!, dataApi(v[1])!] : null)} />
          {gerir && (
            <Popconfirm title="Propor as multas dos atrasos?" description="Cria propostas (nada é descontado) para os entregáveis em atraso com multa configurada." okText="Propor" cancelText="Cancelar"
              onConfirm={() => propor.mutateAsync()}>
              <Button type="primary" loading={propor.isPending} icon={<IconeFa nome="calculator" />}>Propor do período</Button>
            </Popconfirm>
          )}
        </Space>
      </Card>
      {avisos.length > 0 && (
        <Alert type="info" showIcon closable style={{ marginBottom: 12 }} onClose={() => setAvisos([])} title="Sem penalizado — não foram propostas"
          description={<ul style={{ margin: 0, paddingLeft: 18 }}>{avisos.map((a, i) => <li key={i}>{a}</li>)}</ul>} />
      )}
      <Table<MultaEntregavel> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} columns={colunas} pagination={lista.length > 50 ? { pageSize: 50 } : false} scroll={scrollTabela()}
        locale={{ emptyText: 'Ainda não há multas.' }} />
      {gerir && <ConfiguracaoMultas />}
      <ModalMotivoCurto aberto={dispensar !== null} titulo="Dispensar a multa" mensagem="Porque é que esta multa não deve ser aplicada? Fica registado." rotulo="Motivo" textoOk="Dispensar"
        carregando={dispensa.isPending} aoFechar={() => setDispensar(null)} aoConfirmar={(m) => dispensar && dispensa.mutate({ id: dispensar.id, motivo: m })} />
    </>
  );
}

/** Rubrica de desconto e tecto das multas na folha (configuração de RH: infotipo_multa_entregavel_id e multas_entregavel_max_pct). */
function ConfiguracaoMultas() {
  const q = useQuery({ queryKey: ['rh', 'configuracao'], queryFn: () => obter<ConfiguracaoRH>('/rh/configuracao') });
  useAvisarErro(q.error);
  const infotipos = useInfotipos();
  const accao = useAccaoRh();
  const [form] = Form.useForm<{ infotipo_multa_entregavel_id: number | null; multas_entregavel_max_pct: number | null }>();
  useEffect(() => {
    if (q.data) form.setFieldsValue({ infotipo_multa_entregavel_id: q.data.infotipo_multa_entregavel_id ?? null, multas_entregavel_max_pct: Number(q.data.multas_entregavel_max_pct ?? 0) });
  }, [q.data, form]);
  return (
    <Card size="small" title="Multas na folha: rubrica e tecto" style={{ marginTop: 16 }} loading={q.isLoading}>
      <Form form={form} layout="vertical" style={{ maxWidth: 720 }}
        onFinish={(v) => accao.mutate({ metodo: 'put', url: '/rh/configuracao', dados: { infotipo_multa_entregavel_id: v.infotipo_multa_entregavel_id ?? null, multas_entregavel_max_pct: v.multas_entregavel_max_pct ?? 0 } })}>
        <Row gutter={16}>
          <Col xs={24} md={14}>
            <Form.Item name="infotipo_multa_entregavel_id" label="Rubrica de desconto" extra="Tem de ser do tipo DESCONTO. Sem rubrica não se lançam multas na folha.">
              <Select allowClear showSearch optionFilterProp="label" options={infotipos.lista.filter((i) => i.tipo === 'DESCONTO').map((i) => ({ value: i.id, label: i.nome }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={10}>
            <Form.Item name="multas_entregavel_max_pct" label="Tecto (% do salário base)" extra="0 = sem tecto. O excedente fica aplicado mas não é descontado."
              rules={[{ validator: (_, v: number | null) => { const e = validarTectoMulta(v); return e ? Promise.reject(new Error(e)) : Promise.resolve(); } }]}>
              <InputNumber min={0} max={100} style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        <Button type="primary" htmlType="submit" loading={accao.isPending}>Gravar</Button>
        <Typography.Text type="secondary" style={{ marginLeft: 12 }}>O lançamento na folha faz-se em RH › Cálculo › Importar Multas.</Typography.Text>
      </Form>
    </Card>
  );
}
