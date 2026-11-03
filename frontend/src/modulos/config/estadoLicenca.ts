import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';

/**
 * Estado da licença da empresa activa — partilhado pelo ecrã Configurações › Licença (Licenca.tsx) e pela moldura
 * do ERP (AvisoLicenca, ContadorLicencaHeader, TourBoasVindas). Fica num módulo pequeno para o layout não arrastar
 * o ecrã completo da licença (tabelas, carregamentos, pagamentos) para o JS inicial.
 */
export interface EstadoLicenca {
  licencas_ativas: boolean;
  origem: 'SEM_LIMITE' | 'TESTE' | 'PLANO';
  inicio: string | null;
  fim: string | null;
  dias_restantes: number | null;
  expirada: boolean;
  somente_leitura: boolean;
  aviso: 'A_EXPIRAR' | 'EXPIRADA' | null;
  plano: { id: number; codigo: string; nome: string } | null;
  pendentes: number;
  dias_teste: number;
}

export const CHAVE_LICENCA = ['licenca'];

export function useEstadoLicenca(activo = true) {
  return useQuery({
    queryKey: [...CHAVE_LICENCA, 'estado'],
    queryFn: () => obter<EstadoLicenca>('/licenca/estado'),
    enabled: activo,
    staleTime: 60_000,
  });
}
