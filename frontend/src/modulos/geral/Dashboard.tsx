import { Alert, Button, Card, Empty, Flex, Form, Popover, Select, Skeleton, Space, Tag, Typography } from 'antd';
import { FilterOutlined } from '@ant-design/icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useRef, useState, type CSSProperties } from 'react';
import { BotoesExportar, clonarParaImpressao } from '@/componentes/impressao';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import type { GraficoApi } from '@/componentes/graficos/Graficos';
import { IconeFa } from '@/componentes/fluxos';
import { GraficoPainel } from './comum/GraficoPainel';
import { estiloKpi, estiloPainel } from './comum/estiloPainel';
import './comum/painel.css';
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

export interface DadosPainel {
  /** Painel de origem (aspecto e título da exportação). A comparação de empresas devolve `__comparacao`. */
  modulo?: { id: string; nome: string };
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

/** Separador «Comparar empresas» (só existe no sistema novo, para quem tem várias empresas). */
export const COMPARACAO = '__comparacao';
const MODULO_COMPARACAO = { id: COMPARACAO, nome: 'Comparar empresas' };

/**
 * O separador «Comparar empresas» aparece a quem pode ver o painel de Contabilidade e tem mais de uma empresa (que não seja
 * holding) para comparar — ou está numa holding (compara as empresas do grupo).
 */
export function mostraComparacao(empresas: { e_consolidacao?: boolean }[], paineis: { id: string }[], holding: boolean): boolean {
  if (!paineis.some((p) => p.id === 'contabilidade')) return false;
  return holding || empresas.filter((e) => !e.e_consolidacao).length > 1;
}

/**
 * Geral › Dashboard Global (renderPainelModulos, js/ui_painel_modulos.js:964-1012): título «Dashboard» com a empresa e o
 * período de referência, separadores em pílula com o ícone e a cor de cada módulo (várias linhas; o activo fica com a cor
 * do módulo — escuro na Visão Geral), cartões KPI com barra de cor à esquerda e ícone, e gráficos de 12 meses.
 * O separador activo fica no endereço (`?separador=`), para voltar ao mesmo painel.
 */
export default function Dashboard() {
  const lista = useQuery({ queryKey: ['gestao', 'paineis'], queryFn: () => obter<PainelLista>('/gestao/paineis') });
  const { empresas, empresa } = useSessao();
  // legado: os painéis mostram os valores com IVA (total_gross); «Sem IVA» fica disponível nos filtros
  const [filtros, setFiltros] = useState<Filtros>({ ano: dayjs().year(), mes: dayjs().month() + 1, iva: 'com' });
  const [procura, setProcura] = useSearchParams();
  const [actualizar, setActualizar] = useState(0);
  const refConteudo = useRef<HTMLDivElement>(null);
  const cliente = useQueryClient();

  if (lista.isLoading) return <Skeleton active />;
  if (lista.error) return <Alert type="error" showIcon message="Não foi possível carregar os painéis." />;
  const paineis = lista.data?.paineis ?? [];
  const holding = !!lista.data?.holding;
  // a comparação mostra valores contabilísticos: exige as vistas do painel de Contabilidade (como o servidor)
  const separadores = [
    ...paineis.map((p) => ({ id: p.id, nome: p.nome, tipo: p.tipo as string })),
    ...(mostraComparacao(empresas, paineis, holding) ? [{ id: COMPARACAO, nome: MODULO_COMPARACAO.nome, tipo: 'EMPRESAS' }] : []),
  ];
  const pedido = procura.get('separador');
  const activo = separadores.find((p) => p.id === pedido) ?? separadores[0];
  const escolher = (id: string) =>
    setProcura(
      (p) => {
        const n = new URLSearchParams(p);
        n.set('separador', id);
        return n;
      },
      { replace: true },
    );

  // legado (ui_painel_modulos.js:996-1001): Mês, Ano, Actualizar e Ecrã cheio à direita do título
  const hoje = dayjs().year();
  const anos = Array.from({ length: 8 }, (_, i) => hoje + 1 - i);
  const comDimensoes = activo?.tipo === 'PAINEL' && activo.id !== 'grupo';
  const filtrosCabecalho = (
    <Space size={6} wrap className="painel-filtros">
      <Select value={filtros.mes} onChange={(m: number) => setFiltros({ ...filtros, mes: m })} style={{ width: 130 }} aria-label="Mês" options={MESES.map((n, i) => ({ value: i + 1, label: n }))} />
      <Select value={filtros.ano} onChange={(a: number) => setFiltros({ ...filtros, ano: a })} style={{ width: 90 }} aria-label="Ano" options={anos.map((a) => ({ value: a, label: String(a) }))} />
      {comDimensoes && (
        <Popover trigger="click" placement="bottomRight" title="Filtros do painel" content={<FiltrosDimensoes filtros={filtros} aoMudar={setFiltros} />}>
          <Button icon={<FilterOutlined />} title="Valores, unidade de negócio e centro de custo" aria-label="Filtros do painel" />
        </Popover>
      )}
      <Button
        icon={<IconeFa nome="sync-alt" />}
        title="Actualizar"
        aria-label="Actualizar"
        onClick={() => {
          setActualizar((n) => n + 1);
          void cliente.invalidateQueries({ queryKey: ['gestao', 'painel'] });
        }}
      />
      <Button
        icon={<IconeFa nome="expand" />}
        title="Ecrã cheio"
        aria-label="Ecrã cheio"
        onClick={() => {
          const alvo = refConteudo.current;
          if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined);
          else if (alvo?.requestFullscreen) void alvo.requestFullscreen().catch(() => undefined);
        }}
      />
    </Space>
  );

