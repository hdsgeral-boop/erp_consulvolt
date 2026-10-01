import { Button, Card, DatePicker, Flex, InputNumber, Select } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado } from '../comum/estados';
import { NomeTerceiro } from '../comum/referencias';
import { TabelaServidor } from '../comum/Tabelas';
import { numeroOuId, type PropostaCompra } from '../comum/tipos';
import { ValorMoeda } from '../comum/Valores';

export function ListaPropostas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [pedido, setPedido] = useState<number | null>(null);
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);

  const colunas: ColumnsType<PropostaCompra> = [
    { title: 'Proposta', key: 'numero', fixed: 'left', render: (_, r) => <strong>{numeroOuId(r.numero_proposta, r.id)}</strong> },
    { title: 'Referência', dataIndex: 'referencia' },
    { title: 'Pedido', dataIndex: 'pedido_compra_id', render: (v: number) => `#${v}` },
    { title: 'Fornecedor', key: 'fornecedor', render: (_, r) => <NomeTerceiro id={r.fornecedor_id} /> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Entrega', dataIndex: 'data_entrega', render: formatarData },
    { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, r) => <ValorMoeda kz={r.montante_total} moeda={r.codigo_moeda} valorMoeda={r.montante_total_moeda} /> },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Prospecção e adjudicação"
        subtitulo="Propostas de fornecedores para os pedidos aprovados"
        accoes={
          pode('compras_new_proposal') && (
            <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
              Registar proposta
            </Button>
          )
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Select
            placeholder="Estado"
            allowClear
            style={{ width: 230 }}
            value={estado}
            onChange={setEstado}
            options={opcoesEstado(['PROPOSTA', 'PROPOSTA_ADJUDICACAO', 'ADJUDICADO', 'RECUSADA', 'ANULADA'])}
          />
          <InputNumber placeholder="N.º interno do pedido" min={1} style={{ width: 200 }} value={pedido} onChange={(v) => setPedido(v)} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          {pedido && <Button onClick={() => navegar(`comparacao/${pedido}`)}>Quadro comparativo do pedido</Button>}
        </Flex>
        <TabelaServidor<PropostaCompra>
          url="/compras/propostas"
          chaveConsulta={['compras', 'propostas']}
          filtros={{ estado, pedido_compra_id: pedido ?? undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}
