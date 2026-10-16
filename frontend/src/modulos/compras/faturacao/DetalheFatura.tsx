import { Alert, Button, Card, Col, Descriptions, Flex, Row, Skeleton, Table, Tag, Typography } from 'antd';
import { ArrowLeftOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { scrollTabela } from '@/componentes/responsivo';
import { CartaoAlocacaoUN, bloqueioAlocacao, useColunasUNCC, type LinhaComUNCC, type ResumoAlocacao } from '@/componentes/reparticao';
import { BotaoPreVisualizarLancamento } from '@/componentes/simulacoes/PreVisualizacaoLancamento';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosFaturaCompra } from '../comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { ModalMotivo, useAccao } from '@/componentes/Accoes';
import { numero } from '../comum/calculos';
import { EstadoTag } from '../comum/estados';
import { NomeProduto, NomeTerceiro, type RefProduto } from '../comum/referencias';
import { accoesFatura } from '../comum/regras';
import { numeroOuId, type FaturaCompra, type ItemCompra } from '../comum/tipos';

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
export function linhasFatura(f: FaturaCompra & { itens?: ItemLegado[] | null }): ({ chave: string; produto_id: number; produto?: RefProduto | null; descricao: string | null; quantidade: number; preco: number; iva: number; liquido: number; imposto: number } & LinhaComUNCC)[] {
  if (f.linhas && f.linhas.length > 0) {
    return f.linhas.map((l: ItemCompra) => {
      const liquido = numero(l.total_kz ?? l.total ?? numero(l.quantidade) * numero(l.preco_unitario));
      const uncc = l as LinhaComUNCC;   // ADR-071 (R5): UN/CC da linha (id + nome)
      return {
        unidade_negocio_id: uncc.unidade_negocio_id ?? null,
        centro_custo_id: uncc.centro_custo_id ?? null,
        unidade_negocio: uncc.unidade_negocio ?? null,
        centro_custo: uncc.centro_custo ?? null,
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
  const consulta = useQuery({ queryKey: ['compras', 'fatura', id], queryFn: () => obter<FaturaCompra & { itens?: ItemLegado[] | null; alocacao_un?: ResumoAlocacao | null; alocacao_un_origem?: 'ENCOMENDA' | 'PEDIDO' | null }>(`/compras/faturas/${id}`) });
  const accao = useAccao<FaturaCompra>({ invalidar: [['compras']], aoSucesso: () => setModal(null) });
  const colunasUNCC = useColunasUNCC(consulta.data ? linhasFatura(consulta.data) : []);

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
        subtitulo={
          <>
            Registo Interno #{f.id} | Status:{' '}
            {f.estado === 'ANULADA' ? <EstadoTag estado="ANULADA" /> : <Tag color={f.contabilizado ? 'green' : 'orange'}>{f.contabilizado ? 'CONTABILIZADO' : 'PENDENTE'}</Tag>}
          </>
        }
        impressao={() =>
          pedidoDocumentoComercial(
            dadosFaturaCompra(
              f,
              linhas.map((l) => ({
                codigo: l.produto?.codigo ?? null,
                descricao: l.produto?.nome ?? l.descricao ?? `Produto #${l.produto_id}`,
                quantidade: l.quantidade,
                preco: l.preco,
                taxa: l.iva,
                base: l.liquido,
                iva: l.imposto || undefined,
                total: l.liquido,
              })),
            ),
          )
        }
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {a.contabilizar && (
              <BotaoPreVisualizarLancamento url={`/compras/faturas/${f.id}/contabilizacao/pre-visualizacao`} titulo={`Factura ${f.numero_fatura}`}
                aoContabilizar={() => accao.mutate({ url: `/compras/faturas/${f.id}/contabilizar` })} aContabilizar={accao.isPending} />
            )}
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
      {/* «Dados do Fornecedor» e «Informação do Documento» lado a lado, como o legado (viewPurchaseInvoice, js/ui_compras_v2.js:3776-3790) */}
      <Row gutter={[24, 16]} style={{ marginBottom: 16 }}>
        <Col xs={24} md={16}>
          <div style={{ background: '#f1f5f9', padding: '1.5rem', borderRadius: 8, height: '100%' }}>
            <Typography.Title level={5} style={{ margin: '0 0 1rem', fontSize: '0.9rem', color: '#475569', textTransform: 'uppercase' }}>Dados do Fornecedor</Typography.Title>
            <div style={{ fontSize: '1.2rem', fontWeight: 800, color: '#1e293b' }}><NomeTerceiro id={f.fornecedor_id} terceiro={f.fornecedor} /></div>
            <div style={{ color: '#64748b' }}>NIF: {f.fornecedor?.nif || '---'}</div>
          </div>
        </Col>
        <Col xs={24} md={8}>
          <div style={{ background: '#eff6ff', padding: '1.5rem', borderRadius: 8, border: '1px solid #dbeafe', height: '100%' }}>
            <Typography.Title level={5} style={{ margin: '0 0 1rem', fontSize: '0.9rem', color: '#1e40af', textTransform: 'uppercase' }}>Informação do Documento</Typography.Title>
            <div style={{ marginBottom: '0.5rem' }}>Fatura Nº: <strong>{f.numero_fatura}</strong></div>
            <div style={{ marginBottom: '0.5rem' }}>Data: <strong>{formatarData(f.data)}</strong></div>
            <div style={{ fontSize: '1.1rem', color: '#1e3a8a' }}>Total: <strong style={{ fontSize: '1.3rem' }}>{formatarKz(f.montante_total, true)}</strong></div>
            {moeda !== 'AOA' && (
              <div style={{ fontSize: '0.8rem', color: '#475569', marginTop: '0.35rem' }}>
                Moeda: <strong>{moeda}</strong> · Total: <strong>{formatarKz(f.montante_total_moeda)} {moeda}</strong>
                {f.taxa_cambio && <><br />Câmbio: 1 {moeda} = {formatarNumero(f.taxa_cambio)} Kz</>}
              </div>
            )}
            {/* dados do sistema novo (vencimento, pagamento, encomenda e contabilização) */}
            <div style={{ fontSize: '0.8rem', color: '#475569', marginTop: '0.75rem' }}>
              <div>Vencimento: {formatarData(f.data_vencimento)}</div>
              <div>Pagamento: <EstadoTag estado={f.estado} /></div>
              <div>
                Encomenda:{' '}
                {f.encomenda_compra_id ? (pode('compras_encomendas_view') ? <a onClick={() => navegar(`/m/compras/compras_encomendas/${f.encomenda_compra_id}`)}>{numeroOuId(f.numero_encomenda, f.encomenda_compra_id)}</a> : numeroOuId(f.numero_encomenda, f.encomenda_compra_id)) : 'Factura directa'}
              </div>
              <div>Contabilização: {f.contabilizado ? `Contabilizada${f.numero_lan_contabilizacao ? ` (${f.numero_lan_contabilizacao})` : ''}` : 'Por contabilizar'}</div>
            </div>
          </div>
        </Col>
      </Row>
      <Card title="Itens da Fatura">
        <Table
          rowKey="chave"
          size="small"
          pagination={false}
          scroll={scrollTabela()}
          dataSource={linhas}
          columns={[
            // colunas do legado: Produto / Descrição (nome + «Ref:»), Quantidade (centro), Preço Unit. e Total (líquido); IVA % do sistema novo
            {
              title: 'Produto / Descrição',
              render: (_, l) => (
                <>
                  <strong><NomeProduto id={l.produto_id} produto={l.produto} descricao={l.descricao} /></strong>
                  <Typography.Text style={{ display: 'block', fontSize: 12, color: '#64748b' }}>Ref: {l.produto?.codigo || '---'}</Typography.Text>
                </>
              ),
            },
            { title: 'Quantidade', dataIndex: 'quantidade', align: 'center', render: formatarNumero },
            { title: 'Preço Unit.', dataIndex: 'preco', align: 'right', render: (v: number) => formatarKz(v) },
            { title: 'IVA %', dataIndex: 'iva', align: 'right', render: formatarNumero },
            { title: 'Total', dataIndex: 'liquido', align: 'right', render: (v: number) => <span style={{ fontWeight: 600 }}>{formatarKz(v)}</span> },
            ...colunasUNCC,
          ]}
        />
        <Flex justify="end" style={{ marginTop: 16 }}>
          <Descriptions column={1} size="small" bordered style={{ width: '100%', maxWidth: 360 }}>
            <Descriptions.Item label="IVA (Kz)">{formatarKz(f.total_imposto)}</Descriptions.Item>
            <Descriptions.Item label="Total (Kz)">{formatarKz(f.montante_total, true)}</Descriptions.Item>
            {moeda !== 'AOA' && <Descriptions.Item label={`Total (${moeda})`}>{formatarKz(f.montante_total_moeda)}</Descriptions.Item>}
          </Descriptions>
        </Flex>
        {/* ADR-071 (R2/R5): repartição por UN — alterável (com permissão) enquanto a factura não estiver contabilizada nem anulada */}
        <CartaoAlocacaoUN tipo="fatura_compra" id={f.id} resumo={f.alocacao_un} valor={numero(f.montante_total)} invalidar={[['compras']]}
          podeAlterar={pode('compras_fact_registar')} bloqueio={bloqueioAlocacao(f)}
          nota={f.alocacao_un_origem ? `Herdada ${f.alocacao_un_origem === 'PEDIDO' ? 'do pedido' : 'da encomenda'} (a factura não tem repartição própria)` : null} />
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
