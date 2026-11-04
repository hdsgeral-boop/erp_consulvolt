import { describe, expect, it } from 'vitest';
import { ECRAS } from '@/modulos/registo';
import { ENDERECO_ECRA_PLATAFORMA } from '@/plataforma/enderecosPlataforma';
import { redireccaoEcra } from './redireccoesEcra';

describe('redireccoesEcra', () => {
  it('/m/contab/ia_assistente → /m/config/ia_assistente, para qualquer utilizador (o destino aplica a guarda de permissões)', () => {
    expect(redireccaoEcra('contab', 'ia_assistente', null)).toEqual({ para: '/m/config/ia_assistente' });
    expect(redireccaoEcra('contab', 'ia_assistente', 'ADMINISTRADOR')).toEqual({ para: '/m/config/ia_assistente' });
    // o endereço novo e os restantes ecrãs não redireccionam
    expect(redireccaoEcra('config', 'ia_assistente', null)).toBeNull();
    expect(redireccaoEcra('contab', 'lancamentos', null)).toBeNull();
  });

  it('cliente normal: /m/config/plataforma_* volta ao Início com aviso (nunca abre)', () => {
    for (const ecra of Object.keys(ENDERECO_ECRA_PLATAFORMA)) {
      const r = redireccaoEcra('config', ecra, 'ADMINISTRADOR');
      expect(r?.para).toBe('/inicio');
      expect(r?.aviso).toBeTruthy();
    }
    expect(redireccaoEcra('config', 'plataforma_inventado', 'UTILIZADOR')?.para).toBe('/inicio');
    expect(redireccaoEcra('config', 'plataforma_clientes', undefined)?.para).toBe('/inicio');
  });

  it('utilizador da plataforma: vai ao ecrã equivalente da consola /admin', () => {
    expect(redireccaoEcra('config', 'plataforma_clientes', 'SUPER_ADMINISTRADOR')).toEqual({ para: '/admin/clientes' });
    expect(redireccaoEcra('config', 'plataforma_painel', 'SUPER_ADMINISTRADOR')).toEqual({ para: '/admin' });
    expect(redireccaoEcra('config', 'plataforma_cupoes', 'OPERADOR_PLATAFORMA')).toEqual({ para: '/admin/cupoes' });
    // agente de suporte não usa a consola
    expect(redireccaoEcra('config', 'plataforma_clientes', 'AGENTE_SUPORTE')?.para).toBe('/inicio');
  });

  it('nenhum ecrã plataforma_* continua registado como ecrã do ERP; o Assistente IA vive em config', () => {
    expect(Object.keys(ECRAS).filter((k) => k.startsWith('plataforma_'))).toEqual([]);
    expect(ECRAS).toHaveProperty('ia_assistente');
    expect(ECRAS).toHaveProperty('config_licenca');
    expect(ECRAS).toHaveProperty('minha_conta');
  });
});
