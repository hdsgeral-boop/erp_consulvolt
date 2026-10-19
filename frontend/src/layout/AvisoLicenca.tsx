import { Alert, Button } from 'antd';
import { useNavigate } from 'react-router-dom';
import { useEstadoLicenca } from '@/modulos/config/Licenca';
import { useSessao } from '@/sessao/SessaoContexto';

/** Aviso de licença da empresa activa: a expirar (<= 7 dias) ou expirada (só de leitura). Invisível sem aviso. */
export function AvisoLicenca() {
  const { empresa, pode } = useSessao();
  const navegar = useNavigate();
  const { data } = useEstadoLicenca(!!empresa);
  if (!data?.aviso) return null;
  const expirada = data.aviso === 'EXPIRADA';
  return (
    <Alert
      banner
      showIcon
      type={expirada ? 'error' : 'warning'}
      message={expirada ? 'Licença expirada: a empresa está só em modo de leitura.' : `Licença a expirar em ${data.dias_restantes} dia(s).`}
      action={pode('config_licenca_view') || pode('config_licenca_gerir') ? <Button size="small" onClick={() => navegar('/m/config/config_licenca')}>Renovar</Button> : undefined}
    />
  );
}