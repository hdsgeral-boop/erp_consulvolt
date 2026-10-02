import type { ResultadoSalarial } from '../api';
import { DeslocamentoHorizontal } from '@/componentes/responsivo';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { mesPorExtenso, numeroRecibo } from './regras';

export interface DadosReciboColaborador {
  nome: string;
  nif?: string | null;
  numero_inss?: string | null;
  funcao?: string | null;
}

/**
 * Recibo de vencimento (da fotografia do período validado). Usado no Portal do Colaborador e nos Recibos de salário.
 * Formato para impressão no navegador (impressao.css).
 */
export function ReciboSalario({ resultado, mesAno, colaborador, empresa, quebra }: {
  resultado: ResultadoSalarial;
  mesAno: string;
  colaborador: DadosReciboColaborador;
  empresa?: { nome?: string | null; nif?: string | null } | null;
  quebra?: boolean;
}) {
  const r = resultado;
  const vencimentos = (r.rubricas ?? []).filter((x) => x.tipo === 'VENCIMENTO' && !x.informativa);
  const descontos = (r.rubricas ?? []).filter((x) => x.tipo === 'DESCONTO');
  const n = r.numero_recibo ?? numeroRecibo(mesAno, r.colaborador_id);
  return (
    <div className={`rh-recibo${quebra ? ' rh-recibo-quebra' : ''}`}>
      <div className="rh-recibo-topo">
        <div>
          <div style={{ fontWeight: 600 }}>{empresa?.nome ?? ''}</div>
          {empresa?.nif && <div>NIF {empresa.nif}</div>}
        </div>
        <div style={{ textAlign: 'right' }}>
          <div className="rh-recibo-titulo">Recibo de vencimento</div>
          <div>N.º {n} · {mesPorExtenso(mesAno)}</div>
        </div>
      </div>
      <div className="rh-recibo-dados">
        <div><span>Colaborador: </span>{colaborador.nome}</div>
        <div><span>NIF: </span>{colaborador.nif ?? '—'}</div>
        <div><span>N.º INSS: </span>{colaborador.numero_inss ?? '—'}</div>
        <div><span>Função: </span>{colaborador.funcao ?? '—'}</div>
        <div><span>Dias do contrato: </span>{formatarNumero(r.dias_contrato)}</div>
        <div><span>Dias trabalhados: </span>{formatarNumero(r.dias_trabalhados)}</div>
      </div>
      <DeslocamentoHorizontal>
      <table className="rh-tabela-mapa">
        <thead>
          <tr>
            <th style={{ textAlign: 'left' }}>Rubrica</th>
            <th>Horas</th>
            <th>Vencimentos (Kz)</th>
            <th>Descontos (Kz)</th>
          </tr>
        </thead>
        <tbody>
          {vencimentos.map((x, i) => (
            <tr key={`v${i}`}>
              <td>{x.nome}</td>
              <td className="num">{x.horas ? formatarNumero(x.horas) : ''}</td>
              <td className="num">{formatarKz(x.valor)}</td>
              <td />
            </tr>
          ))}
          {descontos.map((x, i) => (
            <tr key={`d${i}`}>
              <td>{x.nome}</td>
              <td className="num">{x.horas ? formatarNumero(x.horas) : ''}</td>
              <td />
              <td className="num">{formatarKz(x.valor)}</td>
            </tr>
          ))}
          {!r.avencado && Number(r.inss_trabalhador) > 0 && (
            <tr>
              <td>Segurança Social (trabalhador)</td>
              <td />
              <td />
              <td className="num">{formatarKz(r.inss_trabalhador)}</td>
            </tr>
          )}
          {Number(r.irt) > 0 && (
            <tr>
              <td>{r.avencado ? 'IRT (Grupo B — 6,5 %)' : 'IRT'}</td>
              <td />
              <td />
              <td className="num">{formatarKz(r.irt)}</td>
            </tr>
          )}
        </tbody>
      </table>
      </DeslocamentoHorizontal>
      <div className="rh-recibo-totais">
        <div>Bruto: <strong>{formatarKz(r.bruto)}</strong></div>
        <div>Base INSS: {formatarKz(r.base_inss)}</div>
        <div>Matéria colectável IRT: {formatarKz(r.base_irt)}</div>
        <div>Isenções: {formatarKz(r.isencoes)}</div>
      </div>
      <div className="rh-recibo-liquido">Líquido a receber: {formatarKz(r.liquido, true)}</div>
      <div className="rh-recibo-assinatura">
        <div>A entidade patronal</div>
        <div>Recebi (o colaborador)</div>
      </div>
    </div>
  );
}
