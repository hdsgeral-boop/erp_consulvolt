import { Button, Card, DatePicker, InputNumber, Select } from 'antd';
import { PlusOutlined } from '@ant-design/icons';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado, rotuloEstado } from '../comum/estados';
import { NomeTerceiro } from '../comum/referencias';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type PropostaCompra } from '../comum/tipos';
import { ValorMoeda } from '../comum/Valores';

export function ListaPropostas() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [pedido, setPedido] = useState<number | null>(null);
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const pequeno = useEcraPequeno();

  const colunas: ColunaApi<PropostaCompra>[] = [
    { title: 'Proposta', key: 'numero', fixed: 'left', render: (_, r) => <strong>{numeroOuId(r.numero_proposta, r.id)}</strong> },
    { title: 'Referência', dataIndex: 'referencia', responsive: ['md'] },
    { title: 'Pedido', dataIndex: 'pedido_compra_id', responsive: ['lg'], render: (v: number) => `#${v}` },
    { title: 'Fornecedor', key: 'fornecedor', valorImpressao: (r) => r.fornecedor?.nome?.trim() ?? `#${r.fornecedor_id}`, render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    { title: 'Data', dataIndex: 'data', responsive: ['sm'], render: formatarData },
    { title: 'Entrega', dataIndex: 'data_entrega', responsive: ['lg'], render: formatarData },
    { title: 'Total (Kz)', key: 'total', align: 'right', valorImpressao: (r) => formatarKz(r.montante_total), render: (_, r) => <ValorMoeda kz={r.montante_total} moeda={r.codigo_moeda} valorMoeda={r.montante_total_moeda} /> },
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
        <BarraFiltros>
          <Select
            placeholder="Estado"
            allowClear
            style={{ width: 230, maxWidth: '100%' }}
            value={estado}
            onChange={setEstado}
            options={opcoesEstado(['PROPOSTA', 'PROPOSTA_ADJUDICACAO', 'ADJUDICADO', 'RECUSADA', 'ANULADA'])}
          />
          <InputNumber placeholder="N.º interno do pedido" min={1} style={{ width: 200, maxWidth: '100%' }} value={pedido} onChange={(v) => setPedido(v)} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          {pedido && <Button onClick={() => navegar(`comparacao/${pedido}`)}>Quadro comparativo do pedido</Button>}
        </BarraFiltros>
        <TabelaApi<PropostaCompra>
          url="/compras/propostas"
          chaveConsulta={['compras', 'propostas']}
          filtros={{ estado, pedido_compra_id: pedido ?? undefined, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          impressao={{
            titulo: 'Lista de propostas de fornecedores',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: [estado && `Estado: ${rotuloEstado(estado)}`, !!pedido && `Pedido: #${pedido}`],
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}
