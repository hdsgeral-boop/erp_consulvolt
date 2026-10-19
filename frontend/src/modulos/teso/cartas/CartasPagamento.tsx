import { Alert, Button, Card, DatePicker, Descriptions, Input, InputNumber, Modal, Select, Skeleton, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useImpressao } from '@/componentes/impressao';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import type { AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { pedirMotivo } from '../motivo';
import { ROTULO_TIPO_CARTA, type CartaPagamento, type DocumentoElegivel, type EstadoCarta, type ItemCarta, type TipoCarta } from './api';
import { pedidoCarta } from './impressao';
import { ModalEmissaoCarta } from './ModalEmissao';
import { ModelosTexto } from './Modelos';
import { formatarIban, mesPorExtenso, valorMoeda } from './regras';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

const etiquetaEstado = (e: EstadoCarta) =>
  e === 'ANULADA' ? <Tag color="red">ANULADA</Tag> : <Tag color="green">EMITIDA</Tag>;

/** Acções da lista de cartas: Ver, Imprimir, PDF e Anular (só emitidas, com teso_carta_emitir). */
export function accoesCarta(c: CartaPagamento, o: { podeEmitir: boolean; ver: (c: CartaPagamento) => void; imprimir: (c: CartaPagamento, modo: 'imprimir' | 'pdf') => void; anular: (c: CartaPagamento) => void }): AccaoLinha[] {
  return [
    { chave: 'ver', icone: 'eye', titulo: 'Ver', onClick: () => o.ver(c) },
    { chave: 'imprimir', icone: 'print', titulo: 'Imprimir / reimprimir', onClick: () => o.imprimir(c, 'imprimir') },
    { chave: 'pdf', icone: 'file-pdf', titulo: 'PDF', cor: '#dc2626', onClick: () => o.imprimir(c, 'pdf') },
    { chave: 'anular', icone: 'ban', titulo: 'Anular', variante: 'perigo', oculto: !o.podeEmitir || c.estado_efectivo === 'ANULADA', onClick: () => o.anular(c) },
  ];
}

/**
 * Tesouraria › Cartas de Pagamento (ecrã teso_cartas; renderCartasPagamento do legado novo): «Cartas emitidas»,
 * «Emitir a partir de um pagamento» e «Modelo de texto». A carta nasce de um pagamento já gravado (conta 42/43 com IBAN)
 * e não gera lançamentos; anula-se com motivo e reimprime-se (PDF pelo motor de impressão do navegador).
 */
export default function CartasPagamento() {
  const { pode } = useSessao();
  const podeEmitir = pode('teso_carta_emitir');
  const [parametros, setParametros] = useSearchParams();
  const documentoPedido = Number(parametros.get('documento')) || null;
  const [aba, setAba] = useSeparadorUrl(podeEmitir ? ['cartas', 'emitir', 'modelo'] : ['cartas', 'modelo'], documentoPedido && podeEmitir ? 'emitir' : undefined);
  const [emitir, setEmitir] = useState<number | null>(documentoPedido && podeEmitir ? documentoPedido : null);
  const [ver, setVer] = useState<number | null>(null);
  const { imprimir } = useImpressao();

  const fecharEmissao = () => {
    setEmitir(null);
    if (parametros.has('documento')) {
      parametros.delete('documento');
      setParametros(parametros, { replace: true });
    }
  };
  const imprimirCarta = async (c: CartaPagamento, modo: 'imprimir' | 'pdf') => {
    try {
      const completa = c.itens ? c : await obter<CartaPagamento>(`/tesouraria/cartas/${c.id}`);
      await imprimir(pedidoCarta(completa, modo));
    } catch (e) {
      notificarErro(e, 'Não foi possível obter a carta');
    }
  };

  return (
    <>
      {/* cabeçalho do legado (renderCartasPagamento): só o título «Cartas de Pagamento» — sem subtítulo nem «Ecrã Cheio» */}
      <CabecalhoModulo titulo="Cartas de Pagamento" />
      <Tabs activeKey={aba} onChange={setAba} items={[
        { key: 'cartas', label: <span><IconeFa nome="envelope-open-text" /> Cartas emitidas</span>, children: <ListaCartas podeEmitir={podeEmitir} ver={(c) => setVer(c.id)} imprimir={imprimirCarta} irParaEmitir={() => setAba('emitir')} /> },
        ...(podeEmitir ? [{ key: 'emitir', label: <span><IconeFa nome="file-signature" /> Emitir a partir de um pagamento</span>, children: <PagamentosElegiveis aoEmitir={setEmitir} /> }] : []),
        { key: 'modelo', label: <span><IconeFa nome="file-alt" /> Modelo de texto</span>, children: <ModelosTexto /> },
      ]} />
      <ModalEmissaoCarta documentoId={emitir} aoFechar={fecharEmissao} aoEmitida={() => setAba('cartas')} />
      <DetalheCarta id={ver} aoFechar={() => setVer(null)} imprimir={imprimirCarta} podeEmitir={podeEmitir} />
    </>
  );
}

function ListaCartas({ podeEmitir, ver, imprimir, irParaEmitir }: { podeEmitir: boolean; ver: (c: CartaPagamento) => void; imprimir: (c: CartaPagamento, modo: 'imprimir' | 'pdf') => void; irParaEmitir: () => void }) {
  const [estado, setEstado] = useState<EstadoCarta>();
  const [tipo, setTipo] = useState<TipoCarta>();
  const [ano, setAno] = useState<number | null>(null);
  const [texto, setTexto] = useState('');
  const [q, setQ] = useState('');
  const anular = useAccao({ invalidar: [['teso', 'cartas']] });

  const colunas: ColunaApi<CartaPagamento>[] = [
    { title: 'Número', dataIndex: 'numero', render: (v: string | null, c) => <strong>{v ?? `#${c.id}`}</strong> },
    { title: 'Data', dataIndex: 'data', render: formatarData },
    { title: 'Tipo', dataIndex: 'tipo_efectivo', valorImpressao: (c) => ROTULO_TIPO_CARTA[c.tipo_efectivo],
      render: (t: TipoCarta, c) => <>{ROTULO_TIPO_CARTA[t]}{t === 'SALARIOS' && c.mes_ano ? <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{mesPorExtenso(c.mes_ano)}</Typography.Text> : null}</> },
    { title: 'Banco / Conta', dataIndex: 'banco_nome', responsive: ['md'], valorImpressao: (c) => `${c.banco_nome ?? ''} ${c.codigo_conta_bancaria ?? ''}`,
      render: (v: string | null, c) => <>{v ?? '—'}<Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{c.codigo_conta_bancaria}</Typography.Text></> },
    { title: 'Movimento', dataIndex: 'referencia_movimento', responsive: ['lg'], valorImpressao: (c) => c.documento?.numero_documento ?? c.referencia_movimento ?? '',
      render: (v: string | null, c) => <>{c.documento?.numero_documento ?? v ?? '—'}{c.documento?.estado === 'ANULADO' && <Tag color="red" style={{ marginLeft: 4 }}>pag. anulado</Tag>}</> },
    { title: 'Benef.', dataIndex: 'n_beneficiarios', align: 'center' },
    { title: 'Total', dataIndex: 'montante_total', align: 'right', valorImpressao: (c) => valorMoeda(c.montante_total, c.codigo_moeda),
      render: (v: string, c) => <strong style={{ whiteSpace: 'nowrap' }}>{valorMoeda(v, c.codigo_moeda)}</strong> },
    { title: 'Estado', dataIndex: 'estado_efectivo', align: 'center', valorImpressao: (c) => c.estado_efectivo, render: (e: EstadoCarta) => etiquetaEstado(e) },
  ];

  return (
    <Card>
      <BarraFiltros accoes={<Space wrap><Button type="primary" icon={<IconeFa nome="search" />} onClick={() => setQ(texto)}>Buscar</Button>
        <Button icon={<IconeFa nome="undo" />} onClick={() => { setTexto(''); setQ(''); setEstado(undefined); setTipo(undefined); setAno(null); }}>Limpar</Button></Space>}>
        <Input placeholder="Número / pagamento / banco" allowClear value={texto} style={{ maxWidth: 280 }} aria-label="Pesquisa" onChange={(e) => { setTexto(e.target.value); if (!e.target.value) setQ(''); }} onPressEnter={() => setQ(texto)} />
        <Select placeholder="Estado" allowClear style={{ width: 140 }} value={estado} onChange={setEstado} options={[{ value: 'EMITIDA', label: 'Emitidas' }, { value: 'ANULADA', label: 'Anuladas' }]} />
        <Select placeholder="Tipo" allowClear style={{ width: 200 }} value={tipo} onChange={setTipo} options={(Object.keys(ROTULO_TIPO_CARTA) as TipoCarta[]).map((t) => ({ value: t, label: ROTULO_TIPO_CARTA[t] }))} />
        <InputNumber placeholder="Ano" min={1900} max={2999} value={ano} onChange={(v) => setAno(v)} style={{ width: 100 }} aria-label="Ano" />
      </BarraFiltros>
      <TabelaApi<CartaPagamento> url="/tesouraria/cartas" chaveConsulta={['teso', 'cartas', 'lista']} filtros={{ estado, tipo, ano, q }} rowKey="id" porPagina={20} columns={colunas}
        accoes={(c) => accoesCarta(c, {
          podeEmitir, ver, imprimir,
          anular: (x) => pedirMotivo({
            titulo: `Anular a carta ${x.numero ?? `#${x.id}`}?`,
            texto: 'A carta não é apagada: fica o registo com o motivo, porque pode já ter seguido para o banco. O pagamento na Tesouraria não é alterado.',
            textoOk: 'Anular',
            aoConfirmar: (motivo) => anular.mutateAsync({ url: `/tesouraria/cartas/${x.id}/anular`, dados: { motivo } }),
          }),
        })}
        impressao={{ titulo: 'Lista de cartas de pagamento', filtros: [estado && `Estado: ${estado}`, tipo && `Tipo: ${ROTULO_TIPO_CARTA[tipo]}`, ano ? `Ano: ${ano}` : null, q && `Pesquisa: ${q}`] }}
        vazio={{ icone: 'envelope-open-text', titulo: 'Ainda não há cartas emitidas', texto: 'A carta emite-se a partir de um pagamento já gravado, na Tesouraria.',
          accoes: podeEmitir ? <Button type="primary" onClick={irParaEmitir}>Escolher um pagamento</Button> : undefined }}
        onRow={(c) => ({ onDoubleClick: () => ver(c), style: { cursor: 'pointer', opacity: c.estado_efectivo === 'ANULADA' ? 0.6 : undefined } })} />
    </Card>
  );
}

function PagamentosElegiveis({ aoEmitir }: { aoEmitir: (id: number) => void }) {
  const [texto, setTexto] = useState('');
  const [q, setQ] = useState('');
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const colunas: ColunaApi<DocumentoElegivel>[] = [
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string | null, d) => <strong>{v ?? `#${d.id}`}</strong> },
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Tipo de carta', dataIndex: 'tipo_carta', render: (t: TipoCarta) => <Tag color={t === 'SALARIOS' ? 'blue' : 'purple'}>{ROTULO_TIPO_CARTA[t]}</Tag>, valorImpressao: (d) => ROTULO_TIPO_CARTA[d.tipo_carta] },
    { title: 'Conta', dataIndex: 'conta_financeira', valorImpressao: (d) => `${d.conta_financeira} ${d.conta_descricao ?? ''}`,
      render: (v: string, d) => <>{v} — {d.conta_descricao ?? ''}{!d.conta_com_iban && <div><Tag color="orange">sem IBAN no plano</Tag></div>}</> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, responsive: ['lg'] },
    { title: 'Valor', dataIndex: 'valor_total', align: 'right', valorImpressao: (d) => valorMoeda(d.codigo_moeda && d.codigo_moeda !== 'AOA' ? d.valor_total_moeda : d.valor_total, d.codigo_moeda),
      render: (v: string, d) => <strong style={{ whiteSpace: 'nowrap' }}>{valorMoeda(d.codigo_moeda && d.codigo_moeda !== 'AOA' ? d.valor_total_moeda : v, d.codigo_moeda)}</strong> },
    { title: 'Estado', dataIndex: 'estado', align: 'center', render: (e: string | null) => (e === 'INTEGRADO' ? <Tag color="green">Integrado</Tag> : <Tag>Por integrar</Tag>) },
    { title: 'Cartas', dataIndex: 'cartas_activas', align: 'center', render: (n: number) => (n ? <Tag color="geekblue">{n}</Tag> : '—') },
  ];
  return (
    <Card>
      <Alert type="info" showIcon style={{ marginBottom: 12 }} message="A carta nasce de um pagamento já gravado"
        description="Escolha um pagamento numa conta bancária (42 ou 43) com IBAN no plano de contas. A carta não cria dívida nem lançamento: só manda o banco executar o pagamento registado." />
      <BarraFiltros accoes={<Space wrap><Button type="primary" icon={<IconeFa nome="search" />} onClick={() => setQ(texto)}>Buscar</Button>
        <Button icon={<IconeFa nome="undo" />} onClick={() => { setTexto(''); setQ(''); setPeriodo(null); }}>Limpar</Button></Space>}>
        <Input placeholder="N.º / referência / descrição" allowClear value={texto} style={{ maxWidth: 280 }} aria-label="Pesquisa de pagamentos" onChange={(e) => { setTexto(e.target.value); if (!e.target.value) setQ(''); }} onPressEnter={() => setQ(texto)} />
        <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} placeholder={['De', 'Até']} />
      </BarraFiltros>
      <TabelaApi<DocumentoElegivel> url="/tesouraria/cartas/documentos" chaveConsulta={['teso', 'cartas', 'documentos']} rowKey="id" porPagina={20} columns={colunas}
        filtros={{ q, data_de: dataApi(periodo?.[0]), data_ate: dataApi(periodo?.[1]) }}
        accoes={(d) => [{ chave: 'emitir', icone: 'file-signature', titulo: d.conta_com_iban ? 'Emitir carta' : 'A conta não tem IBAN no plano de contas', cor: '#7c3aed',
          desactivado: d.conta_com_iban ? false : 'A conta não tem IBAN no plano de contas', onClick: () => aoEmitir(d.id) }]}
        vazio={{ icone: 'money-bill-wave', titulo: 'Sem pagamentos em contas bancárias', texto: 'Grave primeiro o pagamento em Tesouraria › Pagamentos (conta 42/43).' }} />
    </Card>
  );
}