  return (
    <div ref={refConteudo} className="painel-conteudo-wrap">
      <CabecalhoPagina
        titulo="Dashboard"
        subtitulo={
          <Space size={6} wrap>
            <IconeFa nome={holding ? 'sitemap' : 'building'} />
            <span>{empresa?.nome}</span>
            {holding && <Tag color="purple" bordered>Consolidação</Tag>}
            <span>
              · Período de referência: <strong>{MESES[filtros.mes - 1]} {filtros.ano}</strong>
            </span>
          </Space>
        }
        accoes={filtrosCabecalho}
      />
      {separadores.length === 0 || !activo ? (
        <Empty description="Não tem acesso a nenhum painel nesta empresa." />
      ) : (
        <>
          <nav className="painel-modulos" aria-label="Módulos do dashboard">
            {separadores.map((p) => {
              const e = estiloPainel(p.id);
              const eActivo = p.id === activo.id;
              return (
                <button
                  key={p.id}
                  type="button"
                  className={`painel-modulo-btn${eActivo ? ' activo' : ''}`}
                  style={{ '--painel-cor': e.cor } as CSSProperties}
                  aria-pressed={eActivo}
                  onClick={() => escolher(p.id)}
                >
                  <IconeFa nome={e.icone} className="painel-modulo-icone" />
                  <span>{p.nome}</span>
                </button>
              );
            })}
          </nav>
          <div key={activo.id}>
            {activo.tipo === 'CUBO' ? (
              <Cubo />
            ) : activo.id === COMPARACAO ? (
              <ComparacaoEmpresas filtros={filtros} actualizar={actualizar} holding={holding} />
            ) : (
              <PainelModulo modulo={activo.id} filtros={filtros} actualizar={actualizar} aoIrPara={(ir) => paineis.some((x) => x.id === ir) && escolher(ir)} />
            )}
          </div>
        </>
      )}
    </div>
  );
}

/** Valores (com/sem IVA), unidade de negócio e centro de custo: filtros do sistema novo, num botão discreto ao lado do período. */
function FiltrosDimensoes({ filtros, aoMudar }: { filtros: Filtros; aoMudar: (f: Filtros) => void }) {
  const un = useUnidadesNegocio(true);
  const cc = useTabelaAux('centros-custo', true);
  return (
    <Form layout="vertical" size="small" style={{ width: 260 }}>
      <Form.Item label="Valores" style={{ marginBottom: 8 }}>
        <Select value={filtros.iva} onChange={(v: 'sem' | 'com') => aoMudar({ ...filtros, iva: v })} options={[{ value: 'sem', label: 'Sem IVA' }, { value: 'com', label: 'Com IVA' }]} />
      </Form.Item>
      <Form.Item label="Unidade de negócio" style={{ marginBottom: 8 }}>
        <Select allowClear showSearch optionFilterProp="label" value={filtros.unidade_negocio_id} onChange={(v?: number) => aoMudar({ ...filtros, unidade_negocio_id: v })} loading={un.isLoading} options={(un.data ?? []).map((u) => ({ value: u.id, label: u.nome }))} placeholder="Todas" />
      </Form.Item>
      <Form.Item label="Centro de custo" style={{ marginBottom: 0 }}>
        <Select allowClear showSearch optionFilterProp="label" value={filtros.centro_custo_id} onChange={(v?: number) => aoMudar({ ...filtros, centro_custo_id: v })} loading={cc.isLoading} options={(cc.data ?? []).map((c) => ({ value: c.id, label: `${c.codigo} — ${c.descricao ?? ''}` }))} placeholder="Todos" />
      </Form.Item>
    </Form>
  );
}

