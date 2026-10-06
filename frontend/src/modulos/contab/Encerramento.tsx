import { Alert, Button, Card, Checkbox, Col, Descriptions, Empty, Flex, Form, Input, List, Modal, Row, Select, Skeleton, Space, Steps, Table, Tabs, Tag, Typography, message } from 'antd';
import { CheckCircleTwoTone, CloseCircleTwoTone, ExclamationCircleTwoTone, LockOutlined, UnlockOutlined } from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar, tabelaHtml } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import type { EstadoExercicio, ExercicioResumo, MapaApuramento, PassoEstado, PrevisualizacaoPasso, ValidacaoExercicio, Verificacao } from './api';
import { BotaoCsv, ValorKz } from './comum/Componentes';
import { accoesEncerramento } from './comum/regras';
import { PainelPlano } from './encerramento/PainelPlano';

/** Contabilidade › Encerramento do exercício (ADR-056): 5 passos de apuramento (período 13), validações, encerrar/reabrir/cancelar. */
export default function Encerramento() {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const exercicios = useQuery({ queryKey: ['contab', 'encerramento', 'exercicios'], queryFn: () => obter<ExercicioResumo[]>('/contabilidade/encerramento') });
  const [ano, setAno] = useState<number>(dayjs().year());
  const [previsto, setPrevisto] = useState<number | null>(null);
  const [criarContas, setCriarContas] = useState(false);
  const [motivoAccao, setMotivoAccao] = useState<'reabrir' | 'cancelar-apuramento' | null>(null);
  const [formMotivo] = Form.useForm<{ motivo: string }>();

  const estado = useQuery({ queryKey: ['contab', 'encerramento', ano], queryFn: () => obter<EstadoExercicio>(`/contabilidade/encerramento/${ano}`) });
  const validacao = useQuery({
    queryKey: ['contab', 'encerramento', ano, 'validacoes'],
    queryFn: () => obter<ValidacaoExercicio>(`/contabilidade/encerramento/${ano}/validacoes`),
    enabled: false,
  });
  const mapa = useQuery({ queryKey: ['contab', 'encerramento', ano, 'mapa'], queryFn: () => obter<MapaApuramento>(`/contabilidade/encerramento/${ano}/mapa`) });
  const previsao = useQuery({
    queryKey: ['contab', 'encerramento', ano, 'passo', previsto],
    queryFn: () => obter<PrevisualizacaoPasso>(`/contabilidade/encerramento/${ano}/passos/${previsto}`),
    enabled: previsto !== null,
  });

  const invalidar = () => {
    // a validação anterior deixa de valer depois de qualquer acção: obriga a validar de novo antes de encerrar
    cliente.removeQueries({ queryKey: ['contab', 'encerramento', ano, 'validacoes'] });
    void cliente.invalidateQueries({ queryKey: ['contab'] });
  };
  const accao = useMutation({
    mutationFn: ({ caminho, dados }: { caminho: string; dados?: unknown }) => enviar<unknown>('post', `/contabilidade/encerramento/${ano}/${caminho}`, dados),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setPrevisto(null);
      setMotivoAccao(null);
      formMotivo.resetFields();
      invalidar();
    },
    onError: (e) => notificarErro(e),
  });

  const anos = Array.from(new Set([...(exercicios.data ?? []).map((e) => e.ano), dayjs().year()])).sort((a, b) => b - a);
  const regras = accoesEncerramento(estado.data, validacao.data, pode);
  const e = estado.data;

  const passoActual = e ? e.passos.findIndex((p) => !p.numero_lan) : 0;
  const [separador, setSeparador] = useState('passos');

  return (
    <>
      <CabecalhoPagina
        titulo="Encerramento de Contas e Apuramento de Resultados"
        subtitulo="Execute as rotinas de fecho em cascata para apuramento dos resultados do exercício (período 13); valide e encerre."
        accoes={
          <Space wrap>
            <Select value={ano} onChange={setAno} style={{ width: 120 }} options={anos.map((a) => ({ value: a, label: String(a) }))} aria-label="Exercício" />
            {regras.podeEncerrar && (
              <Button
                type="primary"
                icon={<LockOutlined />}
                loading={accao.isPending}
                onClick={() =>
                  Modal.confirm({
                    title: `Encerrar o exercício de ${ano}?`,
                    content: 'Depois de encerrado não se podem gravar nem alterar lançamentos com data deste exercício.',
                    okText: 'Encerrar',
                    cancelText: 'Cancelar',
                    onOk: () => accao.mutateAsync({ caminho: 'encerrar' }),
                  })
                }
              >
                Encerrar exercício
              </Button>
            )}
            {regras.podeReabrir && (
              <Button danger icon={<UnlockOutlined />} onClick={() => setMotivoAccao('reabrir')}>
                Reabrir exercício
              </Button>
            )}
            {regras.podeCancelarApuramento && (
              <Button danger onClick={() => setMotivoAccao('cancelar-apuramento')}>
                Cancelar apuramento
              </Button>
            )}
          </Space>
        }
      />
      {estado.isLoading || !e ? (
        <Skeleton active />
      ) : (
        <>
          {e.encerrado ? (
            <Alert type="error" showIcon icon={<LockOutlined />} style={{ marginBottom: 16 }} message={`O exercício de ${ano} está encerrado.`} />
          ) : (
            <Alert type="info" showIcon style={{ marginBottom: 16 }} message={`O exercício de ${ano} está aberto. Execute os passos pela ordem, valide e encerre.`} />
          )}
          <PainelPlano ano={ano} encerrado={e.encerrado} />
          <Tabs
            activeKey={separador}
            onChange={setSeparador}
            items={[
              {
                key: 'passos',
                label: 'Passos de apuramento',
                children: (
                  <Card>
                    <Steps
                      direction="vertical"
                      current={passoActual < 0 ? e.passos.length : passoActual}
                      items={e.passos.map((p) => ({
                        title: `${p.passo}. ${p.titulo}`,
                        status: p.numero_lan ? 'finish' : 'wait',
                        description: <DescricaoPasso p={p} podeExecutar={regras.podeExecutarPassos} aoPrever={() => { setCriarContas(false); setPrevisto(p.passo); }} />,
                      }))}
                    />
                    <Alert
                      type={e.encerrado ? 'error' : 'success'}
                      showIcon
                      icon={<LockOutlined />}
                      style={{ marginTop: 8 }}
                      message={<strong>Passo final: validação e encerramento do exercício</strong>}
                      description="Valida a integridade do Balanço, as amortizações do imobilizado e o inventário do armazém. Após a validação bem-sucedida, o exercício é trancado e não permite mais lançamentos."
                      action={
                        <Button onClick={() => setSeparador('validacoes')} disabled={e.encerrado}>
                          Validar e encerrar
                        </Button>
                      }
                    />
                  </Card>
                ),
              },
              {
                key: 'validacoes',
                label: 'Validações',
                children: <PainelValidacoes ano={ano} validacao={validacao.data} aCarregar={validacao.isFetching} aoValidar={() => void validacao.refetch()} />,
              },
              {
                key: 'mapa',
                label: 'Mapa de apuramento',
                children: <PainelMapa mapa={mapa.data} aCarregar={mapa.isLoading} ano={ano} />,
              },
              {
                key: 'classe8',
                label: 'Resumo da classe 8',
                children: (
                  <Card
                    extra={
                      <BotoesExportar
                        tamanho="small"
                        desactivado={!e.resumo_classe_8.length}
                        obterPedido={() => ({
                          titulo: `Resumo da classe 8 · exercício de ${ano}`,
                          conteudo: tabelaHtml({
                            linhas: e.resumo_classe_8,
                            totais: true,
                            colunas: [
                              { titulo: 'Conta', valor: (l) => l.codigo_conta },
                              { titulo: 'Descrição', valor: (l) => l.descricao ?? '—' },
                              { titulo: 'Saldo credor (Kz)', valor: (l) => l.saldo_credor, formato: 'moeda', somar: true },
                            ],
                          }),
                        })}
                      />
                    }
                  >
                    <Table
                      rowKey="codigo_conta"
                      scroll={scrollTabela()}
                      size="small"
                      pagination={false}
                      dataSource={e.resumo_classe_8}
                      locale={{ emptyText: 'Sem movimentos na classe 8.' }}
                      columns={[
                        { title: 'Conta', dataIndex: 'codigo_conta' },
                        { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '—' },
                        { title: 'Saldo credor (Kz)', dataIndex: 'saldo_credor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
                      ]}
                    />
                  </Card>
                ),
              },
            ]}
          />
        </>
      )}

      <Modal
        title={previsao.data ? `Passo ${previsao.data.passo} — ${previsao.data.titulo}` : 'Pré-visualização'}
        open={previsto !== null}
        onCancel={() => setPrevisto(null)}
        width={larguraModal(900)}
        footer={
          <Space wrap>
            <Button onClick={() => setPrevisto(null)}>Fechar</Button>
            {regras.podeExecutarPassos && previsao.data && (
              <Button
                type="primary"
                loading={accao.isPending}
                disabled={previsao.data.contas_totalizadoras.length > 0 || (previsao.data.contas_em_falta.length > 0 && !criarContas)}
                onClick={() => accao.mutate({ caminho: `passos/${previsto}`, dados: { criar_contas_em_falta: criarContas } })}
              >
                {previsao.data.numero_lan_anterior ? 'Repetir passo (estorna o anterior)' : 'Executar passo'}
              </Button>
            )}
          </Space>
        }
      >
        {previsao.isLoading || !previsao.data ? (
          <Skeleton active />
        ) : (
          <>
            <Descriptions size="small" column={{ xs: 1, md: 3 }} style={{ marginBottom: 12 }}>
              <Descriptions.Item label="Diário">{previsao.data.diario}</Descriptions.Item>
              <Descriptions.Item label="Documento">{previsao.data.documento}</Descriptions.Item>
              <Descriptions.Item label="Data">{formatarData(previsao.data.data_documento)} (período {previsao.data.periodo})</Descriptions.Item>
              <Descriptions.Item label="Conta de resultado">{previsao.data.conta_resultado}</Descriptions.Item>
              <Descriptions.Item label="Resultado">{formatarKz(previsao.data.resultado, true)}</Descriptions.Item>
              <Descriptions.Item label="Linhas a gerar">{previsao.data.linhas}</Descriptions.Item>
            </Descriptions>
            {previsao.data.numero_lan_anterior && (
              <Alert style={{ marginBottom: 12 }} type="warning" showIcon message={`Já existe o lançamento ${previsao.data.numero_lan_anterior}: repetir o passo estorna-o e gera um novo.`} />
            )}
            {previsao.data.contas_totalizadoras.length > 0 && (
              <Alert
                style={{ marginBottom: 12 }}
                type="error"
                showIcon
                message="Contas totalizadoras no apuramento"
                description={`Não se lança em contas totalizadoras: ${previsao.data.contas_totalizadoras.join(', ')}. Corrija o plano de contas antes de executar.`}
              />
            )}
            {previsao.data.contas_em_falta.length > 0 && (
              <Alert
                style={{ marginBottom: 12 }}
                type="warning"
                showIcon
                message="Contas de apuramento em falta"
                description={
                  <>
                    <div>{previsao.data.contas_em_falta.join(', ')}</div>
                    <Checkbox checked={criarContas} onChange={(ev) => setCriarContas(ev.target.checked)} style={{ marginTop: 8 }}>
                      Criar as contas em falta (como contas de movimento)
                    </Checkbox>
                  </>
                }
              />
            )}
            <Table
              rowKey={(_, i) => String(i)}
              size="small"
              scroll={scrollTabela()}
              dataSource={previsao.data.movimentos}
              pagination={{ pageSize: 10, showTotal: (t) => `${t} movimento(s)` }}
              locale={{ emptyText: 'Não existem saldos a apurar neste passo.' }}
              columns={[
                { title: 'Descrição', dataIndex: 'descricao' },
                { title: 'Conta a débito', dataIndex: 'conta_debito' },
                { title: 'Conta a crédito', dataIndex: 'conta_credito' },
                { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', render: (v: string) => <ValorKz valor={v} /> },
              ]}
            />
          </>
        )}
      </Modal>

      <Modal
        title={motivoAccao === 'reabrir' ? `Reabrir o exercício de ${ano}` : `Cancelar o apuramento de ${ano}`}
        open={motivoAccao !== null}
        onCancel={() => setMotivoAccao(null)}
        okText={motivoAccao === 'reabrir' ? 'Reabrir' : 'Cancelar apuramento'}
        cancelText="Voltar"
        okButtonProps={{ danger: true }}
        confirmLoading={accao.isPending}
        onOk={() => formMotivo.submit()}
      >
        <Form form={formMotivo} layout="vertical" onFinish={(v) => motivoAccao && accao.mutate({ caminho: motivoAccao, dados: v })}>
          <Typography.Paragraph type="secondary">
            {motivoAccao === 'reabrir'
              ? 'Só é possível reabrir se não houver exercícios seguintes encerrados.'
              : 'Os lançamentos do período 13 são estornados (fica o rasto no Diário).'}
          </Typography.Paragraph>
          <Form.Item name="motivo" label="Motivo" rules={[{ required: true, message: 'Indique o motivo.' }]}>
            <Input.TextArea rows={3} maxLength={500} />
          </Form.Item>
        </Form>
      </Modal>
    </>
  );
}

/** Texto de cada passo como no legado (ui_closing.js). */
const TEXTOS_PASSOS: Record<number, string> = {
  1: 'Transfere os saldos das contas 61 a 65 e 71 a 75 para as respectivas subcontas agrupadoras .9, seguidamente para a classe 82 e por fim para a conta 881 (Resultado Operacional).',
  2: 'Transfere os saldos das contas 66 e 76 para as respectivas subcontas agrupadoras .9, seguidamente para a classe 83 e por fim para a conta 882 (Resultado Financeiro).',
  3: 'Transfere as contas 67 e 77 para a classe 84, consolidando em 849, e em seguida para a conta 883, apurando por fim na 889.',
  4: 'Transfere as contas 68 e 78 para a classe 85, consolidando em 859, e em seguida para a conta 884, apurando por fim na 889.',
  5: 'Transfere as contas 69 e 79 para a classe 86, consolidando em 869, e em seguida para a conta 886, apurando por fim na 889.',
};

function DescricaoPasso({ p, podeExecutar, aoPrever }: { p: PassoEstado; podeExecutar: boolean; aoPrever: () => void }) {
  return (
    <>
    {TEXTOS_PASSOS[p.passo] && (
      <Typography.Paragraph type="secondary" style={{ margin: '2px 0 4px' }}>
        {TEXTOS_PASSOS[p.passo]}
      </Typography.Paragraph>
    )}
    <Flex gap={16} wrap align="center" style={{ padding: '4px 0 12px' }}>
      <Typography.Text type="secondary">
        Diário {p.diario} · {p.documento}
      </Typography.Text>
      {p.numero_lan ? <Tag color="green">{p.numero_lan} · {p.linhas} linha(s)</Tag> : <Tag>Por executar</Tag>}
      {p.resultado !== null && <span>Resultado: {formatarKz(p.resultado, true)}</span>}
      <Button size="small" onClick={aoPrever}>
        {podeExecutar ? (p.numero_lan ? `Repetir passo ${p.passo}` : `Executar passo ${p.passo}`) : 'Pré-visualizar'}
      </Button>
    </Flex>
    </>
  );
}

function IconeVerificacao({ v }: { v: Verificacao }) {
  if (v.ok) return <CheckCircleTwoTone twoToneColor="#52c41a" />;
  return v.bloqueia ? <CloseCircleTwoTone twoToneColor="#cf1322" /> : <ExclamationCircleTwoTone twoToneColor="#faad14" />;
}

function PainelValidacoes({ ano, validacao, aCarregar, aoValidar }: { ano: number; validacao?: ValidacaoExercicio; aCarregar: boolean; aoValidar: () => void }) {
  return (
    <Card
      title={`Validações do exercício de ${ano}`}
      extra={
        <Space wrap>
          {validacao && (
            <BotoesExportar
              tamanho="small"
              obterPedido={() => ({
                titulo: `Validações do exercício de ${ano}`,
                filtros: [validacao.pode_encerrar ? 'O exercício pode ser encerrado' : `${validacao.divergencias.length} divergência(s) impedem o encerramento`, `${validacao.avisos.length} aviso(s)`],
                conteudo: tabelaHtml({
                  linhas: validacao.verificacoes,
                  colunas: [
                    { titulo: 'Verificação', valor: (v) => v.descricao, quebrar: true },
                    { titulo: 'Resultado', valor: (v) => (v.ok ? 'OK' : v.bloqueia ? 'Bloqueia' : 'Aviso') },
                    { titulo: 'Diferença', valor: (v) => (v.ok ? null : v.diferenca), formato: 'moeda' },
                    {
                      titulo: 'Detalhes',
                      quebrar: true,
                      valor: (v) =>
                        v.ok || !v.detalhes
                          ? ''
                          : Object.entries(v.detalhes)
                              .map(([k, x]) => `${k.replace(/_/g, ' ')}: ${typeof x === 'string' && !Number.isNaN(Number(x)) ? formatarKz(x) : Array.isArray(x) ? x.join(', ') || '—' : String(x ?? '—')}`)
                              .join(' · '),
                    },
                  ],
                }),
              })}
            />
          )}
          <Button type="primary" loading={aCarregar} onClick={aoValidar}>
            {validacao ? 'Validar novamente' : 'Validar'}
          </Button>
        </Space>
      }
    >
      {!validacao ? (
        <Empty description="Execute as validações para ver se o exercício pode ser encerrado." />
      ) : (
        <>
          {validacao.pode_encerrar ? (
            <Alert type="success" showIcon style={{ marginBottom: 12 }} message="Todas as validações bloqueantes foram aprovadas: o exercício pode ser encerrado." />
          ) : (
            <Alert type="error" showIcon style={{ marginBottom: 12 }} message={`Existem ${validacao.divergencias.length} divergência(s) que impedem o encerramento.`} />
          )}
          {validacao.avisos.length > 0 && (
            <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`${validacao.avisos.length} aviso(s): não impedem o encerramento, mas devem ser analisados.`} />
          )}
          <List
            dataSource={validacao.verificacoes}
            renderItem={(v) => (
              <List.Item>
                <List.Item.Meta
                  avatar={<IconeVerificacao v={v} />}
                  title={
                    <Space wrap>
                      <span>{v.descricao}</span>
                      {!v.ok && <Tag color={v.bloqueia ? 'red' : 'orange'}>{v.bloqueia ? 'Bloqueia' : 'Aviso'}</Tag>}
                    </Space>
                  }
                  description={
                    !v.ok && (
                      <Row gutter={16}>
                        {v.diferenca !== null && <Col>Diferença: {formatarKz(v.diferenca, true)}</Col>}
                        {v.detalhes &&
                          Object.entries(v.detalhes).map(([k, x]) => (
                            <Col key={k}>
                              {k.replace(/_/g, ' ')}: {typeof x === 'string' && !Number.isNaN(Number(x)) ? formatarKz(x) : Array.isArray(x) ? x.join(', ') || '—' : String(x ?? '—')}
                            </Col>
                          ))}
                      </Row>
                    )
                  }
                />
              </List.Item>
            )}
          />
        </>
      )}
    </Card>
  );
}

