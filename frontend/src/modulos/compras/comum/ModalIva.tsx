import { Alert, Modal, Select, Table, Typography } from 'antd';
import { useState } from 'react';
import { useAccao } from '@/componentes/Accoes';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { ItemCompra } from './tipos';

/** Taxas legais de IVA (config erp.fiscal.taxas_iva; COMPRAS_TAXAS_IVA do legado). */
export const TAXAS_IVA_LEGAIS = [14, 7, 5, 0];

/** Linhas cuja taxa ainda se pode corrigir: na encomenda, só as que não têm quantidade facturada. */
export const linhaIvaEditavel = (l: Pick<ItemCompra, 'quantidade_faturada'>, encomenda: boolean): boolean => !encomenda || !(Number(l.quantidade_faturada ?? 0) > 0);

/**
 * M-17 (comprasEditarIVA / comprasGravarIVAProposta / comprasGravarIVAEncomenda, js/ui_compras_v2.js:1186-1252):
 * corrige a taxa de IVA das linhas de uma proposta (antes da adjudicação) ou de uma encomenda (linhas por facturar).
 * O servidor recalcula o IVA de cada linha e os totais do documento e regista a alteração na auditoria.
 */
export function ModalIva({ aberto, titulo, linhas, url, encomenda = false, aoFechar }: {
  aberto: boolean; titulo: string; linhas: ItemCompra[]; url: string; encomenda?: boolean; aoFechar: () => void;
}) {
  const [taxas, setTaxas] = useState<Record<number, number>>({});
  const accao = useAccao({ invalidar: [['compras']], aoSucesso: () => { setTaxas({}); aoFechar(); }, tituloErro: 'Não foi possível alterar o IVA' });
  const alteradas = Object.entries(taxas).filter(([id, t]) => Number(linhas.find((l) => l.id === Number(id))?.taxa_imposto ?? -1) !== t);

  return (
    <Modal
      open={aberto}
      title={titulo}
      width={larguraModal(760)}
      okText="Gravar IVA"
      cancelText="Cancelar"
      okButtonProps={{ disabled: !alteradas.length }}
      confirmLoading={accao.isPending}
      onCancel={() => { setTaxas({}); aoFechar(); }}
      onOk={() => accao.mutate({ metodo: 'put', url, dados: { linhas: alteradas.map(([id, t]) => ({ item_id: Number(id), taxa_imposto: t })) } })}
    >
      {encomenda && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Só as linhas ainda por facturar admitem correcção; as já facturadas corrigem-se na factura do fornecedor." />}
      <Table<ItemCompra>
        rowKey="id"
        size="small"
        pagination={false}
        dataSource={linhas}
        scroll={scrollTabela()}
        columns={[
          { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null, l) => v ?? `Produto #${l.produto_id}` },
          { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
          { title: 'Valor (Kz)', dataIndex: 'total_kz', align: 'right', render: (v: string | null, l) => formatarKz(v ?? l.total) },
          {
            title: 'IVA %', key: 'iva', width: 120,
            render: (_, l) => linhaIvaEditavel(l, encomenda)
              ? <Select size="small" style={{ width: 90 }} value={taxas[l.id] ?? Number(l.taxa_imposto ?? 0)} onChange={(v) => setTaxas((t) => ({ ...t, [l.id]: v }))}
                  options={TAXAS_IVA_LEGAIS.map((t) => ({ value: t, label: `${t} %` }))} aria-label={`IVA da linha ${l.descricao ?? l.id}`} />
              : <Typography.Text type="secondary">{formatarNumero(l.taxa_imposto)} % (facturada)</Typography.Text>,
          },
        ]}
      />
    </Modal>
  );
}
