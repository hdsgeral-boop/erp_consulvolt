import { Button, Card, DatePicker, Flex, Input, List, Modal, Select, Space, Tag, Tooltip, Typography, message } from 'antd';
import { CheckCircleTwoTone } from '@ant-design/icons';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { CORES_ESTADO, TIPOS_DOCUMENTO, type DocumentoVenda } from './api';
import { ESTADOS_AGT, INFO_ESTADO_AGT, estadoAgtDoDocumento } from './agt/estadoAgt';
import { accoesLinhaDocumento } from './accoesLinhaDocumento';
import { ModalFluxoProcesso } from './ModalFluxoProcesso';
import { useImprimirDocumentoVenda } from './impressao/useImprimirDocumentoVenda';

const BASE = '/m/vendas/vendas_faturacao';

export type GrupoDocumentos = 'faturas' | 'orcamentos' | 'encomendas' | 'guias';

/** Separadores do legado (renderFaturaçãoTab, js/ui_sales.js:648-653): título do cartão, tipos e ícone. */
export const GRUPOS: Record<GrupoDocumentos, { titulo: string; tipos: string[]; icone: string }> = {
  orcamentos: { titulo: 'Orç. / Proformas', tipos: ['OR', 'PF'], icone: 'file-alt' },
  encomendas: { titulo: 'Encomendas', tipos: ['NE'], icone: 'shopping-cart' },
  faturas: { titulo: 'Faturas / N.CRÉDITO', tipos: ['FT', 'FR', 'NC', 'ND'], icone: 'file-invoice-dollar' },
  guias: { titulo: 'Guias', tipos: ['GR', 'GD'], icone: 'truck' },
};

interface ResultadoLote { ok: number; erros: number; resultados: { id: number; numero: string | null; sucesso: boolean; mensagem: string }[] }

/** Percentagem paga de uma factura (barra «Nível recb.» do legado). Estado PAGO = 100 % (as FR do POS migradas têm valor_pago 0). */
export const percentagemPaga = (d: Pick<DocumentoVenda, 'total_bruto' | 'valor_pago' | 'tipo_documento'> & { estado?: string | null }): number => {
  const total = Number(d.total_bruto) || 0;
  if (d.tipo_documento === 'NC' || total <= 0) return 0;
  if (d.estado === 'PAGO') return 100;
  return Math.min(100, Math.round(((Number(d.valor_pago) || 0) / total) * 100));
};

/** Pago a mostrar: o estado manda (FR/FT PAGO com valor_pago 0 contam como pagas pelo total). */
export const valorPagoMostrado = (d: Pick<DocumentoVenda, 'total_bruto' | 'valor_pago' | 'tipo_documento' | 'estado'>): number =>
  d.tipo_documento === 'NC' ? 0 : d.estado === 'PAGO' && !(Number(d.valor_pago) > 0) ? Number(d.total_bruto) || 0 : Number(d.valor_pago) || 0;

/** Pendente a mostrar (0 nas pagas e nas NC). */
export const valorPendenteMostrado = (d: Pick<DocumentoVenda, 'valor_pendente' | 'tipo_documento' | 'estado'>): number =>
  d.estado === 'PAGO' || d.estado === 'ANULADO' || d.tipo_documento === 'NC' ? 0 : Number(d.valor_pendente) || 0;

/** Barra de nível (pagamento, entrega, facturação) como no legado. */
function Barra({ pct, cor, altura = 14, dica }: { pct: number; cor: string; altura?: number; dica: string }) {
  return (
    <div title={dica} style={{ width: '100%', background: '#f1f5f9', borderRadius: 4, height: altura, overflow: 'hidden', border: '1px solid #e2e8f0' }}>
      <div style={{ width: `${pct}%`, background: cor, height: '100%', transition: 'width 0.4s ease-out' }} />
    </div>
  );
}

const ROTULO_ESTADO: Record<string, string> = { PAGO: 'Pago', PARCIAL: 'Parcial', ANULADO: 'Anulado', PENDENTE: 'Pendente', CONCLUIDO: 'Concluído', VENCIDO: 'Vencido' };

