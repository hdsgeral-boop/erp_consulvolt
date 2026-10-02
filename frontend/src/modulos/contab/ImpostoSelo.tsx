import { Alert, Button, Card, DatePicker, Descriptions, Modal, Space, Table, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { pares, tabelaHtml } from '@/componentes/impressao';
import { scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from './comum/Componentes';
import { eZero } from '@/utilitarios/decimal';

interface ResumoSelo {
  mes: number;
  ano: number;
  documento: string;
  data_documento: string;
  base_vd_cb: string;
  movimentos_vd_cb: number;
  base_cx: string;
  movimentos_cx: number;
  base_total: string;
  taxa: string;
  imposto: string;
  lancamento_existente: string | null;
}

interface HistoricoSelo {
  id: number;
  data_documento: string;
  numero_lan: string;
  numero_documento: string | null;
  descricao: string | null;
  valor: string;
  estado: string;
}

/** Rotinas › Imposto de Selo (ecrã imposto_selo): 1% sobre os recebimentos do mês, lançado uma vez por mês no diário AC. */
export default function ImpostoSelo() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [mes, setMes] = useState<Dayjs>(dayjs().subtract(1, 'month'));
  const params = { mes: mes.month() + 1, ano: mes.year() };
  const resumo = useQuery({ queryKey: ['contab', 'selo', params], queryFn: () => obter<ResumoSelo>('/contabilidade/rotinas/imposto-selo', params) });
  const historico = useQuery({ queryKey: ['contab', 'selo', 'historico'], queryFn: () => obter<HistoricoSelo[]>('/contabilidade/rotinas/imposto-selo/historico') });
  const lancar = useMutation({
    mutationFn: () => enviar('post', '/contabilidade/rotinas/imposto-selo', params),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível gerar o lançamento'),
  });
  const r = resumo.data;
  const podeLancar = !!r && !r.lancamento_existente && !eZero(r.imposto) && pode('selo_lancar');

  return (
    <>
      <CabecalhoPagina
        titulo="Imposto de Selo"
        subtitulo="Recebimentos do mês sujeitos a Imposto de Selo (1%)"
        accoes={<DatePicker picker="month" format="MM/YYYY" value={mes} onChange={(v) => v && setMes(v)} allowClear={false} />}
        impressaoDesactivada={!r}
        impressao={() =>
          r && {
            titulo: 'Imposto de Selo',
            periodo: mes.format('MM/YYYY'),
            subtitulo: `Documento ${r.documento} · ${formatarData(r.data_documento)}`,
            filtros: [r.lancamento_existente ? `Já lançado (${r.lancamento_existente})` : 'Por lançar'],
            conteudo:
              pares([
                ['Base — vendas a dinheiro e cobranças', `${formatarKz(r.base_vd_cb)} Kz (${r.movimentos_vd_cb} mov.)`],
                ['Base — caixa', `${formatarKz(r.base_cx)} Kz (${r.movimentos_cx} mov.)`],
                ['Base total', formatarKz(r.base_total, true)],
                ['Taxa', `${r.taxa.replace('.', ',')} %`],
                ['Imposto a lançar', formatarKz(r.imposto, true)],
              ], 2) +
              tabelaHtml({
                legenda: 'Lançamentos de Imposto de Selo',
                linhas: historico.data ?? [],
                vazio: 'Ainda não foi lançado Imposto de Selo.',
                colunas: [
                  { titulo: 'Data', valor: (h) => h.data_documento, formato: 'data' },
                  { titulo: 'N.º lançamento', valor: (h) => h.numero_lan },
                  { titulo: 'Documento', valor: (h) => h.numero_documento },
                  { titulo: 'Descrição', valor: (h) => h.descricao, quebrar: true },
                  { titulo: 'Valor', valor: (h) => h.valor, formato: 'moeda' },
                  { titulo: 'Estado', valor: (h) => h.estado },
                ],
              }),
          }
        }
      />
      <Card loading={resumo.isLoading} style={{ marginBottom: 16 }} title={r ? `Documento ${r.documento} · ${formatarData(r.data_documento)}` : undefined}>
        {r && (
          <>
            {r.lancamento_existente && <Alert type="success" showIcon style={{ marginBottom: 12 }} message={`O imposto deste mês já foi lançado (${r.lancamento_existente}).`} />}
            <Descriptions bordered size="small" column={{ xs: 1, md: 2 }}>
              <Descriptions.Item label="Base — vendas a dinheiro e cobranças">{formatarKz(r.base_vd_cb)} Kz ({r.movimentos_vd_cb} mov.)</Descriptions.Item>
              <Descriptions.Item label="Base — caixa">{formatarKz(r.base_cx)} Kz ({r.movimentos_cx} mov.)</Descriptions.Item>
              <Descriptions.Item label="Base total">{formatarKz(r.base_total, true)}</Descriptions.Item>
              <Descriptions.Item label="Taxa">{r.taxa.replace('.', ',')} %</Descriptions.Item>
              <Descriptions.Item label="Imposto a lançar" span="filled">
                <strong>{formatarKz(r.imposto, true)}</strong>
              </Descriptions.Item>
            </Descriptions>
            {podeLancar && (
              <Space wrap style={{ marginTop: 16 }}>
                <Button
                  type="primary"
                  loading={lancar.isPending}
                  onClick={() =>
                    Modal.confirm({
                      title: `Lançar ${formatarKz(r.imposto)} Kz de Imposto de Selo?`,
                      content: `O lançamento ${r.documento} é gravado no diário AC com data ${formatarData(r.data_documento)}.`,
                      okText: 'Lançar',
                      cancelText: 'Cancelar',
                      onOk: () => lancar.mutateAsync(),
                    })
                  }
                >
                  Gerar lançamento
                </Button>
              </Space>
            )}
          </>
        )}
      </Card>
      <Card title="Lançamentos de Imposto de Selo">
        <Table<HistoricoSelo>
          rowKey="id"
          size="small"
          loading={historico.isLoading}
          dataSource={historico.data}
          pagination={{ pageSize: 24 }}
          scroll={scrollTabela()}
          locale={{ emptyText: 'Ainda não foi lançado Imposto de Selo.' }}
          columns={[
            { title: 'Data', dataIndex: 'data_documento', render: formatarData },
            { title: 'N.º lançamento', dataIndex: 'numero_lan' },
            { title: 'Documento', dataIndex: 'numero_documento' },
            { title: 'Descrição', dataIndex: 'descricao', responsive: ['md'] },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
            { title: 'Estado', dataIndex: 'estado', render: (v: string) => <EtiquetaEstado estado={v} /> },
          ]}
        />
      </Card>
    </>
  );
}
