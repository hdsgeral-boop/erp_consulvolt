import { Button, Card, DatePicker, Flex, Input, List, Modal, Progress, Select, Space, Table, Tag, Tooltip, Typography, message } from 'antd';
import {
  BookOutlined, CheckCircleTwoTone, CheckOutlined, CopyOutlined, FieldTimeOutlined, FileDoneOutlined, FileTextOutlined, PlusOutlined,
  RiseOutlined, RollbackOutlined, SearchOutlined, ShoppingCartOutlined, TruckOutlined, UndoOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, useEcraPequeno } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { CORES_ESTADO, TIPOS_DOCUMENTO, type DocumentoVenda } from './api';
import { ESTADOS_AGT, INFO_ESTADO_AGT, estadoAgtDoDocumento } from './agt/estadoAgt';

const BASE = '/m/vendas/vendas_faturacao';

export type GrupoDocumentos = 'faturas' | 'orcamentos' | 'encomendas' | 'guias';

/** Separadores do legado (renderFaturaçãoTab, js/ui_sales.js:648-653): tipos de cada um. */
export const GRUPOS: Record<GrupoDocumentos, { titulo: string; tipos: string[]; icone: ReactNode }> = {
  orcamentos: { titulo: 'Orçamentos / Pró-formas', tipos: ['OR', 'PF'], icone: <FileTextOutlined /> },
  encomendas: { titulo: 'Encomendas', tipos: ['NE'], icone: <ShoppingCartOutlined /> },
  faturas: { titulo: 'Facturas / N. crédito', tipos: ['FT', 'FR', 'NC', 'ND'], icone: <FileDoneOutlined /> },
  guias: { titulo: 'Guias', tipos: ['GR', 'GD'], icone: <TruckOutlined /> },
};

interface ResultadoLote { ok: number; erros: number; resultados: { id: number; numero: string | null; sucesso: boolean; mensagem: string }[] }

/** Percentagem paga de uma factura (barra «Nível recb.» do legado). */
export const percentagemPaga = (d: Pick<DocumentoVenda, 'total_bruto' | 'valor_pago' | 'tipo_documento'>): number => {
  const total = Number(d.total_bruto) || 0;
  if (d.tipo_documento === 'NC' || total <= 0) return 0;
  return Math.min(100, Math.round(((Number(d.valor_pago) || 0) / total) * 100));
};

