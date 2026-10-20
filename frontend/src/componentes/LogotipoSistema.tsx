import { useIdentidadeSistema } from '@/sessao/identidade';

/** Logótipo do cliente do endereço (definido em Configurações › Geral) ou, sem ele, o símbolo SVG padrão. */
export function LogotipoDoSistema({ tamanho = 64 }: { tamanho?: number }) {
  const { data } = useIdentidadeSistema();
  if (!data?.logotipo) return <LogotipoSistema tamanho={tamanho} soSimbolo />;
  return (
    <img
      src={data.logotipo}
      alt={data.nome_sistema || 'Logótipo do sistema'}
      data-testid="logotipo-cliente"
      style={{ height: tamanho, maxWidth: tamanho * 3, objectFit: 'contain', display: 'block', flex: 'none' }}
    />
  );
}

interface Props {
  /** altura em px (a largura acompanha) */
  tamanho?: number;
  /** só o símbolo, sem o texto «MEU ERP» */
  soSimbolo?: boolean;
  /** texto claro, para fundos escuros */
  claro?: boolean;
}

/** Logótipo padrão do sistema (SVG): usado enquanto não existir um logótipo oficial. Nunca mostra imagens de empresas. */
export function LogotipoSistema({ tamanho = 64, soSimbolo = false, claro = false }: Props) {
  const largura = soSimbolo ? tamanho : Math.round(tamanho * 3.1);
  return (
    <svg
      role="img"
      aria-label="MEU ERP"
      data-testid="logotipo-sistema"
      width={largura}
      height={tamanho}
      viewBox={soSimbolo ? '0 0 64 64' : '0 0 198 64'}
      xmlns="http://www.w3.org/2000/svg"
      style={{ display: 'block', flex: 'none' }}
    >
      <polygon points="32,3 58,18 58,46 32,61 6,46 6,18" fill="#2563eb" />
      <path d="M19 44V20l13 14 13-14v24" fill="none" stroke="#ffffff" strokeWidth="5.5" strokeLinecap="round" strokeLinejoin="round" />
      {!soSimbolo && (
        <text x="72" y="43" fontFamily="Inter, 'Segoe UI', Arial, sans-serif" fontSize="30" fontWeight="800" fill={claro ? '#ffffff' : '#1e293b'} letterSpacing="0.5">
          MEU ERP
        </text>
      )}
    </svg>
  );
}
