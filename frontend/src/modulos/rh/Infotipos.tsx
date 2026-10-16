import { Button, Card, Checkbox, Form, Input, InputNumber, Select, Space, Tabs, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { CALCULO_HORAS, REGIMES_IRT, TIPOS_RUBRICA, type Infotipo } from './api';
import { BotoesCadastroLegado } from './comum/BotoesCadastroLegado';
import { CadastroSimples, useDestinoAccoes } from './comum/CadastroSimples';
import { useAccaoRh, useAvisarErro } from './comum/consultas';
import { TabelaIrt } from './comum/TabelaIrt';
import type { CampoFormulario } from '@/componentes/formularios';
import { campoSimNao } from './comum/linhasRh';

/** Vista Linhas do «Novo Infotipo» (uma rubrica por linha; mesmas regras e mesmo POST da grade). */
export const CAMPOS_LINHAS_INFOTIPO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome da Rubrica', obrigatorio: true, largura: 240, maxLength: 255, aliases: ['Nome', 'Rubrica', 'Infotipo'] },
  { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, largura: 170, opcoes: TIPOS_RUBRICA, omissao: 'VENCIMENTO' },
  { nome: 'irt', rotulo: 'Sujeito a IRT?', tipo: 'select', obrigatorio: true, largura: 240, opcoes: REGIMES_IRT, omissao: 'true', aliases: ['IRT'] },
  { nome: 'calculo_horas', rotulo: 'Por horas', tipo: 'select', largura: 140, opcoes: CALCULO_HORAS.filter((o) => o.value !== ''), emLinhas: 'opcional' },
  campoSimNao('sujeito_inss', 'Sujeito a INSS (3%)', { omissao: true, largura: 120, aliases: ['INSS'] }),
  campoSimNao('base_horaria', 'Base dia/hora', { largura: 110, emLinhas: 'opcional' }),
];

/** Etiqueta do tipo como no antigo (verde VENCIMENTO, vermelho DESCONTO). */
function EtiquetaTipo({ tipo }: { tipo: string }) {
  const venc = tipo === 'VENCIMENTO';
  const outros = tipo === 'OUTROS';
  return (
    <span style={{ padding: '4px 8px', borderRadius: 4, fontSize: '0.75rem', background: outros ? '#f1f5f9' : venc ? '#dcfce7' : '#fee2e2', color: outros ? '#475569' : venc ? '#166534' : '#991b1b' }}>
      {tipo}
    </span>
  );
}

/** Rótulos do antigo para o IRT («Sim», «Até 30.000 Kz Isento», «Não»). */
function rotuloIrt(v: string | null | undefined): string {
  if (v === 'true' || v === null || v === undefined) return 'Sim';
  if (v === 'conditional_30k') return 'Até 30.000 Kz Isento';
  if (v === 'false') return 'Não';
  return REGIMES_IRT.find((x) => x.value === v)?.label ?? v;
}

/** Ordem do antigo (DataTables sem `order` → 1.ª coluna «Tipo» ascendente: DESCONTO, OUTROS, VENCIMENTO; empate pela ordem de criação). */
/** Rubrica por horas? (RegrasSalariais.tipoHoras do legado e MotorSalarial::tipoHoras): forçada em calculo_horas ou pelo nome. */
export function tipoHoras(i: Pick<Infotipo, 'tipo' | 'nome' | 'calculo_horas'>): 'EXTRA' | 'FALTA' | null {
  if (i.calculo_horas === 'EXTRA' || i.calculo_horas === 'FALTA') return i.calculo_horas;
  if (i.calculo_horas === 'NAO') return null;
  const n = (i.nome ?? '').toLowerCase();
  if (i.tipo === 'VENCIMENTO' && n.includes('hora') && n.includes('extra')) return 'EXTRA';
  if (i.tipo === 'DESCONTO' && n.includes('falta')) return 'FALTA';
  return null;
}

const porTipo = (a: Infotipo, b: Infotipo) => a.tipo.localeCompare(b.tipo, 'pt') || a.id - b.id;

/**
 * RH › Infotipos (Rubricas) (ecrã infotipos): rubricas salariais, regras das horas extra e tabela de IRT.
 * Como no antigo (js/app_v2.js renderInfotipos): título sem subtítulo e, à direita, Template · Copiar de outra empresa ·
 * Importar Excel · + Novo Infotipo (só no separador «Rubricas»); a lista num cartão ordenada por Tipo.
 */
