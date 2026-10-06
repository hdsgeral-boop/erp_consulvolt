import type { ResultadoSalarial } from '../api';
import { valorPorExtenso } from '@/modulos/vendas/impressao/documentoComercial';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { mesPorExtenso, numeroRecibo } from './regras';

export interface DadosReciboColaborador {
  nome: string;
  nif?: string | null;
  numero_inss?: string | null;
  funcao?: string | null;
}

export interface DadosReciboEmpresa {
  nome?: string | null;
  nif?: string | null;
  endereco?: string | null;
  telefone?: string | null;
  email?: string | null;
  taxa_inss_trabalhador?: number | string | null;
  taxa_inss_patronal?: number | string | null;
}

/** Nomes completos das rubricas que o cálculo abrevia (RCB_NOMES do legado). */
const NOMES: Record<string, string> = {
  'Sal. Base': 'Salário Base', 'S. Alim': 'Subsídio de Alimentação', 'S. Transp': 'Subsídio de Transporte', 'S. férias': 'Subsídio de Férias',
  'Adiant.': 'Adiantamento', 'H. Extras': 'Horas Extraordinárias', 'Desc. Falta': 'Desconto por Faltas',
};

const kz = (v: string | number | null | undefined) => formatarKz(v ?? 0);
const taxa = (v: number | string | null | undefined, padrao: number) => String(Number(v ?? padrao)).replace('.', ',');

interface LinhaRecibo { cod: string; nome: string; v?: number; d?: number }

/** Linhas do recibo: rubricas (sem as informativas e as nulas), Segurança Social e IRT. */
export function linhasRecibo(r: ResultadoSalarial, empresa?: DadosReciboEmpresa | null): LinhaRecibo[] {
  const linhas: LinhaRecibo[] = (r.rubricas ?? [])
    .filter((x) => !x.informativa && Number(x.valor) !== 0 && (x.tipo === 'VENCIMENTO' || x.tipo === 'DESCONTO'))
    .map((x) => ({ cod: x.infotipo_id != null ? String(x.infotipo_id).padStart(3, '0') : '', nome: NOMES[x.nome] ?? x.nome, ...(x.tipo === 'VENCIMENTO' ? { v: Number(x.valor) } : { d: Number(x.valor) }) }));
  if (!r.avencado && Number(r.inss_trabalhador) > 0) linhas.push({ cod: 'SS', nome: `Segurança Social — INSS (${taxa(empresa?.taxa_inss_trabalhador, 3)}%)`, d: Number(r.inss_trabalhador) });
  if (Number(r.irt) > 0) linhas.push({ cod: 'IRT', nome: r.avencado ? 'Retenção na fonte — IRT Grupo B (6,5%)' : 'Retenção na fonte — IRT Grupo A', d: Number(r.irt) });
  return linhas;
}

