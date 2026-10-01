import { Button, Empty, Flex, Table, Typography } from 'antd';
import { TableOutlined, BarChartOutlined } from '@ant-design/icons';
import { useState, type ReactNode } from 'react';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { caminhoSector, corSerie, eixoValores, escalaLinear, fatiasDonut, formatarCompacto, paraNumero } from './escalas';

/**
 * Gráficos simples em SVG (sem bibliotecas): barras (agrupadas, empilhadas ou horizontais), linhas e donut.
 * Todos têm título acessível, legenda quando há mais de uma série, dica ao passar o rato/teclado e alternância para tabela.
 */

export interface SerieGrafico {
  id?: string;
  rotulo: string;
  valores: (string | number | null)[];
}

interface PropsBase {
  titulo?: string;
  rotulos: string[];
  series: SerieGrafico[];
  monetario?: boolean;
  altura?: number;
}

const LARGURA = 640;
const TINTA = 'rgba(0,0,0,0.88)';
const TINTA_2 = 'rgba(0,0,0,0.55)';
const GRELHA = 'rgba(0,0,0,0.08)';

function formatarValor(v: number, monetario?: boolean): string {
  return monetario ? `${formatarKz(v)} Kz` : formatarNumero(v);
}

/** Moldura comum: título, botão tabela/gráfico, legenda e dica. */
function Moldura({ titulo, rotulos, series, monetario, children, legenda = true }: PropsBase & { children: ReactNode; legenda?: boolean }) {
  const [tabela, setTabela] = useState(false);
  const temDados = rotulos.length > 0 && series.some((s) => s.valores.some((v) => paraNumero(v) !== 0));
  return (
    <div>
      <Flex justify="space-between" align="center" gap={8} style={{ marginBottom: 8 }}>
        {titulo ? <Typography.Text strong>{titulo}</Typography.Text> : <span />}
        {temDados && (
          <Button
            size="small"
            type="text"
            icon={tabela ? <BarChartOutlined /> : <TableOutlined />}
            onClick={() => setTabela((t) => !t)}
            aria-label={tabela ? 'Ver gráfico' : 'Ver tabela'}
            title={tabela ? 'Ver gráfico' : 'Ver tabela'}
          />
        )}
      </Flex>
      {!temDados ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Sem dados no período." />
      ) : tabela ? (
        <TabelaGrafico rotulos={rotulos} series={series} monetario={monetario} />
      ) : (
        <>
          {children}
          {legenda && series.length > 1 && <Legenda itens={series.map((s) => s.rotulo)} />}
        </>
      )}
    </div>
  );
}

export function Legenda({ itens }: { itens: string[] }) {
  return (
    <Flex wrap gap={16} style={{ marginTop: 8 }} role="list" aria-label="Legenda">
      {itens.map((r, i) => (
        <Flex key={r + i} align="center" gap={6} role="listitem">
          <span style={{ width: 12, height: 12, borderRadius: 3, background: corSerie(i), display: 'inline-block' }} />
          <Typography.Text style={{ fontSize: 12 }}>{r}</Typography.Text>
        </Flex>
      ))}
    </Flex>
  );
}

/** Vista em tabela dos dados de um gráfico (acessibilidade e leitura exacta). */
export function TabelaGrafico({ rotulos, series, monetario }: { rotulos: string[]; series: SerieGrafico[]; monetario?: boolean }) {
  const dados = rotulos.map((r, i) => ({ chave: `${i}`, rotulo: r, ...Object.fromEntries(series.map((s, j) => [`s${j}`, s.valores[i]])) }));
  return (
    <Table
      size="small"
      rowKey="chave"
      pagination={false}
      scroll={{ x: 'max-content', y: 320 }}
      dataSource={dados}
      columns={[
        { title: '', dataIndex: 'rotulo' },
        ...series.map((s, j) => ({
          title: s.rotulo,
          dataIndex: `s${j}`,
          align: 'right' as const,
          render: (v: unknown) => (monetario ? formatarKz(v as string) : formatarNumero(v as string)),
        })),
      ]}
    />
  );
}

