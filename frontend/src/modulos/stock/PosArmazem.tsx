import { Alert, Button, Card, Col, DatePicker, Descriptions, Empty, Flex, Input, Modal, Row, Skeleton, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoModulo, type SeparadorModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { TabelaComModos } from '@/componentes/vistas';
import { pedidoDocumentoComercial } from '@/modulos/vendas/impressao/documentoComercial';
import { dadosGuiaSaida } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';
import { EstadoTag } from '@/modulos/compras/comum/estados';
import { NomeProduto, NomeTerceiro, useArmazens } from '@/modulos/compras/comum/referencias';
import { SeletorArmazem } from '@/modulos/compras/comum/Seletores';
import { TabelaLocal } from '@/modulos/compras/comum/Tabelas';
import type { EncomendaPicking, GuiaSaida, ListaRecolha } from './comum/tipos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { Balcao } from './posArmazem/Balcao';
import { Senhas } from './posArmazem/Senhas';

const ROTA = '/m/stock/pos_armazem';
const SEPARADORES = ['balcao', 'picking', 'senhas', 'vendas'] as const;
type Separador = (typeof SEPARADORES)[number];

/**
 * Armazém › POS de Armazém (Logística) — ecrã pos_armazem (js/ui_pos_armazem.js; RELATORIO 2.6). Separadores em botão como
 * no legado: «Atendimento ao Balcão» (cartões do armazém + Carrinho de Picking → guia de saída), «Fila de Picking»
 * (encomendas de clientes; a expedição gera a guia de remessa), «Monitor de Chamada de Senhas» e, no fim, «Vendas ao
 * balcão» (histórico das guias, só do sistema novo). O separador vai na query (?separador=…), sem dados pessoais.
 */
export default function PosArmazem() {
  const armazens = useArmazens();
  const [params] = useSearchParams();
  const [armazem, setArmazem] = useState<number>();
  useEffect(() => {
    if (!armazem && armazens.data?.length) setArmazem((armazens.data.find((a) => a.predefinido) ?? armazens.data[0]).id);
  }, [armazens.data, armazem]);

  const pedido = params.get('separador') as Separador | null;
  const activo: Separador = pedido && SEPARADORES.includes(pedido) ? pedido : 'balcao';
  const separadores: SeparadorModulo[] = [
    { chave: 'balcao', rotulo: 'Atendimento ao Balcão', icone: 'desktop', destino: `${ROTA}?separador=balcao` },
    { chave: 'picking', rotulo: 'Fila de Picking', icone: 'clipboard-list', destino: `${ROTA}?separador=picking` },
    { chave: 'senhas', rotulo: 'Monitor de Chamada de Senhas', icone: 'bullhorn', destino: `${ROTA}?separador=senhas` },
    { chave: 'vendas', rotulo: 'Vendas ao balcão', icone: 'history', destino: `${ROTA}?separador=vendas` },
  ];
  const seletor = (
    <Flex justify="flex-end" style={{ marginBottom: 12 }}>
      <SeletorArmazem style={{ width: 260, maxWidth: '100%' }} value={armazem} onChange={setArmazem} aria-label="Armazém" />
    </Flex>
  );

  let conteudo;
  if (armazens.isSuccess && !armazens.data.length) {
    conteudo = (
      <Card style={{ textAlign: 'center', padding: '2rem 0' }}>
        <IconeFa nome="exclamation-triangle" style={{ fontSize: '3rem', color: '#f59e0b', marginBottom: '1rem' }} />
        <h3>Nenhum Armazém Configurado</h3>
        <Typography.Paragraph type="secondary">Por favor, configure pelo menos um Armazém antes de aceder ao POS.</Typography.Paragraph>
      </Card>
    );
  } else if (!armazem) {
    conteudo = <Skeleton active />;
  } else if (activo === 'balcao') {
    conteudo = <Balcao armazem={armazem} aoMudarArmazem={setArmazem} />;
  } else if (activo === 'picking') {
    conteudo = <>{seletor}<Picking armazem={armazem} /></>;
  } else if (activo === 'senhas') {
    conteudo = <>{seletor}<Senhas armazem={armazem} /></>;
  } else {
    conteudo = <>{seletor}<VendasBalcao armazem={armazem} /></>;
  }

  return (
    <>
      <CabecalhoModulo titulo="POS de Armazém (Logística)" icone="cash-register" separadores={separadores} activo={activo} />
      {conteudo}
    </>
  );
}

function VendasBalcao({ armazem }: { armazem?: number }) {
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [aberta, setAberta] = useState<number | null>(null);
  const detalhe = useQuery({ queryKey: ['logistica', 'pos-armazem', 'venda', aberta], queryFn: () => obter<GuiaSaida>(`/pos/armazem/vendas/${aberta}`), enabled: aberta !== null });
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
      </Flex>
      <TabelaLocal<GuiaSaida>
        url="/pos/armazem/vendas"
        params={{ armazem_id: armazem, de: dataApi(periodo?.[0]), ate: dataApi(periodo?.[1]) }}
        chaveConsulta={['logistica', 'pos-armazem', 'vendas']}
        idVista="vendas_balcao"
        impressao={{ titulo: 'Vendas ao balcão' }}
        onRow={(r) => ({ onClick: () => setAberta(r.id), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Guia', dataIndex: 'numero_documento', render: (v: string) => <strong>{v}</strong> },
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Cliente', key: 'c', render: (_, r) => (r.terceiro_id ? <NomeTerceiro id={r.terceiro_id} terceiro={r.terceiro} /> : r.area_rececao || 'Cliente de balcão') },
          { title: 'Estado', dataIndex: 'estado', responsive: ['sm'], render: (e: string | null) => <EstadoTag estado={e} /> },
          { title: 'Contab.', dataIndex: 'contabilizado', responsive: ['md'], render: (c: boolean | null) => (c ? <Tag color="green">Sim</Tag> : <Tag>Não</Tag>) },
          { title: 'Emitida por', dataIndex: 'criado_por', responsive: ['lg'], render: (v) => v || '—' },
        ]}
      />
      <Modal
        title={detalhe.data ? `Guia ${detalhe.data.numero_documento}` : 'Guia'}
        open={aberta !== null}
        onCancel={() => setAberta(null)}
        footer={detalhe.data ? <BotoesExportar obterPedido={() => (detalhe.data ? pedidoDocumentoComercial(dadosGuiaSaida(detalhe.data, null)) : null)} /> : null}
        width={larguraModal(720)}
      >
        {detalhe.isLoading ? (
          <Skeleton active />
        ) : (
          detalhe.data && (
            <>
              <Descriptions size="small" column={{ xs: 1, sm: 2 }} style={{ marginBottom: 12 }}>
                <Descriptions.Item label="Data">{formatarData(detalhe.data.data)}</Descriptions.Item>
                <Descriptions.Item label="Estado"><EstadoTag estado={detalhe.data.estado} /></Descriptions.Item>
                <Descriptions.Item label="Cliente">{detalhe.data.area_rececao || '—'}</Descriptions.Item>
                {detalhe.data.observacoes && <Descriptions.Item label="Observações">{detalhe.data.observacoes}</Descriptions.Item>}
              </Descriptions>
              <Table scroll={scrollTabela()}
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={detalhe.data.linhas ?? []}
                columns={[
                  { title: 'Produto', dataIndex: 'produto_id', render: (v: number, l) => <NomeProduto id={v} produto={l.produto} /> },
                  { title: 'Qtd.', dataIndex: 'quantidade', align: 'right', render: formatarNumero },
                  { title: 'Custo (Kz)', dataIndex: 'valor_kz', align: 'right', render: (v: string | null) => formatarKz(v) },
                ]}
              />
            </>
          )
        )}
      </Modal>
    </Card>
  );
}

/** «📋 Fila de Picking» (renderPOSPickingTab/startOrderPicking, js/ui_pos_armazem.js:804-1120). */
function Picking({ armazem }: { armazem: number }) {
  const { pode } = useSessao();
  const [encomenda, setEncomenda] = useState<number>();
  const [observacoes, setObservacoes] = useState('');
  const fila = useQuery({ queryKey: ['logistica', 'pos-armazem', 'picking'], queryFn: () => obter<EncomendaPicking[]>('/pos/armazem/picking') });
  const podeVerLista = pode('pos_armazem_picking', 'pos_armazem_expedir');
  const lista = useQuery({
    queryKey: ['logistica', 'pos-armazem', 'recolha', encomenda, armazem],
    queryFn: () => obter<ListaRecolha>(`/pos/armazem/picking/${encomenda}`, { armazem_id: armazem }),
    enabled: !!encomenda && podeVerLista,
  });
  const expedir = useAccao({
    invalidar: [['logistica'], ['vendas']],
    aoSucesso: () => {
      setEncomenda(undefined);
      setObservacoes('');
    },
    tituloErro: 'Não foi possível expedir',
  });

  return (
    <Row gutter={[16, 16]}>
      <Col xs={24} xl={encomenda ? 10 : 24}>
        <Card
          title={
            <div style={{ whiteSpace: 'normal', padding: '8px 0' }}>
              <IconeFa nome="tasks" /> Fila de Atendimento de Encomendas
              <Typography.Paragraph type="secondary" style={{ margin: 0, fontWeight: 400, fontSize: 13 }}>
                Lista de pedidos de clientes aguardando recolha física nas prateleiras do Armazém.
              </Typography.Paragraph>
            </div>
          }
        >
          <TabelaComModos<EncomendaPicking>
            rowKey="id"
            size="small"
            idVista="fila_picking"
            loading={fila.isFetching}
            dataSource={fila.data ?? []}
            impressao={{ titulo: 'Fila de Picking', subtitulo: 'Encomendas de clientes aguardando recolha' }}
            vazio={{ icone: 'clipboard-list', titulo: 'Nenhuma encomenda na fila de picking hoje.' }}
            rowClassName={(r) => (r.id === encomenda ? 'ant-table-row-selected' : '')}
            columns={[
              { title: 'Data Pedido', dataIndex: 'data_emissao', render: formatarData },
              { title: 'Nº Encomenda', dataIndex: 'numero_documento', render: (v: string) => <strong>{v}</strong> },
              { title: 'Cliente', dataIndex: 'cliente', render: (v) => v?.trim() || 'Cliente Geral' },
              { title: 'Valor Total', dataIndex: 'total_bruto', align: 'right', total: true, render: (v: string) => <strong>{formatarKz(v)}</strong> },
              { title: 'Linhas', dataIndex: 'linhas_por_expedir', align: 'right', ocultaPorOmissao: true },
              { title: 'Estado', dataIndex: 'estado_picking', render: (e: string) => <EstadoTag estado={e} /> },
              {
                title: 'Operação',
                key: 'op',
                align: 'right',
                filtravel: false,
                ocultavel: false,
                render: (_, r) =>
                  podeVerLista ? (
                    <Button type="primary" size="small" onClick={() => setEncomenda(r.id)}>
                      <IconeFa nome="dolly" /> Iniciar Picking
                    </Button>
                  ) : null,
              },
            ]}
          />
        </Card>
      </Col>
      {encomenda && (
        <Col xs={24} xl={14}>
          <Card title={<><IconeFa nome="dolly" /> Checklist de Recolha</>} extra={<Button size="small" onClick={() => setEncomenda(undefined)}>Fechar</Button>}>
            {!podeVerLista ? (
              <Alert type="info" showIcon message="Sem permissão para iniciar o picking." />
            ) : lista.isLoading ? (
              <Skeleton active />
            ) : !lista.data ? (
              <Empty description="Escolha uma encomenda da fila." />
            ) : (
              <>
                <Flex justify="space-between" align="center" gap={8} wrap style={{ marginBottom: 8 }}>
                  <Typography.Title level={5} style={{ margin: 0 }}>
                    <Tag color="orange">PICKING EM CURSO</Tag> {lista.data.encomenda.numero_documento} · {formatarKz(lista.data.encomenda.total_bruto)} Kz
                  </Typography.Title>
                  <BotoesExportar
                    tamanho="small"
                    obterPedido={() =>
                      lista.data
                        ? {
                            titulo: `Lista de recolha — ${lista.data.encomenda.numero_documento}`,
                            filtros: ['Picking de encomenda de cliente'],
                            conteudo:
                              tabelaHtml({
                                colunas: [
                                  { titulo: 'Código', valor: (l: (typeof lista.data.linhas)[number]) => l.codigo ?? '' },
                                  { titulo: 'Produto', valor: (l) => l.nome, quebrar: true },
                                  { titulo: 'Por expedir', valor: (l) => l.por_expedir, formato: 'numero' },
                                  { titulo: 'Stock no armazém', valor: (l) => (l.movimenta_stock ? formatarNumero(l.stock_armazem) : 'serviço'), alinhamento: 'direita' },
                                  { titulo: 'Situação', valor: (l) => (l.disponivel ? 'Disponível' : 'Sem stock') },
                                  { titulo: 'Recolhido', valor: () => '', largura: '24mm' },
                                ],
                                linhas: lista.data.linhas,
                              }) +
                              '<div class="imp-sem-quebra" style="display:flex;justify-content:space-around;gap:10mm;margin-top:14mm"><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Recolhido por</div><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Conferido por</div></div>',
                          }
                        : null
                    }
                  />
                </Flex>
                <Table scroll={scrollTabela()}
                  rowKey="item_id"
                  size="small"
                  pagination={false}
                  dataSource={lista.data.linhas}
                  columns={[
                    { title: 'Código', dataIndex: 'codigo', render: (v) => v || '—' },
                    { title: 'Produto', dataIndex: 'nome' },
                    { title: 'Qtd Pedida', dataIndex: 'por_expedir', align: 'right', render: formatarNumero },
                    { title: 'Stock Local', dataIndex: 'stock_armazem', align: 'right', responsive: ['sm'], render: (v: string | null, l) => (l.movimenta_stock ? formatarNumero(v) : 'serviço') },
                    { title: 'Verificado', dataIndex: 'disponivel', render: (d: boolean) => (d ? <Tag color="green">Disponível</Tag> : <Tag color="red">Sem stock</Tag>) },
                  ]}
                />
                {!lista.data.pode_expedir && <Alert style={{ marginTop: 12 }} type="warning" showIcon message="Há artigos sem stock suficiente neste armazém: não é possível expedir." />}
                {pode('pos_armazem_expedir') && (
                  <>
                    <Input.TextArea rows={2} maxLength={2000} style={{ marginTop: 12 }} placeholder="Observações da expedição" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} />
                    <Button
                      type="primary"
                      style={{ marginTop: 12 }}
                      disabled={!lista.data.pode_expedir}
                      loading={expedir.isPending}
                      onClick={() =>
                        Modal.confirm({
                          title: `Confirmar a expedição de ${lista.data?.encomenda.numero_documento}?`,
                          content: 'É emitida a guia de remessa e o stock sai do armazém.',
                          okText: 'Expedir',
                          cancelText: 'Cancelar',
                          onOk: () => expedir.mutateAsync({ url: `/pos/armazem/picking/${encomenda}/expedir`, dados: { armazem_id: armazem, observacoes: observacoes || undefined } }).catch(() => undefined),
                        })
                      }
                    >
                      <IconeFa nome="check-circle" /> Confirmar expedição
                    </Button>
                  </>
                )}
              </>
            )}
          </Card>
        </Col>
      )}
    </Row>
  );
}
