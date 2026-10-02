import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { useSessao } from './SessaoContexto';

/** Identidade da empresa activa (GET /sistema/identidade): barra do menu e cabeçalho das impressões/PDF. */
export interface IdentidadeEmpresa {
  id: number;
  nome: string;
  nif: string | null;
  morada: string | null;
  telefone: string | null;
  email: string | null;
  website: string | null;
  registo_comercial: string | null;
  rodape: string | null;
  /** data URI (PNG/JPEG/GIF/WEBP) ou null */
  logotipo: string | null;
}

export const chaveIdentidade = (empresaId: number | undefined) => ['sistema', 'identidade', empresaId] as const;

/** Lida uma vez por empresa activa (o logótipo pode ter ~1 MB); muda quando se troca de empresa. */
export function useIdentidade() {
  const { empresa } = useSessao();
  return useQuery({
    queryKey: chaveIdentidade(empresa?.id),
    queryFn: () => obter<IdentidadeEmpresa>('/sistema/identidade'),
    enabled: !!empresa,
    staleTime: 30 * 60_000,
  });
}