interface Dica {
  x: number;
  y: number;
  titulo: string;
  linhas: { cor: string; rotulo: string; valor: string }[];
}

function CaixaDica({ dica, altura }: { dica: Dica | null; altura: number }) {
  if (!dica) return null;
  const esquerda = (dica.x / LARGURA) * 100;
  return (
    <div
      role="status"
      style={{
        position: 'absolute',
        left: `${Math.min(Math.max(esquerda, 10), 90)}%`,
        top: `${(dica.y / altura) * 100}%`,
        transform: 'translate(-50%, calc(-100% - 8px))',
        background: '#fff',
        border: '1px solid rgba(0,0,0,0.12)',
        borderRadius: 6,
        boxShadow: '0 4px 12px rgba(0,0,0,0.12)',
        padding: '6px 10px',
        fontSize: 12,
        pointerEvents: 'none',
        whiteSpace: 'nowrap',
        zIndex: 2,
      }}
    >
      <div style={{ fontWeight: 600, marginBottom: 2 }}>{dica.titulo}</div>
      {dica.linhas.map((l, i) => (
        <Flex key={i} gap={6} align="center">
          <span style={{ width: 8, height: 8, borderRadius: 2, background: l.cor, display: 'inline-block' }} />
          <span style={{ color: TINTA_2 }}>{l.rotulo}:</span>
          <span style={{ color: TINTA }}>{l.valor}</span>
        </Flex>
      ))}
    </div>
  );
}

