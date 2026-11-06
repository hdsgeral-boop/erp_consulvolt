import { Alert, Button, Form, Input, Modal, Select, Skeleton, Space, Table, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { entregarValor, obterSerie, type Indicador, type LinhaSerie, type Plano } from './api';
import { PastilhaSemaforo, formatarValorIndicador } from './comum';

/**
 * Série de um indicador num ano do ciclo: meta, valor, cumprimento e semáforo por período (decimal, exacto). Nos indicadores de
 * recolha manual, quem tem est_entregar entrega o valor de cada período já iniciado (entregar de novo substitui; não há apagar).
 */
export function ModalSerie({ indicador, plano, podeEntregar, aoFechar }: { indicador: Indicador; plano: Plano; podeEntregar: boolean; aoFechar: () => void }) {
  const qc = useQueryClient();
  const anoActual = new Date().getFullYear();
  const [ano, setAno] = useState(Math.min(Math.max(anoActual, plano.ano_inicio), plano.ano_fim));
  const [entrega, setEntrega] = useState<LinhaSerie | null>(null);
  const [form] = Form.useForm<{ valor: string; nota: string }>();
  const { data, isLoading } = useQuery({ queryKey: ['estrategia', 'serie', indicador.id, ano], queryFn: () => obterSerie(indicador.id, ano) });
  const anos = Array.from({ length: plano.ano_fim - plano.ano_inicio + 1 }, (_, k) => plano.ano_inicio + k);
  const manual = indicador.origem === 'MANUAL';
  const permiteEntregar = manual && podeEntregar && indicador.ativo && plano.estado !== 'ENCERRADO';

  const gravar = useMutation({
    mutationFn: (v: { periodo: string; valor: string; nota: string }) => entregarValor(indicador.id, { periodo: v.periodo, valor: v.valor.trim().replace(',', '.'), nota: v.nota }),
    onSuccess: () => { message.success('Valor entregue.'); setEntrega(null); void qc.invalidateQueries({ queryKey: ['estrategia'] }); },
    onError: (e) => notificarErro(e),
  });
  const f = (v: string | null, oculto: boolean) => formatarValorIndicador(v, indicador.formato, oculto);

  return (
    <Modal open title={`Série · ${indicador.nome}`} width={larguraModal(860)} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} destroyOnHidden>
      <Space wrap style={{ marginBottom: 12 }}>
        <label>Ano <Select value={ano} onChange={setAno} aria-label="Ano" options={anos.map((a) => ({ value: a, label: a }))} style={{ width: 110 }} /></label>
        {data && <Typography.Text type="secondary">{String(data.resumo.VERDE)} cumprida(s) · {String(data.resumo.AMARELO)} em risco · {String(data.resumo.VERMELHO)} não cumprida(s){data.resumo.cumpridos_pct !== null ? ` · ${data.resumo.cumpridos_pct} % cumpridas` : ''}</Typography.Text>}
      </Space>
      {data?.oculto && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Os valores são financeiros e estão reservados; o semáforo mostra-se na mesma." />}
      {isLoading || !data ? <Skeleton active /> : (
        <Table<LinhaSerie> size="small" rowKey="periodo" pagination={false} dataSource={data.periodos} scroll={scrollTabela()}
          columns={[
            { title: 'Período', dataIndex: 'rotulo' },
            { title: 'Meta', dataIndex: 'meta', align: 'right', render: (v, l) => <span className="est-valor">{f(v, l.oculto)}</span> },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v, l) => <span className="est-valor">{f(v, l.oculto)}</span> },
            { title: 'Cumprimento', dataIndex: 'cumprimento', align: 'right', render: (v: string | null, l) => <span className="est-valor">{l.oculto ? 'Reservado' : v === null ? '—' : `${Number(v).toLocaleString('pt-AO', { maximumFractionDigits: 2 })} %`}</span> },
            { title: 'Semáforo', dataIndex: 'semaforo', render: (s) => <PastilhaSemaforo semaforo={s} /> },
            { title: 'Entregue por', dataIndex: 'medido_por', render: (v: string | null, l) => (v ? `${v}${l.nota ? ` — ${l.nota}` : ''}` : '—'), className: 'erp-quebra' },
            ...(permiteEntregar ? [{ title: '', key: 'acc', render: (_: unknown, l: LinhaSerie) => <Button size="small" onClick={() => { form.setFieldsValue({ valor: '', nota: '' }); setEntrega(l); }}>Entregar</Button> }] : []),
          ]} />
      )}
      <Modal open={!!entrega} title={`Entregar valor · ${entrega?.rotulo ?? ''}`} okText="Entregar" cancelText="Cancelar" width={larguraModal(420)} confirmLoading={gravar.isPending} onCancel={() => setEntrega(null)} destroyOnHidden
        onOk={() => form.validateFields().then((v) => gravar.mutate({ periodo: entrega!.periodo, valor: v.valor, nota: v.nota ?? '' })).catch(() => undefined)}>
        <Form form={form} layout="vertical">
          <Form.Item name="valor" label="Valor medido" rules={[{ required: true, whitespace: true, message: 'Indique o valor medido.' }, { pattern: /^-?\d+([.,]\d+)?$/, message: 'O valor tem de ser um número.' }]}><Input inputMode="decimal" /></Form.Item>
          <Form.Item name="nota" label="Nota"><Input maxLength={300} /></Form.Item>
        </Form>
      </Modal>
    </Modal>
  );
}
