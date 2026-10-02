import { Checkbox, Form, Input, Select, Tag } from 'antd';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { CALCULO_HORAS, REGIMES_IRT, TIPOS_RUBRICA, type Infotipo } from './api';
import { CadastroSimples } from './comum/CadastroSimples';

const COR_TIPO: Record<string, string> = { VENCIMENTO: 'green', DESCONTO: 'red', OUTROS: 'default' };

/** RH › Rubricas (ecrã infotipos): rubricas salariais e as suas marcações (INSS, IRT, cálculo por horas). */
export default function Infotipos() {
  const { pode } = useSessao();
  return (
    <>
      <CabecalhoPagina titulo="Rubricas (infotipos)" subtitulo="Vencimentos, descontos e rubricas informativas usados nos contratos e no processamento" />
      <CadastroSimples<Infotipo>
        url="/rh/infotipos"
        chave={['rh', 'infotipos']}
        nomeItem="rubrica"
        tituloImpressao="Lista de rubricas salariais (infotipos)"
        podeGerir={pode('rh_infotipos_gerir')}
        podeEliminar={pode('rh_infotipo_del')}
        pesquisa={(r) => `${r.nome} ${r.tipo}`}
        valoresNovos={{ tipo: 'VENCIMENTO', sujeito_inss: true, irt: 'true', base_horaria: false, calculo_horas: '' }}
        paraFormulario={(r) => ({ ...r, irt: r.irt ?? 'true', calculo_horas: r.calculo_horas ?? '', sujeito_inss: Boolean(r.sujeito_inss), base_horaria: Boolean(r.base_horaria) })}
        paraEnvio={(v) => ({ ...v, calculo_horas: v.calculo_horas || null })}
        colunas={[
          { title: 'Nome', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong>, sorter: (a, b) => a.nome.localeCompare(b.nome, 'pt') },
          { title: 'Tipo', dataIndex: 'tipo', render: (t: string) => <Tag color={COR_TIPO[t]}>{TIPOS_RUBRICA.find((x) => x.value === t)?.label ?? t}</Tag>,
            filters: TIPOS_RUBRICA.map((t) => ({ text: t.label, value: t.value })), onFilter: (v, r) => r.tipo === v },
          { title: 'INSS', dataIndex: 'sujeito_inss', align: 'center', render: (v: boolean | null) => (v ? 'Sim' : 'Não') },
          { title: 'IRT', dataIndex: 'irt', render: (v: string | null) => REGIMES_IRT.find((x) => x.value === v)?.label ?? v ?? '—' },
          { title: 'Base horária', dataIndex: 'base_horaria', align: 'center', render: (v: boolean | null) => (v ? 'Sim' : '—') },
          { title: 'Cálculo por horas', dataIndex: 'calculo_horas', render: (v: string | null) => CALCULO_HORAS.find((x) => x.value === (v ?? ''))?.label ?? v },
        ]}
        campos={
          <>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
              <Input autoFocus />
            </Form.Item>
            <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
              <Select options={TIPOS_RUBRICA} />
            </Form.Item>
            <Form.Item name="irt" label="Regime de IRT" rules={[{ required: true }]}>
              <Select options={REGIMES_IRT} />
            </Form.Item>
            <Form.Item name="calculo_horas" label="Cálculo por horas" extra="Rubricas de horas extra ou de faltas por hora (efectividade).">
              <Select options={CALCULO_HORAS} />
            </Form.Item>
            <Form.Item name="sujeito_inss" valuePropName="checked">
              <Checkbox>Sujeita a Segurança Social (INSS)</Checkbox>
            </Form.Item>
            <Form.Item name="base_horaria" valuePropName="checked">
              <Checkbox>Entra na base do valor hora</Checkbox>
            </Form.Item>
          </>
        }
      />
    </>
  );
}
