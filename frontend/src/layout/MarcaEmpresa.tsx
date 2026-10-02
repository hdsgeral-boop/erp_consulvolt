import { Avatar, Tooltip, Typography, theme } from 'antd';
import { useSessao } from '@/sessao/SessaoContexto';
import { useIdentidade } from '@/sessao/identidade';
import { iniciais } from './marca';

interface Props {
  /** só o logótipo/avatar (menu recolhido) */
  soIcone?: boolean;
  /** tamanho do logótipo/avatar em px */
  tamanho?: number;
  /** mostra «ERP Consulvolt» por baixo do nome */
  comProduto?: boolean;
}

/**
 * Identidade da empresa activa: logótipo (GET /sistema/identidade) e nome com reticências e tooltip.
 * Sem logótipo → avatar com as iniciais na cor primária. Enquanto a identidade carrega usa o nome da sessão.
 */
export function MarcaEmpresa({ soIcone = false, tamanho = 36, comProduto = false }: Props) {
  const { empresa } = useSessao();
  const identidade = useIdentidade();
  const { token } = theme.useToken();
  const nome = identidade.data?.nome || empresa?.nome || 'ERP Consulvolt';
  const logotipo = identidade.data?.logotipo ?? null;

  const imagem = logotipo ? (
    <img src={logotipo} alt={`Logótipo de ${nome}`} className="erp-marca-logotipo" style={{ width: tamanho, height: tamanho, background: token.colorBgContainer }} />
  ) : (
    <Avatar
      shape="square"
      size={tamanho}
      aria-label={`Iniciais de ${nome}`}
      style={{ background: token.colorPrimary, color: token.colorTextLightSolid, flex: 'none', fontWeight: 600, fontSize: Math.round(tamanho * 0.4) }}
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
    <div className="erp-marca" data-testid="marca-empresa">
      {imagem}
      <div className="erp-marca-textos">
        <Typography.Text strong ellipsis={{ tooltip: { title: nome, placement: 'bottom' } }} style={{ color: token.colorText, maxWidth: '100%' }} data-testid="nome-empresa">
          {nome}
        </Typography.Text>
        {comProduto && (
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            ERP Consulvolt
          </Typography.Text>
        )}
      </div>
    </div>
  );
}
