import { esc } from '@/componentes/impressao';
import { iniciais, MEDIDAS, type Disposicao } from './layout';
import { CARTAO, medirCartao, quebrarLinhas, medidorAproximado, type CartaoMedido } from './texto';

/** Cores das posições (CORES do legado, projectos_organigrama.js:15). */
export const CORES_HEX: Record<string, string> = {
  azul: '#2563eb', verde: '#16a34a', laranja: '#ea580c', roxo: '#7c3aed', cinza: '#475569', vermelho: '#dc2626', ciano: '#0891b2',
};

export interface CartaoSvg {
  id: number;
  titulo: string;
  area: string | null;
  cor: string | null;
  apoio: boolean;
  vagas: string;
  pessoas: { nome: string; cargo?: string | null; responsavel?: boolean }[];
  contacto?: string | null;
  /** Texto já quebrado em linhas (o mesmo do ecrã); sem ele mede-se aqui. */
  medida?: CartaoMedido;
}

/**
 * O organigrama como SVG autónomo (texto e formas, sem HTML embebido), em cores de papel: serve à impressão/PDF e à
 * exportação PNG (um SVG sem foreignObject pode ser desenhado num canvas sem o «contaminar»).
 */
export function organigramaSvg(d: Disposicao, cartoes: Map<number, CartaoSvg>): string {
  const m = MEDIDAS;
  const lh = (t: number) => Math.ceil(t * CARTAO.alturaLinha);
  const linhas = d.ligacoes.map((l) => `<path d="${l.d}" fill="none" stroke="#94a3b8" stroke-width="1.4"/>`).join('');
  const caixas = [...d.nos.values()].map((n) => {
    const c = cartoes.get(n.id);
    if (!c) return '';
    const cor = CORES_HEX[c.cor ?? 'azul'] ?? CORES_HEX.azul;
    const mais = c.pessoas.length > 2 ? `+${c.pessoas.length - 2} membro(s)` : '';
    const rodape = [c.contacto ?? '', mais].filter(Boolean).join(' · ');
    const med = c.medida ?? medirCartao({ titulo: c.titulo, area: [c.area, c.apoio ? 'apoio' : null].filter(Boolean).join(' · ') || null, pessoas: c.pessoas, rodape });
    const h = Math.max(n.altura ?? 0, med.altura);
    const x = n.x + CARTAO.padH;
    // título completo, em linhas (sem reticências)
    let y = n.y + CARTAO.faixa + CARTAO.padTopo;
    const titulo = med.titulo.linhas.map((l) => { y += lh(med.titulo.tamanho); return `<text x="${x}" y="${y - 3}" font-size="${med.titulo.tamanho}" font-weight="700" fill="#0f172a">${esc(l)}</text>`; }).join('');
    const topoArea = y;
    y += med.linhaArea;
    const area = med.area?.linhas ?? [];
    const linhaArea = `${area.map((l, i) => `<text x="${x}" y="${topoArea + (i + 1) * Math.ceil(med.area!.tamanho * CARTAO.alturaLinha) - 4}" font-size="${med.area!.tamanho}" fill="#64748b">${esc(l)}</text>`).join('')}
      <text x="${n.x + m.largura - 10}" y="${topoArea + 12}" font-size="9" text-anchor="end" fill="#475569">${esc(c.vagas)}</text>`;
    const pessoas = med.pessoas.map((pm, i) => {
      const p = c.pessoas[i];
      const topo = y;
      y += pm.altura + CARTAO.entrePessoas;
      let ty = topo;
      const nome = pm.nome.linhas.map((l) => { ty += lh(pm.nome.tamanho); return `<text x="${x + CARTAO.avatar + 6}" y="${ty - 3}" font-size="${pm.nome.tamanho}" fill="#0f172a"${p.responsavel ? ' font-weight="700"' : ''}>${esc(l)}</text>`; }).join('');
      const cargo = (pm.cargo?.linhas ?? []).map((l) => { ty += lh(pm.cargo!.tamanho); return `<text x="${x + CARTAO.avatar + 6}" y="${ty - 3}" font-size="${pm.cargo!.tamanho}" fill="#64748b">${esc(l)}</text>`; }).join('');
      return `<circle cx="${x + 11}" cy="${topo + 11}" r="10" fill="${cor}" fill-opacity="0.15" stroke="${cor}" stroke-width="0.8"/>
        <text x="${x + 11}" y="${topo + 14}" font-size="8" font-weight="700" text-anchor="middle" fill="${cor}">${esc(iniciais(p.nome))}</text>${nome}${cargo}`;
    }).join('');
    const vazio = !c.pessoas.length ? `<text x="${x}" y="${y + 12}" font-size="9" font-style="italic" fill="#94a3b8">Sem ocupante</text>` : '';
    // rodapé: contacto (quebra em linhas se for preciso) e «+N»
    const linhasRodape = rodape ? quebrarLinhas(rodape, m.largura - 2 * CARTAO.padH, 8.5, false, medidorAproximado) : [];
    const textoRodape = linhasRodape.map((l, i) => `<text x="${x}" y="${n.y + h - 8 - (linhasRodape.length - 1 - i) * 11}" font-size="8.5" fill="#475569">${esc(l)}</text>`).join('');
    return `<g>
      <rect x="${n.x}" y="${n.y}" width="${m.largura}" height="${h}" rx="6" fill="${c.apoio ? '#faf5ff' : '#ffffff'}" stroke="#cbd5e1"${c.apoio ? ' stroke-dasharray="4 3"' : ''}/>
      <rect x="${n.x}" y="${n.y}" width="${m.largura}" height="4" rx="2" fill="${cor}"/>
      ${titulo}${linhaArea}${pessoas}${vazio}${textoRodape}
    </g>`;
  }).join('');
  return `<svg xmlns="http://www.w3.org/2000/svg" width="${d.largura}" height="${d.altura}" viewBox="0 0 ${d.largura} ${d.altura}" font-family="'Segoe UI', Arial, sans-serif">
    <rect width="100%" height="100%" fill="#ffffff"/>${linhas}${caixas}</svg>`;
}

/** SVG → PNG (escala 2×) e descarga. */
export async function descarregarPng(svg: string, nome: string, escala = 2): Promise<void> {
  const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));
  try {
    const img = new Image();
    await new Promise<void>((ok, falha) => { img.onload = () => ok(); img.onerror = () => falha(new Error('Não foi possível gerar a imagem.')); img.src = url; });
    const canvas = document.createElement('canvas');
    canvas.width = Math.ceil(img.width * escala);
    canvas.height = Math.ceil(img.height * escala);
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('O navegador não permite gerar a imagem.');
    ctx.scale(escala, escala);
    ctx.drawImage(img, 0, 0);
    const blob = await new Promise<Blob | null>((ok) => canvas.toBlob(ok, 'image/png'));
    if (!blob) throw new Error('Não foi possível gerar a imagem.');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nome;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  } finally {
    URL.revokeObjectURL(url);
  }
}
