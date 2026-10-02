import { Alert, Button, Card, Col, Empty, Flex, Form, InputNumber, Row, Select, Skeleton, Space, Tabs, Tag, Typography } from 'antd';
import { ReloadOutlined, RightOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useRef, useState } from 'react';
import { BotoesExportar, clonarParaImpressao } from '@/componentes/impressao';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { GraficoAuto, type GraficoApi } from '@/componentes/graficos/Graficos';
import { CartaoKpi, TabelaGestao, tabelaGestaoHtml, useRotaDaVista, type TabelaApiGestao } from './comum/componentes';
import { AnaliseDinamica } from './comum/AnaliseDinamica';
import type { ConjuntoCubo } from './comum/pivot';

interface PainelLista {
  holding: boolean;
  paineis: { id: string; nome: string; tipo: 'PAINEL' | 'CUBO' | 'COMPARACAO' }[];
}

interface KpiPainel {
  id: string;
  rotulo: string;
  valor: unknown;
  formato: string;
  subtitulo?: string | null;
  ir?: string | null;
}

interface DadosPainel {
  modulo: { id: string; nome: string };
  empresa?: { id: number; nome: string; holding: boolean };
  periodo: { ano: number; mes: number; meses: { chave: string; rotulo: string }[] };
  filtros_ignorados?: string[];
  aviso?: string | null;
  kpis: KpiPainel[];
  graficos?: GraficoApi[];
  tabelas?: TabelaApiGestao[];
  atalhos?: { rotulo: string; vista: string }[];
  calculado_em?: string;
}

const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

interface Filtros {
  ano: number;
  mes: number;
  iva: 'sem' | 'com';
  unidade_negocio_id?: number;
  centro_custo_id?: number;
}

/** Geral › Dashboard: painéis por módulo (KPIs, gráficos e tabelas do período), comparação de empresas e análise dinâmica. */
export default function Dashboard() {
  const lista = useQuery({ queryKey: ['gestao', 'paineis'], queryFn: () => obter<PainelLista>('/gestao/paineis') });
  const { empresas } = useSessao();
  const [filtros, setFiltros] = useState<Filtros>({ ano: dayjs().year(), mes: dayjs().month() + 1, iva: 'sem' });
  const [activo, setActivo] = useState<string | undefined>();

  if (lista.isLoading) return <Skeleton active />;
  if (lista.error) return <Alert type="error" showIcon message="Não foi possível carregar os painéis." />;
  const paineis = lista.data?.paineis ?? [];

  return (
    <>
      <CabecalhoPagina titulo="Dashboard" subtitulo={lista.data?.holding ? 'Holding — valores consolidados e por empresa do grupo' : 'Indicadores por módulo da empresa activa'} />
      {paineis.length === 0 ? (
        <Empty description="Não tem acesso a nenhum painel nesta empresa." />
      ) : (
        <Tabs
          destroyOnHidden
          activeKey={activo ?? paineis[0].id}
          onChange={setActivo}
          items={[
            ...paineis.map((p) => ({
              key: p.id,
              label: p.nome,
              children: p.tipo === 'CUBO' ? <Cubo /> : <PainelModulo modulo={p.id} filtros={filtros} aoMudarFiltros={setFiltros} aoIrPara={(ir) => paineis.some((x) => x.id === ir) && setActivo(ir)} />,
            })),
            // a comparação mostra valores contabilísticos: exige as vistas do painel de Contabilidade (como o servidor)
            ...(empresas.length > 1 && paineis.some((p) => p.id === 'contabilidade') ? [{ key: '__comparacao', label: 'Comparar empresas', children: <ComparacaoEmpresas filtros={filtros} aoMudarFiltros={setFiltros} /> }] : []),
          ]}
        />
      )}
    </>
  );
}

