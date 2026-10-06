import { Button, Space } from 'antd';
import { DeleteOutlined, HourglassOutlined, PercentageOutlined, TableOutlined, TagsOutlined, ToolOutlined, UnorderedListOutlined } from '@ant-design/icons';
import type { ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';

/** Botões de navegação do legado «Gestão de Activos Imobilizados» (renderAssets, js/ui_assets.js), na mesma ordem. */
export const SECCOES_ACTIVOS: { id: string; rotulo: string; icone: ReactNode }[] = [
  { id: 'activos', rotulo: 'Inventário de Activos', icone: <UnorderedListOutlined /> },
  { id: 'activos_categorias', rotulo: 'Categorias e Taxas', icone: <TagsOutlined /> },
  { id: 'activos_manutencao', rotulo: 'Manutenções', icone: <ToolOutlined /> },
  { id: 'activos_amortizacoes', rotulo: 'Amortizações', icone: <PercentageOutlined /> },
  { id: 'activos_mapa', rotulo: 'Mapa de Amortizações', icone: <TableOutlined /> },
  { id: 'activos_abates', rotulo: 'Abates e Vendas', icone: <DeleteOutlined /> },
  { id: 'activos_pendentes', rotulo: 'Aquisições Pendentes', icone: <HourglassOutlined /> },
];

/** Barra de secções dos Activos: só as que o utilizador pode ver (menu do servidor); a actual em destaque. */
export function NavActivos({ actual }: { actual: string }) {
  const { menu } = useSessao();
  const navegar = useNavigate();
  const visiveis = new Set(menu.find((m) => m.id === 'activos')?.ecras.map((e) => e.id) ?? []);
  const lista = SECCOES_ACTIVOS.filter((s) => visiveis.has(s.id));
  if (lista.length < 2) return null;
  return (
    <nav aria-label="Secções dos activos" className="erp-oculto-impressao" style={{ marginBottom: 16 }}>
      <Space wrap size={[8, 8]}>
        {lista.map((s) => (
          <Button key={s.id} size="small" icon={s.icone} type={s.id === actual ? 'primary' : 'default'} aria-current={s.id === actual ? 'page' : undefined}
            onClick={() => s.id !== actual && navegar(`/m/activos/${s.id}`)}>{s.rotulo}</Button>
        ))}
      </Space>
    </nav>
  );
}
