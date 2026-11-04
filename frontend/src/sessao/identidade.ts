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
  nome_sistema?: string | null;
  /** data URI (PNG/JPEG/GIF/WEBP) ou null */
  logotipo: string | null;
}

/** Identidade do sistema do endereço (GET /sistema/logotipo-login, público): logótipo (data URI) e nome; null = padrão. */
export interface IdentidadeSistema {
  logotipo: string | null;
  nome_sistema: string | null;
}

export const CHAVE_IDENTIDADE_SISTEMA = ['sistema', 'logotipo-login'] as const;

/** O servidor escolhe o cliente pelo endereço, por isso serve antes do login (ecrã de entrada, menu, registo). */
export function useIdentidadeSistema() {
  return useQuery({
    queryKey: CHAVE_IDENTIDADE_SISTEMA,
    queryFn: () => obter<IdentidadeSistema>('/sistema/logotipo-login'),
    staleTime: 10 * 60_000,
    retry: false,
  });
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
