import { Alert, Button, DatePicker, Modal, Space, Statistic, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { obter } from '@/api/cliente';
import { BotoesExportar, pares } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { eZero } from '@/utilitarios/decimal';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { BotaoCsv, ValorKz } from '../comum/Componentes';
import { MovimentosSemNota } from '../comum/Demonstracao';
import { tabelaDeColunas } from '../comum/impressao';
import { useAbrirLancamento } from '../comum/useMapa';

interface Desequilibrio {
  diario: string;
  diario_id: number;
  lancamento: string;
  sem_numero_lan: boolean;
  data_documento: string;
  linhas: number;
  debito: string;
  credito: string;
  diferenca: string;
  primeira_linha_id: number;
}

interface RelatorioDesequilibrios {
  resumo: { debito: string; credito: string; diferenca: string; lancamentos_desequilibrados: number; soma_das_diferencas: string };
  lancamentos: Desequilibrio[];
}

/**
 * Controlo de desequilíbrios (D ≠ C por lançamento) e movimentos sem nota DEMO — ferramenta do sistema novo (no legado o
 * desequilíbrio só aparecia como linha no Balanço). Fica acessível pelo botão «Controlo de desequilíbrios» do ecrã
 * «Relatórios e mapas contabilísticos» (permissão relatorios_contabeis_view).
 */
export function ControloDesequilibrios({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const abrir = useAbrirLancamento();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [semNota, setSemNota] = useState(false);

  const deseq = useQuery({
    queryKey: ['contab', 'desequilibrios', dataApi(periodo?.[0]), dataApi(periodo?.[1])],
    queryFn: () => obter<RelatorioDesequilibrios>('/contabilidade/relatorios/desequilibrios', { data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }),
    enabled: aberto,
  });
  const r = deseq.data?.resumo;

  const colunas: ColunaApi<Desequilibrio>[] = [
    { title: 'Diário', dataIndex: 'diario' },
    {
      title: 'Lançamento',
      dataIndex: 'lancamento',
      render: (v: string, l) => (abrir ? <Typography.Link onClick={() => abrir(l.primeira_linha_id)}>{v}</Typography.Link> : v),
      valorImpressao: (l) => l.lancamento,
    },
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Linhas', dataIndex: 'linhas', align: 'right' },
    { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'Diferença', dataIndex: 'diferenca', align: 'right', render: (v: string) => <ValorKz valor={v} forte /> },
  ];

  return (
    <>
      <Modal title="Controlo de desequilíbrios" open={aberto} onCancel={aoFechar} footer={<Button onClick={aoFechar}>Fechar</Button>} width={larguraModal(1000)} destroyOnHidden>
        <Space wrap style={{ marginBottom: 12 }}>
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} allowEmpty={[true, true]} />
          <Button onClick={() => setSemNota(true)}>Movimentos sem nota DEMO</Button>
        </Space>
        {r && (
          <>
            <Space size={32} wrap style={{ marginBottom: 12 }}>
              <Statistic title="Total a débito" value={formatarKz(r.debito)} />
              <Statistic title="Total a crédito" value={formatarKz(r.credito)} />
              <Statistic title="Diferença" value={formatarKz(r.diferenca)} valueStyle={{ color: eZero(r.diferenca) ? undefined : '#cf1322' }} />
              <Statistic title="Lançamentos desequilibrados" value={r.lancamentos_desequilibrados} />
            </Space>
            {r.lancamentos_desequilibrados === 0 ? (
              <Alert type="success" showIcon message="Todos os lançamentos do período estão equilibrados (débito = crédito)." />
            ) : (
              <>
                <div style={{ marginBottom: 8, display: 'flex', flexWrap: 'wrap', gap: 8 }}>
                  <BotoesExportar
                    obterPedido={async () => ({
                      titulo: 'Controlo de desequilíbrios',
                      periodo:
                        periodo?.[0] || periodo?.[1]
                          ? `${periodo?.[0] ? formatarData(dataApi(periodo[0])) : '…'} a ${periodo?.[1] ? formatarData(dataApi(periodo[1])) : '…'}`
                          : 'Todos os períodos',
                      conteudo:
                        pares([
                          ['Total a débito', formatarKz(r.debito)],
                          ['Total a crédito', formatarKz(r.credito)],
                          ['Diferença', formatarKz(r.diferenca)],
                          ['Lançamentos desequilibrados', r.lancamentos_desequilibrados],
                        ], 4) + (await tabelaDeColunas(colunas, deseq.data?.lancamentos ?? [])),
                    })}
                  />
                  <BotaoCsv<Desequilibrio>
                    nome="desequilibrios"
                    linhas={deseq.data?.lancamentos}
                    colunas={[
                      { titulo: 'Diário', valor: (l) => l.diario },
                      { titulo: 'Lançamento', valor: (l) => l.lancamento },
                      { titulo: 'Data', valor: (l) => l.data_documento },
                      { titulo: 'Linhas', valor: (l) => l.linhas },
                      { titulo: 'Débito', valor: (l) => l.debito, numerico: true },
                      { titulo: 'Crédito', valor: (l) => l.credito, numerico: true },
                      { titulo: 'Diferença', valor: (l) => l.diferenca, numerico: true },
                    ]}
                  />
                </div>
                <Table<Desequilibrio>
                  rowKey={(l) => `${l.diario_id}|${l.lancamento}`}
                  size="small"
                  loading={deseq.isFetching}
                  dataSource={deseq.data?.lancamentos}
                  pagination={{ pageSize: 25 }}
                  scroll={scrollTabela()}
                  columns={colunas}
                />
              </>
            )}
          </>
        )}
      </Modal>
      {semNota && (
        <MovimentosSemNota
          parametros={{ data_fim: dataApi(periodo?.[1]) ?? new Date().toISOString().slice(0, 10), data_inicio: dataApi(periodo?.[0]) }}
          aoFechar={() => setSemNota(false)}
        />
      )}
    </>
  );
}
