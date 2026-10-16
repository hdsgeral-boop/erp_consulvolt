import { Alert, Form, Input, Tabs, Tag, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import type { Cadastro } from './api';
import { CadastroSimples, useDestinoAccoes } from './comum/CadastroSimples';
import { BotoesCadastroLegado } from './comum/BotoesCadastroLegado';
import type { CampoFormulario } from '@/componentes/formularios';

/** Vista Linhas da «Nova Função» (legado: formularios_linhas_config.js › funcoes — nome e descrição). */
const CAMPOS_LINHAS_FUNCAO: CampoFormulario[] = [
  { nome: 'nome', rotulo: 'Nome da função', obrigatorio: true, largura: 240, maxLength: 255, aliases: ['Nome', 'Função'] },
  { nome: 'objetivo_geral', rotulo: 'Objectivo geral do cargo', tipo: 'textoLongo', largura: 300, maxLength: 5000, aliases: ['Objectivo', 'Objetivo geral'] },
  { nome: 'responsabilidades', rotulo: 'Principais responsabilidades', tipo: 'textoLongo', largura: 360, maxLength: 10000, aliases: ['Responsabilidades', 'Descrição', 'Descricao'] },
];

/** Ordem do antigo (DataTables sem `order` → 1.ª coluna ascendente): Funções por ID, tipos de organização por nome. */
const porId = (a: Cadastro, b: Cadastro) => a.id - b.id;
const porNome = (a: Cadastro, b: Cadastro) => a.nome.localeCompare(b.nome, 'pt') || a.id - b.id;

/** Resumo de um texto longo numa linha (antigo: 70 caracteres e «…»). */
export const resumoTexto = (t: string | null | undefined, n = 70): string => {
  const s = String(t ?? '').replace(/\s+/g, ' ').trim();
  return s.length > n ? `${s.slice(0, n)}…` : s;
};

/** Responsabilidades da função: as próprias ou, nos registos antigos, a `descricao` (window.funcaoResponsabilidades, app_v2.js:4013). */
export const responsabilidadesDe = (r: Pick<Cadastro, 'responsabilidades' | 'descricao'> | null | undefined): string =>
  String(r?.responsabilidades || r?.descricao || '').trim();

/** Cargos da estrutura orgânica que usam a função: até 3 etiquetas e «+N» (antigo renderFunçãoes). */
function CargosEstrutura({ cargos }: { cargos?: string[] }) {
  if (!cargos?.length) return <Typography.Text type="secondary" style={{ fontSize: 12 }} title="Nenhum cargo da estrutura orgânica usa esta função">sem cargo</Typography.Text>;
  return (
    <Tooltip title={cargos.join(' · ')}>
      <span>
        {cargos.slice(0, 3).map((c) => <Tag key={c} color="blue" style={{ marginBottom: 2 }}>{c}</Tag>)}
        {cargos.length > 3 && <Typography.Text type="secondary">+{cargos.length - 3}</Typography.Text>}
      </span>
    </Tooltip>
  );
}

/**
 * RH › Funções e categorias (ecrã funcoes): cargos/funções (/rh/cargos) e tipos de organização (/rh/tipos-organizacao).
 * Como no antigo (js/app_v2.js renderFunçãoes): título sem subtítulo e, à direita, Template · Copiar de outra empresa ·
 * Importar Excel · + Nova Função (do separador activo); a lista num cartão ordenada por ID.
 */
export default function Funcoes() {
  const { pode } = useSessao();
  const gerir = pode('rh_funcoes_gerir');
  const eliminar = pode('rh_funcao_del');
  const [separador, setSeparador] = useState('cargos');
  const [destino, elementoDestino] = useDestinoAccoes();
  return (
    <>
      <CabecalhoPagina titulo="Gestão de Funções" accoes={elementoDestino} />
      <Tabs
        activeKey={separador}
        onChange={setSeparador}
        items={[
          {
            key: 'cargos',
            label: 'Funções',
            children: (
              <CadastroSimples<Cadastro>
                url="/rh/cargos"
                chave={['rh', 'cargos']}
                nomeItem="função"
                rotuloNovo="Nova Função"
                tituloNovo="Adicionar Nova Função"
                accoesExtra={gerir ? <BotoesCadastroLegado entidade="funcoes" /> : undefined}
                accoesCabecalho={separador === 'cargos' ? destino : null}
                ordemInicial={porId}
                tituloImpressao="Lista de funções"
                podeGerir={gerir}
                podeEliminar={eliminar}
                pesquisa={(r) => `${r.nome} ${r.objetivo_geral ?? ''} ${responsabilidadesDe(r)} ${(r.cargos_estrutura ?? []).join(' ')}`}
                larguraModal={720}
                linhas={{ chave: 'rh.funcoes', campos: CAMPOS_LINHAS_FUNCAO, nomeRegistos: 'funções' }}
                // #10 (Versão Actual): objectivo geral, responsabilidades e os cargos que herdam o descritivo (só para mostrar)
                paraFormulario={(r) => ({ nome: r.nome, objetivo_geral: r.objetivo_geral ?? '', responsabilidades: responsabilidadesDe(r), cargos_estrutura: r.cargos_estrutura ?? [] })}
                paraEnvio={({ cargos_estrutura: _c, ...v }) => v}
                colunas={[
                  { title: 'ID', dataIndex: 'id', width: 80, sorter: porId, defaultSortOrder: 'ascend' },
                  { title: 'Nome da Função', dataIndex: 'nome', sorter: porNome, render: (v: string) => <strong>{v}</strong> },
                  { title: 'Objectivo Geral do Cargo', dataIndex: 'objetivo_geral', ellipsis: true,
                    render: (v: string | null) => (v ? <Tooltip title={<span style={{ whiteSpace: 'pre-line' }}>{v}</span>}>{resumoTexto(v)}</Tooltip> : <Typography.Text type="secondary">—</Typography.Text>) },
                  { title: 'Principais Responsabilidades', key: 'responsabilidades', ellipsis: true,
                    render: (_: unknown, r: Cadastro) => (responsabilidadesDe(r)
                      ? <Tooltip title={<span style={{ whiteSpace: 'pre-line' }}>{responsabilidadesDe(r)}</span>}>{resumoTexto(responsabilidadesDe(r))}</Tooltip>
                      : <Typography.Text type="secondary">—</Typography.Text>) },
                  { title: 'Cargos na estrutura orgânica', key: 'cargos_estrutura', render: (_: unknown, r: Cadastro) => <CargosEstrutura cargos={r.cargos_estrutura} /> },
                ]}
                campos={
                  <>
                    <Form.Item name="nome" label="Nome da Função" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                      <Input autoFocus placeholder="Ex.: Director Financeiro" />
                    </Form.Item>
                    <Form.Item name="objetivo_geral" label="Objectivo Geral do Cargo" extra="Sai na Cláusula 1.ª do contrato de trabalho e é herdado pelos cargos da estrutura orgânica.">
                      <Input.TextArea rows={3} maxLength={5000}
                        placeholder="Para que existe o cargo, numa frase ou duas. Ex.: Assegurar a gestão financeira da empresa, garantindo o equilíbrio de tesouraria e o cumprimento das obrigações fiscais." />
                    </Form.Item>
                    <Form.Item name="responsabilidades" label="Principais Responsabilidades" extra="Escreva uma por linha — é assim que saem listadas (numeradas) no contrato.">
                      <Input.TextArea rows={6} maxLength={10000}
                        placeholder={['Uma responsabilidade por linha. Ex.:', 'Elaborar e acompanhar o orçamento anual;', 'Controlar a tesouraria e as contas bancárias;',
                          'Garantir o cumprimento das obrigações fiscais.'].join('\n')} />
                    </Form.Item>
                    <Form.Item name="cargos_estrutura" hidden><Input /></Form.Item>
                    <Form.Item noStyle shouldUpdate={(a, b) => a.cargos_estrutura !== b.cargos_estrutura}>
                      {({ getFieldValue }) => {
                        const usam = (getFieldValue('cargos_estrutura') as string[] | undefined) ?? [];
                        return usam.length > 0 && (
                          <Alert type="info" showIcon style={{ marginBottom: 8 }} message={`Esta função descreve ${usam.length} cargo(s) da estrutura orgânica:`}
                            description={<>
                              <ul style={{ margin: '4px 0', paddingLeft: 18 }}>{usam.map((c) => <li key={c}>{c}</li>)}</ul>
                              O que alterar aqui passa a valer para todos eles e para os contratos que forem emitidos a partir de agora.
                            </>} />
                        );
                      }}
                    </Form.Item>
                  </>
                }
              />
            ),
          },
          {
            key: 'tipos',
            label: 'Tipos de organização',
            children: (
              <CadastroSimples<Cadastro>
                url="/rh/tipos-organizacao"
                chave={['rh', 'tipos-organizacao']}
                nomeItem="tipo de organização"
                accoesCabecalho={separador === 'tipos' ? destino : null}
                ordemInicial={porNome}
                tituloImpressao="Lista de tipos de organização"
                podeGerir={gerir}
                podeEliminar={eliminar}
                pesquisa={(r) => r.nome}
                linhas={{ chave: 'rh.tiposOrganizacao', campos: [{ nome: 'nome', rotulo: 'Nome', obrigatorio: true, largura: 320, maxLength: 255, aliases: ['Tipo', 'Tipo de organização'] }], nomeRegistos: 'tipos de organização' }}
                colunas={[{ title: 'Nome', dataIndex: 'nome', sorter: porNome, defaultSortOrder: 'ascend', render: (v: string) => <strong>{v}</strong> }]}
                campos={
                  <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                    <Input autoFocus />
                  </Form.Item>
                }
              />
            ),
          },
        ]}
      />
    </>
  );
}
