import { useState, useMemo } from 'react';
import {
  Card,
  Row,
  Col,
  Statistic,
  Tabs,
  Input,
  Select,
  Button,
  Tag,
  Space,
  Typography,
  Empty,
  Badge,
  Tooltip,
  message,
  Spin,
} from 'antd';
import {
  BellOutlined,
  CheckCircleOutlined,
  WarningOutlined,
  ExclamationCircleOutlined,
  InfoCircleOutlined,
  CheckOutlined,
  ReloadOutlined,
  ArrowRightOutlined,
  SearchOutlined,
  UserOutlined,
  ClockCircleOutlined,
} from '@ant-design/icons';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/pt';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { notificarErro } from '@/utilitarios/erros';

dayjs.extend(relativeTime);
dayjs.locale('pt');

const { Text, Paragraph } = Typography;

export interface NotificacaoCompleta {
  id: number | string;
  origem: 'ALERTA_OPERACIONAL' | 'EVENTO_EMPRESA' | 'COMUNICADO_SISTEMA';
  tipo: 'INFO' | 'SUCESSO' | 'AVISO' | 'URGENTE';
  categoria: string;
  modulo: string;
  modulo_nome: string;
  titulo: string;
  mensagem: string;
  link: string | null;
  quantidade?: number;
  autor_nome: string | null;
  lida: boolean;
  lida_em: string | null;
  criado_em: string;
}

interface ResumoNotificacoesCompleto {
  total: number;
  total_nao_lidas: number;
  nao_lidas: number;
  urgentes: number;
  avisos: number;
  por_modulo: Record<string, number>;
  modulos_disponiveis: Record<string, string>;
  notificacoes: NotificacaoCompleta[];
  itens: NotificacaoCompleta[];
}

const CHAVE_CENTRO = ['sistema', 'notificacoes', 'centro'];

const CORES_MODULOS: Record<string, string> = {
  vendas: 'green',
  compras: 'orange',
  teso: 'blue',
  contab: 'purple',
  rh: 'magenta',
  activos: 'cyan',
  crm: 'gold',
  orcamento: 'volcano',
  sistema: 'geekblue',
  config: 'geekblue',
  geral: 'default',
};

