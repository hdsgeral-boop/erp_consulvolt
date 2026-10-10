import { Alert, Button, Card, Col, DatePicker, Input, InputNumber, Modal, Row, Select, Tooltip, Typography } from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import type { AccaoLinha } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi } from '@/utilitarios/formatacao';
import { ROTULO_TIPO, type DocumentoTesouraria, type TipoDocumento } from './api';
import { bloqueioAnulacao, colunasHistorico, urlSegura } from './colunasLegado';
import { TabelaDocumentos } from './comum';
import { ModalReparacao } from './ModalReparacao';
import { BannerTeso, pedirMotivo } from './motivo';
import { DetalheDocumento } from './pagamentos/DetalheDocumento';
import { textoPeriodo } from './pagamentos/ListaDocumentos';

/** Tesouraria › Histórico / Anulação (ecrã teso_contab_historico): documentos integrados e descontabilização (estorno). */
export default function Historico() {
  return (
    <Routes>
      <Route index element={<Integrados />} />
      <Route path=":id" element={<DetalheDocumento permitirEdicao={false} />} />
    </Routes>
  );
}

/** Filtros de Pesquisa do legado (applyTesoHistoryFilters). */
export interface FiltrosHistorico {
  periodo?: [Dayjs | null, Dayjs | null] | null;
  valor?: number | null;
  referencia?: string;
  tipo?: TipoDocumento;
  contaTesouraria?: string;
  contaContrapartida?: string;
}

/** Contas aceites nos filtros (C. Tesouraria / C. Contrapartida): só dígitos e pontos, como o servidor valida. */
export const CONTA_VALIDA = /^[0-9.]*$/;

/** Parâmetros do GET /tesouraria/documentos para os filtros do Histórico (vazios não seguem). */
export function parametrosHistorico(f: FiltrosHistorico): Record<string, unknown> {
  return {
    estado: 'INTEGRADO',
    tipo: f.tipo,
    pesquisa: f.referencia?.trim() || undefined,
    valor: f.valor ?? undefined,
    conta_prefixo: f.contaTesouraria && CONTA_VALIDA.test(f.contaTesouraria) ? f.contaTesouraria : undefined,
    contrapartida_prefixo: f.contaContrapartida && CONTA_VALIDA.test(f.contaContrapartida) ? f.contaContrapartida : undefined,
    data_inicio: dataApi(f.periodo?.[0]),
    data_fim: dataApi(f.periodo?.[1]),
  };
}

/** Acções por linha do legado: «Anular este documento» (bloqueado se reconciliado com o banco) e «Abrir documento externo». */
export function accoesHistorico(d: DocumentoTesouraria, o: { podeAnular: boolean; anular: (d: DocumentoTesouraria) => void }): AccaoLinha[] {
  const bloqueio = bloqueioAnulacao(d);
  const url = urlSegura(d.url_documento);
  return [
    bloqueio
      ? { chave: 'bloqueado', icone: 'ban', titulo: bloqueio, texto: 'Bloqueado', variante: 'perigo', desactivado: bloqueio, oculto: !o.podeAnular }
      : { chave: 'anular', icone: 'undo', titulo: 'Anular este documento', texto: 'Anular', variante: 'perigo', oculto: !o.podeAnular, onClick: () => o.anular(d) },
    { chave: 'externo', icone: 'external-link-alt', titulo: 'Abrir documento externo', texto: 'Doc', variante: 'info', oculto: !url, onClick: () => url && window.open(url, '_blank', 'noopener,noreferrer') },
  ];
}

/**
 * Ferramentas do legado que NÃO são necessárias no sistema novo (verificação visual 7.4.1). Os botões mantêm-se para quem
 * os procura, mas só explicam porquê — não alteram dados.
 */
export const FERRAMENTAS_DISPENSADAS = {
  recuperar: {
    titulo: 'Recuperar Outros Módulos',
    icone: 'sync-alt',
    cor: '#0ea5e9',
    texto:
      'No sistema anterior, apagar linhas do Diário deixava vendas, compras, folhas de salários e amortizações marcadas como contabilizadas sem lançamento, e esta ferramenta repunha-as. ' +
      'No sistema novo as linhas do Diário não se apagam: a descontabilização é sempre um estorno feito no próprio módulo (Vendas, Compras, RH, Activos), que repõe o estado do documento na mesma transacção. ' +
      'Não há, por isso, documentos órfãos a recuperar noutros módulos. Para a Tesouraria use «Reparar Inconsistências».',
  },
  datas: {
    titulo: 'Reparar Datas',
    icone: 'calendar-check',
    cor: '#8b5cf6',
    texto:
      'No sistema anterior, esta ferramenta reescrevia directamente a data de lançamentos de salários, amortizações e imposto de selo para o último dia do período. ' +
      'No sistema novo esses lançamentos já são gerados com a data do fim do período, e alterar datas no Diário sem estorno contornaria o fecho de exercícios e a auditoria. ' +
      'Se um lançamento tiver a data errada, descontabilize-o no módulo de origem e volte a contabilizá-lo.',
  },
} as const;

