import { Col, Form, Row, Select } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type { FormInstance } from 'antd';
import { obter } from '@/api/cliente';
import { nomeAndar } from './regras';
import type { OpcoesLocais } from './tipos';

/** Edifícios, andares e portas da empresa (selects da ficha e coluna «Localização»). */
export function useOpcoesLocais(activo = true) {
  return useQuery({ queryKey: ['activos', 'locais', 'opcoes'], queryFn: () => obter<OpcoesLocais>('/ativos/locais/opcoes'), staleTime: 60_000, enabled: activo, retry: false });
}

/**
 * Selects encadeados Edifício › Andar › Porta da ficha do activo (assetLocalSelects/assetLocalMudou, js/activos_edificios.js
 * :973-1012). Mudar o edifício limpa o andar e a porta; mudar o andar limpa a porta. O servidor deriva o local da porta.
 */
export function CamposLocalizacao({ form }: { form: FormInstance }) {
  const q = useOpcoesLocais();
  const edificio = Form.useWatch('edificio_id', form) as number | undefined;
  const andar = Form.useWatch('andar_id', form) as number | undefined;
  const o = q.data;
  if (o && !o.edificios?.length) {
    return <div style={{ color: '#64748b', fontSize: 13 }}>Ainda não há edifícios registados. Crie um activo do tipo «Edifício / Local» para poder localizar os bens.</div>;
  }
  return (
    <Row gutter={12}>
      <Col xs={24} md={8}>
        <Form.Item name="edificio_id" label="Edifício / Local">
          <Select allowClear loading={q.isLoading} placeholder="-- Sem localização --" onChange={() => form.setFieldsValue({ andar_id: undefined, porta_id: undefined })}
            options={(o?.edificios ?? []).map((e) => ({ value: e.id, label: `${e.codigo ?? ''} — ${e.descricao}` }))} />
        </Form.Item>
      </Col>
      <Col xs={24} md={8}>
        <Form.Item name="andar_id" label="Andar">
          <Select allowClear placeholder="-- Andar --" disabled={!edificio} onChange={() => form.setFieldValue('porta_id', undefined)}
            options={(o?.andares ?? []).filter((a) => a.edificio_id === edificio).map((a) => ({ value: a.id, label: nomeAndar(a) }))} />
        </Form.Item>
      </Col>
      <Col xs={24} md={8}>
        <Form.Item name="porta_id" label="Porta" dependencies={['edificio_id', 'andar_id']}
          rules={[{ validator: (_, v) => (edificio && andar && !v ? Promise.reject(new Error('Indique a porta ou deixe o edifício em branco.')) : Promise.resolve()) }]}>
          <Select allowClear placeholder="-- Porta --" disabled={!andar}
            options={(o?.portas ?? []).filter((p) => p.andar_id === andar).map((p) => ({ value: p.id, label: `${p.numero}${p.nome ? ` — ${p.nome}` : ''}` }))} />
        </Form.Item>
      </Col>
    </Row>
  );
}