export default function Infotipos() {
  const { pode } = useSessao();
  const gerir = pode('rh_infotipos_gerir');
  const [separador, setSeparador] = useState('rubricas');
  const [destino, elementoDestino] = useDestinoAccoes();
  return (
    <>
      <CabecalhoPagina titulo="Infotipos (Rubricas)" accoes={elementoDestino} />
      <Tabs activeKey={separador} onChange={setSeparador} items={[{ key: 'rubricas', label: 'Rubricas', children: (
        <>
          <CadastroSimples<Infotipo>
            url="/rh/infotipos"
            chave={['rh', 'infotipos']}
            nomeItem="infotipo"
            rotuloNovo="Novo Infotipo"
            tituloNovo="Adicionar Novo Infotipo"
            accoesExtra={gerir ? <BotoesCadastroLegado entidade="infotipos" /> : undefined}
            accoesCabecalho={separador === 'rubricas' ? destino : null}
            ordemInicial={porTipo}
            tituloImpressao="Infotipos (Rubricas)"
            podeGerir={gerir}
            podeEliminar={pode('rh_infotipo_del')}
            pesquisa={(r) => `${r.nome} ${r.tipo}`}
            valoresNovos={{ tipo: 'VENCIMENTO', sujeito_inss: true, irt: 'true', base_horaria: false, calculo_horas: '' }}
            paraFormulario={(r) => ({ ...r, irt: r.irt ?? 'true', calculo_horas: r.calculo_horas ?? '', sujeito_inss: Boolean(r.sujeito_inss), base_horaria: Boolean(r.base_horaria) })}
            paraEnvio={(v) => ({ ...v, calculo_horas: v.calculo_horas || null })}
            linhas={{ chave: 'rh.infotipos', campos: CAMPOS_LINHAS_INFOTIPO, nomeRegistos: 'infotipos' }}
            colunas={[
              { title: 'Tipo', dataIndex: 'tipo', sorter: porTipo, defaultSortOrder: 'ascend', render: (t: string) => <EtiquetaTipo tipo={t} /> },
              { title: 'Nome da Rubrica', dataIndex: 'nome', sorter: (a, b) => a.nome.localeCompare(b.nome, 'pt') },
              { title: 'Sujeito a INSS (3%)', dataIndex: 'sujeito_inss', render: (v: boolean | null) => (v ? 'Sim' : 'Não') },
              { title: 'Sujeito a IRT', dataIndex: 'irt', render: (v: string | null) => rotuloIrt(v) },
              {
                title: 'Base dia/hora', dataIndex: 'base_horaria',
                render: (v: boolean | null) => (v ? <span style={{ padding: '2px 6px', borderRadius: 4, fontSize: '0.72rem', background: '#e0f2fe', color: '#075985' }}>Sim</span> : 'Não'),
              },
              { title: 'Por horas', dataIndex: 'calculo_horas', render: (_: unknown, r: Infotipo) => ({ EXTRA: 'Horas extra', FALTA: 'Faltas' } as Record<string, string>)[tipoHoras(r) ?? ''] ?? '—' },
            ]}
            campos={
              <>
                <Form.Item name="nome" label="Nome da Rubrica" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                  <Input autoFocus placeholder="Ex: Subsídio de Alimentação" />
                </Form.Item>
                <Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}>
                  <Select options={TIPOS_RUBRICA} />
                </Form.Item>
                {/* ordem do antigo (Adicionar Novo Infotipo): Nome · Tipo · INSS · IRT · valor dia/hora · cálculo por horas */}
                <Form.Item name="sujeito_inss" valuePropName="checked">
                  <Checkbox>Sujeito a INSS (3%)</Checkbox>
                </Form.Item>
                <Form.Item name="irt" label="Sujeito a IRT?" rules={[{ required: true }]}>
                  <Select options={REGIMES_IRT} />
                </Form.Item>
                <Form.Item name="base_horaria" valuePropName="checked">
                  <Checkbox>Entra no valor dia/hora (base das faltas e horas extra)</Checkbox>
                </Form.Item>
                <Form.Item name="calculo_horas" label="Cálculo por horas" extra="Rubricas de horas extra ou de faltas por hora (efectividade).">
                  <Select options={CALCULO_HORAS} />
                </Form.Item>
              </>
            }
          />
          <RegrasHorasExtra podeGravar={gerir} />
        </>
      ) }, { key: 'irt', label: 'Tabela de IRT', children: <Card><TabelaIrt /></Card> }]} />
    </>
  );
}

interface Regras {
  he_percentagem_1: number;
  he_limite_horas: number;
  he_percentagem_2: number;
}

/** «Faltas e horas extra por hora — regras da empresa» com «Guardar regras» (legado gravarRegrasHorasExtra). */
function RegrasHorasExtra({ podeGravar }: { podeGravar: boolean }) {
  const [form] = Form.useForm<Regras>();
  const regras = useQuery({ queryKey: ['rh', 'paridade', 'regras-horas-extra'], queryFn: () => obter<Regras>('/rh/paridade/regras-horas-extra') });
  useAvisarErro(regras.error, 'Erro ao carregar as regras das horas extra');
  const accao = useAccaoRh();
  useEffect(() => {
    if (regras.data) form.setFieldsValue(regras.data);
  }, [regras.data, form]);
  return (
    <Card style={{ marginTop: 16 }} title={<Space><IconeFa nome="clock" />Faltas e horas extra por hora — regras da empresa</Space>} loading={regras.isLoading}>
      <Typography.Paragraph style={{ fontSize: '0.85rem', color: '#475569' }}>
        Valor dia = valor mensal das rubricas marcadas «Base dia/hora» ÷ dias contratados no contrato. Valor hora = valor dia ÷ horas por dia do contrato. Faltas = horas × valor hora.
      </Typography.Paragraph>
      <Form form={form} layout="vertical" disabled={!podeGravar} onFinish={(v) => accao.mutate({ metodo: 'put', url: '/sistema/empresa-ativa/horas-extra', dados: v })}>
        <Space wrap align="end">
          <Form.Item name="he_percentagem_1" label="Acréscimo até ao limite (%)" rules={[{ required: true }]} style={{ marginBottom: 0 }}><InputNumber min={0} max={1000} step={1} style={{ width: 160 }} /></Form.Item>
          <Form.Item name="he_limite_horas" label="Limite mensal (horas)" rules={[{ required: true }]} style={{ marginBottom: 0 }}><InputNumber min={0} max={744} step={1} style={{ width: 160 }} /></Form.Item>
          <Form.Item name="he_percentagem_2" label="Acréscimo acima do limite (%)" rules={[{ required: true }]} style={{ marginBottom: 0 }}><InputNumber min={0} max={1000} step={1} style={{ width: 160 }} /></Form.Item>
          {podeGravar && <Button type="primary" icon={<IconeFa nome="save" />} loading={accao.isPending} onClick={() => form.submit()}>Guardar regras</Button>}
        </Space>
      </Form>
    </Card>
  );
}
