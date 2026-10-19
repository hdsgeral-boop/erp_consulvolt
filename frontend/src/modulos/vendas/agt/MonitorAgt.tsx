import { Alert, Button, Card, Flex, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { scrollTabela } from '@/componentes/responsivo';
import { TabelaApi } from '@/componentes/TabelaApi';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora, formatarKz } from '@/utilitarios/formatacao';
import { INFO_ESTADO_AGT, normalizarMensagensAgt } from './estadoAgt';

export interface LinhaMonitor {
  id: number;
  tipo_documento: string;
  numero_documento: string;
  data_emissao: string | null;
  total_bruto: string;
  estado: string | null;
  horas: number | null;
  tentativas: number;
  proxima_tentativa: string | null;
  contingencia_desde: string | null;
  request_id: string | null;
  erros: unknown[];
  substituido_por: string | null;
}

export interface MonitorAgtDados {
  resumo: Record<string, number>;
  por_comunicar: LinhaMonitor[];
  recusados: LinhaMonitor[];
  lotes_incertos: { id: number; submission_uuid: string; documentos: string[] | null; estado: string; tentativas: number; ultimo_erro: string | null; proxima_tentativa_em: string | null }[];
  comunicacoes_24h: { total: number; falhas: number };
  alertas: { nivel: 'erro' | 'aviso'; mensagem: string }[];
  limite_horas: number;
}

interface Comunicacao {
  id: number;
  operacao: string;
  ambiente: string | null;
  endpoint: string | null;
  http_estado: number | null;
  sucesso: boolean;
  codigo_erro: string | null;
  mensagem: string | null;
  duracao_ms: number | null;
  tentativa: number;
  submission_uuid: string | null;
  request_id: string | null;
  documentos: string[] | null;
  pedido: unknown;
  resposta: unknown;
  utilizador: string | null;
  criado_em: string;
}

/** Texto dos erros de um documento (AGT e validador local). */
export function textoErros(erros: unknown[]): string {
  return normalizarMensagensAgt(erros).map((m) => (m.codigo ? `${m.codigo} ${m.mensagem}` : m.mensagem)).join(' | ');
}

/** Documentos por comunicar há mais horas do que o limite (exclui os já enviados, à espera de validação). */
export function atrasados(linhas: LinhaMonitor[], limite: number): number {
  return linhas.filter((l) => l.estado !== 'ENVIADO' && (l.horas ?? 0) >= limite).length;
}

const OPERACOES = ['registarFactura', 'obterEstado', 'consultarFactura', 'solicitarSerie', 'listarSeries', 'webhook'];

/**
 * Monitorização da facturação electrónica por empresa (r4_agt): por comunicar (idade, tentativas, contingência),
 * recusados com o motivo e a correcção (novo n.º, E46), lotes com resultado incerto (reenvio idempotente) e o registo de
 * comunicações (pedido/resposta mascarados).
 */
