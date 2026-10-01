import { Card, DatePicker, Flex, Select, Table, Tabs, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarDataHora } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import type { ReconciliacaoBancaria, TipoDocumento } from './api';
import { SeletorContaFinanceira, TabelaDocumentos } from './comum';
import { DetalheDocumento } from './pagamentos/DetalheDocumento';
import { colunasDocumentos } from './pagamentos/ListaDocumentos';

/** Tesouraria › Anulação de integrações (ecrã teso_contab_historico): documentos integrados (desintegrar no detalhe) e reconciliações. */
export default function Historico() {
  return (
    <Routes>
      <Route index element={<Integrados />} />
      <Route path=":id" element={<DetalheDocumento permitirEdicao={false} />} />
    </Routes>
  );
}

function Integrados() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [tipo, setTipo] = useState<TipoDocumento>();
  const [conta, setConta] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const reconciliacoes = useQuery({ queryKey: ['teso', 'reconciliacoes'], queryFn: () => obter<ReconciliacaoBancaria[]>('/tesouraria/reconciliacao'), retry: false });

  return (
    <>
      <CabecalhoPagina titulo="Anulação de integrações" subtitulo="Documentos integrados na contabilidade; a anulação é um estorno com motivo" />
      <Tabs
        items={[
          {
            key: 'integrados',
            label: 'Documentos integrados',
            children: (
              <Card>
                <Flex gap={8} wrap style={{ marginBottom: 16 }}>
                  <Select placeholder="Tipo" allowClear style={{ width: 150 }} value={tipo} onChange={setTipo} options={[{ value: 'PAGAMENTO', label: 'Pagamentos' }, { value: 'RECEBIMENTO', label: 'Recebimentos' }]} />
                  <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
                  <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
                </Flex>
                {!pode('teso_desintegrar') && <Typography.Paragraph type="secondary">Não tem permissão para anular integrações: só consulta.</Typography.Paragraph>}
                <TabelaDocumentos
                  filtros={{ estado: 'INTEGRADO', tipo, conta_financeira: conta, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
                  columns={colunasDocumentos()}
                  onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
                />
              </Card>
            ),
          },
          {
            key: 'reconciliacoes',
            label: 'Reconciliações bancárias',
            children: (
              <Card>
                <Table<ReconciliacaoBancaria>
                  rowKey="id"
                  size="small"
                  loading={reconciliacoes.isLoading}
                  dataSource={reconciliacoes.data}
                  pagination={{ pageSize: 25 }}
                  locale={{ emptyText: reconciliacoes.isError ? 'Sem acesso às reconciliações.' : 'Sem reconciliações.' }}
                  columns={[
                    { title: 'Código', dataIndex: 'reconciliacao_codigo' },
                    { title: 'Data', dataIndex: 'data', render: formatarDataHora },
                    { title: 'Valor', dataIndex: 'valor_total', align: 'right', render: (v: string | null) => <ValorKz valor={v} /> },
                    { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
                  ]}
                />
                <Typography.Text type="secondary">Para anular uma reconciliação use o ecrã Reconciliação bancária.</Typography.Text>
              </Card>
            ),
          },
        ]}
      />
    </>
  );
}
