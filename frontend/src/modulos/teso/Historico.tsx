import { Card, DatePicker, Select, Table, Tabs, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarDataHora } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../contab/comum/Componentes';
import { ROTULO_TIPO, type ReconciliacaoBancaria, type TipoDocumento } from './api';
import { SeletorContaFinanceira, TabelaDocumentos } from './comum';
import { DetalheDocumento } from './pagamentos/DetalheDocumento';
import { colunasDocumentos, textoPeriodo } from './pagamentos/ListaDocumentos';
import { BarraFiltros, scrollTabela } from '@/componentes/responsivo';

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
                <BarraFiltros>
                  <Select placeholder="Tipo" allowClear style={{ width: 150 }} value={tipo} onChange={setTipo} options={[{ value: 'PAGAMENTO', label: 'Pagamentos' }, { value: 'RECEBIMENTO', label: 'Recebimentos' }]} />
                  <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
                  <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
                </BarraFiltros>
                {!pode('teso_desintegrar') && <Typography.Paragraph type="secondary">Não tem permissão para anular integrações: só consulta.</Typography.Paragraph>}
                <TabelaDocumentos
                  filtros={{ estado: 'INTEGRADO', tipo, conta_financeira: conta, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
                  columns={colunasDocumentos()}
                  impressao={{
                    titulo: 'Documentos de tesouraria integrados',
                    periodo: textoPeriodo(periodo),
                    filtros: [tipo && `Tipo: ${ROTULO_TIPO[tipo]}`, conta && `Conta: ${conta}`, 'Total: recebimentos menos pagamentos'],
                    rotuloTotal: 'Saldo',
                  }}
                  onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
                />
              </Card>
            ),
          },
          {
            key: 'reconciliacoes',
            label: 'Reconciliações bancárias',
            children: (
              <Card
                extra={
                  <BotoesExportar
                    desactivado={!reconciliacoes.data?.length}
                    obterPedido={() => ({
                      titulo: 'Reconciliações bancárias',
                      conteudo: tabelaHtml({
                        colunas: [
                          { titulo: 'Código', valor: (r: ReconciliacaoBancaria) => r.reconciliacao_codigo },
                          { titulo: 'Data', valor: (r) => formatarDataHora(r.data) },
                          { titulo: 'Conta', valor: (r) => r.codigo_conta ?? '' },
                          { titulo: 'Valor (Kz)', valor: (r) => r.valor_total, formato: 'moeda' },
                          { titulo: 'Estado', valor: (r) => r.estado },
                        ],
                        linhas: reconciliacoes.data ?? [],
                      }),
                    })}
                  />
                }
              >
                <Table<ReconciliacaoBancaria> scroll={scrollTabela()}
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
