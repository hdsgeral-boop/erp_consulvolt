import { Button, Descriptions, Modal, Skeleton, Table } from 'antd';
import { PrinterOutlined } from '@ant-design/icons';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { TabelaMeios } from '../comum/DetalheSessao';
import { htmlRelatorioSessao, lerPreferencias, reimprimir } from '../comum/impressao';
import type { TransferenciaSessao } from '../comum/tipos';
import { useRelatorioX } from './FechoZ';

/** Relatório X: totais da sessão aberta, sem a fechar (GET /pos/sessoes/{id}/relatorio-x). */
export function RelatorioXModal({ sessaoId, aberto, aoFechar }: { sessaoId: number; aberto: boolean; aoFechar: () => void }) {
  const { empresa } = useSessao();
  const x = useRelatorioX(sessaoId, aberto);
  const r = x.data;
  return (
    <Modal
      open={aberto}
      onCancel={aoFechar}
      width={760}
      title="Relatório X"
      footer={[
        <Button key="imp" icon={<PrinterOutlined />} disabled={!r} onClick={() => r && reimprimir(htmlRelatorioSessao(r, { empresa: empresa?.nome ?? '' }, lerPreferencias(empresa?.id)), lerPreferencias(empresa?.id))}>
          Imprimir
        </Button>,
        <Button key="ok" type="primary" onClick={aoFechar}>
          Fechar
        </Button>,
      ]}
    >
      {!r ? (
        <Skeleton active />
      ) : (
        <>
          <Descriptions size="small" column={2} bordered style={{ marginBottom: 12 }}>
            <Descriptions.Item label="Sessão">{r.sessao.codigo_sessao}</Descriptions.Item>
            <Descriptions.Item label="Operador">{r.sessao.nome_operador ?? '—'}</Descriptions.Item>
            <Descriptions.Item label="Abertura">{formatarDataHora(r.sessao.aberto_em)}</Descriptions.Item>
            <Descriptions.Item label="Emitido">{formatarDataHora(r.emitido_em)}</Descriptions.Item>
            <Descriptions.Item label="N.º de vendas">{r.numero_vendas}</Descriptions.Item>
            <Descriptions.Item label="Total de vendas">{formatarKz(r.total_vendas)} Kz</Descriptions.Item>
            <Descriptions.Item label="Fundo de maneio">{formatarKz(r.sessao.fundo_maneio_abertura)} Kz</Descriptions.Item>
            <Descriptions.Item label="Numerário esperado">{formatarKz(r.numerario_esperado)} Kz</Descriptions.Item>
            {r.lavandaria.movimento && (
              <Descriptions.Item label="Lavandaria" span={2}>
                {`${r.lavandaria.numero_recibos} recibo(s) · ${formatarKz(r.lavandaria.total_recibos)} Kz; ${r.lavandaria.numero_faturas} factura(s) · ${formatarKz(r.lavandaria.total_faturas)} Kz`}
              </Descriptions.Item>
            )}
          </Descriptions>
          <TabelaMeios linhas={r.totais_por_metodo} />
          {r.transferencias.length > 0 && (
            <Table<TransferenciaSessao>
              style={{ marginTop: 12 }}
              size="small"
              pagination={false}
              rowKey={(t) => `${t.venda_id}-${t.referencia}`}
              dataSource={r.transferencias}
              columns={[
                { title: 'Documento', dataIndex: 'numero_documento' },
                { title: 'Comprovativo', dataIndex: 'referencia' },
                { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
              ]}
            />
          )}
        </>
      )}
    </Modal>
  );
}
