import { useState } from 'react';
import {
  Badge,
  Button,
  Popover,
  List,
  Typography,
  Space,
  Tag,
  Divider,
  Empty,
  Tooltip,
  message,
} from 'antd';
import {
  BellOutlined,
  CheckOutlined,
  InfoCircleFilled,
  CheckCircleFilled,
  WarningFilled,
  CloseCircleFilled,
  RightOutlined,
  ReloadOutlined,
  UserOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/pt';
import { enviar, obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

dayjs.extend(relativeTime);
dayjs.locale('pt');

const { Text, Paragraph } = Typography;

export interface NotificacaoItem {
  id: number;
  tipo: 'INFO' | 'SUCESSO' | 'AVISO' | 'URGENTE';
  categoria: string;
  titulo: string;
  mensagem: string;
  link: string | null;
  lida: boolean;
  lida_em: string | null;
  criado_em: string;
  autor_nome: string | null;
}

interface ResumoNotificacoes {
  total: number;
  nao_lidas: number;
  total_nao_lidas?: number;
  itens: NotificacaoItem[];
  notificacoes?: NotificacaoItem[];
}

const CHAVE_NOTIFICACOES = ['sistema', 'notificacoes'];

export function NotificacoesSinoHeader() {
  const navegar = useNavigate();
  const qc = useQueryClient();
  const [popoverAberto, setPopoverAberto] = useState(false);

  // Consulta de notificações a cada 35 segundos
  const { data: resumo, refetch } = useQuery({
    queryKey: CHAVE_NOTIFICACOES,
    queryFn: () => obter<ResumoNotificacoes>('/sistema/notificacoes'),
    refetchInterval: 35_000,
    staleTime: 20_000,
  });

  // Marcar única notificação como lida
  const marcarLida = useMutation({
    mutationFn: (id: number) => enviar('post', `/sistema/notificacoes/${id}/ler`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: CHAVE_NOTIFICACOES });
    },
    onError: (e) => notificarErro(e),
  });

  // Marcar todas como lidas
  const marcarTodasLidas = useMutation({
    mutationFn: () => enviar('post', '/sistema/notificacoes/ler-todas'),
    onSuccess: () => {
      message.success('Todas as notificações foram marcadas como lidas.');
      qc.invalidateQueries({ queryKey: CHAVE_NOTIFICACOES });
    },
    onError: (e) => notificarErro(e),
  });

  const clicarNotificacao = (item: NotificacaoItem) => {
    if (!item.lida) {
      marcarLida.mutate(item.id);
    }
    if (item.link) {
      setPopoverAberto(false);
      navegar(item.link);
    }
  };

  const getIconeTipo = (tipo: string) => {
    switch (tipo) {
      case 'SUCESSO':
        return <CheckCircleFilled style={{ color: '#52c41a', fontSize: 16 }} />;
      case 'AVISO':
        return <WarningFilled style={{ color: '#fa8c16', fontSize: 16 }} />;
      case 'URGENTE':
        return <CloseCircleFilled style={{ color: '#ff4d4f', fontSize: 16 }} />;
      default:
        return <InfoCircleFilled style={{ color: '#1890ff', fontSize: 16 }} />;
    }
  };

  const naoLidas = resumo?.total_nao_lidas ?? resumo?.nao_lidas ?? 0;
  const lista = (resumo as any)?.notificacoes ?? resumo?.itens ?? [];

  const conteudoPopover = (
    <div style={{ width: 380, maxWidth: '92vw' }}>
      <div
        style={{
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          paddingBottom: 10,
          borderBottom: '1px solid #f0f0f0',
        }}
      >
        <div>
          <Text strong style={{ fontSize: 14 }}>
            Notificações e Alertas
          </Text>
          {naoLidas > 0 && (
            <Tag color="red" style={{ marginLeft: 8, borderRadius: 10 }}>
              {naoLidas} novas
            </Tag>
          )}
        </div>

        <Space size={4}>
          <Tooltip title="Atualizar">
            <Button
              type="text"
              size="small"
              icon={<ReloadOutlined />}
              onClick={() => refetch()}
            />
          </Tooltip>

          {naoLidas > 0 && (
            <Tooltip title="Marcar todas como lidas">
              <Button
                type="text"
                size="small"
                icon={<CheckOutlined />}
                loading={marcarTodasLidas.isPending}
                onClick={() => marcarTodasLidas.mutate()}
              />
            </Tooltip>
          )}
        </Space>
      </div>

      <div style={{ maxHeight: 380, overflowY: 'auto', marginTop: 8 }}>
        {lista.length === 0 ? (
          <Empty
            image={Empty.PRESENTED_IMAGE_SIMPLE}
            description="Sem notificações no momento"
            style={{ padding: '24px 0' }}
          />
        ) : (
          <List
            itemLayout="horizontal"
            dataSource={lista}
            renderItem={(item: any) => {
              const tempo = dayjs(item.criado_em).fromNow();
              const eAlerta = item.origem === 'ALERTA_OPERACIONAL';
              return (
                <div
                  key={item.id}
                  onClick={() => clicarNotificacao(item)}
                  style={{
                    padding: '10px 12px',
                    borderRadius: 8,
                    marginBottom: 6,
                    cursor: item.link ? 'pointer' : 'default',
                    background: item.lida ? 'transparent' : '#f0f7ff',
                    border: `1px solid ${item.lida ? '#f0f0f0' : '#d0e2ff'}`,
                    transition: 'all 0.2s',
                    position: 'relative',
                  }}
                  className="notificacao-hover"
                >
                  <div style={{ display: 'flex', gap: 10, alignItems: 'flex-start' }}>
                    <div style={{ marginTop: 2 }}>{getIconeTipo(item.tipo)}</div>

                    <div style={{ flex: 1, minWidth: 0 }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 6 }}>
                        <Text strong style={{ fontSize: 13, color: item.lida ? '#475569' : '#0f172a' }}>
                          {item.titulo}
                        </Text>
                        {!item.lida && (
                          <span
                            style={{
                              width: 7,
                              height: 7,
                              background: '#1890ff',
                              borderRadius: '50%',
                              display: 'inline-block',
                              flexShrink: 0,
                            }}
                          />
                        )}
                      </div>

                      <Paragraph
                        style={{
                          margin: '3px 0 6px',
                          fontSize: 12,
                          color: '#64748b',
                          lineHeight: '1.4',
                        }}
                      >
                        {item.mensagem}
                      </Paragraph>

                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', fontSize: 11, color: '#94a3b8' }}>
                        <span>
                          {eAlerta ? (
                            <Tag color="purple" style={{ fontSize: 10, margin: 0, marginRight: 4, lineHeight: '16px', height: 18 }}>PENDENTE</Tag>
                          ) : item.autor_nome ? (
                            <span>
                              <UserOutlined style={{ marginRight: 3 }} />
                              {item.autor_nome} •{' '}
                            </span>
                          ) : null}
                          {tempo}
                        </span>

                        {item.link && (
                          <span style={{ color: '#1890ff', display: 'flex', alignItems: 'center', gap: 2, fontWeight: 500 }}>
                            {eAlerta ? 'Resolver' : 'Ver'} <RightOutlined style={{ fontSize: 9 }} />
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              );
            }}
          />
        )}
      </div>

      <Divider style={{ margin: '8px 0' }} />

      <div style={{ textAlign: 'center', padding: '2px 0' }}>
        <Button
          type="primary"
          ghost
          block
          icon={<BellOutlined />}
          onClick={() => {
            setPopoverAberto(false);
            navegar('/m/config/notificacoes_centro');
          }}
        >
          Ver Central de Notificações e Alertas
        </Button>
      </div>
    </div>
  );

  return (
    <Popover
      content={conteudoPopover}
      trigger="click"
      open={popoverAberto}
      onOpenChange={setPopoverAberto}
      placement="bottomRight"
    >
      <Tooltip title={naoLidas > 0 ? `${naoLidas} notificações por abrir` : 'Notificações e alertas'}>
        <Badge
          count={naoLidas}
          size="small"
          overflowCount={99}
          offset={[-3, 4]}
          style={{
            backgroundColor: '#dc2626',
            color: '#ffffff',
            boxShadow: '0 0 0 2px #ffffff',
            fontWeight: 700,
            fontSize: 10,
            zIndex: 10,
          }}
        >
          <Button
            type="text"
            className="erp-botao-notificacoes"
            icon={<BellOutlined style={{ fontSize: 17, color: '#475569' }} />}
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 32,
              height: 32,
              borderRadius: 4,
              border: '1px solid #e2e8f0',
              background: '#ffffff',
            }}
            aria-label={`Notificações: ${naoLidas} por abrir`}
          />
        </Badge>
      </Tooltip>
    </Popover>
  );
}
