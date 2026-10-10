import { Button } from 'antd';
import { useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';
import { CabecalhoModulo } from '@/componentes/CabecalhoModulo';
import { IconeFa } from '@/componentes/fluxos/IconeFa';

const BASE = '/m/compras';

/** Separadores do módulo, pela ordem, rótulos e ícones do legado (renderCompras, js/ui_compras_v2.js:850-875). */
export const SEPARADORES_COMPRAS = [
  { ecra: 'compras_pedidos', rotulo: 'Pedidos', icone: 'shopping-cart' },
  { ecra: 'compras_prospeccao', rotulo: 'Prospeção', icone: 'search-dollar' },
  { ecra: 'compras_encomendas', rotulo: 'Encomendas', icone: 'shopping-cart' },
  { ecra: 'compras_rececoes', rotulo: 'Receções', icone: 'truck-loading' },
  { ecra: 'compras_faturacao', rotulo: 'Faturação', icone: 'file-invoice-dollar' },
  { ecra: 'compras_fornecedores', rotulo: 'Fornecedores', icone: 'users' },
  { ecra: 'compras_encomendas_clientes', rotulo: 'Encomendas Clientes', icone: 'file-export' },
  { ecra: 'compras_contratos', rotulo: 'Contratos', icone: 'file-contract' },
] as const;

/** Acções rápidas do legado (.compras-toolbar, js/ui_compras_v2.js:852-857): rótulos e ícones iguais. */
export const ACCOES_RAPIDAS_COMPRAS = [
  { chave: 'pedido', rotulo: 'Novo Pedido', icone: 'plus', permissao: 'compras_ped_criar', destino: `${BASE}/compras_pedidos/novo` },
  { chave: 'proposta', rotulo: 'Registar Proposta', icone: 'file-contract', permissao: 'compras_new_proposal', destino: `${BASE}/compras_prospeccao/novo` },
  { chave: 'factura', rotulo: 'Fatura Direta', icone: 'file-invoice-dollar', permissao: 'compras_fact_registar', destino: `${BASE}/compras_faturacao/novo` },
  { chave: 'contrato', rotulo: 'Novo Contrato', icone: 'file-signature', permissao: 'compras_contratos_gerir', destino: `${BASE}/compras_contratos?novo=1` },
] as const;

/** Ecrã de compras do caminho actual (ou nulo). */
export function ecraCompras(caminho: string): string | null {
  const m = /^\/m\/compras\/([a-z_]+)/.exec(caminho);
  return m ? m[1] : null;
}

/** A barra de acções rápidas só aparece nas listas (nos formulários e detalhes há botões do ecrã com os mesmos nomes). */
export function eListaCompras(caminho: string): boolean {
  return /^\/m\/compras\/[a-z_]+\/?$/.test(caminho);
}

/**
 * Cabeçalho do módulo de Compras, como no legado: título «Módulo de Compras e Aprovisionamento» com ícone, por cima da barra
 * de acções rápidas («+ Novo Pedido», «Registar Proposta», «Fatura Direta», «Novo Contrato») e dos separadores-botão do
 * módulo (CabecalhoModulo, G.5). Só mostra o que o perfil pode ver/fazer.
 */
export function BarraCompras() {
  const navegar = useNavigate();
  const { pathname } = useLocation();
  const { pode } = useSessao();
  const rapidas = eListaCompras(pathname) ? ACCOES_RAPIDAS_COMPRAS.filter((r) => pode(r.permissao)) : [];

  return (
    <CabecalhoModulo
      titulo="Módulo de Compras e Aprovisionamento"
      icone="shopping-cart"
      accoesRapidas={
        rapidas.length > 0 &&
        rapidas.map((r) => (
          <Button key={r.chave} type={r.chave === 'pedido' ? 'primary' : 'default'} icon={<IconeFa nome={r.icone} />} onClick={() => navegar(r.destino)}>
            {r.rotulo}
          </Button>
        ))
      }
      separadores={SEPARADORES_COMPRAS.map((s) => ({ chave: s.ecra, rotulo: s.rotulo, icone: s.icone, destino: `${BASE}/${s.ecra}`, permissao: `${s.ecra}_view` }))}
    />
  );
}
