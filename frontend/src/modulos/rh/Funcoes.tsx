import { Form, Input, Tabs } from 'antd';
import { useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import type { Cadastro } from './api';
import { CadastroSimples, useDestinoAccoes } from './comum/CadastroSimples';
import { BotoesCadastroLegado } from './comum/BotoesCadastroLegado';

/** Ordem do antigo (DataTables sem `order` → 1.ª coluna ascendente): Funções por ID, tipos de organização por nome. */
const porId = (a: Cadastro, b: Cadastro) => a.id - b.id;
const porNome = (a: Cadastro, b: Cadastro) => a.nome.localeCompare(b.nome, 'pt') || a.id - b.id;

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
                pesquisa={(r) => `${r.nome} ${r.descricao ?? ''}`}
                colunas={[
                  { title: 'ID', dataIndex: 'id', width: 80, sorter: porId, defaultSortOrder: 'ascend' },
                  { title: 'Nome da Função', dataIndex: 'nome', sorter: porNome, render: (v: string) => <strong>{v}</strong> },
                  { title: 'Descrição da Função', dataIndex: 'descricao', render: (v: string | null) => <span style={{ whiteSpace: 'pre-line' }}>{v ?? ''}</span> },
                ]}
                campos={
                  <>
                    <Form.Item name="nome" label="Nome da Função" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                      <Input autoFocus />
                    </Form.Item>
                    <Form.Item name="descricao" label="Descrição da Função (Obrigações)" extra="Aparece no contrato de trabalho para assinatura.">
                      <Input.TextArea rows={4} maxLength={5000} />
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