/** Conteúdo de um painel: KPIs, gráficos, tabelas e atalhos. Reaproveitado pela comparação de empresas. */
export function ConteudoPainel({ dados, aoIrPara, tituloImpressao }: { dados: DadosPainel; aoIrPara?: (ir: string) => void; tituloImpressao?: string }) {
  const navegar = useNavigate();
  const refVisual = useRef<HTMLDivElement>(null);
  const rotaDaVista = useRotaDaVista();
  const atalhos = (dados.atalhos ?? []).map((a) => ({ ...a, rota: rotaDaVista(a.vista) })).filter((a) => a.rota);
  // sem `modulo` (respostas antigas da comparação) o painel não pode rebentar: assume-se a comparação de empresas
  const modulo = dados.modulo ?? MODULO_COMPARACAO;
  const kpis = dados.kpis ?? [];
  // cor do módulo: barra do aviso e contorno dos atalhos (legado htmlConteudo: border-left-color / border-color = mod.cor)
  const corModulo = estiloPainel(modulo.id).cor;
  return (
    <Space direction="vertical" size={16} style={{ width: '100%' }}>
      <Flex justify="end">
        <BotoesExportar
          tamanho="small"
          obterPedido={() => ({
            titulo: tituloImpressao ?? `Dashboard · ${modulo.nome}`,
            periodo: `${MESES[dados.periodo.mes - 1] ?? dados.periodo.mes} de ${dados.periodo.ano}`,
            filtros: [dados.aviso, dados.calculado_em ? `Calculado em ${formatarDataHora(dados.calculado_em)}` : null],
            estilosDaPagina: true,
            conteudo: (refVisual.current ? clonarParaImpressao(refVisual.current) : '') + (dados.tabelas ?? []).map(tabelaGestaoHtml).join(''),
          })}
        />
      </Flex>
      {/* legado .painel-aviso: caixa branca com barra de 4 px na cor do módulo */}
      {dados.aviso && (
        <div className="painel-aviso" role="note" style={{ borderLeftColor: corModulo }}>
          {dados.aviso}
        </div>
      )}
      {!!dados.filtros_ignorados?.length && <Alert type="warning" showIcon message="Este painel não suporta os filtros por unidade de negócio ou centro de custo; foram ignorados." />}
      <div ref={refVisual}>
      {kpis.length > 0 && (
        <div className="painel-kpis">
          {kpis.map((k) => {
            const e = estiloKpi(modulo.id, k.id, k.valor);
            return (
              <CartaoKpi
                key={k.id}
                rotulo={k.rotulo}
                valor={k.valor}
                formato={k.formato}
                subtitulo={k.subtitulo}
                icone={e.icone}
                cor={e.cor}
                barra="esquerda"
                aoClicar={k.ir && aoIrPara ? () => aoIrPara(k.ir!) : undefined}
              />
            );
          })}
        </div>
      )}
      {!!dados.graficos?.length && (
        <div className="painel-grelha">
          {dados.graficos.map((g) => (
            <div key={g.id} className="painel-cartao">
              <GraficoPainel grafico={g} />
            </div>
          ))}
        </div>
      )}
      </div>
      {/* legado: as tabelas ficam na mesma grelha de duas colunas dos gráficos; `largo` (ou mais de 5 colunas, como o
          quadro «Comparação por Empresa») ocupa a linha toda */}
      {!!dados.tabelas?.length && (
        <div className="painel-grelha painel-grelha-tabelas">
          {dados.tabelas.map((t) => (
            <div key={t.id} className={(t.largo ?? t.colunas.length > 5) ? 'painel-largo' : undefined} style={{ minWidth: 0 }}>
              <TabelaGestao tabela={t} />
            </div>
          ))}
        </div>
      )}
      {/* legado .painel-atalhos: «Ir para:» com botões contornados na cor do módulo */}
      {atalhos.length > 0 && (
        <div className="painel-atalhos">
          <span className="painel-atalhos-rotulo">
            <IconeFa nome="external-link-alt" /> Ir para:
          </span>
          {atalhos.map((a) => (
            <Button key={a.vista} size="small" style={{ borderColor: corModulo, color: corModulo }} onClick={() => navegar(a.rota!)}>
              {a.rotulo}
            </Button>
          ))}
        </div>
      )}
      {dados.calculado_em && (
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          Calculado em {formatarDataHora(dados.calculado_em)}
        </Typography.Text>
      )}
    </Space>
  );
}

