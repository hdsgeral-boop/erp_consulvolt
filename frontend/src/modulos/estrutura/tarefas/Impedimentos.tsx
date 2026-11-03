import { Alert, Button, DatePicker, Drawer, Form, Input, Space, Table, Typography } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraGaveta, scrollTabela } from '@/componentes/responsivo';
import { useColaboradores } from '@/modulos/rh/comum/consultas';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { CHAVE_IMPEDIMENTOS, decidirImpedimento, impedimentosDoEntregavel, registarImpedimento } from './api';
import { LigacaoAnexo, ModalMotivoCurto, TagImpedimento, useMutacao } from './comum';
import { normalizarUrl } from './regras';
import type { DadosImpedimento, Entregavel, Impedimento } from './tipos';

interface CamposImpedimento {
  periodo: Dayjs;
  prazo?: Dayjs | null;
  motivo: string;
  url_documento?: string;
}

/**
 * Formulário «Não há condições de executar» (registarImpedimento, §3.5): período servido, prazo pedido, motivo e documento.
 * Partilhado pela estrutura e pelo portal (só muda a função que grava).
 */
export function FormularioImpedimento({ gravar, aGravar, form }: { gravar: (d: DadosImpedimento) => void; aGravar: boolean; form: ReturnType<typeof Form.useForm<CamposImpedimento>>[0] }) {
  return (
    <Form form={form} layout="vertical" initialValues={{ periodo: dayjs().startOf('month') }}
      onFinish={(v) => gravar({ periodo_de: dataApi(v.periodo.startOf('month'))!, prazo: dataApi(v.prazo ?? null) ?? null, motivo: v.motivo.trim(), url_documento: normalizarUrl(v.url_documento) })}>
      <Space wrap align="start">
        <Form.Item name="periodo" label="Período a que respeita" rules={[{ required: true, message: 'Indique o período.' }]}><DatePicker picker="month" format="MM/YYYY" /></Form.Item>
        <Form.Item name="prazo" label="Até quando (opcional)"><DatePicker format="DD/MM/YYYY" /></Form.Item>
      </Space>
      <Form.Item name="motivo" label="Porque é que não há condições de executar" rules={[{ required: true, message: 'Indique o motivo.' }, { min: 3, message: 'Pelo menos 3 caracteres.' }]}>
        <Input.TextArea rows={2} maxLength={2000} />
      </Form.Item>
      <Form.Item name="url_documento" label="Documento de prova (endereço)"><Input maxLength={500} placeholder="https://…" /></Form.Item>
      <Button type="primary" htmlType="submit" loading={aGravar} icon={<IconeFa nome="exclamation-triangle" />}>Registar impedimento</Button>
    </Form>
  );
}

/** Colunas da lista de impedimentos (estrutura e portal). */
export function colunasImpedimentos(nome: (i: Impedimento) => string, decidir?: (i: Impedimento, estado: 'ACEITE' | 'RECUSADO') => void): ColumnsType<Impedimento> {
  return [
    { title: 'Período', dataIndex: 'periodo_de', render: (v: string) => <span style={{ whiteSpace: 'nowrap' }}>{dayjs(v).format('MM/YYYY')}</span> },
    { title: 'Quem', key: 'quem', render: (_, i) => nome(i) },
    { title: 'Motivo', dataIndex: 'motivo', render: (v: string, i) => <>{v}{i.prazo && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>pede até {formatarData(i.prazo)}</div>}</> },
    { title: 'Prova', key: 'prova', render: (_, i) => <LigacaoAnexo url={i.url_documento} rotulo="Abrir documento" semAnexo="sem documento" /> },
    {
      title: 'Estado', key: 'estado',
      render: (_, i) => (
        <>
          <TagImpedimento estado={i.estado} />
          {i.decidido_por && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{i.decidido_por} · {formatarData(i.decidido_em)}</div>}
          {i.decisao_nota && <div style={{ fontSize: '0.74rem', color: '#64748b' }}>{i.decisao_nota}</div>}
        </>
      ),
    },
    ...(decidir ? [{
      title: '', key: 'a', align: 'right' as const,
      render: (_: unknown, i: Impedimento) => i.estado === 'ABERTO' && i.pode_decidir !== false && (
        <Space size={4}>
          <Button size="small" style={{ color: '#15803d' }} icon={<IconeFa nome="check" />} title="Aceitar: o período fica justificado e sai dos atrasos" onClick={() => decidir(i, 'ACEITE')}>Aceitar</Button>
          <Button size="small" danger icon={<IconeFa nome="ban" />} onClick={() => decidir(i, 'RECUSADO')}>Recusar</Button>
        </Space>
      ),
    }] : []),
  ];
}

/** Impedimentos de um entregável (gaveta): lista, decidir e registar um novo. */
export function Impedimentos({ entregavel, aoFechar }: { entregavel: Entregavel | null; aoFechar: () => void }) {
  const colaboradores = useColaboradores();
  const q = useQuery({ queryKey: [...CHAVE_IMPEDIMENTOS, entregavel?.id], queryFn: () => impedimentosDoEntregavel(entregavel!.id), enabled: !!entregavel, retry: false });
  const [form] = Form.useForm<CamposImpedimento>();
  const [recusar, setRecusar] = useState<Impedimento | null>(null);
  const registar = useMutacao((d: DadosImpedimento) => registarImpedimento(entregavel!.id, d), { aoSucesso: () => form.resetFields() });
  const decidir = useMutacao((p: { id: number; estado: 'ACEITE' | 'RECUSADO'; nota?: string }) => decidirImpedimento(p.id, { estado: p.estado, nota: p.nota ?? null }), { aoSucesso: () => setRecusar(null) });
  if (!entregavel) return null;
  const lista = Array.isArray(q.data) ? q.data : [];
  return (
    <Drawer open title={`Impedimentos — ${entregavel.nome}`} onClose={aoFechar} width={larguraGaveta(880)} destroyOnHidden>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Um impedimento aceite justifica o período: o entregável sai dos atrasos nesse período."
        description="Regista quem executa ou o responsável da unidade; decide o responsável da cadeia, nunca o próprio autor. Só um impedimento por período enquanto não for recusado." />
      <Table<Impedimento> rowKey="id" size="small" loading={q.isFetching} dataSource={lista} pagination={false} scroll={scrollTabela()}
        locale={{ emptyText: 'Ninguém reportou falta de condições para executar.' }}
        columns={colunasImpedimentos((i) => i.colaborador_nome ?? colaboradores.nome(i.colaborador_id), (i, estado) => (estado === 'ACEITE' ? decidir.mutate({ id: i.id, estado }) : setRecusar(i)))} />
      <Typography.Title level={5} style={{ marginTop: 16 }}>Não há condições de executar</Typography.Title>
      <FormularioImpedimento form={form} gravar={(d) => registar.mutate(d)} aGravar={registar.isPending} />
      <ModalMotivoCurto aberto={recusar !== null} titulo="Recusar o impedimento" rotulo="Nota da decisão" textoOk="Recusar" perigo carregando={decidir.isPending}
        mensagem="Porque é que o impedimento não é aceite? O período continua por cumprir." aoFechar={() => setRecusar(null)}
        aoConfirmar={(nota) => recusar && decidir.mutate({ id: recusar.id, estado: 'RECUSADO', nota })} />
    </Drawer>
  );
}
