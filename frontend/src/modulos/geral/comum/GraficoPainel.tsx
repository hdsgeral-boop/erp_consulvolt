import { Button, Empty, Flex, Typography } from 'antd';
import { BarChartOutlined, TableOutlined } from '@ant-design/icons';
import { useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { GraficoAuto, TabelaGrafico, corBarra, formatarValor, type GraficoApi } from '@/componentes/graficos/Graficos';
import type { CoresCategorias } from '@/componentes/graficos/escalas';
import { caminhoSuave, corSerie, eixoValores, escalaLinear, formatarCompacto, larguraTexto, paraNumero, saoInteiros, truncarTexto } from '@/componentes/graficos/escalas';
import { ESTILO_GRAFICO } from '@/componentes/graficos/estiloGraficos';
import './painel.css';

/**
 * Gráficos mensais do Dashboard Global com o aspecto do Chart.js do sistema anterior (js/ui_painel_modulos.js:930-962;
 * verificação visual de 2026-10-08, linhas 1.1.6 e 1.1.7): legenda centrada no topo com círculos (vazios nas linhas),
 * eixo dos valores com passos mais finos (≈ uma marca a cada 34 px, como o Chart.js), todos os meses no eixo horizontal
 * (inclinados quando não cabem direitos — nunca se saltam rótulos) e marcadores em todos os pontos das linhas.
 * Invólucro do módulo: barras verticais agrupadas e linhas; o resto (donut, horizontais, empilhadas) usa o componente
 * partilhado (`GraficoAuto`).
 */

// estilo único dos gráficos (o do Chart.js do legado): componentes/graficos/estiloGraficos.ts
const { tinta: TINTA, tintaSecundaria: TINTA_2, grelha: GRELHA, eixo: EIXO, guia: GUIA, fonte: FONTE } = ESTILO_GRAFICO;

/** Divisões do eixo dos valores para uma altura útil (Chart.js: ~1 marca a cada 34 px; entre 4 e 10). */
export function marcasPainel(alturaUtil: number): number {
  return Math.max(4, Math.min(10, Math.round(alturaUtil / 34)));
}

/** Inclina os rótulos do eixo das categorias quando o mais largo não cabe na banda de cada categoria. */
export function inclinarRotulos(rotulos: string[], banda: number): boolean {
  const maior = Math.min(Math.max(0, ...rotulos.map((r) => larguraTexto(r))), 96);
  return rotulos.length > 1 && maior + 6 > banda;
}

function useLargura(): [React.RefObject<HTMLDivElement>, number] {
  const ref = useRef<HTMLDivElement>(null);
  const [largura, setLargura] = useState(640);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const medir = () => {
      const w = Math.round(el.getBoundingClientRect().width);
      if (w > 0) setLargura((a) => (Math.abs(a - w) >= 1 ? w : a));
    };
    medir();
    if (typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(medir);
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return [ref, largura];
}

function caminhoBarra(x: number, y: number, w: number, h: number, r: number, cima: boolean): string {
  const rr = Math.max(0, Math.min(r, w / 2, h));
  if (rr <= 0.5) return `M ${x} ${y} h ${w} v ${h} h ${-w} Z`;
  return cima
    ? `M ${x} ${y + h} V ${y + rr} Q ${x} ${y} ${x + rr} ${y} H ${x + w - rr} Q ${x + w} ${y} ${x + w} ${y + rr} V ${y + h} Z`
    : `M ${x} ${y} V ${y + h - rr} Q ${x} ${y + h} ${x + rr} ${y + h} H ${x + w - rr} Q ${x + w} ${y + h} ${x + w} ${y + h - rr} V ${y} Z`;
}

export function LegendaCentrada({ itens, cores, vazios }: { itens: string[]; cores: string[]; vazios?: boolean }) {
  return (
    <ul className="painel-legenda" aria-label="Legenda">
      {itens.map((r, i) => (
        <li key={r + i}>
          <i className={vazios ? 'vazio' : undefined} style={vazios ? { borderColor: cores[i] } : { background: cores[i], borderColor: cores[i] }} />
          <span>{r}</span>
        </li>
      ))}
    </ul>
  );
}

/** Gráfico de um painel: mensais (barras/linhas) com o invólucro; os restantes com o componente partilhado. */
export function GraficoPainel({ grafico, altura = 280, coresCategorias }: { grafico: GraficoApi; altura?: number; coresCategorias?: CoresCategorias }) {
  const linhas = grafico.tipo === 'linhas' || grafico.tipo === 'line';
  const barras = grafico.tipo === 'barras' || grafico.tipo === 'bar';
  if ((!linhas && !barras) || grafico.horizontal || grafico.empilhado) return <GraficoAuto grafico={grafico} altura={altura} coresCategorias={coresCategorias} />;
  return <GraficoMensal grafico={grafico} altura={altura} linhas={linhas} coresCategorias={coresCategorias} />;
}

function GraficoMensal({ grafico, altura, linhas, coresCategorias }: { grafico: GraficoApi; altura: number; linhas: boolean; coresCategorias?: CoresCategorias }) {
  const [ref, largura] = useLargura();
  const [tabela, setTabela] = useState(false);
  const [activo, setActivo] = useState<number | null>(null);
  const rotulos = grafico.rotulos ?? [];
  const series = grafico.series ?? [];
  const monetario = grafico.monetario;
  const numeros = series.map((s) => rotulos.map((_, i) => paraNumero(s.valores[i])));
  const cores = series.map((s, j) => corSerie(j, s.cor));
  const temDados = rotulos.length > 0 && numeros.some((s) => s.some((v) => v !== 0));

  const L = Math.max(240, largura);
  const n = Math.max(1, rotulos.length);
  const inteiros = !monetario && saoInteiros(numeros.flat());
  const eixoProv = eixoValores(numeros.flat(), marcasPainel(altura - 60), inteiros);
  const mEsq = Math.max(...eixoProv.marcas.map((t) => larguraTexto(formatarCompacto(t))), 20) + 10;
  const mDir = 12;
  const larguraUtil = L - mEsq - mDir;
  // barras: uma banda por categoria; linhas: pontos nas extremidades (como o Chart.js com offset nas barras)
  const banda = linhas ? larguraUtil / Math.max(1, n - 1) : larguraUtil / n;
  const inclinar = inclinarRotulos(rotulos, banda);
  const maiorRotulo = Math.min(Math.max(0, ...rotulos.map((r) => larguraTexto(r))), 96);
  const mBaixo = inclinar ? Math.min(78, 18 + maiorRotulo * 0.64) : 28;
  const mCima = 10;
  const alturaUtil = altura - mCima - mBaixo;
  const eixo = eixoValores(numeros.flat(), marcasPainel(alturaUtil), inteiros);
  const y = escalaLinear(eixo.minimo, eixo.maximo, mCima + alturaUtil, mCima);
  const zero = y(Math.max(eixo.minimo, Math.min(0, eixo.maximo)));
  const xCentro = (i: number) => (linhas ? (n <= 1 ? mEsq + larguraUtil / 2 : mEsq + (i / (n - 1)) * larguraUtil) : mEsq + i * banda + banda / 2);

  const grupos = Math.max(1, series.length);
  const espessura = Math.max(2, Math.min(ESTILO_GRAFICO.barraEspessuraMaxima, (banda * 0.72) / grupos - (grupos > 1 ? 2 : 0)));
  const nos: ReactNode[] = [];
  if (!linhas) {
    rotulos.forEach((_, i) => {
      const inicio = mEsq + i * banda + (banda - espessura * grupos - 2 * (grupos - 1)) / 2;
      series.forEach((_s, j) => {
        const v = numeros[j][i];
        if (!v) return;
        const p1 = y(v);
        // cor por barra (cores da série ou por categoria), senão a da série
        const c = corBarra(series[j], j, rotulos[i], i, coresCategorias);
        nos.push(
          <path
            key={`${i}-${j}`}
            d={caminhoBarra(inicio + j * (espessura + 2), Math.min(zero, p1), espessura, Math.max(Math.abs(p1 - zero), 1), Math.min(ESTILO_GRAFICO.barraRaio, espessura / 3), v >= 0)}
            fill={c}
            fillOpacity={ESTILO_GRAFICO.barraOpacidade}
            stroke={c}
            strokeWidth={ESTILO_GRAFICO.barraBorda}
          />,
        );
      });
    });
  } else {
    const area = !grafico.sem_preenchimento && series.length <= 2;
    numeros.forEach((s, j) => {
      const pontos = s.map((v, i) => [xCentro(i), y(v)] as [number, number]);
      const d = caminhoSuave(pontos);
      nos.push(
        <g key={`l-${j}`}>
          {area && n > 1 && <path d={`${d} L ${xCentro(n - 1).toFixed(1)} ${zero.toFixed(1)} L ${xCentro(0).toFixed(1)} ${zero.toFixed(1)} Z`} fill={cores[j]} fillOpacity={ESTILO_GRAFICO.areaOpacidade} stroke="none" />}
          <path d={d} fill="none" stroke={cores[j]} strokeWidth={area ? ESTILO_GRAFICO.linhaEspessuraComArea : ESTILO_GRAFICO.linhaEspessura} strokeLinejoin="round" strokeLinecap="round" />
          {s.map((v, i) => (
            <circle key={i} className="painel-marcador" cx={xCentro(i)} cy={y(v)} r={activo === i ? ESTILO_GRAFICO.pontoActivo : ESTILO_GRAFICO.ponto} fill={cores[j]} stroke="#fff" strokeWidth={1.5} />
          ))}
        </g>,
      );
    });
  }

  const dica =
    activo === null ? null : (
      <div className="grafico-dica" role="status" style={{ left: Math.min(Math.max(xCentro(activo), 110), L - 110), top: mCima + 4, transform: 'translate(-50%, calc(-100% - 4px))' }}>
        <div style={{ fontWeight: 600, marginBottom: 2 }}>{rotulos[activo]}</div>
        {series.map((s, j) => (
          <Flex key={j} gap={6} align="center">
            <span style={{ width: 8, height: 8, borderRadius: '50%', background: cores[j], display: 'inline-block' }} />
            <span style={{ color: TINTA_2 }}>{s.rotulo}:</span>
            <span style={{ color: TINTA, marginLeft: 'auto', paddingLeft: 8 }}>{formatarValor(numeros[j][activo], monetario)}</span>
          </Flex>
        ))}
      </div>
    );

  return (
    <div className="grafico-raiz painel-grafico">
      <Flex justify="space-between" align="center" gap={8} style={{ marginBottom: 6 }}>
        <Typography.Text strong>{grafico.titulo}</Typography.Text>
        {temDados && (
          <Button
            size="small"
            type="text"
            className="imp-nao-imprimir"
            icon={tabela ? <BarChartOutlined /> : <TableOutlined />}
            onClick={() => setTabela((t) => !t)}
            aria-label={tabela ? 'Ver gráfico' : 'Ver tabela'}
            title={tabela ? 'Ver gráfico' : 'Ver tabela'}
          />
        )}
      </Flex>
      {!temDados ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem dados para o período." />
      ) : tabela ? (
        <TabelaGrafico rotulos={rotulos} series={series} monetario={monetario} />
      ) : (
        <>
          <LegendaCentrada itens={series.map((s) => s.rotulo)} cores={cores} vazios={linhas} />
          <div ref={ref} style={{ position: 'relative', width: '100%' }}>
            <svg viewBox={`0 0 ${L} ${altura}`} width="100%" height={altura} role="img" aria-label={grafico.titulo} style={{ display: 'block', overflow: 'visible' }}>
              {eixo.marcas.map((t) => (
                <g key={t}>
                  <line x1={mEsq} x2={mEsq + larguraUtil} y1={y(t)} y2={y(t)} stroke={GRELHA} />
                  <text className="painel-marca-y" x={mEsq - 6} y={y(t) + 4} fontSize={FONTE} textAnchor="end" fill={TINTA_2}>
                    {formatarCompacto(t)}
                  </text>
                </g>
              ))}
              <line x1={mEsq} x2={mEsq + larguraUtil} y1={zero} y2={zero} stroke={EIXO} />
              {activo !== null && linhas && <line x1={xCentro(activo)} x2={xCentro(activo)} y1={mCima} y2={mCima + alturaUtil} stroke={GUIA} strokeDasharray="3 3" />}
              {nos}
              {rotulos.map((r, i) => {
                const x = xCentro(i);
                const yi = mCima + alturaUtil + 14;
                return (
                  <g key={r + i}>
                    {inclinar ? (
                      <text className="painel-rotulo-x" x={x} y={yi} fontSize={FONTE} textAnchor="end" fill={TINTA_2} transform={`rotate(-40 ${x} ${yi})`}>
                        <title>{r}</title>
                        {truncarTexto(r, 96)}
                      </text>
                    ) : (
                      <text className="painel-rotulo-x" x={x} y={yi + 2} fontSize={FONTE} textAnchor="middle" fill={TINTA_2}>
                        <title>{r}</title>
                        {truncarTexto(r, Math.max(banda - 4, 24))}
                      </text>
                    )}
                    <rect
                      x={linhas ? x - banda / 2 : mEsq + i * banda}
                      y={mCima}
                      width={n <= 1 ? larguraUtil : banda}
                      height={alturaUtil}
                      fill="transparent"
                      tabIndex={0}
                      aria-label={`${r}: ${series.map((s, j) => `${s.rotulo} ${formatarValor(numeros[j][i], monetario)}`).join('; ')}`}
                      onMouseEnter={() => setActivo(i)}
                      onFocus={() => setActivo(i)}
                      onMouseLeave={() => setActivo(null)}
                      onBlur={() => setActivo(null)}
                    />
                  </g>
                );
              })}
            </svg>
            {dica}
          </div>
        </>
      )}
    </div>
  );
}
