import { Alert, Button, Card, DatePicker, Form, Modal, Progress, Select, Tabs, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { enviar, obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BarraFiltros } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi } from '@/utilitarios/formatacao';
import { SeletorConta } from '../contab/comum/Seletores';
import type { DocumentoTesouraria, ResultadoIntegracaoLote, TipoDocumento } from './api';
import { SeletorContaFinanceira, TabelaDocumentos } from './comum';
import { DetalheDocumento } from './pagamentos/DetalheDocumento';
import { colunasDocumentos, textoPeriodo } from './pagamentos/ListaDocumentos';
import { ROTULO_TIPO } from './api';
import { errosDoLote, LOTE_INTEGRACAO } from './regras';

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
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const [tipo, setTipo] = useState<TipoDocumento>();
  const [conta, setConta] = useState<string>();
  const [periodo, setPeriodo] = useState<[Dayjs | null, Dayjs | null] | null>(null);
  const [seleccao, setSeleccao] = useState<DocumentoTesouraria[]>([]);
  const [progresso, setProgresso] = useState<{ feitos: number; total: number; erros: string[] } | null>(null);
  const podeIntegrar = pode('teso_integrar');

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
      <CabecalhoPagina titulo="Integração no razão" subtitulo="Gerar os lançamentos contabilísticos dos pagamentos e recebimentos" />
      <Tabs
        items={[
          {
            key: 'pendentes',
            label: 'Por integrar',
            children: (
              <Card>
                <BarraFiltros
                  accoes={
                    podeIntegrar && (
                      <Button type="primary" disabled={!seleccao.length || !!(progresso && progresso.feitos < progresso.total)} onClick={() => Modal.confirm({ title: `Integrar ${seleccao.length} documento(s)?`, okText: 'Integrar', cancelText: 'Cancelar', onOk: () => void integrarSeleccionados() })}>
                        Integrar seleccionados ({seleccao.length})
                      </Button>
                    )
                  }
                >
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
                  filtros={{ estado: 'PENDENTE', tipo, conta_financeira: conta, data_inicio: dataApi(periodo?.[0]), data_fim: dataApi(periodo?.[1]) }}
                  columns={colunasDocumentos()}
                  impressao={{
                    titulo: 'Documentos de tesouraria por integrar',
                    periodo: textoPeriodo(periodo),
                    filtros: [tipo && `Tipo: ${ROTULO_TIPO[tipo]}`, conta && `Conta: ${conta}`, 'Total: recebimentos menos pagamentos'],
                    rotuloTotal: 'Saldo',
                  }}
                  rowSelection={podeIntegrar ? { selectedRowKeys: seleccao.map((d) => d.id), onChange: (_, linhas) => setSeleccao(linhas) } : undefined}
                  onRow={(r) => ({ onDoubleClick: () => navegar(String(r.id)) })}
                />
                <Typography.Text type="secondary">Duplo clique num documento para ver o detalhe.</Typography.Text>
              </Card>
            ),
          },
          { key: 'contas', label: 'Contas de tesouraria', children: <ContasTesouraria /> },
        ]}
      />
    </>
  );
}

type ConfigContas = Record<string, { descricao: string; codigo_conta: string | null }>;

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
            <Form.Item key={k} name={k} label={v.descricao}>
              <SeletorConta allowClear style={{ width: '100%' }} />
            </Form.Item>
          ))}
          {editar && <Button type="primary" htmlType="submit" loading={gravar.isPending}>Gravar</Button>}
        </Form>
      )}
    </Card>
  );
}