/**
 * Listagem de um separador da Facturação, como no legado: painel de pesquisa (n.º/cliente, De, Até, Buscar, Limpar),
 * cartão com o título do separador e os botões de criação e de acções em lote (contabilizar/descontabilizar
 * seleccionados — M-06; facturar guias seleccionadas — M-18), e a tabela com as colunas do legado
 * (Data, N.º doc., Cliente, UN, CC, Moeda, Total moeda, Total Kz, Pago, Pendente, Nível recb., Estado).
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
  // filtro «Estado AGT» no endereço (?estado_fe=…): os contadores da Facturação electrónica abrem a lista já filtrada (A-04)
  const [procura, setProcura] = useSearchParams();
  const estadoFe = procura.get('estado_fe') && INFO_ESTADO_AGT[procura.get('estado_fe') as string] ? (procura.get('estado_fe') as string) : undefined;
  const setEstadoFe = (v?: string) => setProcura((p) => { const n = new URLSearchParams(p); if (v) n.set('estado_fe', v); else n.delete('estado_fe'); return n; }, { replace: true });
  const pequeno = useEcraPequeno();
  const faturas = grupo === 'faturas';
  const guias = grupo === 'guias';

  // indicadores do cabeçalho do legado («Pendentes» e «Mensal»): resumo do mês no servidor
  const inicioMes = dayjs().startOf('month').format('YYYY-MM-DD');
  const resumo = useQuery({
    queryKey: ['vendas', 'relatorio', 'resumo', inicioMes, 'cabecalho'],
    queryFn: () => obter<{ bruto: string; a_receber: string; documentos: number }>('/vendas/relatorios/resumo', { inicio: inicioMes, fim: dayjs().format('YYYY-MM-DD') }),
    enabled: pode('vendas_relatorios_view'),
    retry: false,
    staleTime: 60_000,
  });

  const [resultado, setResultado] = useState<{ titulo: string; r: ResultadoLote } | null>(null);
  const lote = useMutation({
    mutationFn: (p: { url: string; motivo?: string; titulo: string }) => enviar<ResultadoLote>('post', p.url, { ids: seleccao.map((d) => d.id), motivo: p.motivo }),
    onSuccess: ({ dados, mensagem }, p) => {
      message[dados.erros ? 'warning' : 'success'](mensagem);
      setResultado({ titulo: p.titulo, r: dados });
      setSeleccao([]);
      void consulta.invalidateQueries({ queryKey: ['vendas'] });
    },
    onError: (e) => notificarErro(e),
  });
  const faturarGuias = useMutation({
    mutationFn: () => enviar<DocumentoVenda>('post', '/vendas/documentos/faturar-guias', { guias: seleccao.map((d) => d.id) }),
    onSuccess: ({ dados, mensagem }) => { message.success(mensagem); void consulta.invalidateQueries({ queryKey: ['vendas'] }); navegar(`${BASE}/${dados.id}`); },
    onError: (e) => notificarErro(e, 'Não foi possível facturar as guias'),
  });
  const copiar = useMutation({
    mutationFn: (id: number) => obter<DocumentoVenda>(`/vendas/documentos/${id}`),
    onSuccess: (doc) => navegar(`${BASE}/novo`, { state: { copia: doc } }),
    onError: (e) => notificarErro(e),
  });

  const pedirMotivo = (titulo: string, url: string) => {
    let motivo = '';
    Modal.confirm({
      title: titulo,
      content: (
        <Space direction="vertical" style={{ width: '100%' }}>
          <Typography.Text>{seleccao.length} documento(s). O estorno fica registado (cada documento na sua transacção).</Typography.Text>
          <Input.TextArea rows={2} maxLength={500} placeholder="Motivo (obrigatório)" onChange={(e) => { motivo = e.target.value; }} aria-label="Motivo" />
        </Space>
      ),
      okText: 'Descontabilizar',
      okButtonProps: { danger: true },
      cancelText: 'Cancelar',
      onOk: () => (motivo.trim().length < 5 ? Promise.reject(message.error('Indique o motivo (mínimo 5 caracteres).')) : lote.mutate({ url, motivo, titulo })),
    });
  };
  const novo = (t: string) => navegar(`${BASE}/novo?tipo=${t}`);
  const algum = seleccao.length > 0;

  const botoes: Record<GrupoDocumentos, ReactNode> = {
    orcamentos: (
      <>
        <Button icon={<PlusOutlined />} onClick={() => novo('OR')}>Orçamento</Button>
        <Button icon={<PlusOutlined />} onClick={() => novo('PF')}>Pró-forma</Button>
      </>
    ),
    encomendas: <Button icon={<PlusOutlined />} onClick={() => novo('NE')}>Encomenda</Button>,
    faturas: (
      <>
        <Button type="primary" ghost icon={<PlusOutlined />} onClick={() => novo('FT')}>Factura</Button>
        <Button icon={<CheckOutlined />} style={{ color: '#15803d', borderColor: '#86efac', background: '#f0fdf4' }} onClick={() => novo('FR')}>Factura-recibo</Button>
        <Button icon={<RollbackOutlined />} onClick={() => novo('NC')}>Nota de crédito</Button>
      </>
    ),
    guias: <Button icon={<PlusOutlined />} onClick={() => novo('GR')}>Guia de remessa</Button>,
  };

  const colunas: ColunaApi<DocumentoVenda>[] = [
    { title: 'Data', dataIndex: 'data_emissao', render: formatarData },
    { title: 'N.º doc.', dataIndex: 'numero_documento', render: (v: string) => <strong style={{ color: 'var(--ant-color-primary, #2563eb)' }}>{v}</strong> },
    { title: 'Tipo', dataIndex: 'tipo_documento', responsive: ['sm'], render: (t: string) => <Tooltip title={TIPOS_DOCUMENTO[t]}><Tag>{t}</Tag></Tooltip> },
    { title: 'Cliente', ellipsis: true, render: (_, r) => r.cliente?.nome ?? `#${r.cliente_id}` },
    { title: 'Moeda', dataIndex: 'codigo_moeda', align: 'center', responsive: ['lg'], render: (m: string) => m || 'AOA' },
    { title: 'Total moeda', align: 'right', responsive: ['lg'], render: (_, r) => (r.moeda ? `${formatarKz(r.moeda.total_bruto)} ${r.moeda.codigo}` : ''), valorImpressao: (r) => (r.moeda ? `${formatarKz(r.moeda.total_bruto)} ${r.moeda.codigo}` : '') },
    { title: 'Total (Kz)', dataIndex: 'total_bruto', align: 'right', render: (v: string) => formatarKz(v), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.total_bruto))) },
    ...(faturas
      ? ([
          { title: 'Pago', dataIndex: 'valor_pago', align: 'right', responsive: ['md'], render: (v: string | null) => <span style={{ color: '#15803d' }}>{formatarKz(v ?? 0)}</span>, valorImpressao: (r) => formatarKz(r.valor_pago ?? 0), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.valor_pago))) },
          {
            title: 'Pendente', dataIndex: 'valor_pendente', align: 'right', responsive: ['md'],
            render: (v: string | null) => (v && Number(v) > 0 ? <span style={{ color: '#dc2626' }}>{formatarKz(v)}</span> : '—'),
            valorImpressao: (r) => formatarKz(r.valor_pendente ?? 0), totalImpressao: (ls) => formatarKz(somar(ls.map((l) => l.valor_pendente))),
          },
          {
            title: 'Nível recb.', width: 120, responsive: ['lg'], exportar: false,
            render: (_, r) => (r.tipo_documento === 'NC' ? null : <Progress percent={percentagemPaga(r)} size="small" strokeColor="#16a34a" format={(p) => `${p}% pago`} />),
          },
        ] as ColunaApi<DocumentoVenda>[])
      : []),
    ...(grupo === 'orcamentos' ? ([{ title: 'Válido até', dataIndex: 'valido_ate', responsive: ['md'], render: (v: string | null) => (v ? <span style={{ color: dayjs(v).isBefore(dayjs(), 'day') ? '#dc2626' : undefined }}>{formatarData(v)}</span> : '—') }] as ColunaApi<DocumentoVenda>[]) : []),
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => (e ? <Tag color={CORES_ESTADO[e]}>{e}</Tag> : '—') },
    { title: 'Contab.', dataIndex: 'contabilizado', align: 'center', responsive: ['lg'], render: (c: boolean) => (c ? <CheckCircleTwoTone twoToneColor="#52c41a" /> : null), valorImpressao: (r) => (r.contabilizado ? 'Sim' : 'Não') },
    ...(faturas
      ? ([{
          title: 'AGT', responsive: ['xl'],
          render: (_, r) => { const e = estadoAgtDoDocumento(r.faturacao_eletronica); return e ? <Tag color={INFO_ESTADO_AGT[e].cor}>{INFO_ESTADO_AGT[e].rotulo}</Tag> : '—'; },
          valorImpressao: (r) => { const e = estadoAgtDoDocumento(r.faturacao_eletronica); return e ? INFO_ESTADO_AGT[e].rotulo : ''; },
        }] as ColunaApi<DocumentoVenda>[])
      : []),
    {
      key: 'accoes', width: 48, exportar: false,
      render: (_, r) => pode('vendas_fat_emitir') && r.tipo_documento !== 'NC' && r.tipo_documento !== 'GD' && (
        <Tooltip title="Copiar documento">
          <Button size="small" type="text" icon={<CopyOutlined />} aria-label={`Copiar ${r.numero_documento}`} loading={copiar.isPending && copiar.variables === r.id}
            onClick={(e) => { e.stopPropagation(); copiar.mutate(r.id); }} />
        </Tooltip>
      ),
    },
  ];

  const filtrosImpressao = [
    tipo && `Tipo: ${TIPOS_DOCUMENTO[tipo] ?? tipo}`, estado && `Estado: ${estado}`,
    contabilizado && (contabilizado === '1' ? 'Contabilizados' : 'Por contabilizar'), pesquisa && `Pesquisa: ${pesquisa}`,
    estadoFe && `Estado AGT: ${INFO_ESTADO_AGT[estadoFe].rotulo}`,
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Facturação"
        subtitulo="Administre clientes, produtos e todo o ciclo de facturação."
        accoes={
          <Space wrap>
            {resumo.data && (
              <>
                <Tag icon={<FieldTimeOutlined />} color="orange" style={{ fontSize: 13, padding: '4px 8px' }}>A receber: {formatarKz(resumo.data.a_receber)} Kz</Tag>
                <Tag icon={<RiseOutlined />} color="green" style={{ fontSize: 13, padding: '4px 8px' }}>Mensal: {formatarKz(resumo.data.bruto)} Kz</Tag>
              </>
            )}
            {pode('vendas_fat_emitir') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar(`${BASE}/novo`)}>
                Novo documento
              </Button>
            )}
          </Space>
        }
      />
      <Card style={{ marginBottom: 16 }} styles={{ body: { paddingBottom: 8 } }}>
        <BarraFiltros
          accoes={
            <Space wrap>
              <Button type="primary" ghost icon={<SearchOutlined />} onClick={() => setPesquisa(texto)}>Buscar</Button>
              <Button icon={<UndoOutlined />} onClick={() => { setTexto(''); setPesquisa(''); setPeriodo(null); setTipo(undefined); setEstado(undefined); setContabilizado(undefined); setEstadoFe(undefined); }}>Limpar</Button>
            </Space>
          }
        >
          <Input
            prefix={<SearchOutlined />}
            placeholder="N.º doc. / nome do cliente…"
            allowClear
            value={texto}
            onChange={(e) => { setTexto(e.target.value); if (!e.target.value) setPesquisa(''); }}
            onPressEnter={() => setPesquisa(texto)}
            style={{ width: 260, maxWidth: '100%' }}
            aria-label="Pesquisa"
          />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} placeholder={['De', 'Até']} />
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
        title={<Space>{cfg.icone}<span>{cfg.titulo}</span></Space>}
        extra={algum ? <Typography.Text type="secondary">{seleccao.length} seleccionado(s)</Typography.Text> : undefined}
      >
        {pode('vendas_fat_emitir', 'vendas_fat_contabilizar', 'vendas_fat_descontab') && (
          <Flex wrap gap="small" style={{ marginBottom: 12 }}>
            {pode('vendas_fat_emitir') && botoes[grupo]}
            {(faturas || guias) && pode('vendas_fat_contabilizar') && (
              <Button icon={<BookOutlined />} disabled={!algum} loading={lote.isPending} style={{ color: 'var(--ant-color-primary, #2563eb)' }}
                onClick={() => lote.mutate({ url: '/vendas/documentos/contabilizar', titulo: 'Contabilizar seleccionados' })}>
                Contabilizar seleccionados
              </Button>
            )}
            {(faturas || guias) && pode('vendas_fat_descontab') && (
              <Button danger icon={<UndoOutlined />} disabled={!algum} onClick={() => pedirMotivo('Descontabilizar seleccionados', '/vendas/documentos/descontabilizar')}>
                Descontabilizar seleccionados
              </Button>
            )}
            {guias && pode('vendas_fat_emitir') && (
              <Button icon={<FileDoneOutlined />} disabled={!seleccao.some((d) => d.tipo_documento === 'GR')} loading={faturarGuias.isPending}
                onClick={() => Modal.confirm({ title: 'Facturar as guias seleccionadas', content: 'É emitida uma factura (FT) com as quantidades por facturar das guias de remessa escolhidas (todas do mesmo cliente).', okText: 'Emitir factura', cancelText: 'Cancelar', onOk: () => faturarGuias.mutate() })}>
                Facturar guias seleccionadas
              </Button>
            )}
          </Flex>
        )}
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
          summary={(linhas) => (linhas.length > 1 ? (
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={(faturas || guias) ? 2 : 1}><strong>Total</strong></Table.Summary.Cell>
              <Table.Summary.Cell index={1} colSpan={20}>
                <Typography.Text strong>{formatarKz(somar(linhas.map((l) => l.total_bruto)))} Kz</Typography.Text>
                {faturas && <Typography.Text type="secondary"> · pendente {formatarKz(somar(linhas.map((l) => l.valor_pendente)))} Kz</Typography.Text>}
              </Table.Summary.Cell>
            </Table.Summary.Row>
          ) : null)}
          impressao={{
            titulo: `${cfg.titulo} — documentos de venda`,
            periodo: periodo?.[0] && periodo?.[1] ? `${periodo[0].format('DD/MM/YYYY')} a ${periodo[1].format('DD/MM/YYYY')}` : undefined,
            filtros: filtrosImpressao,
          }}
          onRow={(r) => ({ onClick: () => navegar(`${BASE}/${r.id}`), style: { cursor: 'pointer' } })}
        />
      </Card>
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
