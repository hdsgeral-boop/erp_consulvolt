import { Button, Empty, Flex, Table, Typography } from 'antd';
import { TableOutlined, BarChartOutlined } from '@ant-design/icons';
import { useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import {
  agruparOutros,
  alvoMarcas,
  caminhoSector,
  caminhoSuave,
  corSerie,
  COR_OUTROS,
  eixoValores,
  escalaLinear,
  fatiasDonut,
  formatarCompacto,
  larguraTexto,
  paraNumero,
  passoRotulos,
  saoInteiros,
  truncarTexto,
} from './escalas';
import './graficos.css';

/**
 * Gráficos em SVG próprio (sem bibliotecas): barras (agrupadas, empilhadas ou horizontais), linhas (com área) e donut.
 * Aspecto dos gráficos Chart.js do sistema anterior (js/ui_painel_modulos.js:930-975): paleta e cores por série, legenda no
 * topo com marcadores redondos (no donut, ao lado), eixo de valores compacto («1,2 mil», «3,4 M»), barras arredondadas no
 * topo (máx. 38 px), linhas suaves com área translúcida, dica com o valor em Kz (pt-PT).
 * Afinações: desenho à largura real do contentor (texto legível a 375 px), rótulos do eixo sem sobreposição (salta ou
 * inclina), margem dos rótulos das barras horizontais medida, rótulos de dados/totais quando há espaço, dica com total,
 * animação discreta, alternância para tabela, estado vazio e cores preservadas na impressão.
 */

export interface SerieGrafico {
  id?: string;
  rotulo: string;
  valores: (string | number | null)[];
  /** cor própria da série (as do legado: vendas #2563eb, compras #ea580c, proveitos #10b981, custos #ef4444…) */
  cor?: string | null;
}

interface PropsBase {
  titulo?: string;
  rotulos: string[];
  series: SerieGrafico[];
  monetario?: boolean;
  altura?: number;
}

const TINTA = 'rgba(0,0,0,0.88)';
const TINTA_2 = 'rgba(0,0,0,0.6)';
const GRELHA = 'rgba(0,0,0,0.08)';
const EIXO = 'rgba(0,0,0,0.3)';
const FONTE = 11;

export function formatarValor(v: number, monetario?: boolean): string {
  return monetario ? `${formatarKz(v)} Kz` : formatarNumero(v);
}

/** Largura real do contentor (px), actualizada ao redimensionar; 640 antes da primeira medição (e nos testes). */
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

/** Moldura comum: título, botão tabela/gráfico, legenda (topo) e estado vazio. */
function Moldura({ titulo, rotulos, series, monetario, children, legenda = true }: PropsBase & { children: ReactNode; legenda?: boolean }) {
  const [tabela, setTabela] = useState(false);
  const temDados = rotulos.length > 0 && series.some((s) => s.valores.some((v) => paraNumero(v) !== 0));
  return (
    <div className="grafico-raiz">
      <Flex justify="space-between" align="center" gap={8} style={{ marginBottom: 6 }}>
        {titulo ? <Typography.Text strong>{titulo}</Typography.Text> : <span />}
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
          {legenda && series.length > 1 && <Legenda itens={series.map((s) => s.rotulo)} cores={series.map((s, i) => corSerie(i, s.cor))} />}
          {children}
        </>
      )}
    </div>
  );
}

export function Legenda({ itens, cores }: { itens: string[]; cores?: string[] }) {
  return (
    <ul className="grafico-legenda" aria-label="Legenda">
      {itens.map((r, i) => (
        <li key={r + i}>
          <i style={{ background: cores?.[i] ?? corSerie(i) }} />
          <span>{r}</span>
        </li>
      ))}
    </ul>
  );
}

