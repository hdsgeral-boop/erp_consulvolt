import { Col, Form, Row } from 'antd';
import type { NamePath } from 'antd/es/form/interface';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';

/** Há unidades ou centros de custo para escolher? Sem nenhum não vale a pena ocupar espaço na linha (LinhaUNCC.disponivel). */
export function useLinhaUNCCDisponivel(): boolean {
  const u = useUnidadesNegocio();
  const c = useTabelaAux('centros-custo');
  return (u.data?.length ?? 0) > 0 || (c.data?.length ?? 0) > 0;
}

/**
 * Unidade de negócio e centro de custo na LINHA do documento (js/shared/linha_un_cc.js). Vazio = «— cabeçalho —».
 * Na contabilização a linha manda: linha › repartição do documento (`unidades_alocadas`) › cabeçalho.
 * Usa-se dentro de um Form.List: `nome` é o índice da linha (ou o caminho relativo).
 */
export function CamposLinhaUNCC({ nome }: { nome: number | NamePath }) {
  const disponivel = useLinhaUNCCDisponivel();
  if (!disponivel) return null;
  const caminho = (campo: string) => (Array.isArray(nome) ? [...nome, campo] : [nome, campo]);
  return (
    <Row gutter={8}>
      <Col xs={12} md={8}>
        <Form.Item name={caminho('unidade_negocio_id')} style={{ marginBottom: 8 }}>
          <SeletorUnidade placeholder="Un. negócio — cabeçalho —" style={{ width: '100%' }} />
        </Form.Item>
      </Col>
      <Col xs={12} md={8}>
        <Form.Item name={caminho('centro_custo_id')} style={{ marginBottom: 8 }}>
          <SeletorAux tabela="centros-custo" placeholder="C. custo — cabeçalho —" style={{ width: '100%' }} />
        </Form.Item>
      </Col>
    </Row>
  );
}

/** Campos da linha a enviar (só os escolhidos). */
export function unccDaLinha(l: { unidade_negocio_id?: number | null; centro_custo_id?: number | null }): { unidade_negocio_id?: number; centro_custo_id?: number } {
  return {
    ...(l.unidade_negocio_id ? { unidade_negocio_id: l.unidade_negocio_id } : {}),
    ...(l.centro_custo_id ? { centro_custo_id: l.centro_custo_id } : {}),
  };
}