/** Barras verticais (agrupadas ou empilhadas) ou horizontais. */
export function GraficoBarras({ titulo, rotulos, series, monetario, altura = 260, empilhado, horizontal }: PropsBase & { empilhado?: boolean; horizontal?: boolean }) {
  const [dica, setDica] = useState<Dica | null>(null);
  const numeros = series.map((s) => rotulos.map((_, i) => paraNumero(s.valores[i])));
  const extremos = empilhado
    ? rotulos.flatMap((_, i) => {
        const pos = numeros.reduce((t, s) => t + Math.max(0, s[i]), 0);
        const neg = numeros.reduce((t, s) => t + Math.min(0, s[i]), 0);
        return [pos, neg];
      })
    : numeros.flat();
  const eixo = eixoValores(extremos);
  const alturaReal = horizontal ? Math.max(altura, rotulos.length * (empilhado ? 26 : 14 * series.length + 12) + 40) : altura;
  const m = horizontal ? { e: 150, d: 16, t: 8, b: 28 } : { e: 64, d: 12, t: 12, b: 44 };
  const larguraUtil = LARGURA - m.e - m.d;
  const alturaUtil = alturaReal - m.t - m.b;
  const n = Math.max(1, rotulos.length);
  const banda = (horizontal ? alturaUtil : larguraUtil) / n;
  const grupos = empilhado ? 1 : series.length;
  const espessura = Math.max(2, Math.min(40, (banda * 0.72) / grupos - 2));
  const v = horizontal ? escalaLinear(eixo.minimo, eixo.maximo, m.e, m.e + larguraUtil) : escalaLinear(eixo.minimo, eixo.maximo, m.t + alturaUtil, m.t);
  const zero = v(0);
  const passoRotulo = horizontal ? 1 : Math.ceil(n / 12);

  const mostrarDica = (i: number, x: number, y: number) =>
    setDica({
      x,
      y,
      titulo: rotulos[i],
      linhas: series.map((s, j) => ({ cor: corSerie(j), rotulo: s.rotulo, valor: formatarValor(numeros[j][i], monetario) })),
    });

  const barras: ReactNode[] = [];
  rotulos.forEach((_, i) => {
    const inicioBanda = (horizontal ? m.t : m.e) + i * banda + (banda - espessura * grupos - 2 * (grupos - 1)) / 2;
    let acumPos = 0;
    let acumNeg = 0;
    series.forEach((_s, j) => {
      const valor = numeros[j][i];
      let a0: number;
      let a1: number;
      if (empilhado) {
        if (valor >= 0) {
          a0 = acumPos;
          a1 = acumPos += valor;
        } else {
          a0 = acumNeg;
          a1 = acumNeg += valor;
        }
      } else {
        a0 = 0;
        a1 = valor;
      }
      const p0 = v(a0);
      const p1 = v(a1);
      const desloc = empilhado ? 0 : j * (espessura + 2);
      const props = horizontal
        ? { x: Math.min(p0, p1), y: inicioBanda + desloc, width: Math.max(Math.abs(p1 - p0) - (empilhado ? 1 : 0), valor ? 1 : 0), height: espessura }
        : { x: inicioBanda + desloc, y: Math.min(p0, p1), width: espessura, height: Math.max(Math.abs(p1 - p0) - (empilhado ? 1 : 0), valor ? 1 : 0) };
      barras.push(<rect key={`${i}-${j}`} {...props} rx={Math.min(4, espessura / 2)} fill={corSerie(j)} />);
    });
    const centro = (horizontal ? m.t : m.e) + i * banda + banda / 2;
    barras.push(
      <rect
        key={`alvo-${i}`}
        x={horizontal ? m.e : m.e + i * banda}
        y={horizontal ? m.t + i * banda : m.t}
        width={horizontal ? larguraUtil : banda}
        height={horizontal ? banda : alturaUtil}
        fill="transparent"
        tabIndex={0}
        aria-label={`${rotulos[i]}: ${series.map((s, j) => `${s.rotulo} ${formatarValor(numeros[j][i], monetario)}`).join('; ')}`}
        onMouseEnter={() => mostrarDica(i, horizontal ? m.e + larguraUtil / 2 : centro, horizontal ? centro - banda / 2 : m.t + 20)}
        onFocus={() => mostrarDica(i, horizontal ? m.e + larguraUtil / 2 : centro, horizontal ? centro - banda / 2 : m.t + 20)}
        onMouseLeave={() => setDica(null)}
        onBlur={() => setDica(null)}
      />,
    );
  });

  return (
    <Moldura titulo={titulo} rotulos={rotulos} series={series} monetario={monetario}>
      <div style={{ position: 'relative' }}>
        <svg viewBox={`0 0 ${LARGURA} ${alturaReal}`} width="100%" role="img" aria-label={titulo ?? 'Gráfico de barras'} style={{ display: 'block', overflow: 'visible' }}>
          {eixo.marcas.map((t) =>
            horizontal ? (
              <g key={t}>
                <line x1={v(t)} x2={v(t)} y1={m.t} y2={m.t + alturaUtil} stroke={GRELHA} />
                <text x={v(t)} y={alturaReal - 8} fontSize={11} textAnchor="middle" fill={TINTA_2}>{formatarCompacto(t)}</text>
              </g>
            ) : (
              <g key={t}>
                <line x1={m.e} x2={m.e + larguraUtil} y1={v(t)} y2={v(t)} stroke={GRELHA} />
                <text x={m.e - 6} y={v(t) + 4} fontSize={11} textAnchor="end" fill={TINTA_2}>{formatarCompacto(t)}</text>
              </g>
            ),
          )}
          {horizontal ? (
            <line x1={zero} x2={zero} y1={m.t} y2={m.t + alturaUtil} stroke="rgba(0,0,0,0.3)" />
          ) : (
            <line x1={m.e} x2={m.e + larguraUtil} y1={zero} y2={zero} stroke="rgba(0,0,0,0.3)" />
          )}
          {barras}
          {rotulos.map((r, i) =>
            i % passoRotulo !== 0 ? null : horizontal ? (
              <text key={r + i} x={m.e - 6} y={m.t + i * banda + banda / 2 + 4} fontSize={11} textAnchor="end" fill={TINTA}>
                {r.length > 22 ? `${r.slice(0, 21)}…` : r}
              </text>
            ) : (
              <text key={r + i} x={m.e + i * banda + banda / 2} y={m.t + alturaUtil + 16} fontSize={11} textAnchor="middle" fill={TINTA_2}>
                {r.length > 12 ? `${r.slice(0, 11)}…` : r}
              </text>
            ),
          )}
        </svg>
        <CaixaDica dica={dica} altura={alturaReal} />
      </div>
    </Moldura>
  );
}

