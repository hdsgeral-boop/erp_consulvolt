import { Button, Card, DatePicker, Flex, Input, Modal, Select, Space, Tag, Tooltip, Typography, theme } from 'antd';
import { useAccao } from '@/componentes/Accoes';
import { descarregarModeloTesouraria, ModalImportacaoTesouraria } from './ModalImportacao';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { BarraFiltros } from '@/componentes/responsivo';
import type { AccaoLinha } from '@/componentes/vistas';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { somar } from '@/utilitarios/decimal';
import type { Dayjs } from 'dayjs';
import { useEffect, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaEstado, ValorKz } from '../../contab/comum/Componentes';
import { ROTULO_TIPO, type DocumentoTesouraria, type EstadoDocumento, type TipoDocumento } from '../api';
import { colunasOperacoes, bloqueioEdicao, bloqueioExclusao } from '../colunasLegado';
import { SeletorContaFinanceira, TabelaDocumentos } from '../comum';
import { pedirMotivo } from '../motivo';
import { eContaBancaria } from '../cartas/regras';

/** Colunas genéricas dos documentos de tesouraria (mantidas para ecrãs que não seguem uma lista do legado). */
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

/** Separador pedido na query string (?tipo=PAGAMENTO|RECEBIMENTO — menu do legado com duas entradas); outro valor → Todos. */
export function separadorDaQuery(tipo: string | null): Separador {
  return tipo === 'PAGAMENTO' || tipo === 'RECEBIMENTO' ? tipo : 'TODOS';
}

/**
 * Botão «Novo …» do legado: `Novo ${tipo === 'PAGAMENTO' ? 'Pagamento' : 'Recebimento'}` — no separador «Todos os
 * Registos» o antigo mostrava «Novo Recebimento» (renderMovimentosTesouraria, js/ui_tesouraria.js:507).
 */
export function botaoNovo(separador: Separador): { tipo: TipoDocumento; rotulo: string } {
  return separador === 'PAGAMENTO' ? { tipo: 'PAGAMENTO', rotulo: 'Novo Pagamento' } : { tipo: 'RECEBIMENTO', rotulo: 'Novo Recebimento' };
}

/**
 * Acções por linha do legado: Detalhes, Copiar operação, Carta de ordem de pagamento (só pagamentos em conta 42/43 não
 * anulados, com teso_carta_emitir — ui_tesouraria.js:599 do legado novo), Editar (cadeado se integrado), Excluir (= anular com motivo).
 */
export function accoesDocumento(
  d: DocumentoTesouraria,
  o: { podeEmitir: boolean; podeEliminar: boolean; abrir: (d: DocumentoTesouraria) => void; copiar: (d: DocumentoTesouraria) => void; editar: (d: DocumentoTesouraria) => void; excluir: (d: DocumentoTesouraria) => void; emitirCarta?: (d: DocumentoTesouraria) => void },
): AccaoLinha[] {
  const edicao = bloqueioEdicao(d);
  const exclusao = bloqueioExclusao(d);
  return [
    { chave: 'detalhes', icone: 'eye', titulo: 'Detalhes', onClick: () => o.abrir(d) },
    { chave: 'copiar', icone: 'copy', titulo: 'Copiar operação', cor: '#0891b2', oculto: !o.podeEmitir, onClick: () => o.copiar(d) },
    ...(o.emitirCarta && d.tipo === 'PAGAMENTO' && d.estado !== 'ANULADO' && eContaBancaria(d.conta_financeira)
      ? [{ chave: 'carta', icone: 'file-signature', titulo: 'Emitir carta de ordem de pagamento', cor: '#7c3aed', onClick: () => o.emitirCarta?.(d) }]
      : []),
    { chave: 'editar', icone: d.estado === 'INTEGRADO' ? 'lock' : 'edit', titulo: edicao || 'Editar', desactivado: edicao, oculto: !o.podeEmitir, onClick: () => o.editar(d) },
    { chave: 'excluir', icone: 'trash', titulo: exclusao || 'Excluir', variante: 'perigo', desactivado: exclusao, oculto: !o.podeEliminar, onClick: () => o.excluir(d) },
  ];
}

