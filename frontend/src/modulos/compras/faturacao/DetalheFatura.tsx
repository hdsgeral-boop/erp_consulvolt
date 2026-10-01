import { Alert, Button, Card, Descriptions, Flex, Skeleton, Table } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { numero } from '../comum/calculos';
import { EstadoTag } from '../comum/estados';
import { NomeProduto, NomeTerceiro, type RefProduto } from '../comum/referencias';
import { accoesFatura } from '../comum/regras';
import type { FaturaCompra, ItemCompra } from '../comum/tipos';

/** Linha do JSON `itens` das facturas migradas do legado (sem linhas em itens_compra). */
interface ItemLegado {
  item_id?: number;
  product_id: number;
  quantity: number;
  unit_price: number;
  tax_rate: number;
  net_kz: number;
  tax_kz: number;
}

/** Linhas para mostrar: as linhas normalizadas ou, nas facturas migradas, o JSON do legado. */
export function linhasFatura(f: FaturaCompra & { itens?: ItemLegado[] | null }): { chave: string; produto_id: number; produto?: RefProduto | null; descricao: string | null; quantidade: number; preco: number; iva: number; liquido: number; imposto: number }[] {
  if (f.linhas && f.linhas.length > 0) {
    return f.linhas.map((l: ItemCompra) => {
      const liquido = numero(l.total_kz ?? l.total ?? numero(l.quantidade) * numero(l.preco_unitario));
      return {
        chave: String(l.id),
        produto_id: l.produto_id,
        produto: l.produto ?? null,
        descricao: l.descricao,
        quantidade: numero(l.quantidade),
        preco: numero(l.preco_unitario),
        iva: numero(l.taxa_imposto),
        liquido,
        imposto: numero(l.imposto_kz as string | null | undefined),
      };
    });
  }
  return (f.itens ?? []).map((i, k) => ({
    chave: `legado-${i.item_id ?? k}`,
    produto_id: i.product_id,
    descricao: null,
    quantidade: i.quantity,
    preco: i.unit_price,
    iva: i.tax_rate,
    liquido: i.net_kz,
    imposto: i.tax_kz,
  }));
}

export function DetalheFatura() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, setModal] = useState<'anular' | 'descontabilizar' | null>(null);
  const consulta = useQuery({ queryKey: ['compras', 'fatura', id], queryFn: () => obter<FaturaCompra & { itens?: ItemLegado[] | null }>(`/compras/faturas/${id}`) });
  const accao = useAccao<FaturaCompra>({ invalidar: [['compras']], aoSucesso: () => setModal(null) });

  if (consulta.isLoading) return <Skeleton active />;
  const f = consulta.data;
  if (!f) return <Alert type="error" message="Factura não encontrada." />;
  const a = accoesFatura(f, pode);
  const moeda = f.codigo_moeda || 'AOA';
  const linhas = linhasFatura(f);

  return (
    <>
      <CabecalhoPagina
        titulo={`Factura ${f.numero_fatura}`}
        subtitulo={<NomeTerceiro id={f.fornecedor_id} terceiro={f.fornecedor} />}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.contabilizar && (
              <Button type="primary" loading={accao.isPending} onClick={() => accao.mutate({ url: `/compras/faturas/${f.id}/contabilizar` })}>
                Contabilizar
              </Button>
            )}
            {a.descontabilizar && <Button danger onClick={() => setModal('descontabilizar')}>Descontabilizar</Button>}
            {a.anular && <Button danger onClick={() => setModal('anular')}>Anular</Button>}
          </>
        }
      />
      {f.estado === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 16 }} message={`Factura anulada${f.motivo_anulacao ? `: ${f.motivo_anulacao}` : '.'}`} />}
      <Card style={{ marginBottom: 16 }}>
        <Descriptions column={{ xs: 1, md: 3 }} size="small">
          <Descriptions.Item label="Data">{formatarData(f.data)}</Descriptions.Item>
          <Descriptions.Item label="Vencimento">{formatarData(f.data_vencimento)}</Descriptions.Item>
          <Descriptions.Item label="Estado"><EstadoTag estado={f.estado} /></Descriptions.Item>
          <Descriptions.Item label="Encomenda">
            {f.encomenda_compra_id ? (pode('compras_encomendas_view') ? <a onClick={() => navegar(`/m/compras/compras_encomendas/${f.encomenda_compra_id}`)}>#{f.encomenda_compra_id}</a> : `#${f.encomenda_compra_id}`) : 'Factura directa'}
          </Descriptions.Item>
          <Descriptions.Item label="Moeda">{moeda}{f.taxa_cambio && moeda !== 'AOA' ? ` (câmbio ${formatarNumero(f.taxa_cambio)})` : ''}</Descriptions.Item>
          <Descriptions.Item label="Contabilização">{f.contabilizado ? `Contabilizada${f.numero_lan_contabilizacao ? ` (${f.numero_lan_contabilizacao})` : ''}` : 'Por contabilizar'}</Descriptions.Item>
        </Descriptions>
      </Card>
      <Card title="Linhas">
        <Table
          rowKey="chave"
          size="small"
          pagination={false}
          scroll={{ x: 'max-content' }}
          dataSource={linhas}
          columns={[
            { title: 'Produto', render: (_, l) => <NomeProduto id={l.produto_id} produto={l.produto} descricao={l.descricao} /> },
            { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
            { title: 'Preço (Kz)', dataIndex: 'preco', align: 'right', render: (v: number) => formatarKz(v) },
            { title: 'IVA %', dataIndex: 'iva', align: 'right', render: formatarNumero },
            { title: 'Líquido (Kz)', dataIndex: 'liquido', align: 'right', render: (v: number) => formatarKz(v) },
          ]}
        />
        <Flex justify="end" style={{ marginTop: 16 }}>
          <Descriptions column={1} size="small" bordered style={{ width: 360 }}>
            <Descriptions.Item label="IVA (Kz)">{formatarKz(f.total_imposto)}</Descriptions.Item>
            <Descriptions.Item label="Total (Kz)">{formatarKz(f.montante_total, true)}</Descriptions.Item>
            {moeda !== 'AOA' && <Descriptions.Item label={`Total (${moeda})`}>{formatarKz(f.montante_total_moeda)}</Descriptions.Item>}
          </Descriptions>
        </Flex>
      </Card>
      <ModalMotivo
        aberto={modal === 'descontabilizar'}
        titulo={`Descontabilizar a factura ${f.numero_fatura}`}
        textoOk="Descontabilizar"
        aviso="O lançamento é estornado (fica o rasto no Diário). Não é possível em facturas pagas."
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/compras/faturas/${f.id}/descontabilizar`, dados: { motivo } })}
      />
      <ModalMotivo
        aberto={modal === 'anular'}
        titulo={`Anular a factura ${f.numero_fatura}`}
        textoOk="Anular"
        carregando={accao.isPending}
        aoFechar={() => setModal(null)}
        aoConfirmar={(motivo) => accao.mutate({ url: `/compras/faturas/${f.id}/anular`, dados: { motivo } })}
      />
    </>
  );
}
