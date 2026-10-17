import { esc } from '@/componentes/impressao';
import { cortar, iniciais, MEDIDAS, type Disposicao } from './layout';

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
}

/**
 * O organigrama como SVG autónomo (texto e formas, sem HTML embebido), em cores de papel: serve à impressão/PDF e à
 * exportação PNG (um SVG sem foreignObject pode ser desenhado num canvas sem o «contaminar»).
 */
export function organigramaSvg(d: Disposicao, cartoes: Map<number, CartaoSvg>): string {
  const m = MEDIDAS;
  const linhas = d.ligacoes.map((l) => `<path d="${l.d}" fill="none" stroke="#94a3b8" stroke-width="1.4"/>`).join('');
  const caixas = [...d.nos.values()].map((n) => {
    const c = cartoes.get(n.id);
    if (!c) return '';
    const cor = CORES_HEX[c.cor ?? 'azul'] ?? CORES_HEX.azul;
    const pessoas = c.pessoas.slice(0, 2).map((p, i) => {
      const y = n.y + 64 + i * 22;
      return `<circle cx="${n.x + 20}" cy="${y}" r="9" fill="${cor}" fill-opacity="0.15" stroke="${cor}" stroke-width="0.8"/>
        <text x="${n.x + 20}" y="${y + 3}" font-size="8" font-weight="700" text-anchor="middle" fill="${cor}">${esc(iniciais(p.nome))}</text>
        <text x="${n.x + 34}" y="${y + 3}" font-size="10" fill="#0f172a"${p.responsavel ? ' font-weight="700"' : ''}>${esc(cortar(p.nome, 30))}</text>`;
    }).join('');
    const mais = c.pessoas.length > 2 ? `<text x="${n.x + 34}" y="${n.y + 109}" font-size="9" fill="#64748b">+${c.pessoas.length - 2} membro(s)</text>` : '';
    const vazio = !c.pessoas.length ? `<text x="${n.x + 12}" y="${n.y + 70}" font-size="9" font-style="italic" fill="#94a3b8">Sem ocupante</text>` : '';
    return `<g>
      <rect x="${n.x}" y="${n.y}" width="${m.largura}" height="${m.altura}" rx="6" fill="${c.apoio ? '#faf5ff' : '#ffffff'}" stroke="#cbd5e1"${c.apoio ? ' stroke-dasharray="4 3"' : ''}/>
      <rect x="${n.x}" y="${n.y}" width="${m.largura}" height="4" rx="2" fill="${cor}"/>
      <text x="${n.x + 12}" y="${n.y + 24}" font-size="12" font-weight="700" fill="#0f172a">${esc(cortar(c.titulo, 32))}</text>
      <text x="${n.x + 12}" y="${n.y + 40}" font-size="9.5" fill="#64748b">${esc(cortar([c.area, c.apoio ? 'apoio' : null].filter(Boolean).join(' · '), 40))}</text>
      <text x="${n.x + m.largura - 10}" y="${n.y + 40}" font-size="9" text-anchor="end" fill="#475569">${esc(c.vagas)}</text>
      ${pessoas}${mais}${vazio}
      ${c.contacto ? `<text x="${n.x + 12}" y="${n.y + m.altura - 8}" font-size="8.5" fill="#475569">${esc(cortar(c.contacto, 44))}</text>` : ''}
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
