import { Checkbox, TimePicker } from 'antd';
import dayjs, { type Dayjs } from 'dayjs';
import type { CampoFormulario, OpcaoCampo, PropsCelula } from '@/componentes/formularios';
import type { Colaborador } from '../api';

/**
 * Peças comuns dos formulários «Grade | Linhas» do RH (componentes/formularios). As funções puras que transformam os
 * valores em pedido aceitam tanto os valores da grade (`dayjs`) como os das linhas (`'AAAA-MM-DD'`, `'HH:mm'`).
 */

/** Data da grade (`dayjs`) ou das linhas (`'AAAA-MM-DD'`) → `'AAAA-MM-DD'` (ou `null`). */
export function dataDe(v: unknown): string | null {
  if (!v) return null;
  if (dayjs.isDayjs(v)) return v.isValid() ? v.format('YYYY-MM-DD') : null;
  const s = String(v).trim();
  return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0, 10) : null;
}

/** Hora «8:00», «08:00», «8h30», «0830» → `'HH:mm'`; `undefined` se não for reconhecida; `null` se vazia. */
export function horaTexto(v: unknown): string | null | undefined {
  if (v === null || v === undefined || v === '') return null;
  if (dayjs.isDayjs(v)) return v.isValid() ? v.format('HH:mm') : null;
  const s = String(v).trim().toLowerCase();
  if (!s) return null;
  const m = s.match(/^(\d{1,2})\s*[:hH.]\s*(\d{2})(?::\d{2})?$/) ?? s.match(/^(\d{2})(\d{2})$/);
  if (!m) {
    // fracção do dia do Excel (0,375 = 09:00)
    const f = Number(s.replace(',', '.'));
    if (Number.isFinite(f) && f >= 0 && f < 1 && /[.,]/.test(s)) {
      const min = Math.round(f * 24 * 60);
      return `${String(Math.floor(min / 60)).padStart(2, '0')}:${String(min % 60).padStart(2, '0')}`;
    }
    return undefined;
  }
  const h = Number(m[1]);
  const mi = Number(m[2]);
  if (h > 23 || mi > 59) return undefined;
  return `${String(h).padStart(2, '0')}:${String(mi).padStart(2, '0')}`;
}

/** Hora da grade ou das linhas → `'HH:mm'` ou `null` (para o pedido). */
export const horaDe = (v: unknown): string | null => horaTexto(v) ?? null;

/**
 * Propriedades do `Form.Item` de uma hora: o `TimePicker` mostra um `dayjs`, mas o formulário guarda `'HH:mm'` — o
 * mesmo valor das linhas, para a passagem Grade ↔ Linhas não ter conversões.
 */
export const propsHora = {
  getValueProps: (v: unknown) => ({ value: typeof v === 'string' && v ? dayjs(v, 'HH:mm') : dayjs.isDayjs(v) ? v : null }),
  normalize: (v: Dayjs | null) => (v ? v.format('HH:mm') : null),
};

/** Campo de hora da vista Linhas (selector de hora; colagem «08:00», «8h00», «0,375»). */
export function campoHora(nome: string, rotulo: string, extra: Partial<CampoFormulario> = {}): CampoFormulario {
  return {
    nome,
    rotulo,
    tipo: 'personalizado',
    largura: 110,
    converter: (t) => horaTexto(t) ?? undefined,
    celula: ({ valor, aoMudar, rotulo: r, erro }: PropsCelula) => (
      <TimePicker size="small" format="HH:mm" minuteStep={5} needConfirm={false} aria-label={r} status={erro ? 'error' : undefined} style={{ width: '100%' }}
        value={typeof valor === 'string' && valor ? dayjs(valor, 'HH:mm') : null} onChange={(d) => aoMudar(d ? d.format('HH:mm') : null)} />
    ),
    ...extra,
  };
}

/** Sim/Não colado («sim», «s», «1», «x», «true»; «não», «n», «0», «false»). */
export function simNaoTexto(t: string): boolean | undefined {
  const s = t.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  if (['sim', 's', '1', 'x', 'true', 'yes', 'y'].includes(s)) return true;
  if (['nao', 'n', '0', 'false', 'no', ''].includes(s)) return false;
  return undefined;
}

/** Campo Sim/Não da vista Linhas (caixa de verificação). */
export function campoSimNao(nome: string, rotulo: string, extra: Partial<CampoFormulario> = {}): CampoFormulario {
  return {
    nome,
    rotulo,
    tipo: 'personalizado',
    largura: 90,
    omissao: false,
    converter: simNaoTexto,
    celula: ({ valor, aoMudar, rotulo: r }: PropsCelula) => <Checkbox aria-label={r} checked={!!valor} onChange={(e) => aoMudar(e.target.checked)} />,
    ...extra,
  };
}

/**
 * Opções de colaborador para as linhas: «Nome (NIF)» — a colagem reconhece o id, o nome completo, o NIF entre
 * parênteses ou o início único do nome (conversoes.opcaoPara).
 */
export function opcoesColaboradores(lista: Colaborador[], apenasActivos = false): OpcaoCampo[] {
  return lista
    .filter((c) => !apenasActivos || c.estado === 'ACTIVO')
    .map((c) => ({ value: c.id, label: c.nif ? `${c.nome_completo} (${c.nif})` : c.nome_completo }));
}

/** Número da grade ou das linhas → número ou `null`. */
export const numeroDe = (v: unknown): number | null => (v === null || v === undefined || v === '' || Number.isNaN(Number(v)) ? null : Number(v));

/** Texto aparado ou `null`. */
export const textoDe = (v: unknown): string | null => {
  const s = typeof v === 'string' ? v.trim() : v === null || v === undefined ? '' : String(v);
  return s ? s : null;
};

/** Tira os valores vazios (`null`, `undefined`, `''`): o corpo da linha fica igual ao da grade, que omite os campos por preencher. */
export function semVazios<T extends Record<string, unknown>>(r: T): Partial<T> {
  return Object.fromEntries(Object.entries(r).filter(([, v]) => v !== null && v !== undefined && v !== '')) as Partial<T>;
}
