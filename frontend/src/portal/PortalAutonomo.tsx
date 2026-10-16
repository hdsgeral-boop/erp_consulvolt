import { Button, Card, Flex, Layout, Result, Spin, Tooltip, Typography } from 'antd';
import { LogoutOutlined, SwapOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { lazy, Suspense } from 'react';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { MarcaEmpresa } from '@/layout/MarcaEmpresa';
import { useSessao } from '@/sessao/SessaoContexto';

// os separadores são os do ecrã rh_portal (mesmos componentes, mesma lógica e as mesmas rotas /rh/portal/*)
const Portal = lazy(() => import('@/modulos/rh/Portal'));
const Aprovacoes = lazy(() => import('@/modulos/rh/Portal').then((m) => ({ default: m.Aprovacoes })));

/** Resposta de GET /portal/acesso (guarda do servidor; 403 SEM_ACESSO_PORTAL sem acesso). */
export interface AcessoPortal {
  colaborador: { id: number; nome_completo: string } | null;
  aprovacoes_pendentes: number;
  aprova_rh: boolean;
}

export const chaveAcessoPortal = (empresaId: number | undefined) => ['portal', 'acesso', empresaId] as const;

/**
 * Portal autónomo do colaborador (Versão Actual: portal.html). O colaborador entra, escolhe a empresa e só vê o portal:
 * cabeçalho mínimo (logótipo e nome da empresa, nome do colaborador, trocar empresa, sair) sem o menu do ERP. A entrada
 * é decidida pelo servidor (GET /portal/acesso) para a empresa activa; os dados vêm das rotas /rh/portal/*.
 */
export function PortalAutonomo() {
  const { empresa, utilizador, largarEmpresa, sair } = useSessao();
  const ecra = useEcra();
  const acesso = useQuery({
    queryKey: chaveAcessoPortal(empresa?.id),
    queryFn: () => obter<AcessoPortal>('/portal/acesso'),
    enabled: !!empresa,
    retry: false,
    staleTime: 60_000,
  });
  const nome = acesso.data?.colaborador?.nome_completo || utilizador?.nome_completo || utilizador?.nome_utilizador || '';
  const trocar = () => largarEmpresa();

  let conteudo;
  if (acesso.isLoading) conteudo = <Spin size="large" style={{ display: 'block', marginTop: 80 }} />;
  else if (acesso.error || !acesso.data) {
    const msg = acesso.error instanceof ErroApi ? acesso.error.message : 'Não foi possível abrir o portal do colaborador.';
    conteudo = (
      <Result
        status={acesso.error instanceof ErroApi && acesso.error.estado === 403 ? '403' : 'warning'}
        title="Portal do Colaborador"
        subTitle={msg}
        extra={[
          <Button key="trocar" type="primary" icon={<SwapOutlined />} onClick={trocar}>Escolher outra empresa</Button>,
          <Button key="sair" icon={<LogoutOutlined />} onClick={() => void sair()}>Sair</Button>,
        ]}
      />
    );
  } else if (acesso.data.colaborador) {
    conteudo = <Portal />;
  } else {
    // aprovador sem registo de colaborador nesta empresa: só as aprovações à sua espera
    conteudo = (
      <>
        <CabecalhoPagina titulo={`Olá, ${nome.split(' ')[0]}`} subtitulo={`Portal do Colaborador — aprovações pendentes: ${acesso.data.aprovacoes_pendentes}`} excel={false} />
        <Typography.Paragraph type="secondary">O seu utilizador não está associado a um colaborador desta empresa: aqui só vê os pedidos que aguardam a sua decisão.</Typography.Paragraph>
        <Aprovacoes />
      </>
    );
  }

  return (
    <Layout style={{ minHeight: '100vh' }} className="erp-portal">
      <Layout.Header
        className="erp-portal-cabecalho"
        style={{ background: '#ffffff', borderBottom: '1px solid #e2e8f0', height: ecra.md ? 72 : 56, lineHeight: 'normal', padding: ecra.md ? '0 24px' : '0 8px' }}
      >
        <Flex justify="space-between" align="center" gap={ecra.md ? 12 : 6} style={{ height: '100%' }}>
          <Flex align="center" gap={12} style={{ minWidth: 0, flex: '1 1 auto' }}>
            <div style={{ minWidth: 0, flex: '0 1 auto' }}>
              <MarcaEmpresa tamanho={ecra.md ? 40 : 30} />
            </div>
            {ecra.md && <Typography.Text type="secondary" style={{ whiteSpace: 'nowrap' }}>Portal do Colaborador</Typography.Text>}
          </Flex>
          <Flex align="center" gap={ecra.md ? 8 : 4} style={{ flex: 'none' }}>
            {ecra.md ? (
              <Typography.Text strong className="erp-portal-utilizador" style={{ maxWidth: 260 }} ellipsis={{ tooltip: nome }}>
                <UserOutlined style={{ marginRight: 6 }} />
                {nome}
              </Typography.Text>
            ) : null}
            <Tooltip title="Trocar de empresa">
              <Button icon={<SwapOutlined />} onClick={trocar} aria-label="Trocar empresa">
                {ecra.md ? 'Trocar empresa' : null}
              </Button>
            </Tooltip>
            <Tooltip title="Terminar sessão">
              <Button icon={<LogoutOutlined />} onClick={() => void sair()} aria-label="Sair">
                {ecra.md ? 'Sair' : null}
              </Button>
            </Tooltip>
          </Flex>
        </Flex>
      </Layout.Header>
      <Layout.Content style={{ padding: ecra.md ? 24 : 12, maxWidth: 1400, width: '100%', margin: '0 auto' }}>
        {!ecra.md && nome && (
          <Card size="small" style={{ marginBottom: 12 }}>
            <UserOutlined style={{ marginRight: 6 }} />
            {nome}
          </Card>
        )}
        <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>{conteudo}</Suspense>
      </Layout.Content>
    </Layout>
  );
}