/** Vista em tabela dos dados de um gráfico (acessibilidade e leitura exacta), com linha de totais. */
export function TabelaGrafico({ rotulos, series, monetario }: { rotulos: string[]; series: SerieGrafico[]; monetario?: boolean }) {
  const dados = rotulos.map((r, i) => ({ chave: `${i}`, rotulo: r, ...Object.fromEntries(series.map((s, j) => [`s${j}`, s.valores[i]])) }));
  const fmt = (v: unknown) => (monetario ? formatarKz(v as string) : formatarNumero(v as string));
  return (
    <Table
      size="small"
      rowKey="chave"
      pagination={false}
      scroll={{ x: 'max-content', y: 320 }}
      dataSource={dados}
      columns={[
        { title: '', dataIndex: 'rotulo' },
        ...series.map((s, j) => ({ title: s.rotulo, dataIndex: `s${j}`, align: 'right' as const, render: fmt })),
      ]}
      summary={() =>
        rotulos.length > 1 ? (
          <Table.Summary fixed>
            <Table.Summary.Row>
              <Table.Summary.Cell index={0}>
                <strong>Total</strong>
              </Table.Summary.Cell>
              {series.map((s, j) => (
                <Table.Summary.Cell key={j} index={j + 1} align="right">
                  <strong>{fmt(s.valores.reduce<number>((t, v) => t + paraNumero(v), 0))}</strong>
                </Table.Summary.Cell>
              ))}
            </Table.Summary.Row>
          </Table.Summary>
        ) : null
      }
    />
  );
}

interface Dica {
  x: number;
  y: number;
  titulo: string;
  linhas: { cor: string; rotulo: string; valor: string }[];
  total?: string;
}

/** Dica posicionada em px dentro do contentor (nunca sai pelos lados). */
function CaixaDica({ dica, largura }: { dica: Dica | null; largura: number }) {
  if (!dica) return null;
  const metade = Math.min(largura / 2, 110);
  const x = Math.min(Math.max(dica.x, metade), largura - metade);
  return (
    <div className="grafico-dica" role="status" style={{ left: x, top: dica.y, transform: 'translate(-50%, calc(-100% - 8px))' }}>
      <div style={{ fontWeight: 600, marginBottom: 2 }}>{dica.titulo}</div>
      {dica.linhas.map((l, i) => (
        <Flex key={i} gap={6} align="center">
          <span style={{ width: 8, height: 8, borderRadius: '50%', background: l.cor, display: 'inline-block' }} />
          <span style={{ color: TINTA_2 }}>{l.rotulo}:</span>
          <span style={{ color: TINTA, marginLeft: 'auto', paddingLeft: 8 }}>{l.valor}</span>
        </Flex>
      ))}
      {dica.total && (
        <Flex gap={6} style={{ borderTop: '1px solid rgba(0,0,0,0.08)', marginTop: 3, paddingTop: 3, fontWeight: 600 }}>
          <span>Total:</span>
          <span style={{ marginLeft: 'auto', paddingLeft: 8 }}>{dica.total}</span>
        </Flex>
      )}
    </div>
  );
}

/** Rectângulo com os cantos arredondados só na ponta (como o borderRadius do Chart.js). */
function caminhoBarra(x: number, y: number, w: number, h: number, r: number, ponta: 'cima' | 'baixo' | 'direita' | 'esquerda'): string {
  const rr = Math.max(0, Math.min(r, ponta === 'cima' || ponta === 'baixo' ? w / 2 : h / 2, ponta === 'cima' || ponta === 'baixo' ? h : w));
  if (rr <= 0.5) return `M ${x} ${y} h ${w} v ${h} h ${-w} Z`;
  switch (ponta) {
    case 'cima':
      return `M ${x} ${y + h} V ${y + rr} Q ${x} ${y} ${x + rr} ${y} H ${x + w - rr} Q ${x + w} ${y} ${x + w} ${y + rr} V ${y + h} Z`;
    case 'baixo':
      return `M ${x} ${y} V ${y + h - rr} Q ${x} ${y + h} ${x + rr} ${y + h} H ${x + w - rr} Q ${x + w} ${y + h} ${x + w} ${y + h - rr} V ${y} Z`;
    case 'direita':
      return `M ${x} ${y} H ${x + w - rr} Q ${x + w} ${y} ${x + w} ${y + rr} V ${y + h - rr} Q ${x + w} ${y + h} ${x + w - rr} ${y + h} H ${x} Z`;
    default:
      return `M ${x + w} ${y} H ${x + rr} Q ${x} ${y} ${x} ${y + rr} V ${y + h - rr} Q ${x} ${y + h} ${x + rr} ${y + h} H ${x + w} Z`;
  }
}

/**
 * Barras verticais (agrupadas ou empilhadas) ou horizontais.
 * `rotulosDados`: valor no topo de cada barra (ou o total, se empilhadas) quando há espaço; por omissão só nas horizontais.
 */