export default function NotificacoesCentro() {
  const navegar = useNavigate();
  const qc = useQueryClient();

  const [moduloAtivo, setModuloAtivo] = useState<string>('todos');
  const [tipoFiltro, setTipoFiltro] = useState<string>('todos');
  const [apenasNaoLidas, setApenasNaoLidas] = useState<boolean>(false);
  const [termoPesquisa, setTermoPesquisa] = useState<string>('');

  const [alertasDispensados, setAlertasDispensados] = useState<Set<string>>(() => {
    try {
      const salvo = localStorage.getItem('erp_alertas_dispensados');
      return salvo ? new Set(JSON.parse(salvo)) : new Set();
    } catch {
      return new Set();
    }
  });

  const dispensarAlerta = (id: string) => {
    setAlertasDispensados((prev) => {
      const next = new Set(prev);
      next.add(id);
      try {
        localStorage.setItem('erp_alertas_dispensados', JSON.stringify([...next]));
      } catch {}
      return next;
    });
    message.success('Alerta operacional resolvido/dispensado.');
  };

  const { data: dados, isLoading, refetch, isFetching } = useQuery({
    queryKey: CHAVE_CENTRO,
    queryFn: () => obter<ResumoNotificacoesCompleto>('/sistema/notificacoes?limite=100'),
    refetchInterval: 30_000,
  });

  const marcarLida = useMutation({
    mutationFn: async (id: number | string) => {
      if (typeof id === 'number' || !String(id).startsWith('alerta_')) {
        return enviar('post', `/sistema/notificacoes/${id}/ler`);
      }
      dispensarAlerta(String(id));
      return { dados: null, mensagem: '' };
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: CHAVE_CENTRO });
      qc.invalidateQueries({ queryKey: ['sistema', 'notificacoes'] });
    },
    onError: (e) => notificarErro(e),
  });

  const marcarTodasLidas = useMutation({
    mutationFn: async () => {
      const raw = dados?.notificacoes ?? dados?.itens ?? [];
      const novosDispensados = new Set(alertasDispensados);
      raw.forEach((i) => {
        if (i.origem === 'ALERTA_OPERACIONAL') {
          novosDispensados.add(String(i.id));
        }
      });
      setAlertasDispensados(novosDispensados);
      try {
        localStorage.setItem('erp_alertas_dispensados', JSON.stringify([...novosDispensados]));
      } catch {}
      return enviar('post', '/sistema/notificacoes/ler-todas');
    },
    onSuccess: () => {
      message.success('Todas as notificações e alertas foram marcados como lidos.');
      qc.invalidateQueries({ queryKey: CHAVE_CENTRO });
      qc.invalidateQueries({ queryKey: ['sistema', 'notificacoes'] });
    },
    onError: (e) => notificarErro(e),
  });

  const listaBruta = useMemo(() => {
    const raw = dados?.notificacoes ?? dados?.itens ?? [];
    return raw.map((item) => {
      if (item.origem === 'ALERTA_OPERACIONAL' && alertasDispensados.has(String(item.id))) {
        return { ...item, lida: true };
      }
      return item;
    });
  }, [dados, alertasDispensados]);

  // Filtragem dinâmica no cliente
  const listaFiltrada = useMemo(() => {
    return listaBruta.filter((item) => {
      // Filtro de Módulo / Origem
      if (moduloAtivo === 'alertas') {
        if (item.origem !== 'ALERTA_OPERACIONAL') return false;
      } else if (moduloAtivo !== 'todos' && item.modulo !== moduloAtivo) {
        return false;
      }

      // Filtro de Tipo / Prioridade
      if (tipoFiltro !== 'todos' && item.tipo !== tipoFiltro) {
        return false;
      }

      // Filtro de Não Lidas
      if (apenasNaoLidas && item.lida) {
        return false;
      }

      // Filtro de Busca Textual
      if (termoPesquisa.trim() !== '') {
        const termo = termoPesquisa.toLowerCase();
        const noTitulo = item.titulo?.toLowerCase().includes(termo);
        const naMensagem = item.mensagem?.toLowerCase().includes(termo);
        const noAutor = item.autor_nome?.toLowerCase().includes(termo);
        const noModulo = item.modulo_nome?.toLowerCase().includes(termo);
        if (!noTitulo && !naMensagem && !noAutor && !noModulo) {
          return false;
        }
      }

      return true;
    });
  }, [listaBruta, moduloAtivo, tipoFiltro, apenasNaoLidas, termoPesquisa]);

  const totalNaoLidas = dados?.total_nao_lidas ?? dados?.nao_lidas ?? 0;
  const totalUrgentes = dados?.urgentes ?? 0;
  const totalAvisos = dados?.avisos ?? 0;
  const modulosDisponiveis = dados?.modulos_disponiveis ?? {};

  const getIconeTipo = (tipo: string) => {
    switch (tipo) {
      case 'URGENTE':
        return <ExclamationCircleOutlined style={{ color: '#dc2626', fontSize: 18 }} />;
      case 'AVISO':
        return <WarningOutlined style={{ color: '#f59e0b', fontSize: 18 }} />;
      case 'SUCESSO':
        return <CheckCircleOutlined style={{ color: '#059669', fontSize: 18 }} />;
      default:
        return <InfoCircleOutlined style={{ color: '#2563eb', fontSize: 18 }} />;
    }
  };

  const getTagTipo = (tipo: string) => {
    switch (tipo) {
      case 'URGENTE':
        return <Tag color="error">URGENTE</Tag>;
      case 'AVISO':
        return <Tag color="warning">AVISO</Tag>;
      case 'SUCESSO':
        return <Tag color="success">SUCESSO</Tag>;
      default:
        return <Tag color="processing">INFORMAÇÃO</Tag>;
    }
  };

  const abasModulos = useMemo(() => {
    const abas = [
      { key: 'todos', label: `Todos (${listaBruta.length})` },
      { key: 'alertas', label: `Processos Pendentes (${listaBruta.filter(i => i.origem === 'ALERTA_OPERACIONAL').length})` },
    ];

    Object.entries(modulosDisponiveis).forEach(([slug, nome]) => {
      const contagem = dados?.por_modulo?.[slug] ?? listaBruta.filter(i => i.modulo === slug).length;
      if (contagem > 0) {
        abas.push({
          key: slug,
          label: `${nome} (${contagem})`,
        });
      }
    });

    return abas;
  }, [modulosDisponiveis, dados?.por_modulo, listaBruta]);

  return (
    <div style={{ maxWidth: 1400, margin: '0 auto' }}>
      <CabecalhoPagina
        titulo="Central de Notificações e Alertas"
        subtitulo="Gestão unificada de processos operacionais pendentes, eventos da empresa e notificações com controlo RBAC por perfil"
      />

      {/* Cartões de Indicadores */}
      <Row gutter={[16, 16]} style={{ marginBottom: 20 }}>
        <Col xs={12} sm={6} lg={6}>
          <Card size="small" bordered style={{ borderRadius: 8, background: '#fff' }}>
            <Statistic
              title={<span style={{ fontSize: 12, color: '#64748b' }}>Total de Notificações</span>}
              value={dados?.total ?? listaBruta.length}
              prefix={<BellOutlined style={{ color: '#2563eb', marginRight: 6 }} />}
              valueStyle={{ fontSize: 24, fontWeight: 700, color: '#1e293b' }}
            />
          </Card>
        </Col>

        <Col xs={12} sm={6} lg={6}>
          <Card size="small" bordered style={{ borderRadius: 8, background: '#fff' }}>
            <Statistic
              title={<span style={{ fontSize: 12, color: '#64748b' }}>Pendentes / Não Lidas</span>}
              value={totalNaoLidas}
              prefix={<ClockCircleOutlined style={{ color: '#d97706', marginRight: 6 }} />}
              valueStyle={{ fontSize: 24, fontWeight: 700, color: totalNaoLidas > 0 ? '#d97706' : '#059669' }}
            />
          </Card>
        </Col>

        <Col xs={12} sm={6} lg={6}>
          <Card size="small" bordered style={{ borderRadius: 8, background: '#fff' }}>
            <Statistic
              title={<span style={{ fontSize: 12, color: '#64748b' }}>Alertas Urgentes</span>}
              value={totalUrgentes}
              prefix={<ExclamationCircleOutlined style={{ color: '#dc2626', marginRight: 6 }} />}
              valueStyle={{ fontSize: 24, fontWeight: 700, color: totalUrgentes > 0 ? '#dc2626' : '#64748b' }}
            />
          </Card>
        </Col>

        <Col xs={12} sm={6} lg={6}>
          <Card size="small" bordered style={{ borderRadius: 8, background: '#fff' }}>
            <Statistic
              title={<span style={{ fontSize: 12, color: '#64748b' }}>Avisos Operacionais</span>}
              value={totalAvisos}
              prefix={<WarningOutlined style={{ color: '#f59e0b', marginRight: 6 }} />}
              valueStyle={{ fontSize: 24, fontWeight: 700, color: '#f59e0b' }}
            />
          </Card>
        </Col>
      </Row>

      {/* Card Principal de Listagem e Filtros */}
      <Card bordered style={{ borderRadius: 8 }}>
        {/* Barra Superior de Filtros */}
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <Space wrap size={10}>
            <Input
              prefix={<SearchOutlined style={{ color: '#94a3b8' }} />}
              placeholder="Pesquisar por texto, módulo ou autor..."
              allowClear
              value={termoPesquisa}
              onChange={(e) => setTermoPesquisa(e.target.value)}
              style={{ width: 280 }}
            />

            <Select
              value={tipoFiltro}
              onChange={setTipoFiltro}
              style={{ width: 140 }}
              options={[
                { value: 'todos', label: 'Toda Gravidade' },
                { value: 'URGENTE', label: 'Urgente' },
                { value: 'AVISO', label: 'Aviso' },
                { value: 'INFO', label: 'Informativo' },
                { value: 'SUCESSO', label: 'Sucesso' },
              ]}
            />

            <Button
              type={apenasNaoLidas ? 'primary' : 'default'}
              onClick={() => setApenasNaoLidas(!apenasNaoLidas)}
            >
              {apenasNaoLidas ? 'A exibir: Não lidas' : 'Mostrar: Todas'}
            </Button>
          </Space>

          <Space wrap size={8}>
            <Button
              icon={<CheckOutlined />}
              onClick={() => marcarTodasLidas.mutate()}
              loading={marcarTodasLidas.isPending}
              disabled={totalNaoLidas === 0}
            >
              Marcar todas como lidas
            </Button>

            <Tooltip title="Actualizar em tempo real">
              <Button
                icon={<ReloadOutlined spin={isFetching} />}
                onClick={() => refetch()}
              >
                Actualizar
              </Button>
            </Tooltip>
          </Space>
        </div>

        {/* Abas por Módulo */}
        <Tabs
          activeKey={moduloAtivo}
          onChange={setModuloAtivo}
          items={abasModulos}
          style={{ marginBottom: 16 }}
        />

        {/* Conteúdo da Lista */}
        {isLoading ? (
          <div style={{ textAlign: 'center', padding: '60px 0' }}>
            <Spin size="large" />
            <div style={{ marginTop: 16, color: '#64748b' }}>A carregar notificações e processos pendentes...</div>
          </div>
        ) : listaFiltrada.length === 0 ? (
          <Empty
            image={Empty.PRESENTED_IMAGE_SIMPLE}
            description="Nenhuma notificação ou alerta encontrado para os filtros selecionados."
            style={{ padding: '40px 0' }}
          >
            {(termoPesquisa || tipoFiltro !== 'todos' || apenasNaoLidas || moduloAtivo !== 'todos') && (
              <Button
                onClick={() => {
                  setModuloAtivo('todos');
                  setTipoFiltro('todos');
                  setApenasNaoLidas(false);
                  setTermoPesquisa('');
                }}
              >
                Limpar Filtros
              </Button>
            )}
          </Empty>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            {listaFiltrada.map((item) => {
              const tempo = dayjs(item.criado_em).fromNow();
              const corModulo = CORES_MODULOS[item.modulo] ?? 'blue';
              const eAlerta = item.origem === 'ALERTA_OPERACIONAL';

              return (
                <div
                  key={String(item.id)}
                  style={{
                    display: 'flex',
                    flexDirection: 'row',
                    alignItems: 'flex-start',
                    gap: 16,
                    padding: '16px 18px',
                    borderRadius: 8,
                    border: `1px solid ${item.lida ? '#e2e8f0' : '#bfdbfe'}`,
                    background: item.lida ? '#ffffff' : '#f8faff',
                    boxShadow: item.lida ? 'none' : '0 2px 6px rgba(37, 99, 235, 0.04)',
                    transition: 'all 0.2s ease',
                  }}
                >
                  {/* Ícone de Tipo */}
                  <div style={{ marginTop: 2, flexShrink: 0 }}>
                    {getIconeTipo(item.tipo)}
                  </div>

                  {/* Conteúdo Central */}
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 8, marginBottom: 6 }}>
                      <Text strong style={{ fontSize: 14, color: item.lida ? '#334155' : '#0f172a' }}>
                        {item.titulo}
                      </Text>

                      <Tag color={corModulo} style={{ borderRadius: 4, margin: 0, fontSize: 11 }}>
                        {item.modulo_nome || item.modulo}
                      </Tag>

                      {getTagTipo(item.tipo)}

                      {eAlerta && (
                        <Tag color="purple" style={{ borderRadius: 4, margin: 0, fontSize: 11 }}>
                          PROCESSO PENDENTE
                        </Tag>
                      )}

                      {!item.lida && (
                        <Badge status="processing" text={<span style={{ fontSize: 11, color: '#2563eb' }}>Não lida</span>} />
                      )}
                    </div>

                    <Paragraph
                      style={{
                        margin: '0 0 8px 0',
                        fontSize: 13,
                        color: '#475569',
                        lineHeight: 1.5,
                      }}
                    >
                      {item.mensagem}
                    </Paragraph>

                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 16, fontSize: 12, color: '#94a3b8' }}>
                      {item.autor_nome && (
                        <span>
                          <UserOutlined style={{ marginRight: 4 }} />
                          {item.autor_nome}
                        </span>
                      )}

                      <span>
                        <ClockCircleOutlined style={{ marginRight: 4 }} />
                        {dayjs(item.criado_em).format('DD/MM/YYYY [às] HH:mm')} ({tempo})
                      </span>
                    </div>
                  </div>

                  {/* Ações à Direita */}
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flexShrink: 0, alignItems: 'flex-end' }}>
                    {item.link && (
                      <Button
                        type="primary"
                        size="small"
                        icon={<ArrowRightOutlined />}
                        onClick={() => {
                          if (!item.lida) {
                            marcarLida.mutate(item.id);
                          }
                          navegar(item.link!);
                        }}
                      >
                        {eAlerta ? 'Resolver Processo' : 'Aceder'}
                      </Button>
                    )}

                    {!item.lida && (
                      <Button
                        size="small"
                        type="text"
                        onClick={() => marcarLida.mutate(item.id)}
                        loading={marcarLida.isPending}
                        style={{ fontSize: 12, color: '#64748b' }}
                      >
                        {eAlerta ? 'Dispensar Alerta' : 'Marcar como lida'}
                      </Button>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </Card>
    </div>
  );
}