function DetalheCarta({ id, aoFechar, imprimir, podeEmitir }: { id: number | null; aoFechar: () => void; imprimir: (c: CartaPagamento, modo: 'imprimir' | 'pdf') => void; podeEmitir: boolean }) {
  const carta = useQuery({ queryKey: ['teso', 'cartas', 'detalhe', id], queryFn: () => obter<CartaPagamento>(`/tesouraria/cartas/${id}`), enabled: id !== null });
  const anular = useAccao({ invalidar: [['teso', 'cartas']] });
  const c = carta.data;
  const moeda = c?.codigo_moeda || 'AOA';
  return (
    <Modal open={id !== null} width={larguraModal(900)} onCancel={aoFechar} destroyOnHidden
      title={<Space><IconeFa nome="file-signature" />Carta {c?.numero ?? (id ? `#${id}` : '')}{c && etiquetaEstado(c.estado_efectivo)}</Space>}
      footer={
        <Space wrap>
          {c && podeEmitir && c.estado_efectivo === 'EMITIDA' && (
            <Button danger icon={<IconeFa nome="ban" />} onClick={() => pedirMotivo({ titulo: `Anular a carta ${c.numero ?? c.id}?`, textoOk: 'Anular',
              texto: 'Fica o registo com o motivo; o pagamento na Tesouraria não é alterado.', aoConfirmar: (motivo) => anular.mutateAsync({ url: `/tesouraria/cartas/${c.id}/anular`, dados: { motivo } }) })}>Anular</Button>
          )}
          <Button icon={<IconeFa nome="file-pdf" />} disabled={!c} onClick={() => c && imprimir(c, 'pdf')}>PDF</Button>
          <Button type="primary" icon={<IconeFa nome="print" />} disabled={!c} onClick={() => c && imprimir(c, 'imprimir')}>Imprimir</Button>
          <Button onClick={aoFechar}>Fechar</Button>
        </Space>
      }>
      {carta.isLoading || !c ? <Skeleton active /> : (
        <>
          {c.estado_efectivo === 'ANULADA' && <Alert type="error" showIcon style={{ marginBottom: 12 }} message={`Anulada em ${formatarData(c.anulada_em)}${c.anulada_por ? ` por ${c.anulada_por}` : ''}`} description={c.motivo_anulacao} />}
          {c.dados_banco_actuais && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Carta antiga sem fotografia da conta bancária: mostram-se os dados actuais do plano de contas." />}
          <Descriptions size="small" bordered column={{ xs: 1, md: 2 }} style={{ marginBottom: 12 }} items={[
            { key: 'tipo', label: 'Tipo', children: ROTULO_TIPO_CARTA[c.tipo_efectivo] + (c.tipo_efectivo === 'SALARIOS' && c.mes_ano ? ` — ${mesPorExtenso(c.mes_ano)}` : '') },
            { key: 'data', label: 'Data', children: formatarData(c.data) },
            { key: 'pag', label: 'Pagamento', children: <>{c.documento?.numero_documento ?? c.referencia_movimento ?? '—'}{c.documento?.estado && <Tag style={{ marginLeft: 6 }}>{c.documento.estado}</Tag>}</> },
            { key: 'conta', label: 'Conta a debitar', children: `${c.codigo_conta_bancaria ?? ''} · ${c.banco_nome ?? '—'}` },
            { key: 'iban', label: 'IBAN', children: <code>{formatarIban(c.banco_iban) || '—'}</code> },
            { key: 'titular', label: 'Titular', children: c.banco_titular ?? c.empresa?.nome ?? '—' },
            { key: 'assunto', label: 'Assunto', span: 2, children: c.assunto },
            { key: 'emitida', label: 'Emitida por', children: c.criado_por ?? '—' },
            { key: 'total', label: 'Total', children: <strong>{valorMoeda(c.montante_total, moeda)}</strong> },
          ]} />
          <Typography.Paragraph type="secondary" style={{ whiteSpace: 'pre-wrap' }} ellipsis={{ rows: 4, expandable: true, symbol: 'ver texto completo' }}>{c.corpo}</Typography.Paragraph>
          <Table<ItemCarta> rowKey="id" size="small" pagination={false} scroll={scrollTabela()} dataSource={c.itens ?? []} columns={[
            { title: '#', key: 'n', width: 40, render: (_, __, i) => i + 1 },
            { title: 'Beneficiário', dataIndex: 'nome', render: (v: string | null, l) => <>{v ?? '—'}{l.nif && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>NIF {l.nif}</Typography.Text>}</> },
            { title: 'Banco', dataIndex: 'banco', responsive: ['md'] },
            { title: 'IBAN', dataIndex: 'iban', render: (v: string | null) => <code style={{ fontSize: 12 }}>{formatarIban(v)}</code> },
            { title: 'Valor', dataIndex: 'montante', align: 'right', render: (v: string) => valorMoeda(v, moeda) },
          ]} />
        </>
      )}
    </Modal>
  );
}
