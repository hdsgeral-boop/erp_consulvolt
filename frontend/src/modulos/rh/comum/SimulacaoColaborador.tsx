import { Alert, Button, Card, Col, Descriptions, Dropdown, Modal, Row, Space, Statistic, Table, Tag, Typography } from 'antd';
import { CalculatorOutlined, DownOutlined, FileTextOutlined, PrinterOutlined } from '@ant-design/icons';
import { useState } from 'react';
import { aplicarPreferencia, BotoesExportar, gravarPreferencia, lerPreferencia, SeletorPagina, useImpressao } from '@/componentes/impressao';
import { larguraModal } from '@/componentes/responsivo';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import type { DetalhePeriodo, ResultadoSalarial, RubricaResultado } from '../api';
import { AreaImpressao, BotaoImprimir } from './componentes';
import { pedidoSimulacaoColaborador, pedidoSimulacaoPeriodo, percentagemEfectiva, totalDescontos } from './simulacao';
import { ReciboSalario, useEmpresaRecibo, type DadosReciboColaborador } from './ReciboSalario';

/**
 * Simulação salarial de um colaborador (modal «Simular» do legado, js/app_v2.js:5593 `simularColaborador`): resultado
 * calculado NO SERVIDOR (cálculo ao vivo do período em aberto, ou a fotografia do período encerrado) — o ecrã só mostra.
 * Nada é gravado. Ilíquido, descontos e líquido; vencimentos, faltas, outros descontos, retenções (INSS, base e IRT,
 * adiantamentos) e encargo patronal com o custo total para a empresa; impressão marcada «Simulação».
 */
export function ModalSimulacaoColaborador({ resultado, mesAno, nome, simulacao = true, aoFechar }: {
  resultado: ResultadoSalarial | null;
  mesAno: string;
  nome: string;
  /** false nos períodos validados (o documento deixa de ser marcado «Simulação»). */
  simulacao?: boolean;
  aoFechar: () => void;
}) {
  const r = resultado;
  return (
    <Modal open={!!r} onCancel={aoFechar} width={larguraModal(620)} destroyOnHidden
      title={<Space><CalculatorOutlined />{simulacao ? 'Simulação salarial' : 'Detalhe salarial'} — {nome}</Space>}
      footer={r && (
        <Space wrap>
          <BotoesExportar chave="rh simulacao salarial colaborador" excel={false} textoImprimir="Imprimir simulação"
            obterPedido={() => pedidoSimulacaoColaborador(r, { nome, mesAno, simulacao })} />
          <Button onClick={aoFechar}>Fechar</Button>
        </Space>
      )}>
      {r && <DetalheSimulacao r={r} mesAno={mesAno} simulacao={simulacao} />}
    </Modal>
  );
}

function TabelaRubricas({ titulo, linhas, cor }: { titulo: string; linhas: RubricaResultado[]; cor: string }) {
  if (!linhas.length) return null;
  return (
    <div style={{ marginBottom: 12 }}>
      <Typography.Text strong style={{ color: cor, textTransform: 'uppercase', fontSize: 12 }}>{titulo}</Typography.Text>
      <Table<RubricaResultado> size="small" pagination={false} showHeader={false} rowKey={(x, i) => `${x.infotipo_id}-${i}`} dataSource={linhas}
        columns={[
          { key: 'n', render: (_, x) => <span>{x.nome}{x.horas && Number(x.horas) ? <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{formatarNumero(x.horas)} h</Typography.Text> : null}</span> },
          { key: 'v', align: 'right', width: 150, render: (_, x) => <strong style={{ color: cor, whiteSpace: 'nowrap' }}>{formatarKz(x.valor)}</strong> },
        ]} />
    </div>
  );
}

