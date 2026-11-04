import { useIdentidadeSistema } from '@/sessao/identidade';

/** Logótipo do cliente do endereço (definido em Configurações › Geral) ou, sem ele, o símbolo SVG padrão. */
export function LogotipoDoSistema({ tamanho = 64 }: { tamanho?: number }) {
  const { data } = useIdentidadeSistema();
  if (!data?.logotipo) return <LogotipoSistema tamanho={tamanho} nome={data?.nome_sistema ?? undefined} />;
  return (
    <img
      src={data.logotipo}
      alt={data.nome_sistema || 'Logótipo do sistema'}
      data-testid="logotipo-cliente"
      style={{ height: tamanho, maxWidth: tamanho * 3, objectFit: 'contain', display: 'block', flex: 'none' }}
    />
  );
}

/** Símbolo padrão do sistema (SVG): usado enquanto o cliente não carregar o seu logótipo. Nunca mostra imagens de empresas. */
export function LogotipoSistema({ tamanho = 64, nome = 'ERP' }: { tamanho?: number; nome?: string }) {
  return (
    <svg role="img" aria-label={nome} data-testid="logotipo-sistema" width={tamanho} height={tamanho} viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" style={{ display: 'block', flex: 'none' }}>
      <polygon points="32,3 58,18 58,46 32,61 6,46 6,18" fill="#1947b3" />
      <text x="32" y="39" textAnchor="middle" fontFamily="Inter, 'Segoe UI', Arial, sans-serif" fontSize="18" fontWeight="800" fill="#ffffff">ERP</text>
    </svg>
  );
}