/** Linhas (2px, marcadores ≥ 8px) com guia vertical e dica por ponto do eixo. */
export function GraficoLinhas({ titulo, rotulos, series, monetario, altura = 260 }: PropsBase) {
  const [activo, setActivo] = useState<number | null>(null);
  const numeros = series.map((s) => rotulos.map((_, i) => paraNumero(s.valores[i])));
  const eixo = eixoValores(numeros.flat());
  const m = { e: 64, d: 16, t: 12, b: 44 };
  const larguraUtil = LARGURA - m.e - m.d;
  const alturaUtil = altura - m.t - m.b;
  const n = rotulos.length;
  const x = (i: number) => (n <= 1 ? m.e + larguraUtil / 2 : m.e + (i / (n - 1)) * larguraUtil);
  const y = escalaLinear(eixo.minimo, eixo.maximo, m.t + alturaUtil, m.t);
  const passoRotulo = Math.ceil(n / 12);
  const dica: Dica | null =
    activo === null
      ? null
      : { x: x(activo), y: m.t + 10, titulo: rotulos[activo], linhas: series.map((s, j) => ({ cor: corSerie(j), rotulo: s.rotulo, valor: formatarValor(numeros[j][activo], monetario) })) };

  return (
    <Moldura titulo={titulo} rotulos={rotulos} series={series} monetario={monetario}>
      <div style={{ position: 'relative' }}>
        <svg viewBox={`0 0 ${LARGURA} ${altura}`} width="100%" role="img" aria-label={titulo ?? 'Gráfico de linhas'} style={{ display: 'block', overflow: 'visible' }}>
          {eixo.marcas.map((t) => (
            <g key={t}>
              <line x1={m.e} x2={m.e + larguraUtil} y1={y(t)} y2={y(t)} stroke={GRELHA} />
              <text x={m.e - 6} y={y(t) + 4} fontSize={11} textAnchor="end" fill={TINTA_2}>{formatarCompacto(t)}</text>
            </g>
          ))}
          <line x1={m.e} x2={m.e + larguraUtil} y1={y(0)} y2={y(0)} stroke="rgba(0,0,0,0.3)" />
          {activo !== null && <line x1={x(activo)} x2={x(activo)} y1={m.t} y2={m.t + alturaUtil} stroke="rgba(0,0,0,0.25)" strokeDasharray="3 3" />}
          {numeros.map((s, j) => (
            <g key={j}>
              <polyline points={s.map((val, i) => `${x(i).toFixed(1)},${y(val).toFixed(1)}`).join(' ')} fill="none" stroke={corSerie(j)} strokeWidth={2} strokeLinejoin="round" />
              {s.map((val, i) => (
                <circle key={i} cx={x(i)} cy={y(val)} r={activo === i ? 5 : 3} fill={corSerie(j)} stroke="#fff" strokeWidth={2} />
              ))}
            </g>
          ))}
          {rotulos.map((r, i) => (
            <g key={r + i}>
              {i % passoRotulo === 0 && (
                <text x={x(i)} y={m.t + alturaUtil + 16} fontSize={11} textAnchor="middle" fill={TINTA_2}>
                  {r.length > 12 ? `${r.slice(0, 11)}…` : r}
                </text>
              )}
              <rect
                x={x(i) - larguraUtil / Math.max(1, n - 1) / 2}
                y={m.t}
                width={larguraUtil / Math.max(1, n - 1)}
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
          ))}
        </svg>
        <CaixaDica dica={dica} altura={altura} />
      </div>
    </Moldura>
  );
}