/**
 * Listagem de um subseparador da Faturação, como no legado: painel de pesquisa (Pesquisa, De, Até, Buscar, Limpar),
 * cartão com o título do subseparador, os botões de criação e de acções em lote e a tabela com as colunas do legado
 * (Data, Nº Doc., Cliente, UN, CC, Moeda, Total Moeda, Total Kz, Pago, Pendente, Nível Recb., Status (Ent/Fat), Status,
 * Ações) e a linha TOTAL. DECIDIDO (4.2.2): lista completa paginada (não só o último documento até «Buscar»).
 */
export function ListaDocumentos({ grupo = 'faturas' }: { grupo?: GrupoDocumentos }) {
  const navegar = useNavigate();
  const consulta = useQueryClient();
  const { pode } = useSessao();
  const cfg = GRUPOS[grupo];
  const [tipo, setTipo] = useState<string>();
  const [estado, setEstado] = useState<string>();
  const [contabilizado, setContabilizado] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [texto, setTexto] = useState('');
  const [pesquisa, setPesquisa] = useState('');
  const [seleccao, setSeleccao] = useState<DocumentoVenda[]>([]);
  const [processo, setProcesso] = useState<DocumentoVenda | null>(null);   // janela «Fluxo Completo do Processo»
  const [procura, setProcura] = useSearchParams();
  const estadoFe = procura.get('estado_fe') && INFO_ESTADO_AGT[procura.get('estado_fe') as string] ? (procura.get('estado_fe') as string) : undefined;
  const setEstadoFe = (v?: string) => setProcura((p) => { const n = new URLSearchParams(p); if (v) n.set('estado_fe', v); else n.delete('estado_fe'); return n; }, { replace: true });
  const pequeno = useEcraPequeno();
  const faturas = grupo === 'faturas';
  const guias = grupo === 'guias';
  const comProgresso = grupo === 'orcamentos' || grupo === 'encomendas';
  const imprimirDocumento = useImprimirDocumentoVenda();

  const invalidar = () => void consulta.invalidateQueries({ queryKey: ['vendas'] });
  const [resultado, setResultado] = useState<{ titulo: string; r: ResultadoLote } | null>(null);
  const lote = useMutation({
    mutationFn: (p: { url: string; motivo?: string; titulo: string }) => enviar<ResultadoLote>('post', p.url, { ids: seleccao.map((d) => d.id), motivo: p.motivo }),
    onSuccess: ({ dados, mensagem }, p) => {
      message[dados.erros ? 'warning' : 'success'](mensagem);
      setResultado({ titulo: p.titulo, r: dados });
      setSeleccao([]);
      invalidar();
    },
    onError: (e) => notificarErro(e),
  });
  const faturarGuias = useMutation({
    mutationFn: () => enviar<DocumentoVenda>('post', '/vendas/documentos/faturar-guias', { guias: seleccao.map((d) => d.id) }),
    onSuccess: ({ dados, mensagem }) => { message.success(mensagem); invalidar(); navegar(`${BASE}/${dados.id}`); },
    onError: (e) => notificarErro(e, 'Não foi possível facturar as guias'),
  });
  /** POST de uma acção da linha (mensagem do servidor; erros no aviso comum). */
  const accao = async (url: string, dados?: unknown) => {
    try {
      const r = await enviar<unknown>('post', url, dados);
      message.success(r.mensagem);
      invalidar();
      return r.dados;
    } catch (e) {
      notificarErro(e);
      return null;
    }
  };
  const copiar = async (id: number) => {
    try {
      navegar(`${BASE}/novo`, { state: { copia: await obter<DocumentoVenda>(`/vendas/documentos/${id}`) } });
    } catch (e) {
      notificarErro(e);
    }
  };

  /** Pede o motivo (estorno ou anulação; obrigatório, ≥ 5 caracteres) e chama `fazer(motivo)`. */
  const pedirMotivo = (titulo: string, explicacao: ReactNode, fazer: (motivo: string) => unknown, textoOk = 'Descontabilizar') => {
    let motivo = '';
    Modal.confirm({
      title: titulo,
      content: (
        <Space direction="vertical" style={{ width: '100%' }}>
          <Typography.Text>{explicacao}</Typography.Text>
          <Input.TextArea rows={2} maxLength={500} placeholder="Motivo (obrigatório)" onChange={(e) => { motivo = e.target.value; }} aria-label="Motivo" />
        </Space>
      ),
      okText: textoOk,
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => (motivo.trim().length < 5 ? Promise.reject(message.error('Indique o motivo (mínimo 5 caracteres).')) : fazer(motivo)),
    });
  };
  const contabilizar = (id: number) => accao(`/vendas/documentos/${id}/contabilizar`);
  const descontabilizar = (id: number, numero: string) => pedirMotivo(`Descontabilizar ${numero}?`, 'O estorno fica registado no diário.', (motivo) => accao(`/vendas/documentos/${id}/descontabilizar`, { motivo }));
  // Fase A (anulacao_documentos.js:anularVenda): não se apaga — anula-se com motivo, autor e data
  const anular = (id: number, numero: string) => pedirMotivo(`Anular ${numero}?`,
    'O documento mantém-se no sistema, marcado como ANULADO (o número não é reutilizado) e, nas guias, o stock é reposto. Fica registado quem anulou, quando e porquê.',
    (motivo) => accao(`/vendas/documentos/${id}/anular`, { motivo }), 'Anular');
  const converter = (id: number) => navegar(`${BASE}/${id}`, { state: { abrir: 'converter' } });
  const liquidar = (clienteId: number | null | undefined, id: number) => navegar(`${BASE}/recibos/novo`, { state: { liquidar: { cliente_id: clienteId, venda_id: id } } });
  const novo = (t: string) => navegar(`${BASE}/novo?tipo=${t}`);
  const algum = seleccao.length > 0;

  const botoes: Record<GrupoDocumentos, ReactNode> = {
    orcamentos: (
      <>
        <Button size="small" icon={<IconeFa nome="file-alt" />} onClick={() => novo('OR')}>+ Orçamento</Button>
        <Button size="small" icon={<IconeFa nome="file-invoice" />} onClick={() => novo('PF')}>+ Proforma</Button>
      </>
    ),
    encomendas: <Button size="small" icon={<IconeFa nome="shopping-cart" />} onClick={() => novo('NE')}>+ Encomenda</Button>,
    faturas: (
      <>
        <Button size="small" type="primary" icon={<IconeFa nome="file-invoice-dollar" />} onClick={() => novo('FT')}>+ Factura</Button>
        <Button size="small" icon={<IconeFa nome="check-double" />} style={{ color: '#fff', borderColor: '#16a34a', background: '#16a34a' }} onClick={() => novo('FR')}>+ Factura-Recibo</Button>
        <Button size="small" icon={<IconeFa nome="undo" />} onClick={() => novo('NC')}>+ Nota de CRÉDITO</Button>
      </>
    ),
    guias: (
      <>
        <Button size="small" icon={<IconeFa nome="truck" />} onClick={() => novo('GR')}>+ Guia Remessa</Button>
        <Tooltip title="A guia de devolução nasce de uma guia de remessa: use «Converter» na linha da guia.">
          <Button size="small" icon={<IconeFa nome="undo" />} onClick={() => message.info('Escolha a guia de remessa e use «Converter» → Guia de devolução.')}>+ Guia Devolução</Button>
        </Tooltip>
      </>
    ),
  };

  const colunas: ColunaApi<DocumentoVenda>[] = [
    { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
    {
      title: 'Nº Doc.', dataIndex: 'numero_documento', valorImpressao: (r) => r.numero_documento,
      render: (v: string, r) => {
        const e = estadoAgtDoDocumento(r.faturacao_eletronica);
        return (
          <Space size={4} style={{ whiteSpace: 'nowrap' }}>
            <a href={`${BASE}/${r.id}`} title="Ver Processo" onClick={(ev) => { ev.preventDefault(); ev.stopPropagation(); setProcesso(r); }} style={{ fontWeight: 700, color: 'var(--ant-color-primary, #2563eb)' }}>{v}</a>
            {faturas && e && <Tag color={INFO_ESTADO_AGT[e].cor} style={{ fontSize: 10, marginInlineEnd: 0 }}>{INFO_ESTADO_AGT[e].rotulo}</Tag>}
          </Space>
        );
      },
    },
    { title: 'Tipo', dataIndex: 'tipo_documento', ocultaPorOmissao: true, render: (t: string) => <Tooltip title={TIPOS_DOCUMENTO[t]}><Tag>{t}</Tag></Tooltip> },
    { title: 'Cliente', key: 'cliente', ellipsis: true, render: (_, r) => r.cliente?.nome ?? 'N/D', valorImpressao: (r) => r.cliente?.nome ?? 'N/D' },
    { title: 'UN', key: 'un', render: (_, r) => <Tooltip title={r.unidade_negocio?.nome}>{r.unidade_negocio?.codigo ?? '-'}</Tooltip>, valorImpressao: (r) => r.unidade_negocio?.codigo ?? '-' },
    { title: 'CC', key: 'cc', render: (_, r) => <Tooltip title={r.centro_custo?.descricao}>{r.centro_custo?.codigo ?? '-'}</Tooltip>, valorImpressao: (r) => r.centro_custo?.codigo ?? '-' },
    { title: 'Moeda', dataIndex: 'codigo_moeda', align: 'center', render: (m: string) => m || 'AOA' },
    { title: 'Total Moeda', align: 'right', key: 'total_moeda', render: (_, r) => (r.moeda ? `${formatarKz(r.moeda.total_bruto)} ${r.moeda.codigo}` : ''), valorImpressao: (r) => (r.moeda ? `${formatarKz(r.moeda.total_bruto)} ${r.moeda.codigo}` : '') },
    { title: 'Total (Kz)', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v), total: true },
    ...(faturas
      ? ([
          {
            title: 'Pago', key: 'pago', align: 'right', total: (ls: DocumentoVenda[]) => formatarKz(ls.reduce((s, l) => s + valorPagoMostrado(l), 0)),
            render: (_: unknown, r: DocumentoVenda) => <span style={{ color: '#15803d' }}>{formatarKz(valorPagoMostrado(r))}</span>, valorImpressao: (r: DocumentoVenda) => formatarKz(valorPagoMostrado(r)),
          },
          {
            title: 'Pendente', key: 'pendente', align: 'right',
            total: (ls: DocumentoVenda[]) => <span style={{ color: '#dc2626' }}>{formatarKz(ls.reduce((s, l) => s + valorPendenteMostrado(l), 0))}</span>,
            render: (_: unknown, r: DocumentoVenda) => <span style={{ color: valorPendenteMostrado(r) > 0 ? '#dc2626' : '#94a3b8' }}>{formatarKz(valorPendenteMostrado(r))}</span>,
            valorImpressao: (r: DocumentoVenda) => formatarKz(valorPendenteMostrado(r)),
          },
          {
            title: 'Nível Recb.', key: 'nivel', width: 120, exportar: false, filtravel: false,
            render: (_: unknown, r: DocumentoVenda) => (r.tipo_documento === 'NC' ? null : (
              <div>
                <Barra pct={percentagemPaga(r)} cor="#16a34a" dica="Nível de Pagamento" />
                <div style={{ textAlign: 'center', fontSize: '0.65rem', fontWeight: 700, color: '#64748b', marginTop: 3 }}>{percentagemPaga(r)}% Pago</div>
              </div>
            )),
          },
        ] as ColunaApi<DocumentoVenda>[])
      : []),
    ...(comProgresso
      ? ([{
          title: 'Status (Ent/Fat)', key: 'progresso', width: 150, filtravel: false,
          valorImpressao: (r: DocumentoVenda) => `ENT: ${r.progresso?.entrega ?? 0}% · FAT: ${r.progresso?.faturacao ?? 0}%`,
          render: (_: unknown, r: DocumentoVenda) => (
            <Flex vertical gap={4}>
              <Barra pct={r.progresso?.entrega ?? 0} cor="#f59e0b" altura={10} dica="Entrega Logística" />
              <Barra pct={r.progresso?.faturacao ?? 0} cor="#3b82f6" altura={10} dica="Faturação ao Cliente" />
              <Flex justify="space-between" style={{ fontSize: '0.6rem', fontWeight: 700, color: '#64748b' }}>
                <span>ENT: {r.progresso?.entrega ?? 0}%</span>
                <span>FAT: {r.progresso?.faturacao ?? 0}%</span>
              </Flex>
            </Flex>
          ),
        }] as ColunaApi<DocumentoVenda>[])
      : []),
    ...(grupo === 'orcamentos' ? ([{ title: 'Válido até', dataIndex: 'valido_ate', render: (v: string | null) => (v ? <span style={{ color: dayjs(v).isBefore(dayjs(), 'day') ? '#dc2626' : undefined }}>{formatarData(v)}</span> : '—') }] as ColunaApi<DocumentoVenda>[]) : []),
    { title: 'Status', dataIndex: 'estado', render: (e: string | null) => <Tag color={e ? CORES_ESTADO[e] : undefined}>{ROTULO_ESTADO[e ?? 'PENDENTE'] ?? e}</Tag>, valorImpressao: (r) => ROTULO_ESTADO[r.estado ?? 'PENDENTE'] ?? r.estado ?? '' },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', ocultaPorOmissao: true, render: (c: boolean) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null), valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não') },
  ];

  const filtrosImpressao = [
    tipo && `Tipo: ${TIPOS_DOCUMENTO[tipo] ?? tipo}`, estado && `Estado: ${estado}`,
    contabilizado && (contabilizado === '1' ? 'Contabilizados' : 'Por contabilizar'), pesquisa && `Pesquisa: ${pesquisa}`,
    estadoFe && `Estado AGT: ${INFO_ESTADO_AGT[estadoFe].rotulo}`,
  ];

  return (
    <>
      <Card style={{ marginBottom: 16, background: '#f8fafc', borderRadius: 15 }} styles={{ body: { paddingBottom: 8 } }}>
        <BarraFiltros
          accoes={
            <Space wrap>
              <Button type="primary" icon={<IconeFa nome="search" />} onClick={() => setPesquisa(texto)}>Buscar</Button>
              <Button icon={<IconeFa nome="undo" />} onClick={() => { setTexto(''); setPesquisa(''); setPeriodo(null); setTipo(undefined); setEstado(undefined); setContabilizado(undefined); setEstadoFe(undefined); }}>Limpar</Button>
            </Space>
          }
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flex: 1, minWidth: 260 }}>
            <Typography.Text strong style={{ color: '#475569', whiteSpace: 'nowrap' }}><IconeFa nome="search" style={{ color: '#2563eb' }} /> Pesquisa:</Typography.Text>
            <Input placeholder="Nº Doc / Nome Cliente..." allowClear value={texto} onChange={(e) => { setTexto(e.target.value); if (!e.target.value) setPesquisa(''); }} onPressEnter={() => setPesquisa(texto)} style={{ width: '100%' }} aria-label="Pesquisa" />
          </div>
          <Space size={6} wrap>
            <Typography.Text strong style={{ color: '#475569' }}><IconeFa nome="calendar-alt" style={{ color: '#2563eb' }} /> De / Até:</Typography.Text>
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} placeholder={['De', 'Até']} />
          </Space>
          {cfg.tipos.length > 1 && (
            <Select placeholder="Tipo" allowClear style={{ width: 190, maxWidth: '100%' }} value={tipo} onChange={setTipo} options={cfg.tipos.map((v) => ({ value: v, label: `${v} — ${TIPOS_DOCUMENTO[v] ?? 'Nota de débito'}` }))} />
          )}
          <Select placeholder="Estado" allowClear style={{ width: 140 }} value={estado} onChange={setEstado} options={Object.keys(CORES_ESTADO).map((e) => ({ value: e, label: e }))} />
          {(faturas || guias) && (
            <Select placeholder="Contabilização" allowClear style={{ width: 170 }} value={contabilizado} onChange={setContabilizado} options={[{ value: '1', label: 'Contabilizados' }, { value: '0', label: 'Por contabilizar' }]} />
          )}
          {faturas && <Select placeholder="Estado AGT" allowClear style={{ width: 180 }} value={estadoFe} onChange={setEstadoFe} options={ESTADOS_AGT.map((e) => ({ value: e.chave, label: e.rotulo }))} />}
        </BarraFiltros>
      </Card>
      <Card
        title={<Space wrap><IconeFa nome={cfg.icone} /><span>{cfg.titulo}</span>{algum && <Typography.Text type="secondary" style={{ fontWeight: 400 }}>{seleccao.length} seleccionado(s)</Typography.Text>}</Space>}
        extra={pode('vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_fat_descontab') && (
          <Flex wrap gap="small" justify="end">
            {pode('vendas_fat_emitir') && botoes[grupo]}
            {(faturas || guias) && pode('vendas_fat_contabilizar') && (
              <Button size="small" icon={<IconeFa nome="book" />} disabled={!algum} loading={lote.isPending} style={{ color: 'var(--ant-color-primary, #2563eb)', borderColor: 'var(--ant-color-primary, #2563eb)' }}
                onClick={() => lote.mutate({ url: '/vendas/documentos/contabilizar', titulo: 'Contabilizar Selecionados' })}>
                Contabilizar Selecionados
              </Button>
            )}
            {(faturas || guias) && pode('vendas_fat_descontab') && (
              <Button size="small" danger icon={<IconeFa nome="undo" />} disabled={!algum}
                onClick={() => pedirMotivo('Descontabilizar Selecionados', `${seleccao.length} documento(s). O estorno fica registado (cada documento na sua transacção).`, (motivo) => lote.mutate({ url: '/vendas/documentos/descontabilizar', motivo, titulo: 'Descontabilizar Selecionados' }))}>
                Descontabilizar Selecionados
              </Button>
            )}
            {guias && pode('vendas_fat_emitir') && (
              <Button size="small" icon={<IconeFa nome="file-invoice-dollar" />} disabled={!seleccao.some((d) => d.tipo_documento === 'GR')} loading={faturarGuias.isPending}
                onClick={() => Modal.confirm({ title: 'Facturar as guias seleccionadas', content: 'É emitida uma factura (FT) com as quantidades por facturar das guias de remessa escolhidas (todas do mesmo cliente).', okText: 'Emitir factura', cancelText: 'Cancelar', onOk: () => faturarGuias.mutate() })}>
                Facturar guias seleccionadas
              </Button>
            )}
          </Flex>
        )}
      >
        <TabelaApi<DocumentoVenda>
          url="/vendas/documentos"
          chaveConsulta={['vendas', 'documentos', grupo]}
          filtros={{ tipos: tipo ? undefined : cfg.tipos.join(','), tipo_documento: tipo, estado, contabilizado, pesquisa, estado_fe: estadoFe, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          rowSelection={(faturas || guias) ? {
            selectedRowKeys: seleccao.map((d) => d.id),
            onChange: (_, linhas) => setSeleccao(linhas),
            getCheckboxProps: (r) => ({ disabled: r.estado === 'ANULADO', 'aria-label': `Seleccionar ${r.numero_documento}` }),
          } : undefined}
          accoes={(r) => accoesLinhaDocumento(r, grupo, pode, {
            verProcesso: () => setProcesso(r),
            verDocumentoAgt: () => navegar(`${BASE}/${r.id}`),
            imprimir: () => imprimirDocumento(r.id),
            copiar: () => copiar(r.id),
            contabilizar: () => contabilizar(r.id),
            descontabilizar: () => descontabilizar(r.id, r.numero_documento),
            converter: () => converter(r.id),
            pedidoCompra: () => accao(`/vendas/documentos/${r.id}/pedido-compra`),
            liquidar: () => liquidar(r.cliente_id, r.id),
            limparEstado: () => accao(`/vendas/documentos/${r.id}/recalcular-estado`),
            eliminar: () => anular(r.id, r.numero_documento),
          })}
          impressao={{
            titulo: `${cfg.titulo} — documentos de venda`,
            orientacao: 'paisagem',
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: filtrosImpressao,
          }}
          vazio={{ icone: cfg.icone, titulo: 'Nenhum documento encontrado.', texto: 'Use «Buscar» com outros filtros ou crie um documento.' }}
          onRow={(r) => ({ onClick: () => navegar(`${BASE}/${r.id}`), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalFluxoProcesso
        id={processo?.id ?? null}
        onFechar={() => setProcesso(null)}
        accoes={{
          imprimir: imprimirDocumento, contabilizar: (id) => contabilizar(id), descontabilizar, abrirNoModulo: (id) => navegar(`${BASE}/${id}`), converter,
          emitirRecibo: (id) => liquidar(processo?.cliente_id, id),
        }}
      />
      <Modal open={!!resultado} title={resultado?.titulo} onCancel={() => setResultado(null)} footer={<Button onClick={() => setResultado(null)}>Fechar</Button>}>
        <List
          size="small"
          dataSource={resultado?.r.resultados ?? []}
          renderItem={(x) => (
            <List.Item>
              <Space direction="vertical" size={0}>
                <Space><Tag color={x.sucesso ? 'green' : 'red'}>{x.sucesso ? 'OK' : 'Erro'}</Tag><strong>{x.numero ?? `#${x.id}`}</strong></Space>
                <Typography.Text type={x.sucesso ? 'secondary' : 'danger'}>{x.mensagem}</Typography.Text>
              </Space>
            </List.Item>
          )}
        />
      </Modal>
    </>
  );
}