function PainelModulo({ modulo, filtros, actualizar, aoIrPara }: { modulo: string; filtros: Filtros; actualizar: number; aoIrPara: (ir: string) => void }) {
  // o período e os filtros estão no cabeçalho do Dashboard (como no legado); «Actualizar» recalcula sem a cache de 5 minutos
  const consulta = useQuery({
    queryKey: ['gestao', 'painel', modulo, filtros, actualizar],
    queryFn: () => obter<DadosPainel>(`/gestao/paineis/${modulo}`, { ...filtros, actualizar: actualizar ? 1 : undefined }),
    staleTime: 60_000,
  });
  return (
    <>
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

/** Empresas que se podem escolher na comparação: as holdings ficam de fora (repetiriam os valores das empresas do grupo). */
export function opcoesComparacao(empresas: { id: number; nome: string; e_consolidacao?: boolean }[]): { value: number; label: string }[] {
  return empresas.filter((e) => !e.e_consolidacao).map((e) => ({ value: e.id, label: e.nome }));
}

/**
 * Separador «Comparar empresas»: números da contabilidade de várias empresas (cálculo no servidor, GET /gestao/paineis/comparacao).
 * Sem escolha, o servidor compara as empresas acessíveis (fora das holdings) ou, numa holding, as empresas do grupo. O período é o
 * do cabeçalho do Dashboard e «Actualizar» recalcula sem a cache.
 */
function ComparacaoEmpresas({ filtros, actualizar, holding }: { filtros: Filtros; actualizar: number; holding: boolean }) {
  const { empresas } = useSessao();
  const [escolhidas, setEscolhidas] = useState<number[]>([]);
  const consulta = useQuery({
    queryKey: ['gestao', 'comparacao', escolhidas, filtros.ano, filtros.mes, actualizar],
    queryFn: () => obter<DadosPainel>('/gestao/paineis/comparacao', { empresas: escolhidas.length ? escolhidas : undefined, ano: filtros.ano, mes: filtros.mes, actualizar: actualizar ? 1 : undefined }),
    staleTime: 60_000,
  });
  const opcoes = opcoesComparacao(empresas);
  return (
    <>
      <Card size="small" style={{ marginBottom: 16 }}>
        <Flex gap={8} align="center" wrap>
          <Typography.Text id="comparacao-empresas-rotulo">Empresas:</Typography.Text>
          <Select
            mode="multiple"
            allowClear
            style={{ flex: '1 1 240px', minWidth: 0 }}
            maxCount={30}
            value={escolhidas}
            onChange={setEscolhidas}
            optionFilterProp="label"
            aria-labelledby="comparacao-empresas-rotulo"
            placeholder={holding ? 'Empresas do grupo de consolidação (a que tem acesso)' : 'Todas as empresas a que tem acesso (excepto holdings, até 30)'}
            options={opcoes}
          />
          <Tag>Contabilidade · classe 9 e apuramento excluídos</Tag>
        </Flex>
      </Card>
      {consulta.isLoading ? (
        <Skeleton active />
      ) : consulta.error ? (
        <Alert type="error" showIcon message={(consulta.error as Error).message} />
      ) : consulta.data ? (
        <ConteudoPainel dados={{ ...consulta.data, modulo: consulta.data.modulo ?? MODULO_COMPARACAO }} tituloImpressao="Comparação de empresas" />
      ) : null}
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
