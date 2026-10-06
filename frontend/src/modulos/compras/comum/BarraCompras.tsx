import { Button, Card, Flex, Segmented } from 'antd';
import {
  AuditOutlined, FileAddOutlined, FileDoneOutlined, FileProtectOutlined, FileSearchOutlined, InboxOutlined, PlusOutlined,
  ShoppingCartOutlined, SolutionOutlined, TeamOutlined, UnorderedListOutlined,
} from '@ant-design/icons';
import { useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';

const BASE = '/m/compras';

/** Separadores do módulo, pela ordem do legado (renderPurchasesModule, js/ui_compras_v2.js). */
export const SEPARADORES_COMPRAS = [
  { ecra: 'compras_pedidos', rotulo: 'Pedidos', icone: <UnorderedListOutlined /> },
  { ecra: 'compras_prospeccao', rotulo: 'Prospecção', icone: <FileSearchOutlined /> },
  { ecra: 'compras_encomendas', rotulo: 'Encomendas', icone: <ShoppingCartOutlined /> },
  { ecra: 'compras_rececoes', rotulo: 'Recepções', icone: <InboxOutlined /> },
  { ecra: 'compras_faturacao', rotulo: 'Facturação', icone: <FileDoneOutlined /> },
  { ecra: 'compras_fornecedores', rotulo: 'Fornecedores', icone: <TeamOutlined /> },
  { ecra: 'compras_encomendas_clientes', rotulo: 'Encomendas clientes', icone: <SolutionOutlined /> },
  { ecra: 'compras_contratos', rotulo: 'Contratos', icone: <FileProtectOutlined /> },
] as const;

/** Ecrã de compras do caminho actual (ou nulo). */
export function ecraCompras(caminho: string): string | null {
  const m = /^\/m\/compras\/([a-z_]+)/.exec(caminho);
  return m ? m[1] : null;
}

/**
 * Barra do módulo de Compras, como no legado: acções rápidas (+ Pedido, + Proposta, + Factura directa, + Contrato —
 * rótulos curtos para não repetir os botões dos ecrãs) e os separadores do módulo. Só mostra o que o perfil pode ver/fazer.
 */
export function BarraCompras() {
  const navegar = useNavigate();
  const { pathname } = useLocation();
  const { pode } = useSessao();
  const actual = ecraCompras(pathname);
  const separadores = SEPARADORES_COMPRAS.filter((s) => pode(`${s.ecra}_view`));
  const rapidas = [
    pode('compras_ped_criar') && { chave: 'pedido', rotulo: 'Pedido', icone: <PlusOutlined />, destino: `${BASE}/compras_pedidos/novo` },
    pode('compras_new_proposal') && { chave: 'proposta', rotulo: 'Proposta', icone: <FileAddOutlined />, destino: `${BASE}/compras_prospeccao/novo` },
    pode('compras_fact_registar') && { chave: 'factura', rotulo: 'Factura directa', icone: <AuditOutlined />, destino: `${BASE}/compras_faturacao/novo` },
    pode('compras_contratos_gerir') && { chave: 'contrato', rotulo: 'Contrato', icone: <FileProtectOutlined />, destino: `${BASE}/compras_contratos?novo=1` },
  ].filter(Boolean) as { chave: string; rotulo: string; icone: JSX.Element; destino: string }[];

  if (!separadores.length && !rapidas.length) return null;
  return (
    <div className="imp-nao-imprimir" style={{ marginBottom: 16 }}>
      {rapidas.length > 0 && (
        <Card size="small" style={{ marginBottom: 12 }} styles={{ body: { padding: 10 } }}>
          <Flex wrap gap="small">
            {rapidas.map((r, i) => (
              <Button key={r.chave} type={i === 0 ? 'primary' : 'default'} ghost={i === 0} icon={r.icone} onClick={() => navegar(r.destino)}>
                {r.rotulo}
              </Button>
            ))}
          </Flex>
        </Card>
      )}
      {separadores.length > 1 && (
        <Segmented
          style={{ maxWidth: '100%', overflowX: 'auto' }}
          value={actual ?? undefined}
          onChange={(v) => navegar(`${BASE}/${v}`)}
          options={separadores.map((s) => ({ value: s.ecra, label: s.rotulo, icon: s.icone }))}
        />
      )}
    </div>
  );
}
