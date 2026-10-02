import { Alert, Button, Col, Input, InputNumber, Modal, Row, Skeleton, Space, Statistic, Table, Typography, Upload } from 'antd';
import { DownloadOutlined, FileExcelOutlined, SaveOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { descarregar, enviar, obter } from '@/api/cliente';
import { BotoesExportar, pares, tabelaHtml, useMensagem } from '@/componentes/impressao';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { formatarKz } from '@/utilitarios/formatacao';
import { enviarFicheiro } from '../comum/ficheiros';
import { aplicarLeitura, calcularResultadoLiquido, corpoGravacao, valoresDe, type LeituraHistorico, type NotaHistorico, type SaldosHistoricos, type Valores } from './saldosHistoricos';

function TabelaNotas({ titulo, notas, valores, aoMudar, soLeitura, filtro }: { titulo: string; notas: NotaHistorico[]; valores: Valores; aoMudar: (codigo: string, v: number | null) => void; soLeitura: boolean; filtro: string }) {
  const termo = filtro.trim().toLowerCase();
  const visiveis = termo ? notas.filter((n) => `${n.codigo} ${n.descricao ?? ''}`.toLowerCase().includes(termo)) : notas;
  return (
    <>
      <Typography.Title level={5} style={{ marginTop: 0 }}>{titulo}</Typography.Title>
      <Table<NotaHistorico>
        rowKey="codigo"
        size="small"
        dataSource={visiveis}
        pagination={false}
        scroll={{ ...scrollTabela(), y: 360 }}
        locale={{ emptyText: 'Sem notas nesta empresa (Tabelas auxiliares).' }}
        columns={[
          { title: 'Código', dataIndex: 'codigo', width: 80, render: (v: string) => <strong>{v}</strong> },
          { title: 'Descrição', dataIndex: 'descricao', ellipsis: true },
          {
            title: 'Valor (Kz)',
            key: 'valor',
            width: 160,
            align: 'right',
            render: (_, n) => (
              <InputNumber
                aria-label={`Valor ${n.codigo}`}
                size="small"
                precision={2}
                disabled={soLeitura}
                value={valores[n.codigo] ?? null}
                onChange={(v) => aoMudar(n.codigo, v === null || v === undefined ? null : Number(v))}
                style={{ width: '100%' }}
                placeholder="0,00"
              />
            ),
          },
        ]}
      />
    </>
  );
}

/**
 * Lançamentos › Saldos históricos (A-06; «Importar Histórico» do legado, js/ui_lancamentos.js:1186): valores por nota DEMO
 * e de fluxo de um exercício sem lançamentos detalhados, para o comparativo do ano seguinte. Ler, editar, gravar (substitui o
 * ano), importar de Excel (TIPO, CÓDIGO, VALOR — só preenche, grava-se depois), descarregar o modelo e imprimir.
 */
export function ModalSaldosHistoricos({ aberto, aoFechar }: { aberto: boolean; aoFechar: () => void }) {
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const message = useMensagem();
  const [ano, setAno] = useState<number>(new Date().getFullYear() - 1);
  const [demo, setDemo] = useState<Valores>({});
  const [fluxo, setFluxo] = useState<Valores>({});
  const [alterado, setAlterado] = useState(false);
  const [aGravar, setAGravar] = useState(false);
  const [aImportar, setAImportar] = useState(false);
  const [filtro, setFiltro] = useState('');
  const consulta = useQuery({ queryKey: ['contab', 'saldos-historicos', ano], queryFn: () => obter<SaldosHistoricos>(`/contabilidade/saldos-historicos/${ano}`), enabled: aberto && ano > 1900 });

  useEffect(() => {
    if (!consulta.data) return;
    setDemo(valoresDe(consulta.data.demo));
    setFluxo(valoresDe(consulta.data.fluxo));
    setAlterado(false);
  }, [consulta.data]);

  const podeGravar = pode('lancamentos_saldos') && !consulta.data?.encerrado;
  const resLiq = useMemo(() => calcularResultadoLiquido(demo), [demo]);
  const preenchidos = Object.values(demo).filter((v) => v !== null && v !== undefined).length + Object.values(fluxo).filter((v) => v !== null && v !== undefined).length;

  const gravar = async () => {
    setAGravar(true);
    try {
      const r = await enviar<{ registos: number; res_liq: string | null }>('put', `/contabilidade/saldos-historicos/${ano}`, corpoGravacao(demo, fluxo));
      message.success(r.mensagem);
      setAlterado(false);
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    } catch (e) {
      notificarErro(e, 'Não foi possível gravar o histórico');
    } finally {
      setAGravar(false);
    }
  };

  const importar = async (ficheiro: File) => {
    setAImportar(true);
    try {
      const r = await enviarFicheiro<LeituraHistorico>('/contabilidade/saldos-historicos/importar', ficheiro);
      const res = aplicarLeitura({ demo, fluxo }, r.dados);
      setDemo(res.demo);
      setFluxo(res.fluxo);
      setAlterado(true);
      message.success(`${res.aplicados} valor(es) preenchido(s) a partir do ficheiro: verifique e grave.`);
      if (res.desconhecidos.length || r.dados.ignoradas) {
        message.warning(
          [res.desconhecidos.length ? `Códigos sem nota nesta empresa: ${res.desconhecidos.slice(0, 10).join(', ')}${res.desconhecidos.length > 10 ? '…' : ''}.` : '', r.dados.ignoradas ? `${r.dados.ignoradas} linha(s) ignorada(s) (tipo diferente de DEMO/FLUXO).` : '']
            .filter(Boolean)
            .join(' '),
          8,
        );
      }
    } catch (e) {
      notificarErro(e, 'Não foi possível ler o ficheiro');
    } finally {
      setAImportar(false);
    }
  };

  const fechar = () => {
    if (alterado) {
      Modal.confirm({ title: 'Sair sem gravar?', content: 'As alterações ao histórico perdem-se.', okText: 'Sair', cancelText: 'Continuar a editar', onOk: aoFechar });
      return;
    }
    aoFechar();
  };

  const pedidoImpressao = () => {
    const linhas = (notas: NotaHistorico[], v: Valores) => notas.filter((n) => v[n.codigo] !== null && v[n.codigo] !== undefined);
    const colunas = (v: Valores) => [
      { titulo: 'Código', valor: (n: NotaHistorico) => n.codigo },
      { titulo: 'Descrição', valor: (n: NotaHistorico) => n.descricao ?? '', quebrar: true },
      { titulo: 'Valor (Kz)', valor: (n: NotaHistorico) => v[n.codigo] ?? null, formato: 'moeda' as const },
    ];
    return {
      titulo: `Saldos históricos ${ano}`,
      subtitulo: 'Comparativo do exercício seguinte (Balanço, Demonstração de Resultados e Fluxos de Caixa)',
      filtros: [consulta.data?.encerrado ? 'Exercício encerrado' : 'Exercício aberto', alterado ? 'Valores ainda não gravados' : 'Valores gravados'],
      conteudo:
        pares([['Resultado líquido do exercício (calculado)', `${formatarKz(resLiq)} Kz`]], 1) +
        tabelaHtml({ legenda: 'Notas às demonstrações (DEMO)', colunas: colunas(demo), linhas: linhas(consulta.data?.demo ?? [], demo), vazio: 'Sem valores.' }) +
        tabelaHtml({ legenda: 'Notas de fluxo de caixa (FLUXO)', colunas: colunas(fluxo), linhas: linhas(consulta.data?.fluxo ?? [], fluxo), vazio: 'Sem valores.' }),
    };
  };

  return (
    <Modal
      open={aberto}
      title="Saldos históricos (comparativo)"
      width={larguraModal(1100)}
      onCancel={fechar}
      destroyOnHidden
      footer={
        <Space wrap>
          {pode('lancamentos_saldos') && (
            <>
              <Upload accept=".xlsx,.xls,.csv" showUploadList={false} disabled={!podeGravar} beforeUpload={(f) => { void importar(f); return false; }}>
                <Button icon={<FileExcelOutlined />} loading={aImportar} disabled={!podeGravar}>Importar de Excel</Button>
              </Upload>
              <Button
                icon={<DownloadOutlined />}
                onClick={async () => {
                  try {
                    await descarregar(`/contabilidade/saldos-historicos/${ano}/modelo`, undefined, `Template_Importacao_Historico_${ano}.xlsx`);
                  } catch (e) {
                    notificarErro(e, 'Não foi possível descarregar o modelo');
                  }
                }}
              >
                Modelo Excel
              </Button>
            </>
          )}
          <BotoesExportar desactivado={!consulta.data || !preenchidos} obterPedido={pedidoImpressao} />
          <Button onClick={fechar}>Fechar</Button>
          {pode('lancamentos_saldos') && (
            <Button type="primary" icon={<SaveOutlined />} loading={aGravar} disabled={!podeGravar || !consulta.data} onClick={() => Modal.confirm({ title: `Gravar o histórico de ${ano}?`, content: 'Substitui todos os valores históricos guardados para este ano.', okText: 'Gravar', cancelText: 'Cancelar', onOk: gravar })}>
              Gravar histórico
            </Button>
          )}
        </Space>
      }
    >
      <Row gutter={[16, 12]} align="bottom" style={{ marginBottom: 12 }}>
        <Col xs={12} sm={8} md={5}>
          <Typography.Text type="secondary">Ano do histórico</Typography.Text>
          <InputNumber aria-label="Ano do histórico" min={1990} max={2100} precision={0} value={ano} onChange={(v) => v && (alterado ? Modal.confirm({ title: 'Mudar de ano sem gravar?', okText: 'Mudar', cancelText: 'Cancelar', onOk: () => setAno(v) }) : setAno(v))} style={{ width: '100%' }} />
        </Col>
        <Col xs={12} sm={8} md={6}>
          <Statistic title="Resultado líquido (calculado)" value={formatarKz(resLiq)} valueStyle={{ fontSize: 20, color: Number(resLiq) < 0 ? '#cf1322' : undefined }} />
        </Col>
        <Col xs={24} sm={8} md={6}>
          <Input.Search placeholder="Filtrar notas" allowClear onChange={(e) => setFiltro(e.target.value)} />
        </Col>
      </Row>
      {consulta.data?.encerrado && <Alert type="info" showIcon style={{ marginBottom: 12 }} message={`O exercício de ${ano} está encerrado: o histórico é só de leitura.`} />}
      {!pode('lancamentos_saldos') && <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Consulta: não tem permissão para alterar os saldos históricos." />}
      {consulta.isLoading ? (
        <Skeleton active />
      ) : consulta.isError ? (
        <Alert type="error" showIcon message="Não foi possível obter o histórico." description={consulta.error instanceof Error ? consulta.error.message : undefined} />
      ) : (
        <Row gutter={[16, 16]}>
          <Col xs={24} lg={12}>
            <TabelaNotas titulo="Notas DEMO (Balanço / DR)" notas={consulta.data?.demo ?? []} valores={demo} soLeitura={!podeGravar} filtro={filtro} aoMudar={(c, v) => { setDemo((d) => ({ ...d, [c]: v })); setAlterado(true); }} />
          </Col>
          <Col xs={24} lg={12}>
            <TabelaNotas titulo="Notas FLUXO (Fluxos de caixa)" notas={consulta.data?.fluxo ?? []} valores={fluxo} soLeitura={!podeGravar} filtro={filtro} aoMudar={(c, v) => { setFluxo((f) => ({ ...f, [c]: v })); setAlterado(true); }} />
          </Col>
        </Row>
      )}
      <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
        O resultado líquido é (Σ 22 a 26 − Σ 27 a 30) + Σ 31 a 34 − 35 e é gravado com o histórico. O ficheiro Excel tem as colunas TIPO (DEMO ou FLUXO), CÓDIGO e VALOR.
      </Typography.Paragraph>
    </Modal>
  );
}
