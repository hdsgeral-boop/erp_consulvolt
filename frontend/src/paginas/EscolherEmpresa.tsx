import { Avatar, Button, Card, Empty, Input, List, Typography, theme } from 'antd';
import { LogoutOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import type { Empresa } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { iniciais } from '@/layout/marca';
import { notificarErro } from '@/utilitarios/erros';

/** Texto normalizado para pesquisa: sem acentos, minúsculas e sem espaços a mais. */
export const normalizarPesquisa = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

/** Filtra as empresas pelo nome ou pelo NIF (todas as palavras têm de aparecer, sem distinguir acentos). */
export function filtrarEmpresas(empresas: Empresa[], pesquisa: string): Empresa[] {
  const termos = normalizarPesquisa(pesquisa).split(' ').filter(Boolean);
  if (!termos.length) return empresas;
  return empresas.filter((e) => {
    const alvo = normalizarPesquisa(`${e.nome} ${e.nif ?? ''}`);
    return termos.every((t) => alvo.includes(t));
  });
}

/** Logótipo da empresa (lido do detalhe, só para quem tem acesso); as iniciais enquanto carrega ou se não houver. */
function LogotipoEmpresa({ empresa }: { empresa: Empresa }) {
  const { token } = theme.useToken();
  const logo = useQuery({
    queryKey: ['empresa-logotipo', empresa.id],
    queryFn: () => obter<{ logotipo?: string | null }>(`/sistema/empresas/${empresa.id}`),
    enabled: empresa.tem_logotipo === true,
    staleTime: 60 * 60_000,
    retry: false,
  });
  const src = logo.data?.logotipo;
  if (src && src.startsWith('data:image/')) {
    return (
      <span className="erp-logo-escolha" aria-hidden>
        <img src={src} alt="" />
      </span>
    );
  }
  return (
    <Avatar shape="square" size={44} style={{ background: token.colorPrimary, fontWeight: 600, flexShrink: 0 }} aria-hidden>
      {iniciais(empresa.nome)}
    </Avatar>
  );
}

/**
 * Escolha da empresa a seguir ao login: pesquisa por nome/NIF, logótipos e lista com deslocação própria
 * (a página não cresce — só a lista desloca), sobre o fundo da entrada. Responsiva.
 */
export function EscolherEmpresa() {
  const { empresas, escolherEmpresa, sair, utilizador } = useSessao();
  const [aEscolher, setAEscolher] = useState<number | null>(null);
  const [pesquisa, setPesquisa] = useState('');
  const visiveis = useMemo(() => filtrarEmpresas(empresas, pesquisa), [empresas, pesquisa]);

  const escolher = (id: number) => {
    setAEscolher(id);
    escolherEmpresa(id)
      .catch((e) => notificarErro(e))
      .finally(() => setAEscolher(null));
  };

  return (
    <main className="erp-pagina-entrada erp-fundo-entrada">
      <Card
        className="erp-cartao-entrada erp-cartao-escolha"
        style={{ width: '100%', maxWidth: 560 }}
        title={<span style={{ whiteSpace: 'normal' }}>{`Bem-vindo, ${utilizador?.nome_completo || utilizador?.nome_utilizador}`}</span>}
        extra={
          <Button icon={<LogoutOutlined />} onClick={() => void sair()}>
            Sair
          </Button>
        }
      >
        <Typography.Paragraph type="secondary" style={{ marginBottom: 12 }}>
          Escolha a empresa com que vai trabalhar.
        </Typography.Paragraph>
        {empresas.length === 0 ? (
          <Empty description="Não tem acesso a nenhuma empresa. Contacte o administrador." />
        ) : (
          <>
            {empresas.length > 1 && (
              <>
                <Input
                  allowClear
                  autoFocus
                  size="large"
                  prefix={<SearchOutlined />}
                  placeholder="Pesquisar empresa por nome ou NIF"
                  aria-label="Pesquisar empresa"
                  value={pesquisa}
                  onChange={(ev) => setPesquisa(ev.target.value)}
                  onPressEnter={() => visiveis.length === 1 && escolher(visiveis[0].id)}
                  style={{ marginBottom: 6 }}
                />
                <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginBottom: 4 }} aria-live="polite">
                  {pesquisa ? `${visiveis.length} de ${empresas.length} empresa(s)` : `${empresas.length} empresa(s)`}
                </Typography.Text>
              </>
            )}
            <div className="erp-lista-escolha" role="region" aria-label="Empresas disponíveis" tabIndex={0}>
              <List
                dataSource={visiveis}
                locale={{ emptyText: <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="Nenhuma empresa corresponde à pesquisa." /> }}
                renderItem={(e) => (
                  <List.Item
                    style={{ flexWrap: 'wrap', gap: 8 }}
                    actions={[
                      <Button key="entrar" type="primary" loading={aEscolher === e.id} onClick={() => escolher(e.id)}>
                        Entrar
                      </Button>,
                    ]}
                  >
                    <List.Item.Meta style={{ minWidth: 0 }} avatar={<LogotipoEmpresa empresa={e} />} title={e.nome} description={e.nif ? `NIF ${e.nif}` : undefined} />
                  </List.Item>
                )}
              />
            </div>
          </>
        )}
      </Card>
    </main>
  );
}
