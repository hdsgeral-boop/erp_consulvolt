import { Tabs } from 'antd';
import { SafetyOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useSessao } from '@/sessao/SessaoContexto';

/** Separadores do topo das Configurações do legado (Geral/Backup … Manutenção de dados), na mesma ordem e com os mesmos rótulos. */
export const SECCOES_CONFIG: { id: string; rotulo: string }[] = [
  { id: 'config_geral', rotulo: 'Geral / Backup' },
  { id: 'config_empresas', rotulo: 'Gestão de Empresas' },
  { id: 'config_plano', rotulo: 'Plano de Contas' },
  { id: 'config_moedas', rotulo: 'Moedas e Câmbios' },
  { id: 'config_utilizadores', rotulo: 'Utilizadores' },
  { id: 'config_perfis', rotulo: 'Perfis e Permissões' },
  { id: 'config_manutencao', rotulo: 'Manutenção de dados' },
];

/** Navegação entre os ecrãs de Configurações que o utilizador pode ver (menu do servidor); o actual fica activo. */
export function NavConfig({ actual }: { actual: string }) {
  const { menu } = useSessao();
  const navegar = useNavigate();
  const visiveis = new Set(menu.find((m) => m.id === 'config')?.ecras.map((e) => e.id) ?? []);
  const lista = SECCOES_CONFIG.filter((s) => visiveis.has(s.id));
  if (lista.length < 2) return null;
  return (
    <Tabs size="small" activeKey={actual} onChange={(k) => navegar(`/m/config/${k}`)} className="erp-oculto-impressao" style={{ marginBottom: 4 }}
      items={lista.map((s) => ({ key: s.id, label: s.id === 'config_manutencao' ? <span style={{ color: '#cf1322' }}><SafetyOutlined /> {s.rotulo}</span> : s.rotulo }))} />
  );
}