export function GraficoBarras({
  titulo,
  rotulos,
  series,
  monetario,
  altura = 260,
  empilhado,
  horizontal,
  rotulosDados,
}: PropsBase & { empilhado?: boolean; horizontal?: boolean; rotulosDados?: boolean }) {
  const [ref, largura] = useLargura();
  const [dica, setDica] = useState<Dica | null>(null);
  const L = Math.max(240, largura);
  const numeros = series.map((s) => rotulos.map((_, i) => paraNumero(s.valores[i])));
  const cores = series.map((s, j) => corSerie(j, s.cor));
  const totais = rotulos.map((_, i) => numeros.reduce((t, s) => t + s[i], 0));
  const extremos = empilhado
    ? rotulos.flatMap((_, i) => [numeros.reduce((t, s) => t + Math.max(0, s[i]), 0), numeros.reduce((t, s) => t + Math.min(0, s[i]), 0)])
    : numeros.flat();
  const n = Math.max(1, rotulos.length);
  const grupos = empilhado ? 1 : series.length;
  const comRotulos = rotulosDados ?? !!horizontal;

  // Margens medidas: rótulos das categorias (horizontais) e do eixo de valores
  const larguraRotuloMax = Math.max(...rotulos.map((r) => larguraTexto(r)), 0);
  const margemEsq = horizontal ? Math.min(Math.max(48, larguraRotuloMax + 10), Math.round(L * 0.38)) : 0;
  const alturaReal = horizontal ? Math.max(altura, rotulos.length * (empilhado ? 26 : Math.max(16, 12 * series.length + 10)) + 40) : altura;
  const inteiros = !monetario && saoInteiros(numeros.flat());
  const eixoProv = eixoValores(extremos, alvoMarcas(horizontal ? L - margemEsq : alturaReal - 60), inteiros);
  const larguraEixo = Math.max(...eixoProv.marcas.map((t) => larguraTexto(formatarCompacto(t))), 20) + 10;
  const mEsq = horizontal ? margemEsq : larguraEixo;
  const mDir = horizontal ? (comRotulos ? Math.min(64, Math.max(...totais.map((t) => larguraTexto(formatarCompacto(t)))) + 10) : 16) : 12;
  const larguraUtil = L - mEsq - mDir;
  const bandaV = larguraUtil / n;
  // Eixo das categorias (verticais): direito se couber, senão inclinado a −40° e, ainda assim, saltando rótulos
  const larguraRotuloV = Math.min(larguraRotuloMax, 96);
  // primeiro tenta saltar um rótulo em cada dois; só inclina se nem assim couberem
  const inclinar = !horizontal && larguraRotuloV + 6 > bandaV * 2;
  const mBaixo = horizontal ? 26 : inclinar ? Math.min(78, 18 + larguraRotuloV * 0.64) : 28;
  const mCima = !horizontal && comRotulos ? 18 : 10;
  const alturaUtil = alturaReal - mCima - mBaixo;
  const eixo = horizontal ? eixoProv : eixoValores(extremos, alvoMarcas(alturaUtil), inteiros);
  const banda = (horizontal ? alturaUtil : larguraUtil) / n;
  const espessura = Math.max(2, Math.min(38, (banda * 0.72) / grupos - (grupos > 1 ? 2 : 0)));
  const v = horizontal ? escalaLinear(eixo.minimo, eixo.maximo, mEsq, mEsq + larguraUtil) : escalaLinear(eixo.minimo, eixo.maximo, mCima + alturaUtil, mCima);
  const zero = v(0);
  const passo = horizontal ? 1 : passoRotulos(n, banda, inclinar ? FONTE * 1.8 : larguraRotuloV);
  // marcas do eixo horizontal sem sobreposição
  const passoMarcas = horizontal ? passoRotulos(eixo.marcas.length, larguraUtil / Math.max(1, eixo.marcas.length - 1), larguraEixo - 4) : 1;
  const mostrarDadosV = comRotulos && !horizontal && banda >= 30 && (grupos === 1 || empilhado);

  const mostrarDica = (i: number, x: number, y: number) =>
    setDica({
      x,
      y,
      titulo: rotulos[i],
      linhas: series.map((s, j) => ({ cor: cores[j], rotulo: s.rotulo, valor: formatarValor(numeros[j][i], monetario) })),
      total: series.length > 1 && empilhado ? formatarValor(totais[i], monetario) : undefined,
    });

  const barras: ReactNode[] = [];
  const rotulosDadosNos: ReactNode[] = [];
  rotulos.forEach((_, i) => {
    const inicioBanda = (horizontal ? mCima : mEsq) + i * banda + (banda - espessura * grupos - 2 * (grupos - 1)) / 2;
    let acumPos = 0;
    let acumNeg = 0;
    series.forEach((_s, j) => {
      const valor = numeros[j][i];
      if (!valor) return;
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
      const ultimo = !empilhado || series.slice(j + 1).every((_x, k) => Math.sign(numeros[j + 1 + k][i]) !== Math.sign(valor));
      const raio = ultimo ? Math.min(6, espessura / 3) : 0;
      const sep = empilhado ? 1 : 0;
      const d = horizontal
        ? caminhoBarra(Math.min(p0, p1), inicioBanda + desloc, Math.max(Math.abs(p1 - p0) - sep, 1), espessura, raio, valor >= 0 ? 'direita' : 'esquerda')
        : caminhoBarra(inicioBanda + desloc, Math.min(p0, p1), espessura, Math.max(Math.abs(p1 - p0) - sep, 1), raio, valor >= 0 ? 'cima' : 'baixo');
      barras.push(
        <path
          key={`${i}-${j}`}
          d={d}
          fill={cores[j]}
          fillOpacity={0.85}
          stroke={cores[j]}
          strokeWidth={1}
          className={`${horizontal ? 'grafico-anim-h' : 'grafico-anim-v'}${valor < 0 ? ' negativo' : ''}`}
          style={{ animationDelay: `${Math.min(i * 25, 300)}ms` }}
        />,
      );
      // rótulo de dados por barra (agrupadas horizontais)
      if (comRotulos && horizontal && !empilhado && grupos > 1 && espessura >= 9) {
        rotulosDadosNos.push(
          <text key={`d-${i}-${j}`} x={valor >= 0 ? p1 + 4 : p1 - 4} y={inicioBanda + desloc + espessura / 2 + 4} fontSize={10} textAnchor={valor >= 0 ? 'start' : 'end'} fill={TINTA_2}>
            {formatarCompacto(valor)}
          </text>,
        );
      }
    });
    const centro = (horizontal ? mCima : mEsq) + i * banda + banda / 2;
    // total (empilhadas) ou valor (uma série) no fim da barra
    if (comRotulos && totais[i] && (empilhado || grupos === 1)) {
      if (horizontal) {
        const fim = v(empilhado ? numeros.reduce((t, s) => t + Math.max(0, s[i]), 0) : totais[i]);
        rotulosDadosNos.push(
          <text key={`t-${i}`} x={totais[i] >= 0 ? fim + 4 : v(totais[i]) - 4} y={centro + 4} fontSize={10} fontWeight={empilhado ? 600 : 400} textAnchor={totais[i] >= 0 ? 'start' : 'end'} fill={TINTA_2}>
            {formatarCompacto(totais[i])}
          </text>,
        );
      } else if (mostrarDadosV) {
        const topo = v(empilhado ? numeros.reduce((t, s) => t + Math.max(0, s[i]), 0) : Math.max(0, totais[i]));
        rotulosDadosNos.push(
          <text key={`t-${i}`} x={centro} y={topo - 4} fontSize={10} fontWeight={empilhado ? 600 : 400} textAnchor="middle" fill={TINTA_2}>
            {formatarCompacto(totais[i])}
          </text>,
        );
      }
    }
    barras.push(
      <rect
        key={`alvo-${i}`}
        x={horizontal ? mEsq : mEsq + i * banda}
        y={horizontal ? mCima + i * banda : mCima}
        width={horizontal ? larguraUtil : banda}
        height={horizontal ? banda : alturaUtil}
        fill="transparent"
        tabIndex={0}
        aria-label={`${rotulos[i]}: ${series.map((s, j) => `${s.rotulo} ${formatarValor(numeros[j][i], monetario)}`).join('; ')}`}
        onMouseEnter={() => mostrarDica(i, horizontal ? mEsq + larguraUtil / 2 : centro, horizontal ? centro - banda / 2 : mCima + 12)}
        onFocus={() => mostrarDica(i, horizontal ? mEsq + larguraUtil / 2 : centro, horizontal ? centro - banda / 2 : mCima + 12)}
        onMouseLeave={() => setDica(null)}
        onBlur={() => setDica(null)}
      />,
    );
  });

  return (
    <Moldura titulo={titulo} rotulos={rotulos} series={series} monetario={monetario}>
      <div ref={ref} style={{ position: 'relative', width: '100%' }}>
        <svg viewBox={`0 0 ${L} ${alturaReal}`} width="100%" height={alturaReal} role="img" aria-label={titulo ?? 'Gráfico de barras'} style={{ display: 'block', overflow: 'visible' }}>
          {eixo.marcas.map((t, k) =>
            horizontal ? (
              <g key={t}>
                <line x1={v(t)} x2={v(t)} y1={mCima} y2={mCima + alturaUtil} stroke={GRELHA} />
                {k % passoMarcas === 0 && (
                  <text x={v(t)} y={alturaReal - 8} fontSize={FONTE} textAnchor="middle" fill={TINTA_2}>
                    {formatarCompacto(t)}
                  </text>
                )}
              </g>
            ) : (
              <g key={t}>
                <line x1={mEsq} x2={mEsq + larguraUtil} y1={v(t)} y2={v(t)} stroke={GRELHA} strokeDasharray={t === 0 ? undefined : '4 4'} />
                <text x={mEsq - 6} y={v(t) + 4} fontSize={FONTE} textAnchor="end" fill={TINTA_2}>
                  {formatarCompacto(t)}
                </text>
              </g>
            ),
          )}
          {horizontal ? <line x1={zero} x2={zero} y1={mCima} y2={mCima + alturaUtil} stroke={EIXO} /> : <line x1={mEsq} x2={mEsq + larguraUtil} y1={zero} y2={zero} stroke={EIXO} />}
          {barras}
          {rotulosDadosNos}
          {rotulos.map((r, i) => {
            if (i % passo !== 0) return null;
            if (horizontal)
              return (
                <text key={r + i} x={mEsq - 6} y={mCima + i * banda + banda / 2 + 4} fontSize={FONTE} textAnchor="end" fill={TINTA}>
                  <title>{r}</title>
                  {truncarTexto(r, mEsq - 10)}
                </text>
              );
            const x = mEsq + i * banda + banda / 2;
            const y = mCima + alturaUtil + 14;
            return inclinar ? (
              <text key={r + i} x={x} y={y} fontSize={FONTE} textAnchor="end" fill={TINTA_2} transform={`rotate(-40 ${x} ${y})`}>
                <title>{r}</title>
                {truncarTexto(r, 96)}
              </text>
            ) : (
              <text key={r + i} x={x} y={y + 2} fontSize={FONTE} textAnchor="middle" fill={TINTA_2}>
                <title>{r}</title>
                {truncarTexto(r, Math.max(bandaV * passo - 4, 24))}
              </text>
            );
          })}
        </svg>
        <CaixaDica dica={dica} largura={L} />
      </div>
    </Moldura>
  );
}

