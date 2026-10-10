import { Button } from 'antd';
import { useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';

const BASE = '/m/stock';

/**
 * Separadores do «Gestão de Armazém» do legado (renderArmazém, js/ui_warehouse.js:103-113), pela mesma ordem e com os
 * mesmos rótulos (o legado não tem ícones nestes separadores).
 */
export const SEPARADORES_ARMAZEM = [
  { ecra: 'armazem_stock', rotulo: 'níveis de Stock' },
  { ecra: 'armazem_rececoes', rotulo: 'Validar Entradas (Compras)' },
  { ecra: 'armazem_guias', rotulo: 'Guias de Saída (Entregas)' },
  { ecra: 'armazem_movimentos', rotulo: 'histórico' },
  { ecra: 'armazem_armazens', rotulo: 'Config. Armazens' },
] as const;

/** Cabeçalho do módulo Armazém (G.5): «Gestão de Armazém» com o ícone do legado, separadores-botão e «Emitir Guia de Saída». */
export function BarraArmazem() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  return (
    <CabecalhoModulo
      titulo="Gestão de Armazém"
      icone="warehouse"
      accoes={
        pode('armazem_guias_emitir') && (
          <Button type="primary" size="small" icon={<IconeFa nome="file-export" />} onClick={() => navegar(`${BASE}/armazem_guias/novo`)}>
            Emitir Guia de Saída
          </Button>
        )
      }
      separadores={SEPARADORES_ARMAZEM.map((s) => ({ chave: s.ecra, rotulo: s.rotulo, destino: `${BASE}/${s.ecra}`, permissao: `${s.ecra}_view` }))}
    />
  );
}

/** Separadores do «Inventário de Stock (Logística)» do legado (renderInventario, js/ui_inventory.js:9-15). */
export const SEPARADORES_INVENTARIO = [
  { ecra: 'inventario_sessoes', rotulo: 'Sessões de Inventário', icone: 'tasks' },
  { ecra: 'inventario_contagem', rotulo: 'Efetuar Contagem', icone: 'list-ol' },
  { ecra: 'inventario_revisao', rotulo: 'Revisão e Regularização', icone: 'balance-scale' },
] as const;

/** Cabeçalho do Inventário (2.7.1): título do legado com ícone e os três separadores-botão. */
export function BarraInventario() {
  return (
    <CabecalhoModulo
      titulo="Inventário de Stock (Logística)"
      icone="clipboard-check"
      separadores={SEPARADORES_INVENTARIO.map((s) => ({ chave: s.ecra, rotulo: s.rotulo, icone: s.icone, destino: `${BASE}/${s.ecra}`, permissao: `${s.ecra}_view` }))}
    />
  );
}
