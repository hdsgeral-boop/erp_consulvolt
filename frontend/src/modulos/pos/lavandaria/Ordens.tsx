import { Button, Flex, Input, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { useAccao } from '@/modulos/compras/comum/accoes';
import { EstadoPOS } from '../comum/estados';
import { SeletorTerminal } from '../comum/Filtros';
import type { Terminal } from '../comum/tipos';
import { DetalheOrdem } from './DetalheOrdem';
import type { ListaOrdens, OrdemResumo } from './tipos';

/** Filtros especiais do servidor (ServicoOrdensLavandaria::listar) e os contadores correspondentes. */
const FILTROS: { valor: string; rotulo: string; contador?: string }[] = [
  { valor: 'ACTIVAS', rotulo: 'Activas' },
  { valor: 'ORCAMENTO', rotulo: 'Orçamentos', contador: 'orcamentos' },
  { valor: 'SEM_RESPONSAVEL', rotulo: 'Sem responsável', contador: 'sem_responsavel' },
  { valor: 'EM_EXECUCAO', rotulo: 'Em execução', contador: 'em_execucao' },
  { valor: 'PRONTA', rotulo: 'Prontas', contador: 'prontas' },
  { valor: 'ATRASADAS', rotulo: 'Atrasadas', contador: 'atrasadas' },
  { valor: 'NAO_LEVANTADAS', rotulo: 'Não levantadas', contador: 'nao_levantadas' },
  { valor: 'ENTREGUE', rotulo: 'Entregues' },
  { valor: 'ANULADA', rotulo: 'Anuladas' },
  { valor: 'TODAS', rotulo: 'Todas' },
];

/** Ordens de serviço da lavandaria: contadores, filtros, alteração de estado em lote e detalhe da ordem. */
export function Ordens({ terminal }: { terminal: Terminal | undefined }) {
  const { pode } = useSessao();
  const [estado, setEstado] = useState('ACTIVAS');
  const [texto, setTexto] = useState('');
  const [terminalFiltro, setTerminalFiltro] = useState<number>();
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [aberta, setAberta] = useState<number | null>(null);
  const filtros = { estado, texto: texto.trim() || undefined, terminal_pos_id: terminalFiltro };
  const consulta = useQuery({ queryKey: ['pos', 'lavandaria', 'ordens', filtros], queryFn: () => obter<ListaOrdens>('/pos/lavandaria/ordens', filtros) });
  useEffect(() => {
    if (consulta.error) notificarErro(consulta.error, 'Erro ao carregar as ordens');
  }, [consulta.error]);
  const lote = useAccao({ invalidar: [['pos']], aoSucesso: () => setSeleccao([]) });
  const contadores = consulta.data?.contadores ?? {};

  return (
    <>
      <Flex gap={6} wrap style={{ marginBottom: 12 }}>
        {FILTROS.map((f) => (
          <Tag.CheckableTag key={f.valor} checked={estado === f.valor} onChange={() => setEstado(f.valor)} style={{ padding: '4px 10px', fontSize: 13 }}>
            {f.rotulo}
            {f.contador && contadores[f.contador] !== undefined ? ` (${contadores[f.contador]})` : ''}
          </Tag.CheckableTag>
        ))}
        {contadores.recebidas_hoje !== undefined && <Typography.Text type="secondary">· {contadores.recebidas_hoje} recebida(s) hoje</Typography.Text>}
      </Flex>
      <Flex gap={8} wrap style={{ marginBottom: 12 }}>
        <Input.Search allowClear placeholder="N.º da ordem, etiqueta, cliente ou telefone" style={{ width: 320 }} onSearch={setTexto} />
        <SeletorTerminal tipo="LAVANDARIA" value={terminalFiltro} onChange={setTerminalFiltro} />
        {pode('lav_ordens') && seleccao.length > 0 && (
          <Space>
            <Button loading={lote.isPending} onClick={() => lote.mutate({ url: '/pos/lavandaria/ordens/estado', dados: { ids: seleccao, estado: 'EM_EXECUCAO' } })}>
              Iniciar execução ({seleccao.length})
            </Button>
            <Button loading={lote.isPending} onClick={() => lote.mutate({ url: '/pos/lavandaria/ordens/estado', dados: { ids: seleccao, estado: 'PRONTA' } })}>
              Marcar prontas ({seleccao.length})
            </Button>
          </Space>
        )}
      </Flex>
      <Table<OrdemResumo>
        rowKey="id"
        size="middle"
        loading={consulta.isFetching}
        dataSource={consulta.data?.ordens}
        scroll={{ x: 'max-content' }}
        pagination={{ defaultPageSize: 25, showSizeChanger: true, showTotal: (t) => `${t} ordem(ns)` }}
        rowSelection={pode('lav_ordens') ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as number[]) } : undefined}
        onRow={(o) => ({ onDoubleClick: () => setAberta(o.id) })}
        columns={[
          {
            title: 'Ordem',
            dataIndex: 'numero_encomenda',
            render: (v, o) => (
              <Button type="link" size="small" style={{ padding: 0 }} onClick={() => setAberta(o.id)}>
                {v}
              </Button>
            ),
          },
          { title: 'Cliente', render: (_, o) => o.cliente?.nome ?? `#${o.cliente_id}` },
          { title: 'Recebida', dataIndex: 'recebido_em', render: (v) => formatarDataHora(v) },
          { title: 'Prometida', dataIndex: 'data_prometida', render: (v, o) => <>{formatarDataHora(v)} {o.urgente && <Tag color="red">Urgente</Tag>}</> },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EstadoPOS estado={v} /> },
          { title: 'Responsável', dataIndex: 'nome_atribuido', render: (v) => v ?? '—' },
          { title: 'Situação', render: (_, o) => <Typography.Text type={(o.indicadores.atraso ?? 0) > 0 ? 'danger' : undefined}>{o.indicadores.situacao ?? '—'}</Typography.Text> },
          { title: 'Total', dataIndex: 'total', align: 'right', render: (v) => formatarKz(v) },
          { title: 'Saldo', dataIndex: 'saldo', align: 'right', render: (v) => <Typography.Text strong={Number(v) > 0}>{formatarKz(v)}</Typography.Text> },
        ]}
      />
      <DetalheOrdem id={aberta} terminal={terminal} aoFechar={() => setAberta(null)} />
    </>
  );
}