/** Donut (parte de um todo) com legenda percentual ao lado; só a primeira série é usada. */
export function GraficoDonut({ titulo, rotulos, series, monetario, altura = 220 }: PropsBase) {
  const [activo, setActivo] = useState<number | null>(null);
  const serie = series[0];
  const fatias = fatiasDonut(rotulos, (serie?.valores ?? []).map(paraNumero));
  const r = altura / 2 - 6;
  const c = altura / 2;
  const total = fatias.reduce((s, f) => s + f.valor, 0);
  const pct = new Intl.NumberFormat('pt-PT', { style: 'percent', maximumFractionDigits: 1 });
  const fActiva = fatias.find((f) => f.indice === activo);

  return (
    <Moldura titulo={titulo} rotulos={rotulos} series={serie ? [serie] : []} monetario={monetario} legenda={false}>
      <Flex gap={16} wrap align="center">
        <svg viewBox={`0 0 ${altura} ${altura}`} width={altura} height={altura} role="img" aria-label={titulo ?? 'Gráfico circular'}>
          {fatias.map((f, k) => (
            <path
              key={f.indice}
              d={caminhoSector(c, c, r, r * 0.6, f.inicio, f.fim)}
              fill={corSerie(k)}
              stroke="#fff"
              strokeWidth={2}
              opacity={activo === null || activo === f.indice ? 1 : 0.45}
              tabIndex={0}
              aria-label={`${f.rotulo}: ${formatarValor(f.valor, monetario)} (${pct.format(f.fraccao)})`}
              onMouseEnter={() => setActivo(f.indice)}
              onFocus={() => setActivo(f.indice)}
              onMouseLeave={() => setActivo(null)}
              onBlur={() => setActivo(null)}
            />
          ))}
          <text x={c} y={c - 4} textAnchor="middle" fontSize={12} fill={TINTA_2}>
            {fActiva ? (fActiva.rotulo.length > 16 ? `${fActiva.rotulo.slice(0, 15)}…` : fActiva.rotulo) : 'Total'}
          </text>
          <text x={c} y={c + 14} textAnchor="middle" fontSize={14} fontWeight={600} fill={TINTA}>
            {formatarCompacto(fActiva ? fActiva.valor : total)}
          </text>
        </svg>
        <div style={{ flex: 1, minWidth: 180 }} role="list" aria-label="Legenda">
          {fatias.map((f, k) => (
            <Flex key={f.indice} justify="space-between" gap={8} role="listitem" style={{ fontSize: 12, padding: '2px 0', fontWeight: activo === f.indice ? 600 : 400 }}>
              <Flex gap={6} align="center" style={{ minWidth: 0 }}>
                <span style={{ width: 10, height: 10, borderRadius: 2, background: corSerie(k), flex: 'none' }} />
                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{f.rotulo}</span>
              </Flex>
              <span style={{ whiteSpace: 'nowrap' }}>{pct.format(f.fraccao)}</span>
            </Flex>
          ))}
        </div>
      </Flex>
    </Moldura>
  );
}

/** Gráfico vindo da API dos painéis (Indicadores::grafico): tipo barras | linhas | circular. */
export interface GraficoApi {
  id: string;
  titulo: string;
  tipo: string;
  monetario?: boolean;
  horizontal?: boolean;
  empilhado?: boolean;
  rotulos: string[];
  series: SerieGrafico[];
}

export function GraficoAuto({ grafico, altura }: { grafico: GraficoApi; altura?: number }) {
  const p = { titulo: grafico.titulo, rotulos: grafico.rotulos ?? [], series: grafico.series ?? [], monetario: grafico.monetario, altura };
  if (grafico.tipo === 'circular' || grafico.tipo === 'donut' || grafico.tipo === 'pie') return <GraficoDonut {...p} />;
  if (grafico.tipo === 'linhas' || grafico.tipo === 'line') return <GraficoLinhas {...p} />;
  return <GraficoBarras {...p} empilhado={grafico.empilhado} horizontal={grafico.horizontal} />;
}
