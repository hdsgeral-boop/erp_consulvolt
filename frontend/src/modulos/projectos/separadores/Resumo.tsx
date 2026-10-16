import { Button, Card, Col, Descriptions, Empty, Progress, Row, Space, Table, Tag, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useRef, type ReactNode } from 'react';
import { BotoesExportar } from '@/componentes/impressao';
import { GraficoBarras, GraficoDonut, GraficoLinhas } from '@/componentes/graficos/Graficos';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { COLUNAS_DESCRICOES } from '@/componentes/responsivo';
import { obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { rotuloProjectos as rotuloTipo } from '../comum/componentes';
import { pct1, ritmoPrazo, rotuloRubrica } from '../comum/regras';
import { formatarQtd } from '../comum/regrasMetas';
import type { EquipamentosProjecto, ResumoProjecto } from '../comum/tipos';
import { OrigemProjecto, textoOrigem } from '../comum/OrigemProjecto';
import { AnelProgresso } from '../comum/AnelProgresso';
import type { PropsSeparador } from '../DetalheProjecto';
import { scrollTabela } from '@/componentes/responsivo';

/** Cores fixas do Resumo no legado (projectos_dashboard.js:28). */
const COR = { azul: '#2563eb', verde: '#16a34a', laranja: '#ea580c', roxo: '#7c3aed', ciano: '#0891b2', vermelho: '#dc2626', cinza: '#64748b', ambar: '#d97706' } as const;
/** Cor do consumo do orçamento (legado: > 100 % vermelho, ≥ 85 % âmbar, abaixo verde). */
const corConsumo = (c: number | null | undefined) => (c === null || c === undefined ? COR.cinza : c > 100 ? COR.vermelho : c >= 85 ? COR.ambar : COR.verde);
/**
 * Cores por fatia dos donuts do legado (projectos_dashboard.js:627, 672, 714 — `backgroundColor: [...]`), repetidas em ciclo
 * como no Chart.js quando há mais fatias do que cores.
 */
const CORES_ORIGEM = [COR.roxo, COR.azul, COR.cinza, COR.laranja, COR.ciano, COR.verde];
const CORES_ORC_RUBRICA = [COR.azul, COR.laranja, COR.roxo, COR.ciano, COR.cinza, COR.verde];
const CORES_PAPEIS = [COR.azul, COR.verde, COR.laranja, COR.roxo, COR.ciano, COR.cinza, COR.ambar, COR.vermelho];
const ciclo = (cores: readonly string[]) => (_r: string, i: number) => cores[i % cores.length];
/** Só as categorias com valor > 0, pela ordem da API (o legado filtra antes de atribuir as cores). */
const positivos = (o: Record<string, number | string> | null | undefined) => Object.entries(o ?? {}).filter(([, v]) => Number(v) > 0);
/** Barra de execução por milestone (legado: ≥ 100 % verde, > 0 azul, 0 cinzento-claro). */
const corExecucao = (v: number) => (v >= 100 ? COR.verde : v > 0 ? COR.azul : '#cbd5e1');
const limitar = (v: number | null | undefined) => Math.min(100, Math.max(0, Number(v ?? 0)));

/** Barra fina dos cartões do Resumo (legado: .pdash-barra). */
function Barra({ pct, cor }: { pct: number | null | undefined; cor: string }) {
  return <Progress percent={limitar(pct)} showInfo={false} size="small" strokeColor={cor} style={{ margin: '4px 0' }} />;
}

/** Barra empilhada das tarefas por estado (concluídas, em curso, pendentes, bloqueadas). */
function BarraEstados({ partes }: { partes: { n: number; cor: string; rotulo: string }[] }) {
  const { token } = theme.useToken();
  const total = partes.reduce((t, p) => t + p.n, 0);
  return (
    <div role="img" aria-label={partes.map((p) => `${p.n} ${p.rotulo}`).join(', ')}
      style={{ display: 'flex', height: 8, borderRadius: 4, overflow: 'hidden', background: token.colorFillSecondary, margin: '6px 0' }}>
      {total > 0 && partes.filter((p) => p.n > 0).map((p) => <span key={p.rotulo} style={{ width: `${(p.n / total) * 100}%`, background: p.cor }} />)}
    </div>
  );
}

const Linha = ({ children }: { children: ReactNode }) => <span style={{ display: 'block' }}>{children}</span>;

/**
 * Resumo e indicadores do projecto (GET /projetos/{id}/resumo) — renderProjectResumo, projectos_dashboard.js:332-723:
 * linha de cartões com a barra à esquerda (Progresso global, Tarefas, Prazo, Venda global, Orçamento × custo, Margem /
 * Custo por rubrica), grelha de gráficos (Curva S a toda a largura; orçado × realizado; origem dos custos; execução por
 * milestone; orçamento por rubrica; horas por colaborador; equipa por papel), equipamentos alocados e, em baixo, os
 * alertas à esquerda e a ficha à direita.
 */
export function SeparadorResumo({ projecto, aoIr }: PropsSeparador & { aoIr: (separador: string) => void }) {
  const { token } = theme.useToken();
  const q = useQuery({ queryKey: ['projectos', 'resumo', projecto.id], queryFn: () => obter<ResumoProjecto>(`/projetos/${projecto.id}/resumo`) });
  // #25a: equipamentos alocados (agregado por equipamento, com categoria e estado do activo)
  const equip = useQuery({ queryKey: ['projectos', 'equipamentos', projecto.id], queryFn: () => obter<EquipamentosProjecto>(`/projetos/${projecto.id}/equipamentos`) });
  const ref = useRef<HTMLDivElement>(null);
  if (q.isLoading || !q.data) return <Card loading />;
  const r = q.data;
  const i = r.indicadores;
  const ritmo = ritmoPrazo(i.prazo.desempenho);
  const externo = i.venda_global !== undefined;
  const nome = (x: string | { nome?: string; numero_documento?: string } | null) => (!x ? '—' : typeof x === 'string' ? x : x.nome ?? x.numero_documento ?? '—');
  const est = i.estados;
  const atrasado = i.prazo.restantes_dias !== null && i.prazo.restantes_dias < 0;
  const custoRubricas = r.orcado_realizado.filter((l) => Number(l.realizado) !== 0);
  const corAlerta = (n: string) => (n === 'ERRO' ? COR.vermelho : n === 'AVISO' ? COR.ambar : COR.azul);

  return (
    <div ref={ref}>
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <div className="imp-nao-imprimir" style={{ display: 'flex', justifyContent: 'flex-end' }}>
        <BotoesExportar
          textoImprimir="Imprimir ficha"
          obterPedido={() =>
            ref.current
              ? {
                  titulo: `Ficha do projecto ${projecto.codigo ?? ''} — ${projecto.nome}`,
                  subtitulo: `${rotuloTipo(projecto.tipo)} · ${rotuloTipo(projecto.estado)}${projecto.cliente ? ` · Cliente: ${projecto.cliente.nome}` : ''}`,
                  periodo: i.prazo.inicio || i.prazo.fim ? `${formatarData(i.prazo.inicio)} a ${formatarData(i.prazo.fim)}` : undefined,
                  conteudo: ref.current,
                }
              : null
          }
        />
      </div>

      {/* Linha de cartões (.pdash-kpis: auto-fit, mínimo 235 px; barra de 4 px à esquerda) */}
      <GrelhaKpi larguraMinima={235}>
        {/* legado (projectos_dashboard.js:418-420): mostrador em anel SVG com a legenda «Execução física» ao lado */}
        <CartaoKpi barra="esquerda" cor={COR.azul} rotulo="Progresso global"
          valor={<AnelProgresso valor={i.execucao} cor={COR.azul} legenda="Execução física"
            nota={i.execucao_ponderada !== null ? <>Ponderado pelo orçamento: <strong>{i.execucao_ponderada}%</strong></> : 'Média dos ramos do WBS'} />} />
        <CartaoKpi barra="esquerda" cor={COR.verde} rotulo="Tarefas (WBS)" valor={`${est.CONCLUIDA ?? 0} / ${i.tarefas}`} unidade="concluídas"
          secundaria={<><BarraEstados partes={[
            { n: est.CONCLUIDA ?? 0, cor: COR.verde, rotulo: 'concluídas' }, { n: est.EM_CURSO ?? 0, cor: COR.azul, rotulo: 'em curso' },
            { n: est.PENDENTE ?? 0, cor: '#94a3b8', rotulo: 'pendentes' }, { n: est.BLOQUEADA ?? 0, cor: COR.vermelho, rotulo: 'bloqueadas' },
          ]} />
            <Linha>{est.CONCLUIDA ?? 0} concl. · {est.EM_CURSO ?? 0} em curso · {est.PENDENTE ?? 0} pend. · {est.BLOQUEADA ?? 0} bloq.</Linha></>} />
        <CartaoKpi barra="esquerda" cor={COR.ambar} rotulo="Prazo" valor={`${i.prazo.tempo_pct ?? 0}%`} unidade="do prazo decorrido"
          secundaria={<><Barra pct={i.prazo.tempo_pct} cor={atrasado ? COR.vermelho : COR.ambar} />
            <Linha>{formatarData(i.prazo.inicio)} → {formatarData(i.prazo.fim)}</Linha>
            <Linha>
              {i.prazo.restantes_dias === null ? '—' : i.prazo.restantes_dias >= 0 ? `${i.prazo.restantes_dias} dia(s) restantes` : <Typography.Text type="danger">{Math.abs(i.prazo.restantes_dias)} dia(s) além do prazo</Typography.Text>}
              {ritmo && <> · ritmo <strong style={{ color: ritmo.cor }}>{ritmo.texto}</strong></>}
            </Linha></>} />
        {externo && (
          // projectos EXTERNOS: venda global (projectos_dashboard.js:437-447)
          <CartaoKpi barra="esquerda" cor={COR.roxo} rotulo="Venda global (sem IVA)" valor={formatarKz(i.venda_global)} unidade="Kz"
            secundaria={<><Barra pct={i.faturado_pct} cor={COR.roxo} />
              <Linha>Facturado (menos NC): <strong>{formatarKz(i.faturado)}</strong> · {pct1(i.faturado_pct)}</Linha>
              <Linha>{Number(i.aditamentos_aprovados ?? 0) ? `Inclui ${formatarKz(i.aditamentos_aprovados)} de aditamentos aprovados` : 'Sem aditamentos aprovados'}</Linha></>} />
        )}
        <CartaoKpi barra="esquerda" cor={COR.laranja} rotulo="Orçamento × custo" valor={formatarKz(i.orcamento)} unidade="Kz"
          secundaria={<><Barra pct={i.consumo_pct} cor={corConsumo(i.consumo_pct)} />
            <Linha>Custo realizado: <strong style={{ color: Number(i.disponivel) < 0 ? token.colorError : undefined }}>{formatarKz(i.custo)}</strong>{i.consumo_pct !== null ? ` · ${i.consumo_pct}% consumido` : ''}</Linha>
            <Linha>Disponível: {formatarKz(i.disponivel)} · Compromissos: {formatarKz(i.compromissos)}</Linha></>} />
        {externo ? (
          <CartaoKpi barra="esquerda" cor={COR.ciano} rotulo="Margem" valor={<>{formatarKz(i.margem_prevista)} <small>({pct1(i.margem_prevista_pct)})</small></>}
            corValor={Number(i.margem_prevista) < 0 ? token.colorError : undefined}
            secundaria={<><Linha>Prevista (venda − orçamento)</Linha><Barra pct={i.margem_real_pct} cor={Number(i.margem_real) >= 0 ? COR.verde : COR.vermelho} />
              <Linha>Real (facturado − custo): <strong style={{ color: Number(i.margem_real) >= 0 ? COR.verde : COR.vermelho }}>{formatarKz(i.margem_real)}</strong> · {pct1(i.margem_real_pct)}</Linha></>} />
        ) : (
          <CartaoKpi barra="esquerda" cor={COR.ciano} rotulo="Custo por rubrica" valor={formatarKz(i.custo)} unidade="Kz"
            secundaria={custoRubricas.length
              ? custoRubricas.map((l) => <Linha key={l.rubrica}>{rotuloRubrica(l.rubrica)}: {formatarKz(l.realizado)}</Linha>)
              : 'Sem custos realizados'} />
        )}
      </GrelhaKpi>

      {/* Grelha de gráficos (.pdash-graf: auto-fit, mínimo 340 px; a Curva S ocupa a linha toda) */}
      <Card size="small" title="Curva S — orçamento previsto, custo realizado e facturação (acumulados)">
        <CurvaS meses={r.curva_s.meses} series={[
          { nome: 'Orçamento previsto', valores: r.curva_s.previsto, cor: COR.laranja },
          { nome: 'Custo realizado', valores: r.curva_s.realizado, cor: COR.vermelho },
          ...(r.curva_s.faturado ? [{ nome: 'Facturação (sem IVA)', valores: r.curva_s.faturado, cor: COR.verde }] : []),
        ]} />
      </Card>
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={12}>
          <Card size="small" title="Orçado × realizado por rubrica" style={{ height: '100%' }}>
            {r.orcado_realizado.length ? (
              <GraficoBarras horizontal monetario rotulos={r.orcado_realizado.map((l) => rotuloRubrica(l.rubrica))}
                series={[{ rotulo: 'Orçado', valores: r.orcado_realizado.map((l) => Number(l.orcado)), cor: COR.azul },
                  // legado: «Realizado» a vermelho na rubrica acima do orçado, verde nas restantes (cor por barra)
                  { rotulo: 'Realizado', valores: r.orcado_realizado.map((l) => Number(l.realizado)), cor: COR.verde,
                    cores: r.orcado_realizado.map((l) => (Number(l.realizado) > Number(l.orcado) ? COR.vermelho : COR.verde)) }]} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
            {r.orcado_realizado.length > 0 && (
              <Table scroll={scrollTabela()} size="small" rowKey="rubrica" pagination={false} dataSource={r.orcado_realizado} style={{ marginTop: 8 }}
                columns={[
                  { title: 'Rubrica', dataIndex: 'rubrica', render: rotuloRubrica },
                  { title: 'Orçado', dataIndex: 'orcado', align: 'right', render: (v) => <ValorKz valor={v} /> },
                  { title: 'Realizado', dataIndex: 'realizado', align: 'right', render: (v, l) => <Typography.Text type={Number(v) > Number(l.orcado) ? 'danger' : undefined}>{formatarKz(v)}</Typography.Text> },
                ]} />
            )}
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card size="small" title="Origem dos custos realizados" style={{ height: '100%' }}>
            {positivos(r.origem_custos).length ? (
              <GraficoDonut monetario coresCategorias={ciclo(CORES_ORIGEM)} rotulos={positivos(r.origem_custos).map(([k]) => rotuloRubrica(k))}
                series={[{ rotulo: 'Custo', valores: positivos(r.origem_custos).map(([, v]) => Number(v)) }]} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
            <Typography.Text type="secondary" style={{ display: 'block', marginTop: 8 }}>
              Contabilidade: proveitos {formatarKz(r.contabilidade.proveitos)} · custos {formatarKz(r.contabilidade.custos)} ({r.contabilidade.linhas} linha(s))
            </Typography.Text>
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card size="small" title="Execução por milestone" style={{ height: '100%' }}>
            {r.execucao_por_marco.length ? (
              <GraficoBarras horizontal rotulos={r.execucao_por_marco.map((m) => `${m.marco} (${m.tarefas})`)}
                series={[{ rotulo: '% de execução', valores: r.execucao_por_marco.map((m) => m.execucao), cor: COR.azul, cores: r.execucao_por_marco.map((m) => corExecucao(Number(m.execucao))) }]} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card size="small" title="Orçamento por rubrica" style={{ height: '100%' }}>
            {positivos(r.orcamento_por_rubrica).length ? (
              <GraficoDonut monetario coresCategorias={ciclo(CORES_ORC_RUBRICA)} rotulos={positivos(r.orcamento_por_rubrica).map(([k]) => rotuloRubrica(k))}
                series={[{ rotulo: 'Orçamento', valores: positivos(r.orcamento_por_rubrica).map(([, v]) => Number(v)) }]} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card size="small" title="Horas lançadas por colaborador" style={{ height: '100%' }}>
            {(r.horas_por_colaborador ?? []).length ? (
              <GraficoBarras horizontal rotulos={r.horas_por_colaborador.slice(0, 10).map((h) => h.nome)}
                series={[{ rotulo: 'Horas lançadas', valores: r.horas_por_colaborador.slice(0, 10).map((h) => h.horas), cor: COR.ciano }]} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem horas lançadas." />}
          </Card>
        </Col>
        <Col xs={24} lg={12}>
          <Card size="small" title="Equipa por papel" style={{ height: '100%' }}>
            {positivos(r.equipa_por_papel).length ? (
              <GraficoDonut coresCategorias={ciclo(CORES_PAPEIS)} rotulos={positivos(r.equipa_por_papel).map(([k]) => k)}
                series={[{ rotulo: 'Membros', valores: positivos(r.equipa_por_papel).map(([, v]) => Number(v)) }]} />
            ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} />}
          </Card>
        </Col>
      </Row>
      <CartaoEquipamentos dados={equip.data} carregando={equip.isLoading} />

      {/* Rodapé (.pdash-baixo: 1,15fr | 1fr): alertas à esquerda, ficha à direita */}
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={13}>
          <Card size="small" title="Alertas e pontos de atenção" style={{ height: '100%' }}>
            {r.alertas.length ? (
              <Space direction="vertical" size={8} style={{ width: '100%' }}>
                {r.alertas.map((a) => (
                  <div key={a.codigo + a.titulo} style={{ borderLeft: `4px solid ${corAlerta(a.nivel)}`, background: token.colorFillAlter, borderRadius: 4, padding: '6px 10px', display: 'flex', gap: 8, alignItems: 'center' }}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                      <Typography.Text strong>{a.titulo}</Typography.Text>
                      {a.texto && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{a.texto}</Typography.Text>}
                    </div>
                    {a.separador && <Button size="small" onClick={() => aoIr(a.separador as string)}>Abrir</Button>}
                  </div>
                ))}
              </Space>
            ) : <Typography.Text type="success">Sem alertas: o projecto não tem pontos de atenção.</Typography.Text>}
          </Card>
        </Col>
        <Col xs={24} lg={11}>
          <Card size="small" title="Ficha do projecto" style={{ height: '100%' }}>
            <Descriptions size="small" column={COLUNAS_DESCRICOES}>
              {textoOrigem(projecto) && <Descriptions.Item label="Origem"><OrigemProjecto projecto={projecto} /></Descriptions.Item>}
              <Descriptions.Item label="Código">{r.ficha.codigo ?? '—'}</Descriptions.Item>
              <Descriptions.Item label="Estado"><Tag>{rotuloTipo(r.ficha.estado)}</Tag></Descriptions.Item>
              <Descriptions.Item label="Designação">{r.ficha.nome}</Descriptions.Item>
              <Descriptions.Item label="Tipo">{rotuloTipo(r.ficha.tipo)}</Descriptions.Item>
              <Descriptions.Item label="Cliente">{nome(r.ficha.cliente)}</Descriptions.Item>
              <Descriptions.Item label="Encomenda">{nome(r.ficha.encomenda)}</Descriptions.Item>
              <Descriptions.Item label="Unidade de negócio">{r.ficha.unidade_negocio ? `${r.ficha.unidade_negocio.codigo ?? ''} ${r.ficha.unidade_negocio.nome}` : '—'}</Descriptions.Item>
              <Descriptions.Item label="Centro de custo">{r.ficha.centro_custo ? `${r.ficha.centro_custo.codigo} — ${r.ficha.centro_custo.descricao ?? ''}` : '—'}</Descriptions.Item>
              <Descriptions.Item label="Equipa">{r.ficha.membros} membro(s), {r.ficha.internos} interno(s)</Descriptions.Item>
              <Descriptions.Item label="Esforço alocado">{r.ficha.horas_dia_alocadas} h/dia</Descriptions.Item>
              <Descriptions.Item label="Horas lançadas">{r.ficha.horas_lancadas} h</Descriptions.Item>
              <Descriptions.Item label="Milestones">{r.ficha.marcos}</Descriptions.Item>
              <Descriptions.Item label="Autos / revisões">{r.ficha.revisoes}</Descriptions.Item>
              <Descriptions.Item label="Posições no organigrama">{r.ficha.posicoes_organigrama}</Descriptions.Item>
            </Descriptions>
          </Card>
        </Col>
      </Row>
    </Space>
    </div>
  );
}

/** Curva S (acumulado) com o gráfico de linhas comum (eixos, dica, alternância gráfico/tabela e impressão). */
function CurvaS({ meses, series }: { meses: string[]; series: { nome: string; valores: number[]; cor: string }[] }) {
  return <GraficoLinhas rotulos={meses} series={series.map((s) => ({ rotulo: s.nome, valores: s.valores, cor: s.cor }))} monetario />;
}

/**
 * Equipamentos alocados ao projecto (projectos_dashboard.js:381-407, 498-537): por equipamento, categoria e estado do
 * activo, horas, custo/hora médio, custo imputado, período de uso e tarefas; no topo, o total e o peso no custo.
 */
function CartaoEquipamentos({ dados, carregando }: { dados: EquipamentosProjecto | undefined; carregando: boolean }) {
  const lista = dados?.por_equipamento ?? [];
  const horas = lista.reduce((s, e) => s + Number(e.horas || 0), 0);
  const custo = lista.reduce((s, e) => s + Number(e.custo || 0), 0);
  const custoProjeto = Number(dados?.custo_projeto ?? 0);
  return (
    <Card size="small" loading={carregando} title="Equipamentos alocados ao projecto"
      extra={lista.length ? <Typography.Text type="secondary">{lista.length} equipamento(s) · {formatarQtd(horas, 1)} h · <strong>{formatarKz(custo)}</strong>{custoProjeto > 0 ? ` · ${formatarQtd((custo / custoProjeto) * 100, 1)}% do custo do projecto` : ''}</Typography.Text> : null}>
      {lista.length ? (
        <Table scroll={scrollTabela()} size="small" pagination={false} rowKey={(e) => e.ativo_codigo ?? '—'} dataSource={lista}
          columns={[
            { title: 'Equipamento', key: 'eq', render: (_, e) => <><strong>{e.ativo_codigo ?? '—'}</strong>{e.ativo_descricao ? ` — ${e.ativo_descricao}` : ''}<Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>{e.usos} registo(s) de utilização</Typography.Text></> },
            { title: 'Categoria', key: 'cat', render: (_, e) => e.ativo_categoria ?? '—' },
            { title: 'Estado', key: 'est', render: (_, e) => (e.ativo_estado ? <Tag>{rotuloTipo(e.ativo_estado)}</Tag> : '—') },
            { title: 'Horas', dataIndex: 'horas', align: 'right', render: (v) => formatarQtd(v, 1) },
            { title: 'Custo/hora médio', dataIndex: 'custo_hora_medio', align: 'right', render: (v) => (v != null ? formatarKz(v) : '—') },
            { title: 'Custo imputado', dataIndex: 'custo', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Período de uso', key: 'per', render: (_, e) => (e.de ? `${formatarData(e.de)} a ${formatarData(e.ate)}` : '—') },
            { title: 'Tarefas', key: 'tar', render: (_, e) => (e.tarefas.length ? `${e.tarefas.slice(0, 2).join(', ')}${e.tarefas.length > 2 ? ` +${e.tarefas.length - 2}` : ''}` : '—') },
          ]}
        />
      ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Ainda não há utilização de equipamento imputada (lança-se no separador Horas e equipamentos)." />}
    </Card>
  );
}
