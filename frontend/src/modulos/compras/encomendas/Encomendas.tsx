import { Alert, Button, Card, DatePicker, Descriptions, Flex, Input, Select, Skeleton, Table } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import type { ColumnsType } from 'antd/es/table';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { obter, obterPagina } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { pendente } from '../comum/calculos';
import { EstadoTag, opcoesEstado } from '../comum/estados';
import { ModalFaturaEncomenda, ModalRececao } from '../comum/ModaisEncomenda';
import { NomeProduto, NomeTerceiro } from '../comum/referencias';
import { accoesEncomenda } from '../comum/regras';
import { SeletorTerceiro } from '../comum/Seletores';
import { TabelaApi } from '@/componentes/TabelaApi';
import { numeroOuId, type EncomendaCompra, type FaturaCompra, type ItemCompra, type RececaoCompra } from '../comum/tipos';
import { ValorMoeda } from '../comum/Valores';

/** Compras › Encomendas a fornecedores (ecrã compras_encomendas). As encomendas nascem da adjudicação de propostas. */
export default function Encomendas() {
  return (
    <Routes>
      <Route index element={<ListaEncomendas />} />
      <Route path=":id" element={<DetalheEncomenda />} />
    </Routes>
  );
}

function ListaEncomendas() {
  const navegar = useNavigate();
  const [estado, setEstado] = useState<string>();
  const [fornecedor, setFornecedor] = useState<number>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [pesquisa, setPesquisa] = useState('');

  const colunas: ColumnsType<EncomendaCompra> = [
    { title: 'Encomenda', key: 'numero', fixed: 'left', render: (_, r) => <strong>{numeroOuId(r.numero_encomenda, r.id)}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Fornecedor', key: 'fornecedor', render: (_, r) => <NomeTerceiro id={r.fornecedor_id} terceiro={r.fornecedor} /> },
    { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, r) => <ValorMoeda kz={r.montante_total} moeda={r.codigo_moeda} valorMoeda={r.montante_total_moeda} /> },
    { title: 'Entrega prevista', dataIndex: 'data_entrega_prevista', render: formatarData },
    { title: 'Contrato', dataIndex: 'contrato_fornecedor_id', render: (v: number | null) => (v ? `#${v}` : '—') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string) => <EstadoTag estado={e} /> },
  ];

  return (
    <>
      <CabecalhoPagina titulo="Encomendas a fornecedores" subtitulo="Geradas pela adjudicação; recepções e facturas registam-se a partir da encomenda" />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 16 }}>
          <Select placeholder="Estado" allowClear style={{ width: 200 }} value={estado} onChange={setEstado} options={opcoesEstado(['EM_PROCESSAMENTO', 'PARCIAL', 'RECEBIDO', 'ANULADA'])} />
          <SeletorTerceiro papel="FORNECEDOR" style={{ width: 320 }} value={fornecedor} onChange={setFornecedor} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
          <Input.Search placeholder="N.º da encomenda" allowClear style={{ width: 200 }} onSearch={(v) => setPesquisa(v.trim())} />
        </Flex>
        <TabelaApi<EncomendaCompra>
          url="/compras/encomendas"
          chaveConsulta={['compras', 'encomendas']}
          filtros={{ estado, pesquisa: pesquisa || undefined, fornecedor_id: fornecedor, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}

export function DetalheEncomenda() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'rececao' | 'fatura' | 'anular' | null>(null);
  const consulta = useQuery({ queryKey: ['compras', 'encomenda', id], queryFn: () => obter<EncomendaCompra>(`/compras/encomendas/${id}`) });
  const rececoes = useQuery({
    queryKey: ['compras', 'rececoes', 'da-encomenda', id],
    queryFn: () => obterPagina<RececaoCompra>('/compras/rececoes', { encomenda_compra_id: id, por_pagina: 100 }),
    enabled: pode('compras_rececoes_view', 'armazem_rececoes_view'),
  });
  const faturas = useQuery({
    queryKey: ['compras', 'faturas', 'da-encomenda', id],
    queryFn: () => obterPagina<FaturaCompra>('/compras/faturas', { encomenda_compra_id: id, por_pagina: 100 }),
    enabled: pode('compras_faturacao_view'),
  });
  const anular = useAccao<EncomendaCompra>({ invalidar: [['compras']], aoSucesso: () => setModal(null) });

  if (consulta.isLoading) return <Skeleton active />;
  const e = consulta.data;
  if (!e) return <Alert type="error" message="Encomenda não encontrada." />;
  const a = accoesEncomenda(e, pode);
  const moeda = e.codigo_moeda || 'AOA';
  const nome = numeroOuId(e.numero_encomenda, e.id);

  return (
    <>
      <CabecalhoPagina
        titulo={`Encomenda ${nome}`}
        subtitulo={<NomeTerceiro id={e.fornecedor_id} terceiro={e.fornecedor} />}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.registarRececao && <Button type="primary" onClick={() => setModal('rececao')}>Registar recepção</Button>}
            {a.registarFatura && <Button onClick={() => setModal('fatura')}>Registar factura</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {e.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Encomenda anulada${e.motivo_anulacao ? `: ${e.motivo_anulacao}` : '.'}`} />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Data">{formatarData(e.data)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={e.estado} /></Descriptions.Item>
          <Descriptions.Item label="Entrega prevista">{formatarData(e.data_entrega_prevista)}</Descriptions.Item>
          <Descriptions.Item label="Pedido">{e.pedido_compra_id ? `#${e.pedido_compra_id}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Proposta">{e.cotacao_compra_id ? `#${e.cotacao_compra_id}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Contrato">{e.contrato_fornecedor_id ? `#${e.contrato_fornecedor_id}` : '—'}</Descriptions.Item>
          <Descriptions.Item label="Moeda">{moeda}{e.taxa_cambio && moeda !== 'AOA' ? ` (câmbio ${formatarNumero(e.taxa_cambio)})` : ''}</Descriptions.Item>
          <Descriptions.Item label="Total (Kz)"><ValorMoeda kz={e.montante_total} moeda={moeda} valorMoeda={e.montante_total_moeda} /></Descriptions.Item>
          {e.total_com_imposto && <Descriptions.Item label="Total c/ IVA (Kz)">{formatarKz(e.total_com_imposto)}</Descriptions.Item>}
        </Descriptions>
      </Card>
      <Card title="Artigos" style={{ marginBottom: 16 }}>
        <Table<ItemCompra>
          rowKey="id"
          size="small"
          pagination={false}
          scroll={{ x: 'max-content' }}
          dataSource={e.linhas ?? []}
          columns={[
            { title: 'Produto', render: (_, l) => <NomeProduto id={l.produto_id} produto={l.produto} descricao={l.descricao} /> },
            { title: 'Encomendado', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            { title: 'Recebido', dataIndex: 'quantidade_recebida', align: 'right', render: (v) => formatarNumero(v ?? 0) },
            { title: 'Por receber', key: 'pr', align: 'right', render: (_, l) => formatarNumero(pendente(l.quantidade, l.quantidade_recebida)) },
            { title: 'Facturado', dataIndex: 'quantidade_faturada', align: 'right', render: (v) => formatarNumero(v ?? 0) },
            ...(moeda !== 'AOA' ? [{ title: `Preço (${moeda})`, dataIndex: 'preco_unitario_moeda', align: 'right' as const, render: (v: string | null) => formatarKz(v) }] : []),
            { title: 'Preço (Kz)', dataIndex: 'preco_unitario', align: 'right', render: (v: string | null) => formatarKz(v) },
            { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, l) => formatarKz(l.total_kz ?? l.total) },
          ]}
        />
      </Card>
      {(rececoes.data?.itens.length ?? 0) > 0 && (
        <Card title="Recepções" style={{ marginBottom: 16 }}>
          <Table<RececaoCompra>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={rececoes.data?.itens}
            onRow={(r) => ({ onClick: () => pode('compras_rececoes_view') && navegar(`/m/compras/compras_rececoes/${r.id}`), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Recepção', render: (_, r) => numeroOuId(r.numero_rececao, r.id) },
              { title: 'Guia do fornecedor', dataIndex: 'numero_entrega' },
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Estado', dataIndex: 'estado', render: (s: string) => <EstadoTag estado={s} /> },
            ]}
          />
        </Card>
      )}
      {(faturas.data?.itens.length ?? 0) > 0 && (
        <Card title="Facturas" style={{ marginBottom: 16 }}>
          <Table<FaturaCompra>
            rowKey="id"
            size="small"
            pagination={false}
            dataSource={faturas.data?.itens}
            onRow={(f) => ({ onClick: () => navegar(`/m/compras/compras_faturacao/${f.id}`), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Factura', dataIndex: 'numero_fatura' },
              { title: 'Data', dataIndex: 'data', render: formatarData },
              { title: 'Total (Kz)', key: 'total', align: 'right', render: (_, f) => <ValorMoeda kz={f.montante_total} moeda={f.codigo_moeda} valorMoeda={f.montante_total_moeda} /> },
              { title: 'Estado', dataIndex: 'estado', render: (s: string | null) => <EstadoTag estado={s} /> },
            ]}
          />
        </Card>
      )}
      <ModalRececao encomenda={e} aberto={modal === 'rececao'} aoFechar={() => setModal(null)} />
      <ModalFaturaEncomenda
        encomenda={e}
        aberto={modal === 'fatura'}
        aoFechar={() => setModal(null)}
        aoRegistar={(f) => pode('compras_faturacao_view') && navegar(`/m/compras/compras_faturacao/${f.id}`)}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular a encomenda ${nome}`}
        textoOk="Anular"
        aviso="Só se anulam encomendas sem recepções nem facturas activas. A proposta volta ao estado Proposta e o pedido a Aprovado."
        carregando={anular.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => anular.mutate({ url: `/compras/encomendas/${e.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
