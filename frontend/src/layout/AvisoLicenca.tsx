import { Button, Modal, Typography } from 'antd';
import { CloseCircleOutlined, ExclamationCircleOutlined } from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useEstadoLicenca } from '@/modulos/config/Licenca';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAvisoLicencaVisto } from './avisoLicencaVisto';

/**
 * Aviso de licença da empresa activa (a expirar ou já expirada): pop-up ao entrar, uma vez por login — ao fechar,
 * não volta a aparecer até ao próximo login (useAvisoLicencaVisto). O indicador vermelho persistente fica no botão
 * do cabeçalho (ContadorLicencaHeader), que não se dispensa.
 */
export function AvisoLicenca() {
  const { empresa, pode, utilizador } = useSessao();
  const navegar = useNavigate();
  const { data } = useEstadoLicenca(!!empresa);
  const { visto, dispensar } = useAvisoLicencaVisto(utilizador?.id);
  if (!data?.aviso || visto) return null;
  const expirada = data.aviso === 'EXPIRADA';
  const podeRenovar = pode('config_licenca_view', 'config_licenca_gerir');

  return (
    <Modal
      open
      closable
      maskClosable={false}
      onCancel={dispensar}
      title={
        <>
          {expirada ? (
            <CloseCircleOutlined style={{ color: '#ff4d4f', marginRight: 8 }} />
          ) : (
            <ExclamationCircleOutlined style={{ color: '#faad14', marginRight: 8 }} />
          )}
          {expirada ? 'Licença expirada' : 'Licença a expirar'}
        </>
      }
      footer={[
        <Button key="fechar" onClick={dispensar}>
          Fechar
        </Button>,
        podeRenovar && (
          <Button
            key="renovar"
            type="primary"
            onClick={() => {
              dispensar();
              navegar('/m/config/config_licenca');
            }}
          >
            Renovar
          </Button>
        ),
      ]}
    >
      <Typography.Paragraph>
        {expirada
          ? 'A licença desta empresa expirou. A empresa está em modo de leitura até a licença ser renovada.'
          : `A licença desta empresa expira em ${data.dias_restantes} dia(s).`}
      </Typography.Paragraph>
    </Modal>
  );
}