function BarraPeriodo({ filtros, aoMudar, dimensoes, aoActualizar, aActualizar }: {
  filtros: Filtros;
  aoMudar: (f: Filtros) => void;
  dimensoes?: boolean;
  aoActualizar: () => void;
  aActualizar?: boolean;
}) {
  const un = useUnidadesNegocio(!!dimensoes);
  const cc = useTabelaAux('centros-custo', !!dimensoes);
  return (
    <Card size="small" style={{ marginBottom: 16 }}>
      <Form layout="inline" style={{ rowGap: 8 }}>
        <Form.Item label="Ano">
          <InputNumber min={1900} max={2999} value={filtros.ano} onChange={(v) => v && aoMudar({ ...filtros, ano: v })} style={{ width: 96 }} />
        </Form.Item>
        <Form.Item label="Mês">
          <Select value={filtros.mes} onChange={(m: number) => aoMudar({ ...filtros, mes: m })} style={{ width: 130 }} options={MESES.map((n, i) => ({ value: i + 1, label: n }))} />
        </Form.Item>
        {dimensoes && (
          <>
            <Form.Item label="Valores">
              <Select value={filtros.iva} onChange={(v: 'sem' | 'com') => aoMudar({ ...filtros, iva: v })} style={{ width: 110 }} options={[{ value: 'sem', label: 'Sem IVA' }, { value: 'com', label: 'Com IVA' }]} />
            </Form.Item>
            <Form.Item label="Unidade de negócio">
              <Select
                allowClear
                showSearch
                optionFilterProp="label"
                value={filtros.unidade_negocio_id}
                onChange={(v?: number) => aoMudar({ ...filtros, unidade_negocio_id: v })}
                style={{ width: 200 }}
                loading={un.isLoading}
                options={(un.data ?? []).map((u) => ({ value: u.id, label: u.nome }))}
                placeholder="Todas"
              />
            </Form.Item>
            <Form.Item label="Centro de custo">
              <Select
                allowClear
                showSearch
                optionFilterProp="label"
                value={filtros.centro_custo_id}
                onChange={(v?: number) => aoMudar({ ...filtros, centro_custo_id: v })}
                style={{ width: 200 }}
                loading={cc.isLoading}
                options={(cc.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} — ${c.descricao ?? ''}` }))}
                placeholder="Todos"
              />
            </Form.Item>
          </>
        )}
        <Form.Item>
          <Button icon={<ReloadOutlined />} onClick={aoActualizar} loading={aActualizar} title="Recalcular (ignora a cache de 5 minutos)">
            Actualizar
          </Button>
        </Form.Item>
      </Form>
    </Card>
  );
}

/** Conteúdo de um painel: KPIs, gráficos, tabelas e atalhos. Reaproveitado pela comparação de empresas. */
export function ConteudoPainel({ dados, aoIrPara, tituloImpressao }: { dados: DadosPainel; aoIrPara?: (ir: string) => void; tituloImpressao?: string }) {
  const navegar = useNavigate();
  const refVisual = useRef<HTMLDivElement>(null);
  const rotaDaVista = useRotaDaVista();
  const atalhos = (dados.atalhos ?? []).map((a) => ({ ...a, rota: rotaDaVista(a.vista) })).filter((a) => a.rota);
  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <Flex justify="end">
        <BotoesExportar
          tamanho="small"
          obterPedido={() => ({
            titulo: tituloImpressao ?? `Dashboard · ${dados.modulo.nome}`,
            periodo: `${MESES[dados.periodo.mes - 1] ?? dados.periodo.mes} de ${dados.periodo.ano}`,
            filtros: [dados.aviso, dados.calculado_em ? `Calculado em ${formatarDataHora(dados.calculado_em)}` : null],
            estilosDaPagina: true,
            conteudo: (refVisual.current ? clonarParaImpressao(refVisual.current) : '') + (dados.tabelas ?? []).map(tabelaGestaoHtml).join(''),
          })}
        />
      </Flex>
      {dados.aviso && <Alert type="info" showIcon message={dados.aviso} />}
      {!!dados.filtros_ignorados?.length && <Alert type="warning" showIcon message="Este painel não suporta os filtros por unidade de negócio ou centro de custo; foram ignorados." />}
      <div ref={refVisual}>
      <Row gutter={[12, 12]}>
        {dados.kpis.map((k) => (
          <Col key={k.id} xs={24} sm={12} md={8} xl={6}>
            <CartaoKpi rotulo={k.rotulo} valor={k.valor} formato={k.formato} subtitulo={k.subtitulo} aoClicar={k.ir && aoIrPara ? () => aoIrPara(k.ir!) : undefined} />
          </Col>
        ))}
      </Row>
      {!!dados.graficos?.length && (
        <Row gutter={[16, 16]} style={{ marginTop: 16 }}>
          {dados.graficos.map((g) => (
            <Col key={g.id} xs={24} xl={dados.graficos!.length === 1 ? 24 : 12}>
              <Card size="small" style={{ height: '100%' }}>
                <GraficoAuto grafico={g} />
              </Card>
            </Col>
          ))}
        </Row>
      )}
      </div>
      {(dados.tabelas ?? []).map((t) => (
        <TabelaGestao key={t.id} tabela={t} />
      ))}
      {atalhos.length > 0 && (
        <Flex gap={8} wrap>
          {atalhos.map((a) => (
            <Button key={a.vista} icon={<RightOutlined />} onClick={() => navegar(a.rota!)}>
              {a.rotulo}
            </Button>
          ))}
        </Flex>
      )}
      {dados.calculado_em && (
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          Calculado em {formatarDataHora(dados.calculado_em)}
        </Typography.Text>
      )}
    </Space>
  );
}

function PainelModulo({ modulo, filtros, aoMudarFiltros, aoIrPara }: { modulo: string; filtros: Filtros; aoMudarFiltros: (f: Filtros) => void; aoIrPara: (ir: string) => void }) {
  const cliente = useQueryClient();
  const [forcar, setForcar] = useState(0);
  const consulta = useQuery({
    queryKey: ['gestao', 'painel', modulo, filtros, forcar],
    queryFn: () => obter<DadosPainel>(`/gestao/paineis/${modulo}`, { ...filtros, actualizar: forcar ? 1 : undefined }),
    staleTime: 60_000,
  });
  const dimensoes = modulo !== 'grupo';
  return (
    <>
      <BarraPeriodo
        filtros={filtros}
        aoMudar={aoMudarFiltros}
        dimensoes={dimensoes}
        aActualizar={consulta.isFetching}
        aoActualizar={() => {
          setForcar((n) => n + 1);
          void cliente.invalidateQueries({ queryKey: ['gestao', 'painel', modulo] });
        }}
      />
      {consulta.isLoading ? (
        <Skeleton active />
      ) : consulta.error ? (
        <Alert type="error" showIcon message={(consulta.error as Error).message} />
      ) : consulta.data ? (
        <ConteudoPainel dados={consulta.data} aoIrPara={aoIrPara} />
      ) : null}
    </>
  );
}

function ComparacaoEmpresas({ filtros, aoMudarFiltros }: { filtros: Filtros; aoMudarFiltros: (f: Filtros) => void }) {
  const { empresas } = useSessao();
  const [escolhidas, setEscolhidas] = useState<number[]>([]);
  const [forcar, setForcar] = useState(0);
  const consulta = useQuery({
    queryKey: ['gestao', 'comparacao', escolhidas, filtros.ano, filtros.mes, forcar],
    queryFn: () => obter<DadosPainel>('/gestao/paineis/comparacao', { empresas: escolhidas.length ? escolhidas : undefined, ano: filtros.ano, mes: filtros.mes, actualizar: forcar ? 1 : undefined }),
    staleTime: 60_000,
  });
  return (
    <>
      <BarraPeriodo filtros={filtros} aoMudar={aoMudarFiltros} aoActualizar={() => setForcar((n) => n + 1)} aActualizar={consulta.isFetching} />
      <Card size="small" style={{ marginBottom: 16 }}>
        <Flex gap={8} align="center" wrap>
          <Typography.Text>Empresas:</Typography.Text>
          <Select
            mode="multiple"
            allowClear
            style={{ flex: '1 1 240px', minWidth: 0 }}
            maxCount={30}
            value={escolhidas}
            onChange={setEscolhidas}
            optionFilterProp="label"
            placeholder="Todas as empresas a que tem acesso (excepto holdings, até 30)"
            options={empresas.map((e) => ({ value: e.id, label: e.nome }))}
          />
          <Tag>Contabilidade · classe 9 e apuramento excluídos</Tag>
        </Flex>
      </Card>
      {consulta.isLoading ? <Skeleton active /> : consulta.error ? <Alert type="error" showIcon message={(consulta.error as Error).message} /> : consulta.data ? <ConteudoPainel dados={consulta.data} tituloImpressao="Comparação de empresas" /> : null}
    </>
  );
}

function Cubo() {
  const conjuntos = useQuery({ queryKey: ['gestao', 'cubo', 'conjuntos'], queryFn: () => obter<ConjuntoCubo[]>('/gestao/cubo/conjuntos'), staleTime: 300_000 });
  const [id, setId] = useState<string | undefined>();
  if (conjuntos.isLoading) return <Skeleton active />;
  const lista = conjuntos.data ?? [];
  if (!lista.length) return <Empty description="Não há conjuntos de dados disponíveis para as suas permissões." />;
  const actual = lista.find((c) => c.id === id) ?? lista[0];
  return (
    <Space direction="vertical" size={12} style={{ width: '100%' }}>
      <Flex gap={8} align="center" wrap>
        <Typography.Text strong>Conjunto de dados:</Typography.Text>
        <Select style={{ minWidth: 280 }} value={actual.id} onChange={setId} options={lista.map((c) => ({ value: c.id, label: c.nome }))} />
        {actual.holding && <Tag color="purple">Holding</Tag>}
      </Flex>
      <AnaliseDinamica key={actual.id} conjunto={actual} urlConsultar="/gestao/cubo/consultar" enviarConjunto comValores />
    </Space>
  );
}