function PainelMapa({ mapa, aCarregar, ano }: { mapa?: MapaApuramento; aCarregar: boolean; ano: number }) {
  type Linha = MapaApuramento['linhas'][number];
  return (
    <Card extra={<Space wrap>
      <BotoesExportar
        tamanho="small"
        desactivado={!mapa?.linhas.length}
        obterPedido={() =>
          mapa && {
            titulo: `Mapa de apuramento · exercício de ${ano}`,
            conteudo: tabelaHtml<Linha>({
              linhas: mapa.linhas,
              totais: 'Totais',
              colunas: [
                { titulo: 'Diário', valor: (l) => l.diario, total: 'Totais' },
                { titulo: 'N.º lançamento', valor: (l) => l.numero_lan, total: '' },
                { titulo: 'Documento', valor: (l) => l.numero_documento, total: '' },
                { titulo: 'Conta', valor: (l) => l.codigo_conta, total: '' },
                { titulo: 'Descrição', valor: (l) => l.descricao, quebrar: true, total: '' },
                { titulo: 'Débito', valor: (l) => l.debito, formato: 'moeda', total: formatarKz(mapa.total_debito) },
                { titulo: 'Crédito', valor: (l) => l.credito, formato: 'moeda', total: formatarKz(mapa.total_credito) },
                { titulo: 'Situação', valor: (l) => (l.estornado ? 'Estornado' : l.estorno ? 'Estorno' : ''), total: '' },
              ],
            }),
          }
        }
      />
      <BotaoCsv<Linha> nome={`apuramento_${ano}`} linhas={mapa?.linhas} colunas={[
      { titulo: 'Data', valor: (l) => l.data_documento },
      { titulo: 'Diário', valor: (l) => l.diario },
      { titulo: 'N.º lançamento', valor: (l) => l.numero_lan },
      { titulo: 'Documento', valor: (l) => l.numero_documento },
      { titulo: 'Conta', valor: (l) => l.codigo_conta },
      { titulo: 'Descrição', valor: (l) => l.descricao },
      { titulo: 'Débito', valor: (l) => l.debito, numerico: true },
      { titulo: 'Crédito', valor: (l) => l.credito, numerico: true },
    ]} />
    </Space>}>
      <Table<Linha>
        rowKey="id"
        size="small"
        loading={aCarregar}
        dataSource={mapa?.linhas}
        pagination={{ pageSize: 50, showTotal: (t) => `${t} linha(s)` }}
        locale={{ emptyText: 'Ainda não há lançamentos de apuramento neste exercício.' }}
        scroll={scrollTabela()}
        rowClassName={(l) => (l.estornado || l.estorno ? 'linha-estornada' : '')}
        columns={[
          { title: 'Diário', dataIndex: 'diario' },
          { title: 'N.º lançamento', dataIndex: 'numero_lan' },
          { title: 'Documento', dataIndex: 'numero_documento' },
          { title: 'Conta', dataIndex: 'codigo_conta' },
          { title: 'Descrição', dataIndex: 'descricao' },
          { title: 'Débito', dataIndex: 'debito', align: 'right', render: (v: string | null) => (v ? <ValorKz valor={v} /> : null) },
          { title: 'Crédito', dataIndex: 'credito', align: 'right', render: (v: string | null) => (v ? <ValorKz valor={v} /> : null) },
          { title: '', render: (_, l) => (l.estornado ? <Tag color="red">Estornado</Tag> : l.estorno ? <Tag color="purple">Estorno</Tag> : null) },
        ]}
        summary={() =>
          mapa && mapa.linhas.length > 0 ? (
            <Table.Summary.Row>
              <Table.Summary.Cell index={0} colSpan={5}>
                <strong>Totais</strong>
              </Table.Summary.Cell>
              <Table.Summary.Cell index={5} align="right">
                <ValorKz valor={mapa.total_debito} forte />
              </Table.Summary.Cell>
              <Table.Summary.Cell index={6} align="right">
                <ValorKz valor={mapa.total_credito} forte />
              </Table.Summary.Cell>
              <Table.Summary.Cell index={7} />
            </Table.Summary.Row>
          ) : null
        }
      />
    </Card>
  );
}
