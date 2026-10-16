import { AppstoreOutlined, UnorderedListOutlined } from '@ant-design/icons';
import { Button, Space, Tooltip } from 'antd';
import type { ModoVista } from '@/componentes/vistas/preferenciaVista';

/**
 * Selector «Grade | Linhas» do canto superior direito dos modais de criação (legado: `.fl-seletor`, com os mesmos
 * textos e dicas). Botões com `aria-pressed`, num grupo com rótulo acessível.
 */
export function AlternarFormulario({ vista, aoMudar, desactivado }: { vista: ModoVista; aoMudar: (v: ModoVista) => void; desactivado?: boolean }) {
  return (
    <Space.Compact size="small" role="group" aria-label="Vista do formulário" className="erp-alternar-vista erp-alternar-formulario">
      <Tooltip title="Um registo de cada vez, em campos">
        <Button type={vista === 'grade' ? 'primary' : 'default'} aria-pressed={vista === 'grade'} icon={<AppstoreOutlined />} disabled={desactivado}
          onClick={() => vista !== 'grade' && aoMudar('grade')}>Grade</Button>
      </Tooltip>
      <Tooltip title="Vários registos numa tabela, uma linha por registo">
        <Button type={vista === 'linhas' ? 'primary' : 'default'} aria-pressed={vista === 'linhas'} icon={<UnorderedListOutlined />} disabled={desactivado}
          onClick={() => vista !== 'linhas' && aoMudar('linhas')}>Linhas</Button>
      </Tooltip>
    </Space.Compact>
  );
}
