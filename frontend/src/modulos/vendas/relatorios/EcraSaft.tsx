import { Alert, Button, Card, Col, DatePicker, Flex, Row, Typography, message } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useState } from 'react';
import { descarregar, obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { TabelaComModos, type ColunaLegado } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { ValidacaoSaft } from './kpis';

const ROSA = '#db2777';

/** Resposta de GET /vendas/saft/analise (ServicoParidadeVendas::analiseSaft). */
export interface AnaliseSaft {
  periodo: { inicio: string; fim: string };
  sumario: { faturas: number; notas_credito: number; clientes: number; artigos: number; total_liquido: string; total_imposto: string; total_bruto: string };
  linhas: LinhaSaft[];
}

export interface LinhaSaft {
  venda_id: number;
  data: string;
  tipo_documento: string;
  documento: string;
  cliente: string;
  artigo: string;
  quantidade: string;
  preco_sem_iva: string;
  preco_com_iva: string;
  doc_liquido: string;
  doc_iva: string;
  doc_bruto: string;
  data_pagamento: string | null;
  meio_pagamento: string | null;
}

/** Colunas de «Dados Detalhados» do legado (previewSAFTData, js/ui_sales.js:10120). */
export const COLUNAS_SAFT: ColunaLegado<LinhaSaft>[] = [
  { title: 'Data', dataIndex: 'data', render: formatarData, valorImpressao: (l) => formatarData(l.data) },
  { title: 'Documento', dataIndex: 'documento' },
  { title: 'Cliente', dataIndex: 'cliente' },
  { title: 'Artigo', dataIndex: 'artigo' },
  { title: 'Qtd', dataIndex: 'quantidade', align: 'right', render: (v: string) => formatarNumero(Number(v)) },
  { title: 'P.Unit s/IVA', dataIndex: 'preco_sem_iva', align: 'right', render: (v: string) => formatarKz(v) },
  { title: 'P.Unit c/IVA', dataIndex: 'preco_com_iva', align: 'right', render: (v: string) => formatarKz(v) },
  { title: 'Doc. Liq', dataIndex: 'doc_liquido', align: 'right', render: (v: string) => formatarKz(v) },
  { title: 'Doc. IVA', dataIndex: 'doc_iva', align: 'right', render: (v: string) => formatarKz(v) },
  { title: 'Doc. Bruto', dataIndex: 'doc_bruto', align: 'right', render: (v: string) => formatarKz(v) },
  { title: 'Dt. Pag.', dataIndex: 'data_pagamento', render: (v: string | null) => (v ? formatarData(v) : '—'), valorImpressao: (l) => (l.data_pagamento ? formatarData(l.data_pagamento) : '') },
  { title: 'M. Pag.', dataIndex: 'meio_pagamento', render: (v: string | null) => v ?? '—' },
];

/**
 * Paridade 4.6.1 — separador «Exportar SAFT-AO» do legado («Ficheiro Fiscal SAFT-AO»): parâmetros do lote (datas),
 * «Analisar Registos» (sumário do lote fiscal e dados detalhados exportáveis para Excel, calculados no servidor) e
 * «Gerar e Descarregar XML» (o gerador SAF-T existente: valida primeiro e só depois descarrega).
 */
export function EcraSaft() {
  const { pode } = useSessao();
  const [datas, setDatas] = useState<[Dayjs, Dayjs]>([dayjs().startOf('month'), dayjs().endOf('month')]);
  const [periodo, setPeriodo] = useState<{ inicio: string; fim: string }>({ inicio: dataApi(datas[0]) as string, fim: dataApi(datas[1]) as string });
  const [aGerar, setAGerar] = useState(false);
  const permitido = pode('vendas_relatorios_view', 'vendas_fe_config');

  const analise = useQuery({
    queryKey: ['vendas', 'saft', 'analise', periodo.inicio, periodo.fim],
    queryFn: () => obter<AnaliseSaft>('/vendas/saft/analise', periodo),
    enabled: permitido,
    staleTime: 60_000,
    retry: false,
  });

  const analisar = () => {
    const p = { inicio: dataApi(datas[0]) as string, fim: dataApi(datas[1]) as string };
    if (p.inicio === periodo.inicio && p.fim === periodo.fim) void analise.refetch();
    else setPeriodo(p);
  };

  const gerar = async () => {
    const inicio = dataApi(datas[0]) as string;
    const fim = dataApi(datas[1]) as string;
    setAGerar(true);
    try {
      const v = await obter<ValidacaoSaft>('/vendas/saft/validar', { inicio, fim });
      const d = await descarregar('/vendas/saft', { inicio, fim }, v.nome || `SAFT_AO_${inicio}_${fim}.xml`);
      message.success(`SAF-T gerado: ${d.cabecalhos['x-saft-documentos'] ?? v.documentos} documento(s), ${d.cabecalhos['x-saft-recibos'] ?? v.recibos} recibo(s).`);
      const avisos = d.cabecalhos['x-saft-avisos'] ? decodeURIComponent(d.cabecalhos['x-saft-avisos']) : v.avisos.join(' | ');
      if (avisos) message.warning(`Avisos: ${avisos}`, 10);
    } catch (e) {
      notificarErro(e, 'Não foi possível gerar o SAF-T');
    } finally {
      setAGerar(false);
    }
  };

  if (!permitido) return <Alert type="info" showIcon message="Sem permissão para exportar o SAF-T (AO)." />;
  const s = analise.data?.sumario;

  return (
    <>
      <div style={{ background: 'linear-gradient(135deg, #db2777 0%, #be185d 100%)', color: '#fff', borderRadius: 20, padding: 24, marginBottom: 24, position: 'relative', overflow: 'hidden' }}>
        <IconeFa nome="file-code" style={{ position: 'absolute', right: -10, bottom: -20, fontSize: 120, opacity: 0.12, transform: 'rotate(-15deg)' }} />
        <h3 style={{ margin: '0 0 8px', fontSize: 22, fontWeight: 800, color: '#fff' }}><IconeFa nome="file-invoice-dollar" /> Ficheiro Fiscal SAFT-AO</h3>
        <p style={{ margin: 0, fontSize: 14, opacity: 0.95, maxWidth: 650 }}>
          Gere e descarregue o ficheiro XML de auditoria tributária (SAF-T AO) com os documentos comerciais e os recibos do período escolhido.
        </p>
      </div>

      <Card style={{ borderRadius: 20, marginBottom: 24 }}>
        <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em' }}>
          <IconeFa nome="filter" style={{ color: ROSA }} /> Parâmetros do Lote de Faturação
        </h4>
        <Row gutter={16}>
          <Col xs={24} sm={12}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 800, color: '#64748b', textTransform: 'uppercase', marginBottom: 6 }} htmlFor="saft-inicio">Data de Início</label>
            <DatePicker id="saft-inicio" format="DD/MM/YYYY" allowClear={false} value={datas[0]} onChange={(d) => d && setDatas([d, datas[1]])} style={{ width: '100%' }} />
          </Col>
          <Col xs={24} sm={12}>
            <label style={{ display: 'block', fontSize: 12, fontWeight: 800, color: '#64748b', textTransform: 'uppercase', marginBottom: 6 }} htmlFor="saft-fim">Data de Fim</label>
            <DatePicker id="saft-fim" format="DD/MM/YYYY" allowClear={false} value={datas[1]} onChange={(d) => d && setDatas([datas[0], d])} style={{ width: '100%' }} />
          </Col>
        </Row>
        <Flex justify="space-between" wrap gap={12} style={{ borderTop: '1px solid #f1f5f9', marginTop: 20, paddingTop: 20 }}>
          <Button icon={<IconeFa nome="search-dollar" />} loading={analise.isFetching} onClick={analisar} disabled={datas[0].isAfter(datas[1])}>Analisar Registos</Button>
          <Button type="primary" icon={<IconeFa nome="file-download" />} loading={aGerar} onClick={() => void gerar()} disabled={datas[0].isAfter(datas[1])}>Gerar e Descarregar XML</Button>
        </Flex>
        {datas[0].isAfter(datas[1]) && <Alert type="warning" showIcon style={{ marginTop: 12 }} message="A data de início não pode ser posterior à data de fim." />}
      </Card>

      {analise.error ? (
        <Alert type="error" showIcon message="Não foi possível analisar os registos." description={analise.error.message} />
      ) : s && analise.data ? (
        <Card style={{ borderRadius: 20 }}>
          <h4 style={{ margin: '0 0 16px', fontSize: 14, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', borderBottom: '1px solid #f1f5f9', paddingBottom: 12 }}>
            <IconeFa nome="chart-bar" style={{ color: ROSA }} /> Sumário do Lote Fiscal
            <Typography.Text type="secondary" style={{ fontWeight: 400, textTransform: 'none', marginLeft: 8 }}>
              {formatarData(analise.data.periodo.inicio)} a {formatarData(analise.data.periodo.fim)}
            </Typography.Text>
          </h4>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(150px, 1fr))', gap: 16, marginBottom: 20 }}>
            {[
              ['Faturas', s.faturas, '#1e293b'],
              ['Notas CRÉDITO', s.notas_credito, '#ef4444'],
              ['Clientes', s.clientes, '#3b82f6'],
              ['Artigos', s.artigos, '#7c3aed'],
            ].map(([r, v, cor]) => (
              <div key={r as string} style={{ background: '#f8fafc', border: '1px solid #e2e8f0', borderRadius: 14, padding: 16, textAlign: 'center' }}>
                <span style={{ fontSize: 11, color: '#64748b', textTransform: 'uppercase', fontWeight: 800, display: 'block', marginBottom: 4 }}>{r}</span>
                <strong style={{ fontSize: 22, color: cor as string, fontWeight: 800 }}>{v}</strong>
              </div>
            ))}
          </div>
          <div style={{ background: '#fffbeb', border: '1px solid #fef3c7', borderRadius: 14, padding: 16, display: 'flex', flexDirection: 'column', gap: 8, fontSize: 14, color: '#451a03', fontWeight: 600 }}>
            <Flex justify="space-between" style={{ borderBottom: '1px dashed #fde68a', paddingBottom: 6 }}><span>Total Líquido do Lote:</span><span style={{ color: '#1e293b', fontWeight: 700 }}>{formatarKz(s.total_liquido)} Kz</span></Flex>
            <Flex justify="space-between" style={{ borderBottom: '1px dashed #fde68a', paddingBottom: 6 }}><span>Total IVA Liquidado:</span><span style={{ color: '#059669', fontWeight: 700 }}>{formatarKz(s.total_imposto)} Kz</span></Flex>
            <Flex justify="space-between" style={{ fontSize: 16, fontWeight: 900, color: '#1e293b' }}><span>Valor Bruto Total a Declarar:</span><span style={{ color: ROSA }}>{formatarKz(s.total_bruto)} Kz</span></Flex>
          </div>
          <div style={{ marginTop: 24, borderTop: '1px solid #e2e8f0', paddingTop: 20 }}>
            <h4 style={{ margin: '0 0 8px', fontSize: 14, fontWeight: 800 }}><IconeFa nome="table" /> Dados Detalhados</h4>
            <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
              Uma linha por artigo de cada documento que consta do ficheiro SAF-T (use «Excel» para exportar).
            </Typography.Paragraph>
            <TabelaComModos<LinhaSaft>
              rowKey={(l) => `${l.venda_id}-${l.artigo}-${l.quantidade}-${l.preco_sem_iva}`}
              dataSource={analise.data.linhas}
              columns={COLUNAS_SAFT}
              modos={false}
              size="small"
              scroll={{ x: 'max-content' }}
              impressao={{
                titulo: 'SAF-T (AO) — dados detalhados',
                periodo: `${formatarData(analise.data.periodo.inicio)} a ${formatarData(analise.data.periodo.fim)}`,
                orientacao: 'paisagem',
                nomeFicheiro: `SAFT_AO_detalhe_${analise.data.periodo.inicio}_${analise.data.periodo.fim}`,
              }}
              vazio={{ icone: 'file-code', titulo: 'Sem documentos no período.', texto: 'Escolha outras datas e carregue em «Analisar Registos».' }}
            />
          </div>
        </Card>
      ) : null}
    </>
  );
}