/** Uma via do recibo (getReciboVia do legado, js/app_v2.js:7655). */
function Via({ r, mesAno, colaborador, empresa, via }: { r: ResultadoSalarial; mesAno: string; colaborador: DadosReciboColaborador; empresa?: DadosReciboEmpresa | null; via: string }) {
  const linhas = linhasRecibo(r, empresa);
  const totalV = linhas.reduce((s, l) => s + (l.v ?? 0), 0);
  const totalD = linhas.reduce((s, l) => s + (l.d ?? 0), 0);
  const regime = r.avencado ? 'Prestador avençado (IRT Grupo B)' : r.reformado ? 'Reformado' : 'Conta de outrem (IRT Grupo A)';
  const dias = Number(r.dias_contrato) > 0 ? `${formatarNumero(Number(r.dias_trabalhados) || Number(r.dias_contrato))} de ${formatarNumero(r.dias_contrato)}` : '—';
  const pagamento = r.iban ? `${r.banco || 'Transferência bancária'} · IBAN ${r.iban}` : 'Transferência bancária';
  const info = [
    !r.avencado && Number(r.base_inss) > 0 ? `Base de incidência INSS: ${kz(r.base_inss)} Kz` : null,
    Number(r.inss_patronal) > 0 ? `INSS a cargo da entidade patronal (${taxa(empresa?.taxa_inss_patronal, 8)}%): ${kz(r.inss_patronal)} Kz` : null,
    !r.avencado && Number(r.base_irt) > 0 ? `Matéria colectável IRT: ${kz(r.base_irt)} Kz` : null,
  ].filter(Boolean);
  return (
    <div className="rh-recibo-via">
      <div className="rh-recibo-topo">
        <div>
          <div className="rh-recibo-empresa">{empresa?.nome ?? ''}</div>
          <div className="rh-recibo-sub">NIF {empresa?.nif || '—'}{empresa?.endereco ? ` · ${empresa.endereco}` : ''}</div>
          {(empresa?.telefone || empresa?.email) && <div className="rh-recibo-sub">{[empresa.telefone, empresa.email].filter(Boolean).join(' · ')}</div>}
        </div>
        <div className="rh-recibo-direita">
          <div className="rh-recibo-titulo">Recibo de vencimento</div>
          <div>Período: <b>{mesPorExtenso(mesAno)}</b></div>
          <div className="rh-recibo-sub">N.º {r.numero_recibo ?? numeroRecibo(mesAno, r.colaborador_id)}</div>
          <span className="rh-recibo-via-rotulo">{via}</span>
        </div>
      </div>
      <div className="rh-recibo-campos">
        <div><span>Colaborador</span>{colaborador.nome}</div>
        <div><span>Função</span>{colaborador.funcao ?? r.funcao ?? '—'}</div>
        <div><span>NIF</span>{colaborador.nif ?? r.nif ?? '—'}</div>
        <div><span>N.º Segurança Social</span>{colaborador.numero_inss ?? r.numero_inss ?? '—'}</div>
        <div><span>Regime fiscal</span>{regime}</div>
        <div><span>Dias trabalhados</span>{dias}</div>
        <div className="rh-recibo-campo-largo"><span>Forma de pagamento</span>{pagamento}</div>
      </div>
      <div className="erp-deslocar-x">
        <table className="rh-recibo-linhas">
          <thead><tr><th>Cód.</th><th>Descrição</th><th className="num">Remunerações</th><th className="num">Descontos</th></tr></thead>
          <tbody>
            {linhas.length ? linhas.map((l, i) => (
              <tr key={i}>
                <td className="rh-recibo-cod">{l.cod}</td>
                <td>{l.nome}</td>
                <td className="num">{l.v !== undefined ? kz(l.v) : ''}</td>
                <td className="num rh-recibo-desc">{l.d !== undefined ? kz(l.d) : ''}</td>
              </tr>
            )) : <tr><td colSpan={4} style={{ textAlign: 'center' }}>Sem rubricas processadas</td></tr>}
          </tbody>
        </table>
      </div>
      <div className="rh-recibo-caixas">
        <div><span>Total de remunerações</span>{kz(totalV)} Kz</div>
        <div><span>Total de descontos</span>{kz(totalD)} Kz</div>
        <div className="rh-recibo-liquido"><span>Líquido a receber</span>{kz(r.liquido)} Kz</div>
      </div>
      <div className="rh-recibo-extenso"><b>Importância líquida:</b> <i>{valorPorExtenso(r.liquido)}</i></div>
      {info.length > 0 && <div className="rh-recibo-sub">{info.join(' · ')}</div>}
      <div className="rh-recibo-assinatura">
        <div>A Entidade Patronal</div>
        <div><small>Declaro ter recebido a importância líquida acima indicada.</small><br />O Colaborador — {colaborador.nome}</div>
      </div>
    </div>
  );
}

/**
 * Recibo de vencimento (da fotografia do período validado), no modelo do legado (getReciboHTML, js/app_v2.js:7780):
 * duas vias por página — «Original — Colaborador» e «Duplicado — Entidade Patronal» — com linha de corte, líquido por
 * extenso e forma de pagamento (banco e IBAN). `vias={1}` mostra só o original (portal do colaborador).
 */
export function ReciboSalario({ resultado, mesAno, colaborador, empresa, quebra, vias = 2 }: {
  resultado: ResultadoSalarial;
  mesAno: string;
  colaborador: DadosReciboColaborador;
  empresa?: DadosReciboEmpresa | null;
  quebra?: boolean;
  vias?: 1 | 2;
}) {
  return (
    <div className={`rh-recibo${quebra ? ' rh-recibo-quebra' : ''}`}>
      <Via r={resultado} mesAno={mesAno} colaborador={colaborador} empresa={empresa} via="Original — Colaborador" />
      {vias === 2 && (
        <>
          <div className="rh-recibo-corte">✂ cortar pelo tracejado</div>
          <Via r={resultado} mesAno={mesAno} colaborador={colaborador} empresa={empresa} via="Duplicado — Entidade Patronal" />
        </>
      )}
    </div>
  );
}
