import { AppstoreOutlined, CheckOutlined, DownOutlined, UnorderedListOutlined } from '@ant-design/icons';
import { Button, Dropdown, Space, Tooltip, type MenuProps } from 'antd';
import { useEcraPequeno } from '@/componentes/responsivo';
import type { EstadoModoVista, OmissaoVista } from './preferenciaVista';

const ROTULOS_OMISSAO: Record<OmissaoVista, string> = {
  automatica: 'Automática (grade no telemóvel)',
  linhas: 'Linhas em todos os ecrãs',
  grade: 'Grade em todos os ecrãs',
};

/**
 * Controlo «Linhas | Grade» do cabeçalho das listas, com um menu para a omissão global do utilizador e para repor a
 * omissão neste ecrã. Botões com rótulo acessível e `aria-pressed` (leitores de ecrã anunciam o modo activo).
 */
export function AlternarVista({ vista }: { vista: EstadoModoVista }) {
  const pequeno = useEcraPequeno();
  const tamanho = pequeno ? 'middle' : 'small';
  const itens: MenuProps['items'] = [
    {
      key: 'omissao',
      type: 'group',
      label: 'Vista por omissão (todos os ecrãs)',
      children: (Object.keys(ROTULOS_OMISSAO) as OmissaoVista[]).map((o) => ({
        key: `omissao:${o}`,
        label: ROTULOS_OMISSAO[o],
        icon: vista.omissaoGlobal === o ? <CheckOutlined /> : <span style={{ display: 'inline-block', width: 14 }} />,
      })),
    },
    { type: 'divider' },
    { key: 'repor', label: 'Repor a omissão neste ecrã', disabled: !vista.temEscolhaEcra },
  ];
  return (
    <Space.Compact size={tamanho} role="group" aria-label="Modo de vista da lista" className="erp-alternar-vista">
      <Tooltip title="Vista em linhas">
        <Button
          aria-label="Vista em linhas"
          aria-pressed={vista.modo === 'linhas'}
          type={vista.modo === 'linhas' ? 'primary' : 'default'}
          icon={<UnorderedListOutlined />}
          onClick={() => vista.modo !== 'linhas' && vista.definir('linhas')}
        />
      </Tooltip>
      <Tooltip title="Vista em grade">
        <Button
          aria-label="Vista em grade"
          aria-pressed={vista.modo === 'grade'}
          type={vista.modo === 'grade' ? 'primary' : 'default'}
          icon={<AppstoreOutlined />}
          onClick={() => vista.modo !== 'grade' && vista.definir('grade')}
        />
      </Tooltip>
      <Dropdown
        trigger={['click']}
        menu={{
          items: itens,
          onClick: ({ key }) => {
            if (key === 'repor') vista.reporEcra();
            else if (key.startsWith('omissao:')) vista.definirOmissaoGlobal(key.slice(8) as OmissaoVista);
          },
        }}
      >
        <Button aria-label="Opções da vista" icon={<DownOutlined />} />
      </Dropdown>
    </Space.Compact>
  );
}
