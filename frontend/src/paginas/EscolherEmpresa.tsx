import { Avatar, Button, Card, Empty, Input, List, Tag, Typography, theme } from 'antd';
import { LogoutOutlined, SearchOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import type { Empresa } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { armazenamento } from '@/sessao/armazenamento';
import { iniciais } from '@/layout/marca';
import { notificarErro } from '@/utilitarios/erros';
import { naRotaPortal } from '@/portal/rota';

/** Endereço próprio da escolha de empresa (no legado: #/selecionar-empresa). */
export const ROTA_ESCOLHA_EMPRESA = '/selecionar-empresa';
const CHAVE_DESTINO = 'erp.destino_apos_escolha';

/**
 * Ao abrir a escolha, o endereço passa a /selecionar-empresa; um link directo aberto antes (ex.: favorito para
 * /rh/colaboradores) fica guardado para depois da escolha. O portal mantém o seu endereço.
 */
export function marcarEnderecoEscolha(): void {
  if (naRotaPortal() || window.location.pathname === ROTA_ESCOLHA_EMPRESA) return;
  const actual = `${window.location.pathname}${window.location.search}`;
  try {
    if (actual !== '/') sessionStorage.setItem(CHAVE_DESTINO, actual);
    else sessionStorage.removeItem(CHAVE_DESTINO);
  } catch {
    // sem sessionStorage: depois da escolha vai para o início
  }
  window.history.replaceState(null, '', ROTA_ESCOLHA_EMPRESA);
}

/** Endereço para onde ir depois de escolher a empresa: o link directo guardado ou o início. */
export function enderecoDepoisDaEscolha(): string {
  let destino: string | null = null;
  try {
    destino = sessionStorage.getItem(CHAVE_DESTINO);
    sessionStorage.removeItem(CHAVE_DESTINO);
  } catch {
    // idem
  }
  return destino && destino.startsWith('/') && !destino.startsWith('//') ? destino : '/';
}

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

/** Põe primeiro as empresas usadas recentemente (pela ordem de uso); as restantes mantêm a ordem recebida. */
export function ordenarPorRecentes(empresas: Empresa[], recentes: number[]): Empresa[] {
  const posicao = (id: number) => {
    const i = recentes.indexOf(id);
    return i === -1 ? recentes.length : i;
  };
  return empresas.map((e, i) => ({ e, i })).sort((a, b) => posicao(a.e.id) - posicao(b.e.id) || a.i - b.i).map(({ e }) => e);
}

/** Logótipo da empresa (lido do detalhe, só para quem tem acesso); as iniciais enquanto carrega ou se não houver. */
function LogotipoEmpresa({ empresa }: { empresa: Empresa }) {
  const { token } = theme.useToken();
  const logo = useQuery({
    queryKey: ['empresa-logotipo', empresa.id, empresa.logotipo_versao],
    // com a versão no URL o browser guarda a resposta em cache (só volta a descarregar se o logótipo mudar)
    queryFn: () => obter<{ logotipo?: string | null }>(`/sistema/empresas/${empresa.id}${empresa.logotipo_versao ? `?v=${empresa.logotipo_versao}` : ''}`),
    enabled: empresa.tem_logotipo === true,
    staleTime: Infinity,
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
  // lidas uma vez ao abrir: a ordem não muda enquanto se escolhe
  const [recentes] = useState(() => (utilizador ? armazenamento.empresasRecentes(utilizador.id) : []));
  const ordenadas = useMemo(() => ordenarPorRecentes(empresas, recentes), [empresas, recentes]);
  const visiveis = useMemo(() => filtrarEmpresas(ordenadas, pesquisa), [ordenadas, pesquisa]);
  const ultimaUsada = recentes.find((id) => empresas.some((e) => e.id === id));

  useEffect(() => marcarEnderecoEscolha(), []);

  const escolher = (id: number) => {
    setAEscolher(id);
    // o ERP monta-se com o endereço actual: define-se antes de a empresa ficar activa
    const portal = naRotaPortal();
    if (!portal) window.history.replaceState(null, '', enderecoDepoisDaEscolha());
    escolherEmpresa(id)
      .catch((e) => {
        if (!portal) window.history.replaceState(null, '', ROTA_ESCOLHA_EMPRESA);
        notificarErro(e);
      })
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
          {naRotaPortal() ? 'Portal do Colaborador — escolha a empresa.' : 'Escolha a empresa com que vai trabalhar.'}
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
                    <List.Item.Meta
                      style={{ minWidth: 0 }}
                      avatar={<LogotipoEmpresa empresa={e} />}
                      title={
                        <>
                          {e.nome}
                          {e.id === ultimaUsada && (
                            <Tag color="blue" bordered style={{ marginInlineStart: 8 }}>
                              Última usada
                            </Tag>
                          )}
                          {/* legado: marca «Consolidação» das holdings na escolha de empresa (js/app_v2.js:455) */}
                          {e.e_consolidacao && (
                            <Tag color="purple" bordered style={{ marginInlineStart: 8 }}>
                              Holding · Consolidação
                            </Tag>
                          )}
                        </>
                      }
                      description={e.nif ? `NIF ${e.nif}` : undefined}
                    />
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
