import { Button, Card, DatePicker, Flex, Input, Modal, Segmented, Select, Space, Tag, Tooltip, Typography, message } from 'antd';
import { CloudUploadOutlined, DeleteOutlined, FileExcelOutlined, MinusCircleOutlined, PlusCircleOutlined, SearchOutlined, UndoOutlined } from '@ant-design/icons';
import { useAccao } from '@/componentes/Accoes';
import { descarregarModeloTesouraria, ModalImportacaoTesouraria } from './ModalImportacao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros, useEcra } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../../contab/comum/Componentes';
import { ROTULO_TIPO, type DocumentoTesouraria, type EstadoDocumento, type TipoDocumento } from '../api';
import { SeletorContaFinanceira, TabelaDocumentos } from '../comum';

/** Colunas comuns às listagens de documentos de tesouraria (gestão, integração e histórico). */
export function colunasDocumentos(): ColunaApi<DocumentoTesouraria>[] {
  return [
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string | null, r) => <strong>{v ?? `#${r.id}`}</strong> },
    { title: 'Tipo', dataIndex: 'tipo', responsive: ['sm'], render: (t: TipoDocumento) => <Tag color={t === 'PAGAMENTO' ? 'volcano' : 'green'}>{ROTULO_TIPO[t] ?? t}</Tag> },
    { title: 'Data', dataIndex: 'data_documento', render: formatarData },
    { title: 'Conta', dataIndex: 'conta_financeira', responsive: ['md'] },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, responsive: ['lg'], valorImpressao: (r) => r.descricao ?? '', render: (v: string | null) => <Tooltip title={v}>{v ?? '—'}</Tooltip> },
    { title: 'Referência', dataIndex: 'referencia', responsive: ['lg'], render: (v: string | null) => v ?? '—' },
    {
      title: 'Valor (Kz)',
      dataIndex: 'valor_total',
      align: 'right',
      valorImpressao: (r) => `${formatarKz(r.valor_total)}${r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? ` (${r.valor_total_moeda} ${r.codigo_moeda})` : ''}`,
      totalImpressao: (ls) => formatarKz(somar(ls.filter((l) => l.estado !== 'ANULADO').map((l) => (l.tipo === 'PAGAMENTO' ? `-${l.valor_total}` : l.valor_total)))),
      render: (v: string, r) => <><ValorKz valor={v} />{r.codigo_moeda && r.codigo_moeda !== 'AOA' && r.valor_total_moeda ? <div style={{ fontSize: 12, opacity: 0.7 }}>{r.valor_total_moeda} {r.codigo_moeda}</div> : null}</>,
    },
    { title: 'Estado', dataIndex: 'estado', responsive: ['sm'], render: (e: string | null) => <EtiquetaEstado estado={e} /> },
    { title: 'Lançamento', dataIndex: 'numero_lan_contabilizacao', responsive: ['lg'], render: (v: string | null) => v ?? '—' },
  ];
}

/** Texto do período (DD/MM/AAAA a DD/MM/AAAA) para o cabeçalho das listagens impressas. */
export function textoPeriodo(periodo: [Dayjs | null, Dayjs | null] | null): string | undefined {
  if (!periodo?.[0] && !periodo?.[1]) return undefined;
  return `${periodo?.[0]?.format('DD/MM/YYYY') ?? '…'} a ${periodo?.[1]?.format('DD/MM/YYYY') ?? '…'}`;
}

type Separador = 'PAGAMENTO' | 'RECEBIMENTO' | 'TODOS';

/**
 * Tesouraria › Operações, como no legado (renderTesouraria): separadores Pagamentos / Recebimentos / Todos os registos
 * (e atalho para a Folha de caixa), painel de pesquisa (texto, De, Até, Buscar, Limpar), barra de acções (Baixar modelo,
 * Carregar dados — A-12 —, Anular seleccionados, Novo pagamento/recebimento) e a lista com selecção.
 */
