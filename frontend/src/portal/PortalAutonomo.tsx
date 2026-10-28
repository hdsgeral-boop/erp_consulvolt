import { Button, Card, ConfigProvider, Flex, Layout, Result, Spin, Tooltip, Typography } from 'antd';
import { AppstoreOutlined, LogoutOutlined, MoonOutlined, SunOutlined, SwapOutlined, UserOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { lazy, Suspense, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useEcra } from '@/componentes/responsivo/useEcra';
import { MarcaEmpresa } from '@/layout/MarcaEmpresa';
import { useAspecto } from '@/layout/aspecto';
import { TEMA_NOCTURNO } from '@/layout/temaNocturno';
import '@/layout/telaNocturna.css';
import { useSessao } from '@/sessao/SessaoContexto';
import { temAcessoAoErp } from './rota';

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
 * Portal autónomo do colaborador (Versão Actual: portal.html; ADR-071). O colaborador entra, escolhe a empresa e só vê o
 * portal: sem o menu lateral do ERP, mas com o mesmo aspecto do sistema (17/10/2026 — antes perdia o tema): barra superior
 * `erp-cabecalho` (marca da empresa, crachá «Portal do Colaborador», colaborador, tela nocturna, trocar empresa e sair),
 * área de trabalho `erp-conteudo` com o fundo do módulo de RH, tela nocturna do utilizador (a mesma preferência do ERP)
 * e rodapé. Quem também tem ecrãs do ERP no menu vê «Voltar ao ERP»; quem só tem o portal não vê essa saída.
 * A entrada é decidida pelo servidor (GET /portal/acesso) para a empresa activa; os dados vêm das rotas /rh/portal/*.
 */
export function PortalAutonomo() {
  const { empresa, empresas, utilizador, largarEmpresa, sair, menu } = useSessao();
  const ecra = useEcra();
  const navegar = useNavigate();
  const { aspecto, definir } = useAspecto(utilizador?.id ?? utilizador?.nome_utilizador ?? null);
  const escuro = aspecto.tema === 'escuro';
  const alternarTema = () => definir({ tema: escuro ? 'claro' : 'escuro' });
  const textoTema = escuro ? 'Desligar a tela nocturna' : 'Tela nocturna';
  const comErp = temAcessoAoErp(menu);
  useEffect(() => {
    const anterior = document.title;
    document.title = 'Portal do Colaborador';
    return () => {
      document.title = anterior;
    };
  }, []);
  const acesso = useQuery({
    queryKey: chaveAcessoPortal(empresa?.id),
    queryFn: () => obter<AcessoPortal>('/portal/acesso'),
    enabled: !!empresa,
    retry: false,
    staleTime: 60_000,
  });
  const nome = acesso.data?.colaborador?.nome_completo || utilizador?.nome_completo || utilizador?.nome_utilizador || '';
  const trocar = () => largarEmpresa();
  const semAcesso = !acesso.isLoading && (!!acesso.error || !acesso.data);
  const msgSemAcesso = acesso.error instanceof ErroApi ? acesso.error.message : 'O seu utilizador não está associado a um colaborador. Contacte o administrador da sua empresa para ser associado ao portal.';
  // sem acesso nenhum (nem colaborador, nem aprovações pendentes) e sem outra empresa para tentar: o portal nunca
  // mostra o resto da aplicação (ADR-071) — volta ao ecrã de login com o motivo, em vez de ficar num ecrã intermédio.
  const semAlternativa = semAcesso && empresas.length <= 1;
  useEffect(() => {
    if (semAlternativa) void sair(msgSemAcesso);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [semAlternativa]);

  let conteudo;
  if (acesso.isLoading || semAlternativa) conteudo = <Spin size="large" style={{ display: 'block', marginTop: 80 }} />;
  else if (semAcesso) {
    conteudo = (
      <Result
        status={acesso.error instanceof ErroApi && acesso.error.estado === 403 ? '403' : 'warning'}
        title="Portal do Colaborador"
        subTitle={msgSemAcesso}
        extra={[
          <Button key="trocar" type="primary" icon={<SwapOutlined />} onClick={trocar}>Escolher outra empresa</Button>,
          <Button key="sair" icon={<LogoutOutlined />} onClick={() => void sair()}>Sair</Button>,
        ]}
      />
    );
  } else if (acesso.data!.colaborador) {
    conteudo = <Portal />;
  } else {
    // aprovador sem registo de colaborador nesta empresa: só as aprovações à sua espera
    conteudo = (
      <>
        <CabecalhoPagina titulo={`Olá, ${nome.split(' ')[0]}`} subtitulo={`Portal do Colaborador — aprovações pendentes: ${acesso.data!.aprovacoes_pendentes}`} excel={false} />
        <Typography.Paragraph type="secondary">O seu utilizador não está associado a um colaborador desta empresa: aqui só vê os pedidos que aguardam a sua decisão.</Typography.Paragraph>
        <Aprovacoes />
      </>
    );
  }

  return (
    <ConfigProvider theme={escuro ? TEMA_NOCTURNO : undefined}>
      <Layout style={{ minHeight: '100vh' }} className="erp-portal">
        <Layout.Header
          className="erp-cabecalho erp-portal-cabecalho"
          style={{
            background: escuro ? '#111a2b' : '#ffffff',
            borderBottom: `1px solid ${escuro ? '#1f2a3d' : '#e2e8f0'}`,
            height: ecra.md ? 72 : 56,
            lineHeight: 'normal',
            padding: ecra.lg ? '0 24px' : ecra.md ? '0 16px' : '0 8px',
          }}
        >
          <Flex justify="space-between" align="center" gap={ecra.md ? 12 : 6} style={{ height: '100%' }}>
            <Flex align="center" gap={12} style={{ minWidth: 0, flex: '1 1 auto' }}>
              <div style={{ minWidth: 0, flex: '0 1 auto' }}>
                <MarcaEmpresa tamanho={ecra.md ? 40 : 30} />
              </div>
              {ecra.md && (
                <span className="erp-cracha-nif erp-portal-cracha">
                  <UserOutlined aria-hidden style={{ marginRight: 6 }} />
                  Portal do Colaborador
                </span>
              )}
            </Flex>
            <nav aria-label="Navegação do portal" style={{ flex: 'none', minWidth: 0 }}>
              <Flex align="center" gap={ecra.md ? 8 : 4}>
                {ecra.lg && nome ? (
                  <span className="erp-utilizador-textos erp-portal-utilizador" title={nome}>
                    <span className="erp-utilizador-nome">{nome}</span>
                    <span className="erp-utilizador-papel">Colaborador</span>
                  </span>
                ) : null}
                {comErp && (
                  <Tooltip title="Voltar ao ERP (menu e ecrãs do sistema)">
                    <Button type="primary" icon={<AppstoreOutlined />} onClick={() => navegar('/')} aria-label="Voltar ao ERP" className="erp-botao-ajuda">
                      {ecra.md ? 'Voltar ao ERP' : null}
                    </Button>
                  </Tooltip>
                )}
                <Tooltip title={textoTema}>
                  <Button className="erp-botao-tema" icon={escuro ? <SunOutlined /> : <MoonOutlined />} aria-label="Tela nocturna" aria-pressed={escuro} onClick={alternarTema} />
                </Tooltip>
                <Tooltip title="Trocar de empresa">
                  <Button icon={<SwapOutlined />} onClick={trocar} aria-label="Trocar empresa">
                    {ecra.md ? 'Trocar empresa' : null}
                  </Button>
                </Tooltip>
                <Tooltip title="Terminar sessão">
                  <Button icon={<LogoutOutlined />} onClick={() => void sair()} aria-label="Sair" className="erp-portal-sair">
                    {ecra.md ? 'Sair' : null}
                  </Button>
                </Tooltip>
              </Flex>
            </nav>
          </Flex>
        </Layout.Header>
        <Layout.Content className="erp-conteudo erp-fundo-rh erp-portal-conteudo">
          <div className="erp-portal-area">
            {!ecra.lg && nome && (
              <Card size="small" style={{ marginBottom: 12 }}>
                <UserOutlined style={{ marginRight: 6 }} />
                {nome}
              </Card>
            )}
            <Suspense fallback={<Spin style={{ display: 'block', marginTop: 80 }} />}>{conteudo}</Suspense>
          </div>
        </Layout.Content>
        <Layout.Footer className="erp-portal-rodape no-print">{(empresa?.nome ? `${empresa.nome} · ` : '') + 'Portal do Colaborador'}</Layout.Footer>
      </Layout>
    </ConfigProvider>
  );
}
