import { Avatar, Tooltip, Typography, theme } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { iniciais } from './marca';

interface Props {
  /** só o logótipo/avatar (menu recolhido) */
  soIcone?: boolean;
  /** tamanho do logótipo/avatar em px */
  tamanho?: number;
  /** mostra «GESTÃO EMPRESARIAL» por baixo do nome */
  comProduto?: boolean;
  /** cores para o fundo escuro do menu lateral (mostra estritamente o sistema e logótipo do sistema) */
  escuro?: boolean;
}

/**
 * Identidade exibida no sistema:
 * - No menu lateral (escuro = true): mostra SEMPRE o nome do sistema ('CONSULVOLT ERP') e
 *   estritamente o logótipo oficial do sistema (de /sistema/logotipo-login), sem exibir o nome da empresa.
 * - Fora do menu escuro (portal, impressões, etc.): mostra a identidade da empresa ativa.
 */
export function MarcaEmpresa({ soIcone = false, tamanho = 36, comProduto = false, escuro = false }: Props) {
  const { empresa } = useSessao();
  const identidade = useIdentidade();
  const { token } = theme.useToken();

  // Consulta do logótipo e nome do sistema (definido globalmente em /sistema/logotipo-login)
  const sistema = useQuery({
    queryKey: ['sistema', 'logotipo-login'],
    queryFn: () => obter<{ logotipo: string | null; nome_sistema?: string | null }>('/sistema/logotipo-login'),
    staleTime: 10 * 60_000,
  });

  const nomeSistema = sistema.data?.nome_sistema || identidade.data?.nome_sistema || 'CONSULVOLT ERP';
  const nomeEmpresa = identidade.data?.nome || empresa?.nome || 'Consulvolt';

  // No menu escuro, exibe SEMPRE o nome e logótipo do sistema (não o da empresa)
  const nome = escuro ? nomeSistema : nomeEmpresa;
  const logotipo = escuro ? (sistema.data?.logotipo ?? null) : (identidade.data?.logotipo ?? null);

  const imagem = logotipo ? (
    <img
      src={logotipo}
      alt={`Logótipo de ${nome}`}
      className="erp-marca-logotipo"
      style={{
        width: tamanho,
        height: tamanho,
        objectFit: 'contain',
        background: escuro ? 'rgba(255, 255, 255, 0.05)' : token.colorBgContainer,
        borderRadius: 6,
        padding: 2,
      }}
    />
  ) : (
    <Avatar
      shape="square"
      size={tamanho}
      aria-label={`Iniciais de ${nome}`}
      style={{
        background: escuro ? '#2563eb' : token.colorPrimary,
        color: '#ffffff',
        flex: 'none',
        fontWeight: 700,
        fontSize: Math.round(tamanho * 0.4),
        borderRadius: 6,
      }}
    >
      {iniciais(nome)}
    </Avatar>
  );

  if (soIcone) {
    return (
      <Tooltip title={nome} placement="right">
        <div className="erp-marca" data-testid="marca-empresa" style={{ justifyContent: 'center' }}>
          {imagem}
        </div>
      </Tooltip>
    );
  }

  return (
    <div className={escuro ? 'erp-marca erp-marca-escura' : 'erp-marca'} data-testid="marca-empresa">
      {imagem}
      <div className="erp-marca-textos">
        <Typography.Text
          strong
          ellipsis={{ tooltip: { title: nome, placement: 'bottom' } }}
          className="erp-marca-nome"
          style={{ color: escuro ? '#ffffff' : token.colorText, maxWidth: '100%', fontSize: escuro ? 15 : undefined }}
          data-testid={escuro ? 'nome-sistema' : 'nome-empresa'}
        >
          {nome}
        </Typography.Text>
        {comProduto && (
          <Typography.Text
            type="secondary"
            className="erp-marca-produto"
            style={escuro ? { color: '#94a3b8', fontSize: 11, letterSpacing: '0.5px' } : { fontSize: 12 }}
          >
            {escuro ? 'GESTÃO EMPRESARIAL' : (identidade.data?.nome_sistema || 'ERP Consulvolt')}
          </Typography.Text>
        )}
      </div>
    </div>
  );
}
