import { Form, Input, Tabs } from 'antd';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import type { Cadastro } from './api';
import { CadastroSimples } from './comum/CadastroSimples';

/** RH › Funções e categorias (ecrã funcoes): cargos/funções (/rh/cargos) e tipos de organização (/rh/tipos-organizacao). */
export default function Funcoes() {
  const { pode } = useSessao();
  const gerir = pode('rh_funcoes_gerir');
  const eliminar = pode('rh_funcao_del');
  return (
    <>
      <CabecalhoPagina titulo="Funções e categorias" subtitulo="Cargos e funções dos colaboradores e tipos de organização (usados no mapeamento contabilístico dos salários)" />
      <Tabs
        items={[
          {
            key: 'cargos',
            label: 'Cargos e funções',
            children: (
              <CadastroSimples<Cadastro>
                url="/rh/cargos"
                chave={['rh', 'cargos']}
                nomeItem="cargo"
                podeGerir={gerir}
                podeEliminar={eliminar}
                pesquisa={(r) => `${r.nome} ${r.descricao ?? ''}`}
                colunas={[
                  { title: 'Nome', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
                  { title: 'Descrição', dataIndex: 'descricao', render: (v: string | null) => v ?? '—' },
                ]}
                campos={
                  <>
                    <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}>
                      <Input autoFocus />
                    </Form.Item>
                    <Form.Item name="descricao" label="Descrição">
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
                podeGerir={gerir}
                podeEliminar={eliminar}
                pesquisa={(r) => r.nome}
                colunas={[{ title: 'Nome', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> }]}
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