/**
 * Tesouraria › Operações, como no legado (renderMovimentosTesouraria): os separadores Pagamentos / Recebimentos / Todos os
 * Registos / Folha de Caixa estão no cabeçalho agregador (BarraTesouraria) e mudam o ?tipo=; aqui ficam o painel de
 * pesquisa (Pesquisa, De, Até, Buscar, Limpar), a barra de acções (Baixar Template, Carregar Dados, Eliminar Sel.,
 * Novo Pagamento/Recebimento) e a lista com as colunas e acções do legado.
 */
export function ListaDocumentos() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  // o menu e o agregador abrem este ecrã já filtrado (?tipo=…; sem dados pessoais na URL)
  const [parametros] = useSearchParams();
  const separador = separadorDaQuery(parametros.get('tipo'));
  const [estado, setEstado] = useState<EstadoDocumento>();
  const [conta, setConta] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [texto, setTexto] = useState('');
  const [pesquisa, setPesquisa] = useState('');
  const [seleccao, setSeleccao] = useState<DocumentoTesouraria[]>([]);
  const [importar, setImportar] = useState(false);
  useEffect(() => setSeleccao([]), [separador]);
  const tipo = separador === 'TODOS' ? undefined : (separador as TipoDocumento);
  const novo = botaoNovo(separador);
  const lote = useAccao<{ ok: { numero_documento: string | null }[]; erros: { numero_documento: string | null; mensagem: string }[] }>({
    invalidar: [['teso']],
    aoSucesso: (r) => {
      setSeleccao([]);
      if (r.erros.length) Modal.warning({ title: 'Alguns documentos não foram tratados', content: <ul>{r.erros.map((e, i) => <li key={i}><strong>{e.numero_documento ?? '—'}</strong>: {e.mensagem}</li>)}</ul> });
    },
  });
  const anularUm = useAccao({ invalidar: [['teso']] });
  const comMotivo = (titulo: string, url: string, ids: number[]) =>
    pedirMotivo({ titulo, texto: `${ids.length} documento(s); cada um é tratado na sua transacção.`, aoConfirmar: (motivo) => lote.mutateAsync({ url, dados: { ids, motivo } }) });
  const pendentes = seleccao.filter((d) => d.estado === 'PENDENTE').map((d) => d.id);
  const integrados = seleccao.filter((d) => d.estado === 'INTEGRADO').map((d) => d.id);
  const podeEmitir = pode('teso_doc_emitir');
  const podeEliminar = pode('teso_doc_eliminar');
  const { token } = theme.useToken();

  return (
    <>
      <div className="imp-nao-imprimir" style={{ background: token.colorFillAlter, border: `1px solid ${token.colorBorderSecondary}`, padding: '14px 16px', borderRadius: 4, marginBottom: 16 }}>
        <BarraFiltros
          accoes={
            <Space wrap>
              <Button type="primary" icon={<IconeFa nome="search" />} onClick={() => setPesquisa(texto)}>Buscar</Button>
              <Button icon={<IconeFa nome="undo" />} onClick={() => { setTexto(''); setPesquisa(''); setPeriodo(null); setEstado(undefined); setConta(undefined); }}>Limpar</Button>
            </Space>
          }
        >
          {/* «Pesquisa:» larga e «De / Até:» com rótulos, como o painel do legado (renderMovimentosTesouraria) */}
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, flex: 1, minWidth: 260 }}>
            <Typography.Text strong style={{ color: token.colorTextSecondary, whiteSpace: 'nowrap' }}><IconeFa nome="search" style={{ color: token.colorPrimary }} /> Pesquisa:</Typography.Text>
            <Input placeholder="Referência / Descrição..." allowClear value={texto} style={{ width: '100%' }} aria-label="Pesquisa"
              onChange={(e) => { setTexto(e.target.value); if (!e.target.value) setPesquisa(''); }} onPressEnter={() => setPesquisa(texto)} />
          </div>
          <Space size={6} wrap>
            <Typography.Text strong style={{ color: token.colorTextSecondary }}><IconeFa nome="calendar-alt" style={{ color: token.colorPrimary }} /> De / Até:</Typography.Text>
            <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} placeholder={['De', 'Até']} />
          </Space>
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={[{ value: 'PENDENTE', label: 'Por integrar' }, { value: 'INTEGRADO', label: 'Integrados' }, { value: 'ANULADO', label: 'Anulados' }]} />
          <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
        </BarraFiltros>
      </div>
      <Card style={{ marginBottom: 16 }} styles={{ body: { padding: '12px 16px' } }}>
        <Flex wrap gap="small" justify="space-between" align="center">
          <Space wrap>
            {podeEmitir && (
              <>
                <Button icon={<IconeFa nome="file-excel" />} style={{ color: '#059669', borderColor: '#059669' }} onClick={() => void descarregarModeloTesouraria()}>Baixar Template</Button>
                <Button icon={<IconeFa nome="upload" />} style={{ color: '#0891b2', borderColor: '#0891b2' }} onClick={() => setImportar(true)}>Carregar Dados</Button>
              </>
            )}
            {podeEliminar && (
              <Tooltip title="Apagar apenas os selecionados (se não integrados)">
                <Button danger icon={<IconeFa nome="trash-alt" />} disabled={!pendentes.length} onClick={() => comMotivo('Eliminar os documentos seleccionados (por integrar)', '/tesouraria/documentos/anular', pendentes)}>
                  Eliminar Sel.
                </Button>
              </Tooltip>
            )}
            {pode('teso_desintegrar') && integrados.length > 0 && (
              <Button danger icon={<IconeFa nome="undo" />} onClick={() => comMotivo('Anular a integração dos seleccionados (estorno)', '/tesouraria/documentos/desintegrar', integrados)}>
                Anular integração ({integrados.length})
              </Button>
            )}
          </Space>
          {podeEmitir && <Button type="primary" icon={<IconeFa nome="plus" />} onClick={() => navegar(`novo?tipo=${novo.tipo}`)}>{novo.rotulo}</Button>}
        </Flex>
      </Card>
      <Card>
        <TabelaDocumentos
          filtros={{ tipo, estado, conta_financeira: conta, pesquisa, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
          columns={colunasOperacoes(tipo)}
          accoes={(d) => accoesDocumento(d, {
            podeEmitir, podeEliminar,
            abrir: (x) => navegar(String(x.id)),
            copiar: (x) => navegar(`novo?copiar=${x.id}`),
            editar: (x) => navegar(`${x.id}/editar`),
            emitirCarta: pode('teso_carta_emitir') ? (x) => navegar(`/m/teso/teso_cartas?documento=${x.id}`) : undefined,
            excluir: (x) => pedirMotivo({
              titulo: `Excluir ${x.numero_documento ?? x.referencia ?? `#${x.id}`}?`,
              texto: 'O documento por integrar é anulado (fica no histórico com o motivo); não é apagado.',
              textoOk: 'Excluir',
              aoConfirmar: (motivo) => anularUm.mutateAsync({ url: `/tesouraria/documentos/${x.id}/anular`, dados: { motivo } }),
            }),
          })}
          rowSelection={{
            selectedRowKeys: seleccao.map((d) => d.id),
            preserveSelectedRowKeys: true,
            onChange: (_, linhas) => setSeleccao(linhas.filter(Boolean)),
            getCheckboxProps: (r) => ({ disabled: r.estado === 'ANULADO', 'aria-label': `Seleccionar ${r.numero_documento ?? r.id}` }),
          }}
          impressao={{
            titulo: tipo ? `Lista de ${ROTULO_TIPO[tipo].toLowerCase()}s` : 'Lista de pagamentos e recebimentos',
            periodo: textoPeriodo(periodo),
            orientacao: 'paisagem',
            filtros: [
              tipo && `Tipo: ${ROTULO_TIPO[tipo]}`,
              estado && `Estado: ${{ PENDENTE: 'Por integrar', INTEGRADO: 'Integrados', ANULADO: 'Anulados' }[estado]}`,
              conta && `Conta: ${conta}`,
              pesquisa && `Pesquisa: ${pesquisa}`,
              !tipo && 'Total: recebimentos menos pagamentos (sem anulados)',
            ],
            rotuloTotal: tipo ? 'Total' : 'Saldo',
          }}
          vazio={{ icone: 'money-bill-wave', titulo: 'Nenhum registo encontrado', texto: 'Use a pesquisa ou registe um novo documento.' }}
          onRow={(r) => ({ onDoubleClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
      <ModalImportacaoTesouraria aberto={importar} aoFechar={() => setImportar(false)} />
    </>
  );
}
