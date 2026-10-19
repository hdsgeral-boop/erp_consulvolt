import { Alert, Button, Card, DatePicker, Form, Modal, Progress, Select, Tabs, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { BarraFiltros } from '@/componentes/responsivo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import { SeletorConta } from '../contab/comum/Seletores';
import type { DocumentoTesouraria, ResultadoIntegracaoLote, TipoDocumento } from './api';
import { SeletorContaFinanceira, TabelaDocumentos, useTotalDocumentos } from './comum';
import { DetalheDocumento } from './pagamentos/DetalheDocumento';
import { textoPeriodo } from './pagamentos/ListaDocumentos';
import { colunasIntegracao } from './colunasLegado';
import { BannerTeso } from './motivo';
import { ROTULO_TIPO } from './api';
import { errosDoLote, LOTE_INTEGRACAO } from './regras';
import { prefixosDaChave } from './ronda3';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';

/** Tesouraria › Integração no razão (ecrã teso_contab_integracao): documentos por integrar e contas de tesouraria. */
export default function Integracao() {
  return (
    <Routes>
      <Route index element={<PorIntegrar />} />
      <Route path=":id" element={<DetalheDocumento permitirEdicao={false} />} />
    </Routes>
  );
}

function PorIntegrar() {
  // detalhe em modal sobre a lista (viewTesoDocDetail/viewTesoMovementDetail do legado); a rota …/:id continua
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [tipo, setTipo] = useState<TipoDocumento>();
  const [conta, setConta] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const filtrosPendentes = { estado: 'PENDENTE', tipo, conta_financeira: conta, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) };
  const totalPendentes = useTotalDocumentos(filtrosPendentes);
  const [seleccao, setSeleccao] = useState<DocumentoTesouraria[]>([]);
  const [progresso, setProgresso] = useState<{ feitos: number; total: number; erros: string[] } | null>(null);
  const podeIntegrar = pode('teso_integrar');
  const [sep, setSep] = useSeparadorUrl(['pendentes', 'contas']);

  /**
   * Integração em lote: POST /tesouraria/documentos/integrar {ids} (ADR-064), em blocos de LOTE_INTEGRACAO. No servidor cada
   * documento é integrado na sua transacção: os erros vêm em `erros` e não interrompem os restantes.
   */
  const integrarSeleccionados = async () => {
    const lista = [...seleccao];
    const erros: string[] = [];
    setProgresso({ feitos: 0, total: lista.length, erros });
    for (let i = 0; i < lista.length; i += LOTE_INTEGRACAO) {
      const bloco = lista.slice(i, i + LOTE_INTEGRACAO);
      try {
        const { dados } = await enviar<ResultadoIntegracaoLote>('post', '/tesouraria/documentos/integrar', { ids: bloco.map((d) => d.id) });
        erros.push(...errosDoLote(dados));
      } catch (e) {
        erros.push(...bloco.map((d) => `${d.numero_documento ?? `#${d.id}`}: ${e instanceof ErroApi ? e.message : String(e)}`));
      }
      setProgresso({ feitos: Math.min(lista.length, i + bloco.length), total: lista.length, erros: [...erros] });
    }
    setSeleccao([]);
    void cliente.invalidateQueries({ queryKey: ['teso'] });
    void cliente.invalidateQueries({ queryKey: ['contab'] });
    if (!erros.length) message.success(`${lista.length} documento(s) integrado(s).`);
  };

  return (
    <>
      <BannerTeso
        icone="check-double"
        titulo="Integração Contabilística"
        texto="Selecione os movimentos de tesouraria finalizados para gerar os Lançamentos no Diário da contabilidade."
        cor="#059669"
        fundo="#f0fdf4"
        corTitulo="#064e3b"
        // legado: «INTEGRAR SELECIONADOS» (btn-warning, varinha) à direita do cartão-título
        accoes={podeIntegrar && (
          <Button color="gold" variant="solid" size="large" style={{ fontWeight: 700, paddingInline: 32 }} icon={<IconeFa nome="magic" />}
            disabled={!seleccao.length || !!(progresso && progresso.feitos < progresso.total)}
            onClick={() => Modal.confirm({ title: `Integrar ${seleccao.length} documento(s)?`, okText: 'Integrar', cancelText: 'Cancelar', onOk: () => void integrarSeleccionados() })}>
            INTEGRAR SELECIONADOS{seleccao.length ? ` (${seleccao.length})` : ''}
          </Button>
        )}
      />
      <Tabs
        activeKey={sep}
        onChange={setSep}
        items={[
          {
            key: 'pendentes',
            label: 'Por integrar',
            children: (
              <Card title="Documentos Pendentes de Contabilização" extra={<Tag color="#059669" data-testid="total-integracao">Total: {totalPendentes ?? '…'}</Tag>}>
                <BarraFiltros>
                  <Select placeholder="Tipo" allowClear style={{ width: 150 }} value={tipo} onChange={setTipo} options={[{ value: 'PAGAMENTO', label: 'Pagamentos' }, { value: 'RECEBIMENTO', label: 'Recebimentos' }]} />
                  <SeletorContaFinanceira value={conta} onChange={setConta} allowClear />
                  <DatePicker.RangePicker format="DD/MM/YYYY" value={periodo} onChange={(v) => setPeriodo(v)} />
                </BarraFiltros>
                {progresso && (
                  <div style={{ marginBottom: 16 }}>
                    <Progress percent={Math.round((progresso.feitos / Math.max(1, progresso.total)) * 100)} status={progresso.erros.length ? 'exception' : undefined} />
                    {progresso.erros.length > 0 && (
                      <Alert type="error" showIcon closable onClose={() => setProgresso(null)} message={`${progresso.erros.length} documento(s) não foram integrados`} description={<ul style={{ margin: 0, paddingLeft: 18 }}>{progresso.erros.map((e) => <li key={e}>{e}</li>)}</ul>} />
                    )}
                  </div>
                )}
                <TabelaDocumentos
                  filtros={filtrosPendentes}
                  columns={colunasIntegracao((d) => setDetalhe(d.id))}
                  impressao={{
                    titulo: 'Documentos de tesouraria por integrar',
                    periodo: textoPeriodo(periodo),
                    filtros: [tipo && `Tipo: ${ROTULO_TIPO[tipo]}`, conta && `Conta: ${conta}`, 'Total: recebimentos menos pagamentos'],
                    rotuloTotal: 'Saldo',
                  }}
                  rowSelection={podeIntegrar ? { selectedRowKeys: seleccao.map((d) => d.id), onChange: (_, linhas) => setSeleccao(linhas) } : undefined}
                  onRow={(r) => ({ onDoubleClick: () => setDetalhe(r.id) })}
                />
                <Typography.Text type="secondary">Duplo clique num documento para ver o detalhe.</Typography.Text>
              </Card>
            ),
          },
          { key: 'contas', label: 'Contas de tesouraria', children: <ContasTesouraria /> },
        ]}
      />
      {detalhe !== null && <DetalheDocumento emModal permitirEdicao={false} id={detalhe} aoFechar={() => setDetalhe(null)} />}
    </>
  );
}

type ConfigContas = Record<string, { descricao: string; codigo_conta: string | null; prefixos?: string[] | null }>;

function ContasTesouraria() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [form] = Form.useForm<Record<string, string | undefined>>();
  const consulta = useQuery({ queryKey: ['teso', 'configuracao-contas'], queryFn: () => obter<ConfigContas>('/tesouraria/configuracao/contas') });
  const gravar = useMutation({
    mutationFn: (contas: Record<string, string | undefined>) => enviar<ConfigContas>('put', '/tesouraria/configuracao/contas', { contas: Object.fromEntries(Object.entries(contas).map(([k, v]) => [k, v || null])) }),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      void cliente.invalidateQueries({ queryKey: ['teso', 'configuracao-contas'] });
    },
    onError: (e) => notificarErro(e),
  });
  const editar = pode('teso_integrar');
  const c = consulta.data;
  return (
    <Card loading={consulta.isLoading}>
      <Typography.Paragraph type="secondary">Contas usadas nas sobras/quebras de caixa e nas diferenças de câmbio das liquidações em moeda.</Typography.Paragraph>
      {c && (
        <Form
          form={form}
          layout="vertical"
          disabled={!editar}
          initialValues={Object.fromEntries(Object.entries(c).map(([k, v]) => [k, v.codigo_conta ?? undefined]))}
          onFinish={(v) => gravar.mutate(v)}
          style={{ maxWidth: 560 }}
        >
          {Object.entries(c).map(([k, v]) => (
            // ronda 3: sobras só em contas 68 e quebras só em 78 (o servidor valida: CONTA_PREFIXO_INVALIDO)
            <Form.Item key={k} name={k} label={v.descricao}
              rules={[{ validator: (_, x?: string) => { const p = prefixosDaChave(k, v.prefixos); return !x || !p || p.some((q) => x.startsWith(q)) ? Promise.resolve() : Promise.reject(new Error(`Escolha uma conta ${p.join(' ou ')}.`)); } }]}>
              <SeletorConta allowClear prefixos={prefixosDaChave(k, v.prefixos)} style={{ width: '100%' }} />
            </Form.Item>
          ))}
          {editar && <Button type="primary" htmlType="submit" loading={gravar.isPending}>Gravar</Button>}
        </Form>
      )}
    </Card>
  );
}