const rotulo = (t: string) => <label style={{ fontSize: 12, fontWeight: 600, display: 'block', marginBottom: 2 }}>{t}</label>;

function Integrados() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const podeAnular = pode('teso_desintegrar');
  const [rascunho, setRascunho] = useState<FiltrosHistorico>({});
  const [filtros, setFiltros] = useState<FiltrosHistorico>({});
  const [seleccao, setSeleccao] = useState<DocumentoTesouraria[]>([]);
  const [reparacao, setReparacao] = useState<'inconsistencias' | 'resgatar' | null>(null);
  const anularUm = useAccao({ invalidar: [['teso'], ['contab']] });
  const lote = useAccao<{ erros: { numero_documento: string | null; mensagem: string }[] }>({
    invalidar: [['teso'], ['contab']],
    aoSucesso: (r) => {
      setSeleccao([]);
      if (r.erros?.length) Modal.warning({ title: 'Alguns documentos não foram anulados', content: <ul>{r.erros.map((e, i) => <li key={i}><strong>{e.numero_documento ?? '—'}</strong>: {e.mensagem}</li>)}</ul> });
    },
  });
  const anular = (d: DocumentoTesouraria) =>
    pedirMotivo({
      titulo: `Anular a contabilização de ${d.numero_documento ?? d.referencia ?? `#${d.id}`}?`,
      texto: 'É registado um estorno no Diário e o documento volta a «por integrar».',
      textoOk: 'Anular',
      aoConfirmar: (motivo) => anularUm.mutateAsync({ url: `/tesouraria/documentos/${d.id}/desintegrar`, dados: { motivo } }),
    });
  const anularSeleccionados = () =>
    pedirMotivo({
      titulo: `Anular (descontabilizar) ${seleccao.length} documento(s) seleccionado(s)?`,
      texto: 'Cada documento é estornado na sua transacção; os reconciliados com o banco são recusados.',
      textoOk: 'Anular seleccionados',
      aoConfirmar: (motivo) => lote.mutateAsync({ url: '/tesouraria/documentos/desintegrar', dados: { ids: seleccao.map((d) => d.id), motivo } }),
    });
  const muda = (p: Partial<FiltrosHistorico>) => setRascunho((r) => ({ ...r, ...p }));
  const contasValidas = CONTA_VALIDA.test(rascunho.contaTesouraria ?? '') && CONTA_VALIDA.test(rascunho.contaContrapartida ?? '');

  return (
    <>
      <BannerTeso
        icone="history"
        titulo="Histórico / Descontabilização"
        texto="Consulte documentos já integrados e anule a contabilização se necessário."
        cor="#4f46e5"
        fundo="#f5f3ff"
        corTitulo="#1e1b4b"
        accoes={podeAnular && (
          <>
            <Tooltip title="Anular (Descontabilizar) todos os documentos selecionados abaixo">
              <Button danger type="primary" style={{ fontWeight: 700 }} icon={<IconeFa nome="undo" />} disabled={!seleccao.length} onClick={anularSeleccionados}>ANULAR SELECIONADOS</Button>
            </Tooltip>
            {Object.values(FERRAMENTAS_DISPENSADAS).map((f) => (
              <Tooltip key={f.titulo} title="Não é necessária no sistema novo — clique para saber porquê">
                <Button style={{ color: f.cor, fontWeight: 600 }} icon={<IconeFa nome={f.icone} />}
                  onClick={() => Modal.info({ title: f.titulo, content: f.texto, okText: 'Fechar', width: 560 })}>{f.titulo}</Button>
              </Tooltip>
            ))}
            <Tooltip title="Detecta e corrige documentos INTEGRADOS que perderam os lançamentos no Diário (ex.: lançamento estornado directamente na Contabilidade)">
              <Button style={{ color: '#f59e0b', borderColor: '#fde68a', fontWeight: 600 }} icon={<IconeFa nome="tools" />} onClick={() => setReparacao('inconsistencias')}>Reparar Inconsistências</Button>
            </Tooltip>
            <Tooltip title="Libertar manualmente um documento específico pelo seu número">
              <Button style={{ color: '#64748b' }} icon={<IconeFa nome="life-ring" />} onClick={() => setReparacao('resgatar')}>Resgatar Documento</Button>
            </Tooltip>
          </>
        )}
      />

      <Card size="small" style={{ marginBottom: 16 }} title={<span><IconeFa nome="filter" /> Filtros de Pesquisa</span>} styles={{ body: { background: '#f8fafc' } }}>
        <Row gutter={[10, 10]} align="bottom">
          <Col xs={12} md={6} lg={5}>{rotulo('Data Inicial / Data Final')}
            <DatePicker.RangePicker format="DD/MM/YYYY" style={{ width: '100%' }} value={rascunho.periodo ?? null} onChange={(v) => muda({ periodo: v })} placeholder={['Data Inicial', 'Data Final']} />
          </Col>
          <Col xs={12} md={4} lg={3}>{rotulo('Valor (Kz)')}<InputNumber style={{ width: '100%' }} min={0} step={0.01} placeholder="Ex: 1500" value={rascunho.valor ?? null} onChange={(v) => muda({ valor: v })} /></Col>
          <Col xs={12} md={4} lg={3}>{rotulo('Referência')}<Input placeholder="RC 2026/1" maxLength={100} value={rascunho.referencia} onChange={(e) => muda({ referencia: e.target.value })} /></Col>
          <Col xs={12} md={4} lg={3}>{rotulo('Tipo Mov.')}
            <Select style={{ width: '100%' }} value={rascunho.tipo ?? 'ALL'} onChange={(v) => muda({ tipo: v === 'ALL' ? undefined : (v as TipoDocumento) })}
              options={[{ value: 'ALL', label: 'Todos' }, { value: 'PAGAMENTO', label: 'Pagamento' }, { value: 'RECEBIMENTO', label: 'Recebimento' }]} />
          </Col>
          <Col xs={12} md={3} lg={3}>{rotulo('C. Tesouraria')}<Input placeholder="Ex: 43.1.1" maxLength={20} value={rascunho.contaTesouraria} status={CONTA_VALIDA.test(rascunho.contaTesouraria ?? '') ? undefined : 'error'} onChange={(e) => muda({ contaTesouraria: e.target.value.trim() })} /></Col>
          <Col xs={12} md={3} lg={3}>{rotulo('C. Contrapartida')}<Input placeholder="Ex: 31.1.2.1" maxLength={20} value={rascunho.contaContrapartida} status={CONTA_VALIDA.test(rascunho.contaContrapartida ?? '') ? undefined : 'error'} onChange={(e) => muda({ contaContrapartida: e.target.value.trim() })} /></Col>
          <Col xs={24} md={24} lg={4} style={{ display: 'flex', gap: 6 }}>
            <Tooltip title="Limpar Filtros"><Button aria-label="Limpar Filtros" icon={<IconeFa nome="eraser" />} onClick={() => { setRascunho({}); setFiltros({}); }} /></Tooltip>
            <Button type="primary" icon={<IconeFa nome="search" />} disabled={!contasValidas} onClick={() => setFiltros(rascunho)}>Pesquisar</Button>
          </Col>
        </Row>
      </Card>

      {!podeAnular && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Não tem permissão para anular integrações: só consulta." />}
      <Card title="Documentos Integrados na Contabilidade">
        <TabelaDocumentos
          filtros={parametrosHistorico(filtros)}
          columns={colunasHistorico((d) => navegar(String(d.id)))}
          accoes={(d) => accoesHistorico(d, { podeAnular, anular })}
          rowSelection={podeAnular ? {
            selectedRowKeys: seleccao.map((d) => d.id),
            preserveSelectedRowKeys: true,
            onChange: (_, linhas) => setSeleccao(linhas.filter(Boolean)),
            getCheckboxProps: (r) => ({ disabled: !!bloqueioAnulacao(r), title: bloqueioAnulacao(r) || undefined, 'aria-label': `Seleccionar ${r.numero_documento ?? r.id}` }),
          } : undefined}
          impressao={{
            titulo: 'Documentos de tesouraria integrados',
            periodo: textoPeriodo(filtros.periodo ?? null),
            orientacao: 'paisagem',
            filtros: [filtros.tipo && `Tipo: ${ROTULO_TIPO[filtros.tipo]}`, filtros.contaTesouraria && `C. Tesouraria: ${filtros.contaTesouraria}`,
              filtros.contaContrapartida && `C. Contrapartida: ${filtros.contaContrapartida}`, filtros.referencia && `Referência: ${filtros.referencia}`],
          }}
          vazio={{ icone: 'history', titulo: 'Nenhum documento integrado', texto: 'Ajuste os filtros e clique em «Pesquisar».' }}
          onRow={(r) => ({ onDoubleClick: () => navegar(String(r.id)) })}
        />
        <Typography.Text type="secondary">Duplo clique (ou clique na referência) para ver o detalhe do documento.</Typography.Text>
      </Card>
      {reparacao && <ModalReparacao modo={reparacao} aberto aoFechar={() => setReparacao(null)} />}
    </>
  );
}
