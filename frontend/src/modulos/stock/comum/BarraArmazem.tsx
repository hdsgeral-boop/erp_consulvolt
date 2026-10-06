import { Button, Flex, Segmented } from 'antd';
import { DatabaseOutlined, ExportOutlined, HistoryOutlined, ImportOutlined, SettingOutlined } from '@ant-design/icons';
import { useLocation, useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';

const BASE = '/m/stock';

/** Separadores do «Gestão de Armazém» do legado (ui_warehouse.js), pela mesma ordem. */
export const SEPARADORES_ARMAZEM = [
  { ecra: 'armazem_stock', rotulo: 'Níveis de stock', icone: <DatabaseOutlined /> },
  { ecra: 'armazem_rececoes', rotulo: 'Validar entradas (compras)', icone: <ImportOutlined /> },
  { ecra: 'armazem_guias', rotulo: 'Guias de saída (entregas)', icone: <ExportOutlined /> },
  { ecra: 'armazem_movimentos', rotulo: 'Histórico', icone: <HistoryOutlined /> },
  { ecra: 'armazem_armazens', rotulo: 'Config. armazéns', icone: <SettingOutlined /> },
] as const;

/** Barra do módulo Armazém, como no legado: separadores e a acção «Emitir guia de saída» à direita. */
export function BarraArmazem() {
  const navegar = useNavigate();
  const { pathname } = useLocation();
  const { pode } = useSessao();
  const actual = /^\/m\/stock\/([a-z_]+)/.exec(pathname)?.[1];
  const separadores = SEPARADORES_ARMAZEM.filter((s) => pode(`${s.ecra}_view`));
  if (separadores.length < 2 && !pode('armazem_guias_emitir')) return null;
  return (
    <Flex wrap gap="small" justify="space-between" align="center" style={{ marginBottom: 16 }} className="imp-nao-imprimir">
      {separadores.length > 1 ? (
        <Segmented style={{ maxWidth: '100%', overflowX: 'auto' }} value={actual} onChange={(v) => navegar(`${BASE}/${v}`)}
          options={separadores.map((s) => ({ value: s.ecra, label: s.rotulo, icon: s.icone }))} />
      ) : <span />}
      {pode('armazem_guias_emitir') && <Button type="primary" ghost icon={<ExportOutlined />} onClick={() => navegar(`${BASE}/armazem_guias/novo`)}>Emitir guia de saída</Button>}
    </Flex>
  );
}