export function DetalheSimulacao({ r, mesAno, simulacao }: { r: ResultadoSalarial; mesAno: string; simulacao: boolean }) {
  const rubricas = (r.rubricas ?? []).filter((x) => !x.informativa && x.tipo !== 'OUTROS' && Number(x.valor) !== 0);
  const vencimentos = rubricas.filter((x) => x.tipo === 'VENCIMENTO' && !x.falta);
  const faltas = rubricas.filter((x) => x.falta);
  const descontos = rubricas.filter((x) => x.tipo === 'DESCONTO' && !x.falta);
  const pctTrab = percentagemEfectiva(r.inss_trabalhador, r.base_inss);
  const pctPat = percentagemEfectiva(r.inss_patronal, r.base_inss);
  return (
    <>
      <Typography.Paragraph type="secondary" style={{ marginTop: -4 }}>
        Período {mesAno}{r.avencado ? ' · Prestador avençado (IRT Grupo B)' : r.reformado ? ' · Reformado' : ''} · Dias {formatarNumero(r.dias_trabalhados)} de {formatarNumero(r.dias_contrato)}
      </Typography.Paragraph>
      <Row gutter={[8, 8]} style={{ marginBottom: 12 }}>
        <Col xs={24} sm={8}><Card size="small"><Statistic title="Ilíquido" value={formatarKz(r.bruto)} valueStyle={{ color: '#15803d', fontSize: 18 }} /></Card></Col>
        <Col xs={24} sm={8}><Card size="small"><Statistic title="Descontos" value={formatarKz(totalDescontos(r))} valueStyle={{ color: '#dc2626', fontSize: 18 }} /></Card></Col>
        <Col xs={24} sm={8}><Card size="small"><Statistic title="Líquido" value={formatarKz(r.liquido)} valueStyle={{ color: '#1d4ed8', fontSize: 20, fontWeight: 700 }} /></Card></Col>
      </Row>
      <TabelaRubricas titulo="Vencimentos" linhas={vencimentos} cor="#166534" />
      <TabelaRubricas titulo="Faltas / absentismo" linhas={faltas} cor="#b45309" />
      <TabelaRubricas titulo="Outros descontos" linhas={descontos} cor="#dc2626" />
      <Descriptions size="small" bordered column={1} title={<span style={{ fontSize: 12, textTransform: 'uppercase' }}>Retenções / impostos</span>} style={{ marginBottom: 12 }}
        contentStyle={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
        {!r.avencado && <Descriptions.Item label={`INSS trabalhador${pctTrab ? ` (${pctTrab})` : ''}`}><span style={{ color: '#dc2626', fontWeight: 600 }}>{formatarKz(r.inss_trabalhador)}</span></Descriptions.Item>}
        {!r.avencado && Number(r.isencoes) > 0 && <Descriptions.Item label="Isenções (não sujeitas a IRT)">{formatarKz(r.isencoes)}</Descriptions.Item>}
        {!r.avencado && <Descriptions.Item label="Base IRT (matéria colectável)">{formatarKz(r.base_irt)}</Descriptions.Item>}
        <Descriptions.Item label={r.avencado ? 'IRT Grupo B (6,5 %)' : 'IRT'}><span style={{ color: '#dc2626', fontWeight: 600 }}>{formatarKz(r.irt)}</span></Descriptions.Item>
        {r.irt_escalao && <Descriptions.Item label="Escalão do IRT">{`${formatarKz(r.irt_escalao.fixo)} + ${formatarNumero(r.irt_escalao.taxa)} % × ${formatarKz(r.irt_escalao.excesso)}`}</Descriptions.Item>}
      </Descriptions>
      {!r.avencado && (
        <Descriptions size="small" bordered column={1} title={<span style={{ fontSize: 12, textTransform: 'uppercase', color: '#6b21a8' }}>Encargo patronal</span>} contentStyle={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
          <Descriptions.Item label={`INSS patronal${pctPat ? ` (${pctPat})` : ''}`}>{formatarKz(r.inss_patronal)}</Descriptions.Item>
          <Descriptions.Item label={<strong>Custo total para a empresa</strong>}><strong>{formatarKz(Number(r.bruto) + Number(r.inss_patronal))}</strong></Descriptions.Item>
        </Descriptions>
      )}
      {r.avisos?.length > 0 && <Alert type="warning" showIcon style={{ marginTop: 12 }} message="Avisos do cálculo" description={<ul style={{ margin: 0, paddingLeft: 18 }}>{r.avisos.map((a, i) => <li key={i}>{a}</li>)}</ul>} />}
      <Typography.Paragraph type="secondary" style={{ textAlign: 'center', marginTop: 12, marginBottom: 0, fontSize: 12 }}>
        {simulacao ? 'Simulação — calculada no servidor com os lançamentos gravados; nada foi gravado. Os valores podem mudar até ao encerramento.' : 'Valores da fotografia do período (imutáveis).'}
      </Typography.Paragraph>
    </>
  );
}

/**
 * «Simulação da folha» / «Mapa detalhado» (legado `imprimirSimulacaoPeriodo`): mapa com TODAS as rubricas em colunas,
 * folhas separadas Colaboradores / Avençados, assinaturas e marca «Simulação» até à validação. Cálculo do servidor.
 * Imprime (ou guarda em PDF) com a orientação escolhida para este documento (por omissão: automática — horizontal
 * quando há muitas colunas).
 */
export function BotaoFolhaDetalhada({ periodo, nome, texto }: { periodo: DetalhePeriodo; nome: (r: ResultadoSalarial) => string; texto: string }) {
  const { imprimir, aImprimir } = useImpressao();
  const chave = 'rh folha detalhada';
  const [pref, setPref] = useState(() => lerPreferencia(chave));
  const temAv = periodo.resultados.some((r) => r.avencado);
  const temCol = periodo.resultados.some((r) => !r.avencado);
  const executar = (grupo?: 'colaboradores' | 'avencados', modo: 'imprimir' | 'pdf' = 'imprimir') =>
    void imprimir({ ...aplicarPreferencia(pedidoSimulacaoPeriodo({ resultados: periodo.resultados, nome, mesAno: periodo.mes_ano, estado: periodo.estado, grupo }), pref), modo });
  return (
    <Space.Compact>
    <SeletorPagina valor={pref} aoMudar={(p) => { setPref(p); gravarPreferencia(chave, p); }} desactivado={!periodo.resultados.length} />
    <Dropdown.Button icon={<DownOutlined />} loading={aImprimir} disabled={!periodo.resultados.length} onClick={() => executar()}
      menu={{
        items: [
          { key: 'pdf', label: 'Guardar em PDF (todos)' },
          { type: 'divider' },
          { key: 'colaboradores', label: 'Só colaboradores', disabled: !temCol },
          { key: 'avencados', label: 'Só avençados', disabled: !temAv },
        ],
        onClick: ({ key }) => (key === 'pdf' ? executar(undefined, 'pdf') : executar(key as 'colaboradores' | 'avencados')),
      }}>
      <PrinterOutlined /> {texto}
    </Dropdown.Button>
    </Space.Compact>
  );
}

/**
 * Recibo de vencimento individual (a partir da linha do processamento): pré-visualização das duas vias e impressão/PDF
 * com o original e o duplicado na mesma folha (vertical: uma por cima da outra; horizontal: lado a lado).
 */
export function ModalReciboColaborador({ resultado, mesAno, colaborador, aoFechar }: {
  resultado: ResultadoSalarial | null;
  mesAno: string;
  colaborador: DadosReciboColaborador;
  aoFechar: () => void;
}) {
  const empresa = useEmpresaRecibo();
  return (
    <Modal open={!!resultado} onCancel={aoFechar} width={larguraModal(980)} destroyOnHidden
      title={<Space><FileTextOutlined />Recibo de vencimento — {colaborador.nome} <Tag>{mesAno}</Tag></Space>}
      footer={<Space wrap><BotaoImprimir texto="Imprimir recibo" titulo={`Recibo de vencimento ${mesAno} — ${colaborador.nome}`} /><Button onClick={aoFechar}>Fechar</Button></Space>}>
      {resultado && (
        <AreaImpressao>
          <ReciboSalario resultado={resultado} mesAno={mesAno} colaborador={colaborador} empresa={empresa} />
        </AreaImpressao>
      )}
    </Modal>
  );
}