/**
 * Linhas suaves (2,5 px, marcadores de 3 px) com guia vertical e dica por ponto do eixo. `preenchimento` (área translúcida,
 * como o `fill` do legado) é por omissão ligado com até duas séries e desligado com mais (evita manchas sobrepostas).
 */
export function GraficoLinhas({ titulo, rotulos, series, monetario, altura = 260, preenchimento }: PropsBase & { preenchimento?: boolean }) {
  const [ref, largura] = useLargura();
  const [activo, setActivo] = useState<number | null>(null);
  const L = Math.max(240, largura);
  const numeros = series.map((s) => rotulos.map((_, i) => paraNumero(s.valores[i])));
  const cores = series.map((s, j) => corSerie(j, s.cor));
  const area = preenchimento ?? series.length <= 2;
  const inteiros = !monetario && saoInteiros(numeros.flat());
  const eixoProv = eixoValores(numeros.flat(), alvoMarcas(altura - 50), inteiros);
  const mEsq = Math.max(...eixoProv.marcas.map((t) => larguraTexto(formatarCompacto(t))), 20) + 10;
  const m = { e: mEsq, d: 16, t: 12 };
  const n = rotulos.length;
  const larguraUtil = L - m.e - m.d;
  const larguraRotuloMax = Math.min(Math.max(...rotulos.map((r) => larguraTexto(r)), 0), 96);
  const bandaX = larguraUtil / Math.max(1, n - 1);
  const inclinar = n > 1 && larguraRotuloMax + 6 > bandaX * 2;
  const mBaixo = inclinar ? Math.min(78, 18 + larguraRotuloMax * 0.64) : 28;
  const alturaUtil = altura - m.t - mBaixo;
  const eixo = eixoValores(numeros.flat(), alvoMarcas(alturaUtil), inteiros);
  const x = (i: number) => (n <= 1 ? m.e + larguraUtil / 2 : m.e + (i / (n - 1)) * larguraUtil);
  const y = escalaLinear(eixo.minimo, eixo.maximo, m.t + alturaUtil, m.t);
  const passo = passoRotulos(n, bandaX, inclinar ? FONTE * 1.8 : larguraRotuloMax);
  const base = y(Math.max(eixo.minimo, Math.min(0, eixo.maximo)));
  const dica: Dica | null =
    activo === null ? null : { x: x(activo), y: m.t + 10, titulo: rotulos[activo], linhas: series.map((s, j) => ({ cor: cores[j], rotulo: s.rotulo, valor: formatarValor(numeros[j][activo], monetario) })) };

  return (
    <Moldura titulo={titulo} rotulos={rotulos} series={series} monetario={monetario}>
      <div ref={ref} style={{ position: 'relative', width: '100%' }}>
        <svg viewBox={`0 0 ${L} ${altura}`} width="100%" height={altura} role="img" aria-label={titulo ?? 'Gráfico de linhas'} style={{ display: 'block', overflow: 'visible' }}>
          {eixo.marcas.map((t) => (
            <g key={t}>
              <line x1={m.e} x2={m.e + larguraUtil} y1={y(t)} y2={y(t)} stroke={GRELHA} strokeDasharray={t === 0 ? undefined : '4 4'} />
              <text x={m.e - 6} y={y(t) + 4} fontSize={FONTE} textAnchor="end" fill={TINTA_2}>
                {formatarCompacto(t)}
              </text>
            </g>
          ))}
          <line x1={m.e} x2={m.e + larguraUtil} y1={y(0)} y2={y(0)} stroke={EIXO} />
          {activo !== null && <line x1={x(activo)} x2={x(activo)} y1={m.t} y2={m.t + alturaUtil} stroke="rgba(0,0,0,0.25)" strokeDasharray="3 3" />}
          {numeros.map((s, j) => {
            const pontos = s.map((val, i) => [x(i), y(val)] as [number, number]);
            const linha = caminhoSuave(pontos);
            return (
              <g key={j} className="grafico-anim-f">
                {area && n > 1 && <path d={`${linha} L ${x(n - 1).toFixed(1)} ${base.toFixed(1)} L ${x(0).toFixed(1)} ${base.toFixed(1)} Z`} fill={cores[j]} fillOpacity={0.13} stroke="none" />}
                <path d={linha} fill="none" stroke={cores[j]} strokeWidth={area ? 2.5 : 2} strokeLinejoin="round" strokeLinecap="round" />
                {(n <= 24 || activo !== null) &&
                  s.map((val, i) => (n <= 24 || activo === i ? <circle key={i} cx={x(i)} cy={y(val)} r={activo === i ? 5 : 3} fill={cores[j]} stroke="#fff" strokeWidth={1.5} /> : null))}
              </g>
            );
          })}
          {rotulos.map((r, i) => {
            const xi = x(i);
            const yi = m.t + alturaUtil + 14;
            return (
              <g key={r + i}>
                {i % passo === 0 &&
                  (inclinar ? (
                    <text x={xi} y={yi} fontSize={FONTE} textAnchor="end" fill={TINTA_2} transform={`rotate(-40 ${xi} ${yi})`}>
                      {truncarTexto(r, 96)}
                    </text>
                  ) : (
                    <text x={xi} y={yi + 2} fontSize={FONTE} textAnchor="middle" fill={TINTA_2}>
                      {truncarTexto(r, Math.max(bandaX * passo - 4, 24))}
                    </text>
                  ))}
                <rect
                  x={xi - bandaX / 2}
                  y={m.t}
                  width={n <= 1 ? larguraUtil : bandaX}
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
        <CaixaDica dica={dica} largura={L} />
      </div>
    </Moldura>
  );
}

/**
 * Donut (parte de um todo) com a legenda ao lado (por baixo em ecrãs estreitos): cor, rótulo, valor e percentagem. Só a
 * primeira série é usada; mais de 10 fatias → as menores juntam-se em «Outros».
 */
export function GraficoDonut({ titulo, rotulos, series, monetario, altura = 220 }: PropsBase) {
  const [ref, largura] = useLargura();
  const [activo, setActivo] = useState<number | null>(null);
  const serie = series[0];
  const agrupado = agruparOutros(rotulos, (serie?.valores ?? []).map(paraNumero));
  const fatias = fatiasDonut(agrupado.rotulos, agrupado.valores);
  const diametro = Math.min(altura, Math.max(160, largura - 8));
  const r = diametro / 2 - 6;
  const c = diametro / 2;
  const total = fatias.reduce((s, f) => s + f.valor, 0);
  const pct = new Intl.NumberFormat('pt-PT', { style: 'percent', maximumFractionDigits: 1 });
  const fActiva = fatias.find((f) => f.indice === activo);
  const cor = (k: number) => (agrupado.outros && k === fatias.length - 1 ? COR_OUTROS : corSerie(k));

  return (
    <Moldura titulo={titulo} rotulos={rotulos} series={serie ? [serie] : []} monetario={monetario} legenda={false}>
      <div ref={ref}>
        <Flex gap={16} wrap align="center" justify="center">
          <svg viewBox={`0 0 ${diametro} ${diametro}`} width={diametro} height={diametro} role="img" aria-label={titulo ?? 'Gráfico circular'} className="grafico-anim-f">
            {fatias.map((f, k) => (
              <path
                key={f.indice}
                d={caminhoSector(c, c, activo === f.indice ? r + 3 : r, r * 0.6, f.inicio, f.fim)}
                fill={cor(k)}
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
            <text x={c} y={c - 6} textAnchor="middle" fontSize={11} fill={TINTA_2}>
              {fActiva ? truncarTexto(fActiva.rotulo, r * 1.1) : 'Total'}
            </text>
            <text x={c} y={c + 12} textAnchor="middle" fontSize={14} fontWeight={600} fill={TINTA}>
              {formatarCompacto(fActiva ? fActiva.valor : total)}
            </text>
            {fActiva && (
              <text x={c} y={c + 28} textAnchor="middle" fontSize={11} fill={TINTA_2}>
                {pct.format(fActiva.fraccao)}
              </text>
            )}
          </svg>
          <div style={{ flex: '1 1 200px', minWidth: 0 }} role="list" aria-label="Legenda">
            {fatias.map((f, k) => (
              <Flex
                key={f.indice}
                justify="space-between"
                gap={8}
                role="listitem"
                style={{ fontSize: 12, padding: '2px 4px', borderRadius: 4, fontWeight: activo === f.indice ? 600 : 400, background: activo === f.indice ? 'rgba(0,0,0,0.04)' : undefined }}
                onMouseEnter={() => setActivo(f.indice)}
                onMouseLeave={() => setActivo(null)}
              >
                <Flex gap={6} align="center" style={{ minWidth: 0 }}>
                  <span style={{ width: 8, height: 8, borderRadius: '50%', background: cor(k), flex: 'none' }} />
                  <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={f.rotulo}>
                    {f.rotulo}
                  </span>
                </Flex>
                <span style={{ whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums', color: TINTA_2 }}>
                  {formatarCompacto(f.valor)} · <strong style={{ color: TINTA }}>{pct.format(f.fraccao)}</strong>
                </span>
              </Flex>
            ))}
          </div>
        </Flex>
      </div>
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
  /** linhas sem área (legado: semPreenchimento) */
  sem_preenchimento?: boolean;
  rotulos: string[];
  series: SerieGrafico[];
}

export function GraficoAuto({ grafico, altura }: { grafico: GraficoApi; altura?: number }) {
  const p = { titulo: grafico.titulo, rotulos: grafico.rotulos ?? [], series: grafico.series ?? [], monetario: grafico.monetario, altura };
  if (grafico.tipo === 'circular' || grafico.tipo === 'donut' || grafico.tipo === 'pie') return <GraficoDonut {...p} />;
  if (grafico.tipo === 'linhas' || grafico.tipo === 'line') return <GraficoLinhas {...p} preenchimento={grafico.sem_preenchimento ? false : undefined} />;
  return <GraficoBarras {...p} empilhado={grafico.empilhado} horizontal={grafico.horizontal} />;
}