export function ListaDocumentos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [separador, setSeparador] = useState<Separador>('TODOS');
  const [estado, setEstado] = useState<EstadoDocumento>();
  const [conta, setConta] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [texto, setTexto] = useState('');
  const [pesquisa, setPesquisa] = useState('');
  const [seleccao, setSeleccao] = useState<DocumentoTesouraria[]>([]);
  const [importar, setImportar] = useState(false);
  const tipo = separador === 'TODOS' ? undefined : (separador as TipoDocumento);
  const lote = useAccao<{ ok: { numero_documento: string | null }[]; erros: { numero_documento: string | null; mensagem: string }[] }>({
    invalidar: [['teso']],
    aoSucesso: (r) => {
      setSeleccao([]);
      if (r.erros.length) Modal.warning({ title: 'Alguns documentos não foram tratados', content: <ul>{r.erros.map((e, i) => <li key={i}><strong>{e.numero_documento ?? '—'}</strong>: {e.mensagem}</li>)}</ul> });
    },
  });
  const comMotivo = (titulo: string, url: string, ids: number[]) => {
    let motivo = '';
    Modal.confirm({
      title: titulo,
      content: (
        <Space direction="vertical" style={{ width: '100%' }}>
          <Typography.Text>{ids.length} documento(s); cada um é tratado na sua transacção.</Typography.Text>
          <Input.TextArea rows={2} maxLength={500} placeholder="Motivo (obrigatório)" aria-label="Motivo" onChange={(e) => { motivo = e.target.value; }} />
        </Space>
      ),
      okText: 'Confirmar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
      onOk: () => (motivo.trim().length < 5 ? Promise.reject(message.error('Indique o motivo (mínimo 5 caracteres).')) : lote.mutate({ url, dados: { ids, motivo } })),
    });
  };
  const pendentes = seleccao.filter((d) => d.estado === 'PENDENTE').map((d) => d.id);
  const integrados = seleccao.filter((d) => d.estado === 'INTEGRADO').map((d) => d.id);

  const { telemovel } = useEcra();
  return (
    <>
      <CabecalhoPagina titulo="Tesouraria — Operações" subtitulo="Movimentos diários de caixa, pagamentos e recebimentos." />
      <Flex wrap gap="small" style={{ marginBottom: 16 }}>
        <Segmented<Separador>
          block={telemovel}
          value={separador}
          onChange={(v) => { setSeparador(v); setSeleccao([]); }}
          options={[
            { value: 'PAGAMENTO', label: 'Pagamentos', icon: <MinusCircleOutlined /> },
            { value: 'RECEBIMENTO', label: 'Recebimentos', icon: <PlusCircleOutlined /> },
            { value: 'TODOS', label: telemovel ? 'Todos' : 'Todos os registos' },
          ]}
        />
        {pode('teso_folha_caixa_view') && <Button onClick={() => navegar('/m/teso/teso_folha_caixa')}>Folha de caixa</Button>}
      </Flex>
      <Card style={{ marginBottom: 16 }} styles={{ body: { paddingBottom: 8 } }}>
        <BarraFiltros
          accoes={
            <Space wrap>
              <Button type="primary" ghost icon={<SearchOutlined />} onClick={() => setPesquisa(texto)}>Buscar</Button>
              <Button icon={<UndoOutlined />} onClick={() => { setTexto(''); setPesquisa(''); setPeriodo(null); setEstado(undefined); setConta(undefined); }}>Limpar</Button>
            </Space>
          }
        >
          <Input prefix={<SearchOutlined />} placeholder="Referência / descrição / n.º…" allowClear value={texto} style={{ width: 260, maxWidth: '100%' }} aria-label="Pesquisa"
            onChange={(e) => { setTexto(e.target.value); if (!e.target.value) setPesquisa(''); }} onPressEnter={() => setPesquisa(texto)} />
          <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} placeholder={['De', 'Até']} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={[{ value: 'PENDENTE', label: 'Por integrar' }, { value: 'INTEGRADO', label: 'Integrados' }, { value: 'ANULADO', label: 'Anulados' }]} />
          <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
        </BarraFiltros>
      </Card>
      <Card style={{ marginBottom: 16 }} styles={{ body: { padding: 12 } }}>
        <Flex wrap gap="small" justify="space-between">
          <Space wrap>
            {pode('teso_doc_emitir') && (
              <>
                <Button icon={<FileExcelOutlined />} style={{ color: '#15803d', borderColor: '#86efac', background: '#f0fdf4' }} onClick={() => void descarregarModeloTesouraria()}>Baixar modelo</Button>
                <Button icon={<CloudUploadOutlined />} onClick={() => setImportar(true)}>Carregar dados</Button>
              </>
            )}
            {pode('teso_doc_eliminar') && (
              <Button danger icon={<DeleteOutlined />} disabled={!pendentes.length} onClick={() => comMotivo('Anular os documentos seleccionados (por integrar)', '/tesouraria/documentos/anular', pendentes)}>
                Anular seleccionados
              </Button>
            )}
            {pode('teso_desintegrar') && (
              <Button danger icon={<UndoOutlined />} disabled={!integrados.length} onClick={() => comMotivo('Anular a integração dos seleccionados (estorno)', '/tesouraria/documentos/desintegrar', integrados)}>
                Desintegrar seleccionados
              </Button>
            )}
          </Space>
          {pode('teso_doc_emitir') && (
            <Space wrap>
              {separador !== 'RECEBIMENTO' && <Button type={separador === 'PAGAMENTO' ? 'primary' : 'default'} ghost={separador === 'PAGAMENTO'} icon={<MinusCircleOutlined />} onClick={() => navegar('novo?tipo=PAGAMENTO')}>Novo pagamento</Button>}
              {separador !== 'PAGAMENTO' && <Button type="primary" ghost icon={<PlusCircleOutlined />} onClick={() => navegar('novo?tipo=RECEBIMENTO')}>Novo recebimento</Button>}
            </Space>
          )}
        </Flex>
      </Card>
      <Card>
        <TabelaDocumentos
          filtros={{ tipo, estado, conta_financeira: conta, pesquisa, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunasDocumentos()}
          rowSelection={{
            selectedRowKeys: seleccao.map((d) => d.id),
            onChange: (_, linhas) => setSeleccao(linhas),
            getCheckboxProps: (r) => ({ disabled: r.estado === 'ANULADO', 'aria-label': `Seleccionar ${r.numero_documento ?? r.id}` }),
          }}
          impressao={{
            titulo: tipo ? `Lista de ${ROTULO_TIPO[tipo].toLowerCase()}s` : 'Lista de pagamentos e recebimentos',
            periodo: textoPeriodo(periodo),
            filtros: [
              tipo && `Tipo: ${ROTULO_TIPO[tipo]}`,
              estado && `Estado: ${{ PENDENTE: 'Por integrar', INTEGRADO: 'Integrados', ANULADO: 'Anulados' }[estado]}`,
              conta && `Conta: ${conta}`,
              pesquisa && `Pesquisa: ${pesquisa}`,
              'Total: recebimentos menos pagamentos (sem anulados)',
            ],
            rotuloTotal: 'Saldo',
          }}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalImportacaoTesouraria aberto={importar} aoFechar={() => setImportar(false)} />
    </>
  );
}

