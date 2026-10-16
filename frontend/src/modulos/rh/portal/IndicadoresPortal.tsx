import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import type { ResumoPortal } from '../api';

/** Texto do estado da autoavaliação ANUAL do ano (portal_ui.js:180). */
export const ESTADO_AUTOAVALIACAO: Record<NonNullable<ResumoPortal['autoavaliacao']>['estado'], string> = {
  SUBMETIDA: 'Submetida',
  RASCUNHO: 'Rascunho',
  POR_FAZER: 'Por fazer',
};

/** Fundo e contorno avermelhados das caixas de alerta do antigo (.pc-kpi com border-color:#fca5a5; background:#fef2f2). */
const ALERTA = { borderColor: '#fca5a5', background: '#fef2f2' } as const;

/**
 * Indicadores do início do portal (portal_ui.js:176-183, .pc-kpis): caixas sem ícone; «Documentos emitidos» e
 * «Autoavaliação» sempre; «Ausências por justificar» (clica para o separador das faltas) e «Aprovações à sua espera»
 * só quando há alguma, a vermelho — como no antigo.
 */
export function IndicadoresPortal({ resumo: r, aoAbrirFaltas }: { resumo: ResumoPortal; aoAbrirFaltas?: () => void }) {
  const auto = r.autoavaliacao?.estado ?? 'POR_FAZER';
  return (
    <GrelhaKpi larguraMinima={170} style={{ marginBottom: 16 }}>
      <CartaoKpi barra="nenhuma" rotulo={`Férias disponíveis ${r.ano}`} valor={r.ferias.saldo} unidade={`de ${r.ferias.direito} dias`} />
      <CartaoKpi barra="nenhuma" rotulo="Pedidos em curso" valor={r.pedidos_pendentes} />
      <CartaoKpi barra="nenhuma" rotulo="Documentos emitidos" valor={r.documentos_emitidos ?? 0} />
      <CartaoKpi barra="nenhuma" rotulo={`Autoavaliação ${r.autoavaliacao?.ano ?? r.ano}`} valor={<span style={{ fontSize: '1rem' }}>{ESTADO_AUTOAVALIACAO[auto]}</span>} />
      {r.faltas_por_justificar > 0 && (
        <CartaoKpi barra="nenhuma" rotulo="Ausências por justificar" valor={r.faltas_por_justificar} cor="vermelho" corValor="#b91c1c" style={ALERTA}
          aoClicar={aoAbrirFaltas} ajuda={aoAbrirFaltas ? 'Abrir o separador das faltas' : undefined} />
      )}
      {r.aprovacoes_para_mim > 0 && (
        <CartaoKpi barra="nenhuma" rotulo="Aprovações à sua espera" valor={r.aprovacoes_para_mim} cor="vermelho" corValor="#b91c1c" style={ALERTA} />
      )}
    </GrelhaKpi>
  );
}
