import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';

const BASE = '/m/pos';

/**
 * Separadores do módulo POS pela ordem, rótulos e ícones do legado (renderPOS, js/ui_sales.js:134-145; separadores
 * acrescentados por js/pos_prestacao.js:802-806 e js/lavandaria.js:2355). «Hotelaria» (só no sistema novo) fica no fim.
 */
export const SEPARADORES_POS = [
  { ecra: 'pos', rotulo: 'Frente de Caixa', icone: 'cash-register' },
  { ecra: 'pos_relatorios', rotulo: 'relatórios POS', icone: 'chart-pie' },
  { ecra: 'pos_terminais', rotulo: 'Terminais POS', icone: 'desktop' },
  { ecra: 'pos_config_print', rotulo: 'Config. Impressão', icone: 'print' },
  { ecra: 'pos_integracao', rotulo: 'Integração contabilística', icone: 'book' },
  { ecra: 'pos_desvios', rotulo: 'Desvios de caixa', icone: 'balance-scale' },
  { ecra: 'pos_prestacao', rotulo: 'Prestação de contas', icone: 'hand-holding-usd' },
  { ecra: 'pos_lavandaria', rotulo: 'Lavandaria', icone: 'tshirt' },
  { ecra: 'pos_hotelaria', rotulo: 'Hotelaria', icone: 'hotel' },
] as const;

/** Título e subtítulo do módulo (texto exacto do legado, ui_sales.js:150-151). */
export const TITULO_POS = 'Ponto de Venda (POS)';
export const SUBTITULO_POS = 'Administre as frentes de caixa, consulte relatórios e configure impressoras termicas.';

/**
 * Cabeçalho do módulo POS (G.5, 3.1.7, 3.1.8): «Ponto de Venda (POS)» com o ícone da caixa registadora, o subtítulo do
 * legado e os separadores-botão; cada separador só aparece com a permissão de ver o ecrã (`<ecra>_view`).
 * No modo imersivo da frente de caixa fica por baixo da camada de ecrã inteiro (não se vê).
 */
export function BarraPos() {
  return (
    <CabecalhoModulo
      titulo={TITULO_POS}
      icone="cash-register"
      subtitulo={SUBTITULO_POS}
      separadores={SEPARADORES_POS.map((s) => ({ chave: s.ecra, rotulo: s.rotulo, icone: s.icone, destino: `${BASE}/${s.ecra}`, permissao: `${s.ecra}_view` }))}
    />
  );
}
