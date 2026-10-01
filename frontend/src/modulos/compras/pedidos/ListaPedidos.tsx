import { Button, Card, DatePicker, Flex, Select } from 'antd';
import { PlusOutlined, SettingOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { EstadoTag, opcoesEstado } from '../comum/estados';
import { etapaPendente } from '../comum/regras';
import { TabelaServidor } from '../comum/Tabelas';
import { numeroOuId, type PedidoCompra } from '../comum/tipos';
import { ModalEscaloes } from './ModalEscaloes';

export function ListaPedidos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [estado, setEstado] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [escaloes, setEscaloes] = useState(false);

  const colunas: ColumnsType<PedidoCompra> = [
    { title: 'Pedido', key: 'numero', fixed: 'left', render: (_, r) => <strong>{numeroOuId(r.numero_pedido, r.id)}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Requerente', dataIndex: 'nome_requerente' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, render: (v) => v || '—' },
    { title: 'Entrega pretendida', dataIndex: 'data_entrega', render: formatarData },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
    { title: 'Etapa pendente', key: 'etapa', render: (_, r) => (r.estado === 'PENDENTE' ? etapaPendente(r)?.nome ?? '—' : '—') },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Pedidos internos"
        subtitulo="Requisições de compra e deliberação por escalões de valor"
        accoes={
          <>
            {pode('compras_pedidos_view', 'compras_deliberacao_config') && (
              <Button icon={<SettingOutlined />} onClick={() => setEscaloes(true)}>
                Escalões de aprovação
              </Button>
            )}
            {pode('compras_ped_criar') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
                Novo pedido
              </Button>
            )}
          </>
        }
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Select
            placeholder="Estado"
            allowClear
            style={{ width: 200 }}
            value={estado}
            onChange={setEstado}
            options={opcoesEstado(['PENDENTE', 'APROVADO', 'REJEITADO', 'ADJUDICADO', 'ANULADO'])}
          />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
        </Flex>
        <TabelaServidor<PedidoCompra>
          url="/compras/pedidos"
          chaveConsulta={['compras', 'pedidos']}
          filtros={{ estado, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalEscaloes aberto={escaloes} aoFechar={() => setEscaloes(false)} />
    </>
  );
}
