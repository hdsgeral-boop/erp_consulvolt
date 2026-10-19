import { useState } from 'react';
import { Button, Modal, Tag, Tooltip, Typography, Space, Descriptions, Divider } from 'antd';
import {
  ClockCircleOutlined,
  CloseCircleOutlined,
  CrownOutlined,
  ThunderboltOutlined,
  SafetyCertificateOutlined,
} from '@ant-design/icons';
import { useNavigate } from 'react-router-dom';
import { useEstadoLicenca } from '@/modulos/config/Licenca';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData } from '@/utilitarios/formatacao';

const { Text } = Typography;

export function ContadorLicencaHeader() {
  const navegar = useNavigate();
  const { empresa } = useSessao();
  const { data: estado, isLoading } = useEstadoLicenca(!!empresa);
  const [modalAberto, setModalAberto] = useState(false);

  if (!empresa || isLoading || !estado) {
    return null;
  }

  const { origem, dias_restantes, expirada, fim, inicio, plano, licencas_ativas } = estado;

  let corBadge = '#52c41a'; // Verde
  let textoBotao = 'Licença Activa';
  let icone = <SafetyCertificateOutlined />;

  if (origem === 'SEM_LIMITE' || (!fim && !licencas_ativas)) {
    corBadge = '#1677ff'; // Azul
    textoBotao = 'Vitalícia';
    icone = <CrownOutlined />;
  } else if (expirada) {
    corBadge = '#ff4d4f'; // Vermelho
    textoBotao = 'Expirada';
    icone = <CloseCircleOutlined />;
  } else if (dias_restantes !== null) {
    if (dias_restantes <= 7) {
      corBadge = '#ff4d4f';
      textoBotao = `${dias_restantes}d restantes`;
      icone = <ClockCircleOutlined />;
    } else if (dias_restantes <= 15) {
      corBadge = '#fa8c16';
      textoBotao = `${dias_restantes}d restantes`;
      icone = <ClockCircleOutlined />;
    } else {
      corBadge = '#52c41a';
      textoBotao = `${dias_restantes} dias`;
      icone = <ClockCircleOutlined />;
    }
  }

  const irParaRenovacao = () => {
    setModalAberto(false);
    navegar('/m/config/config_licenca');
  };

  return (
    <>
      <Tooltip
        title={
          origem === 'SEM_LIMITE' || (!fim && !licencas_ativas)
            ? 'Licença vitalícia sem limite de validade'
            : expirada
            ? 'Licença expirada! Clique para regularizar e desbloquear escrita.'
            : dias_restantes !== null
            ? `Licença válida por mais ${dias_restantes} dia(s). Clique para ver detalhes.`
            : 'Licença activa. Clique para ver detalhes.'
        }
      >
        <Button
          size="small"
          onClick={() => setModalAberto(true)}
          style={{
            borderColor: corBadge,
            color: corBadge,
            fontWeight: 600,
            borderRadius: 16,
            height: 28,
            padding: '0 10px',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 6,
            background: expirada ? '#fff1f0' : 'transparent',
          }}
          icon={icone}
        >
          <span>{textoBotao}</span>
        </Button>
      </Tooltip>

      <Modal
        title={
          <Space>
            <SafetyCertificateOutlined style={{ color: corBadge }} />
            <span>Estado da Licença de Software</span>
          </Space>
        }
        open={modalAberto}
        onCancel={() => setModalAberto(false)}
        footer={null}
        destroyOnClose
        width={480}
      >
        <div style={{ marginTop: 16 }}>
          <div
            style={{
              padding: '16px',
              borderRadius: 8,
              background: expirada ? '#fff1f0' : '#f8fafc',
              border: `1px solid ${expirada ? '#ffa39e' : '#e2e8f0'}`,
              marginBottom: 16,
              textAlign: 'center',
            }}
          >
            <div style={{ fontSize: 13, color: '#64748b' }}>Condição Atual de Acesso:</div>
            <div style={{ fontSize: 20, fontWeight: 700, color: corBadge, margin: '4px 0' }}>
              {origem === 'SEM_LIMITE' || (!fim && !licencas_ativas)
                ? 'Licença Vitalícia e Ilimitada'
                : expirada
                ? 'Licença Expirada (Modo Consulta)'
                : dias_restantes !== null
                ? `${dias_restantes} Dia(s) Restante(s)`
                : 'Licença Activa'}
            </div>
            <Text type="secondary" style={{ fontSize: 12 }}>
              {expirada
                ? 'A empresa está em modo de leitura. Renove para emitir faturas e registar operações.'
                : 'Acesso completo a todos os módulos autorizados pelo plano.'}
            </Text>
          </div>

          <Descriptions column={1} size="small" bordered>
            <Descriptions.Item label="Plano Subscrito">
              <strong>{plano?.nome || 'Plano Base'}</strong>
            </Descriptions.Item>
            <Descriptions.Item label="Modalidade">
              <Tag color={origem === 'SEM_LIMITE' ? 'gold' : origem === 'TESTE' ? 'blue' : 'purple'}>
                {origem === 'SEM_LIMITE' ? 'Vitalícia' : origem === 'TESTE' ? 'Período de Demonstração' : 'Subscrição Comercial'}
              </Tag>
            </Descriptions.Item>
            <Descriptions.Item label="Data de Início">
              {inicio ? formatarData(inicio) : '—'}
            </Descriptions.Item>
            <Descriptions.Item label="Data de Validade">
              {fim ? formatarData(fim) : 'Sem data de término'}
            </Descriptions.Item>
          </Descriptions>

          <Divider style={{ margin: '16px 0' }} />

          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
            <Button onClick={() => setModalAberto(false)}>Fechar</Button>
            <Button
              type="primary"
              icon={<ThunderboltOutlined />}
              onClick={irParaRenovacao}
            >
              Gerir / Renovar Licença
            </Button>
          </div>
        </div>
      </Modal>
    </>
  );
}