export function MonitorAgt() {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const consulta = useQuery({ queryKey: ['vendas', 'fe', 'monitor'], queryFn: () => obter<MonitorAgtDados>('/vendas/faturacao-eletronica/monitor'), refetchInterval: 60_000 });
  const accao = useAccao<{ id: number; numero_documento: string }>({ invalidar: [['vendas']], aoSucesso: (d) => d?.id && navegar(`/m/vendas/vendas_faturacao/${d.id}`) });
  const [filtro, setFiltro] = useState<{ operacao?: string; sucesso?: boolean }>({});
  const [detalhe, setDetalhe] = useState<Comunicacao | null>(null);
  const m = consulta.data;
  const r = m?.resumo ?? {};

  const colunasDoc = (correccao: boolean) => [
    { title: 'Documento', dataIndex: 'numero_documento', render: (v: string, l: LinhaMonitor) => <Button type="link" style={{ padding: 0, fontFamily: 'monospace' }} onClick={() => navegar(`/m/vendas/vendas_faturacao/${l.id}`)}>{v}</Button> },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => (e ? <Tag color={INFO_ESTADO_AGT[e]?.cor}>{INFO_ESTADO_AGT[e]?.rotulo ?? e}</Tag> : '—') },
    { title: 'Total', dataIndex: 'total_bruto', align: 'right' as const, responsive: ['md' as const], render: (v: string) => formatarKz(v) },
    { title: 'Há', dataIndex: 'horas', align: 'right' as const, render: (h: number | null) => (h === null ? '—' : <span style={{ fontWeight: h >= (m?.limite_horas ?? 20) ? 700 : undefined }}>{h} h</span>) },
    { title: 'Tentativas', dataIndex: 'tentativas', align: 'right' as const, responsive: ['lg' as const] },
    { title: 'Motivo', key: 'erros', className: 'erp-quebra', render: (_: unknown, l: LinhaMonitor) => textoErros(l.erros) || (l.contingencia_desde ? `Em contingência desde ${formatarDataHora(l.contingencia_desde)}` : '—') },
    ...(correccao && pode('vendas_agt_enviar')
      ? [{
          title: '',
          key: 'accao',
          render: (_: unknown, l: LinhaMonitor) => (
            <Button size="small" danger onClick={() => Modal.confirm({
              title: `Emitir o documento de correcção de ${l.numero_documento}?`,
              content: 'É emitido um documento novo (novo número), enviado à AGT como correcção (C) do recusado (regra E46). Corrija antes os dados de cliente e produtos.',
              okText: 'Emitir correcção',
              cancelText: 'Cancelar',
              onOk: () => accao.mutateAsync({ url: `/vendas/documentos/${l.id}/correccao-agt` }),
            })}>
              Emitir correcção
            </Button>
          ),
        }]
      : []),
  ];

  return (
    <Card title={<><IconeFa nome="heart-pulse" style={{ marginRight: 6 }} />Monitorização da comunicação com a AGT</>} loading={consulta.isLoading}>
      {consulta.error && <Alert type="error" showIcon message="Não foi possível obter a monitorização." />}
      {m && (
        <>
          <GrelhaKpi larguraMinima={200} style={{ marginBottom: 16 }}>
            <CartaoKpi rotulo="Por comunicar" valor={(r.POR_ENVIAR ?? 0) + (r.ERRO ?? 0)} icone="paper-plane" cor="azul" secundaria={`${r.ENVIADO ?? 0} a aguardar validação`} />
            <CartaoKpi rotulo="Em contingência" valor={r.CONTINGENCIA ?? 0} icone="plug-circle-exclamation" cor={(r.CONTINGENCIA ?? 0) ? 'laranja' : 'verde'} secundaria="Reenvio automático, sem duplicados" />
            <CartaoKpi rotulo="Recusados pela AGT" valor={(r.INVALIDO ?? 0) + (r.REJEITADO ?? 0)} icone="ban" cor={(r.INVALIDO ?? 0) + (r.REJEITADO ?? 0) ? 'vermelho' : 'verde'} secundaria={`${r.SUBSTITUIDO ?? 0} já corrigido(s)`} />
            <CartaoKpi rotulo="Comunicações (24 h)" valor={m.comunicacoes_24h.total} icone="exchange-alt" cor={m.comunicacoes_24h.falhas ? 'laranja' : 'verde'} secundaria={`${m.comunicacoes_24h.falhas} falha(s)`} />
          </GrelhaKpi>
          {m.alertas.map((a) => (
            <Alert key={a.mensagem} type={a.nivel === 'erro' ? 'error' : 'warning'} showIcon style={{ marginBottom: 12 }} message={a.mensagem} />
          ))}
          <Typography.Title level={5}>Recusados pela AGT</Typography.Title>
          <Table<LinhaMonitor> rowKey="id" size="small" pagination={false} dataSource={m.recusados} scroll={scrollTabela()} columns={colunasDoc(true)}
            locale={{ emptyText: 'Nenhum documento recusado.' }} />
          <Typography.Title level={5} style={{ marginTop: 16 }}>Por comunicar e a aguardar validação</Typography.Title>
          <Table<LinhaMonitor> rowKey="id" size="small" pagination={{ pageSize: 20, hideOnSinglePage: true }} dataSource={m.por_comunicar} scroll={scrollTabela()} columns={colunasDoc(false)}
            locale={{ emptyText: 'Tudo comunicado.' }} />
          {m.lotes_incertos.length > 0 && (
            <Alert
              type="info"
              showIcon
              style={{ marginTop: 16 }}
              message={`${m.lotes_incertos.length} lote(s) com resultado incerto: são reenviados com o mesmo identificador (submissionUUID), sem risco de duplicar documentos.`}
              description={m.lotes_incertos.map((l) => `${l.submission_uuid} · ${l.documentos?.length ?? 0} doc. · ${l.tentativas} tentativa(s)${l.proxima_tentativa_em ? ` · próxima ${formatarDataHora(l.proxima_tentativa_em)}` : ''}`).join('\n')}
            />
          )}
        </>
      )}
      {pode('vendas_agt_registos') && (
        <>
          <Flex justify="space-between" align="center" wrap gap={8} style={{ marginTop: 24, marginBottom: 8 }}>
            <Typography.Title level={5} style={{ margin: 0 }}>Registo de comunicações</Typography.Title>
            <Space wrap>
              <Select allowClear placeholder="Operação" style={{ minWidth: 180 }} value={filtro.operacao} onChange={(operacao) => setFiltro((f) => ({ ...f, operacao }))}
                options={OPERACOES.map((o) => ({ value: o, label: o }))} />
              <Select allowClear placeholder="Resultado" style={{ minWidth: 140 }} value={filtro.sucesso === undefined ? undefined : String(filtro.sucesso)}
                onChange={(v?: string) => setFiltro((f) => ({ ...f, sucesso: v === undefined ? undefined : v === 'true' }))}
                options={[{ value: 'true', label: 'Sucesso' }, { value: 'false', label: 'Falha' }]} />
            </Space>
          </Flex>
          <TabelaApi<Comunicacao>
            url="/vendas/faturacao-eletronica/comunicacoes"
            chaveConsulta={['vendas', 'fe', 'comunicacoes']}
            filtros={{ operacao: filtro.operacao, sucesso: filtro.sucesso === undefined ? undefined : filtro.sucesso ? 1 : 0 }}
            modos={false}
            size="small"
            rowKey="id"
            onRow={(c) => ({ onClick: () => setDetalhe(c), style: { cursor: 'pointer' } })}
            columns={[
              { title: 'Quando', dataIndex: 'criado_em', render: (v: string) => formatarDataHora(v) },
              { title: 'Operação', dataIndex: 'operacao' },
              { title: 'HTTP', dataIndex: 'http_estado', align: 'right', render: (v: number | null, c) => <Tag color={c.sucesso ? 'green' : 'red'}>{v ?? 'sem ligação'}</Tag> },
              { title: 'Código', dataIndex: 'codigo_erro', render: (v: string | null) => v ?? '—' },
              { title: 'Mensagem', dataIndex: 'mensagem', className: 'erp-quebra', render: (v: string | null) => v ?? '—' },
              { title: 'Duração', dataIndex: 'duracao_ms', align: 'right', render: (v: number | null) => (v === null ? '—' : `${v} ms`) },
              { title: 'Tent.', dataIndex: 'tentativa', align: 'right' },
              { title: 'Documentos', dataIndex: 'documentos', render: (v: string[] | null) => (v?.length ? v.join(', ') : '—') },
            ]}
            vazio={{ icone: 'exchange-alt', titulo: 'Sem comunicações', texto: 'Ainda não houve pedidos à AGT com estes filtros.' }}
          />
          <Modal open={detalhe !== null} title={detalhe ? `${detalhe.operacao} · ${formatarDataHora(detalhe.criado_em)}` : ''} width="min(900px, 96vw)" footer={null} onCancel={() => setDetalhe(null)}>
            {detalhe && (
              <>
                <Typography.Paragraph type="secondary">Assinaturas, credenciais e NIF de clientes aparecem mascarados.</Typography.Paragraph>
                <Typography.Text strong>Pedido</Typography.Text>
                <pre style={{ maxHeight: 280, overflow: 'auto', fontSize: 12 }}>{JSON.stringify(detalhe.pedido, null, 2)}</pre>
                <Typography.Text strong>Resposta</Typography.Text>
                <pre style={{ maxHeight: 280, overflow: 'auto', fontSize: 12 }}>{JSON.stringify(detalhe.resposta, null, 2)}</pre>
              </>
            )}
          </Modal>
        </>
      )}
    </Card>
  );
}
