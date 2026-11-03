# Outbox transaccional e execuções idempotentes (ADR-080)

Guia curto para quem acrescenta um efeito externo (e-mail, notificação, integração) a uma escrita de negócio.

## Quando usar

Sempre que uma escrita na base de dados tem de ter um efeito FORA dela (pôr um e-mail na fila, chamar um serviço, enviar um webhook) e esse efeito
não pode perder-se nem acontecer sem a escrita. Não se faz «grava e depois envia» (um crash entre os dois perde o efeito ou, se se envia primeiro, repete-o).
Faz-se: **grava o evento na MESMA transacção** e deixa o publicador entregá-lo.

## Como acrescentar um evento

1. **Regista o evento dentro da transacção de negócio** (nunca depois do COMMIT):

   ```php
   DB::transaction(function () use ($pedido) {
       $pedido->update(['estado' => 'APROVADO']);
       Outbox::registar('pedido.aprovado', ['pedido_id' => $pedido->id, 'total' => '1500.00'], "pedido-aprovado:{$pedido->id}", $pedido->empresa_id, 'pedido', $pedido->id);
   });
   ```

   - **Tipo** (`pedido.aprovado`): até 60 caracteres, é o que escolhe o manipulador.
   - **Carga**: só dados serializáveis (escalares, listas). **Nunca objectos Eloquent** e **nunca segredos** (palavras-passe, tokens): a carga fica em claro na base de dados.
   - **Chave estável** (`chave`, até 150): identifica o facto de negócio, não a tentativa. A mesma `(empresa, chave)` nunca cria dois eventos (`ON CONFLICT DO NOTHING`; devolve `null` se já existia). Exemplos: `email:ticket-criado:{id}`, `rh:{evento}:{agregado}:{destinatario}`. Se o mesmo facto pode legitimamente repetir-se (ex.: uma segunda suspensão), junta um elemento que o distinga (data, versão, uuid).
   - **Empresa**: `empresa_id` da empresa a cujo contexto o manipulador deve correr; `null` = evento global da plataforma (sem empresa).
   - Sem transacção aberta, `registar` abre uma. Depois do COMMIT tenta publicar logo; se falhar, o agendador apanha-o.

2. **Escreve o manipulador** (idempotente!) e regista-o em `config/erp.php`:

   ```php
   final class PedidoAprovadoManipulador implements ManipuladorOutbox
   {
       public function tipo(): string { return 'pedido.aprovado'; }

       public function manipular(array $carga, OutboxEvento $evento): void
       {
           // corre com a empresa do evento activa (ContextoEmpresa::executarComo)
           // IDEMPOTENTE: pode ser chamado mais de uma vez para o mesmo evento
       }
   }
   ```

   ```php
   'outbox' => ['manipuladores' => ['pedido.aprovado' => \App\Support\Outbox\Manipuladores\PedidoAprovadoManipulador::class]],
   ```

   Nota: as chaves de `manipuladores` têm pontos (`notificacao.rh`): o publicador lê o mapa inteiro, não usa `config('erp.outbox.manipuladores.tipo')`.

3. **Testa**: rollback ⇒ nenhum evento; commit ⇒ efeito produzido uma só vez; reentrega do mesmo evento ⇒ sem duplicar; falha ⇒ PENDENTE com tentativas+1.
   `tests/Feature/Outbox/OutboxTest.php` tem um `ManipuladorDeTeste` pronto a copiar.

## Regras

- **Entrega «pelo menos uma vez».** O manipulador pode correr mais de uma vez para o mesmo evento (crash depois do efeito e antes de o evento ficar PUBLICADO). Torna-o idempotente: uma chave natural (`INSERT … ON CONFLICT`, índice único), consultar antes, ou um marcador por evento (`$evento->id`).
- **Lançar uma excepção = pedir nova tentativa.** Recuo exponencial com jitter: 30 s, 2 min, 10 min, 30 min, 2 h, 6 h, 12 h. À 8.ª falha (`erp.outbox.max_tentativas`) o evento fica MORTO e os administradores da empresa recebem uma notificação URGENTE. Um erro que nunca vai passar (dados inválidos) é melhor tratado dentro do manipulador do que lançado.
- **O manipulador corre fora de qualquer transacção do publicador**: abre as suas se precisar. Se cria vários registos, faz cada destinatário na sua transacção e põe a chave de idempotência no próprio registo (ver `NotificacaoRHManipulador`, `notificacoes_sistema.chave_origem`).
- **Um manipulador pode registar outros eventos** (o `notificacao.rh` regista `email.enviar` na mesma transacção da notificação).
- **E-mails**: `Outbox::email(Mailable::class, $para, [argumentos nomeados], $chave)`; o Mailable tem de constar em `erp.outbox.emails_permitidos` e os argumentos são os do construtor (escalares). **Não uses para e-mails com segredos.**
- **Não migrar**: o envio à AGT (tem `agt_submissoes` e `submissionUUID` fixo) e os webhooks da AGT (persistem `EventoWebhookAgt`).
- **Ordem**: não há ordem garantida entre eventos. Se a ordem importa, põe-na na carga (versão) e trata-a no manipulador.

## Ciclo de vida e tabela

`outbox_eventos`: `estado` PENDENTE → PUBLICADO | MORTO; `tentativas`; `proxima_tentativa_em` (recuo); `reservado_ate` (lease de 2 min do publicador); `ultimo_erro`;
`publicado_em`. Índice único `(coalesce(empresa_id,0), chave)`. RLS normal por tenant (eventos globais visíveis a todos). PUBLICADOS limpam-se com 14 dias; MORTOS ficam.

## Operação

```sh
php artisan erp:outbox:estado                 # pendentes, o mais antigo, mortos e o detalhe por tipo (sai com 1 se houver mortos)
php artisan erp:outbox:publicar --max=100     # publica já os pendentes devidos (corre de minuto a minuto no scheduler)
php artisan erp:outbox:reprocessar 123        # volta um MORTO a PENDENTE (tentativas a 0), depois de corrigida a causa
php artisan erp:outbox:reprocessar --mortos   # todos os mortos
php artisan erp:outbox:limpar --dias=14       # apaga PUBLICADOS antigos (diário, 03:10)
php artisan erp:idempotencia:limpar --dias=30 # apaga execuções idempotentes concluídas antigas (diário, 03:20)
```

`GET /api/saude` → `dados.informacao.outbox`: `AVISO` se o pendente mais antigo tem mais de 10 minutos ou há MORTOS (nunca dá 503 por isto).
Para ver o motivo de um morto: `SELECT id, tipo, chave, tentativas, ultimo_erro FROM outbox_eventos WHERE estado = 'MORTO';`.

## Jobs repetíveis (`ExecucaoIdempotente`)

Para um job de fila com `tries > 1` cujo efeito não pode repetir-se, envolve o trabalho em
`app(ExecucaoIdempotente::class)->executar($empresaId, $chave, fn (int $tentativa) => [...resultado...])`:

- a chave é determinística e distingue uma NOVA operação do utilizador da retentativa da mesma (lote ou uuid do despacho);
- depois de sucesso devolve o resultado guardado sem voltar a executar; com outro worker em curso lança `ExecucaoEmCursoException` (`$this->release(15)`);
- `ErroNegocio` é um resultado (`CONCLUIDO`); outra excepção propaga e a fila repete (o `tentativa` > 1 permite tratar «já feito» como sucesso na retoma);
- implementa `failed(Throwable)` para o ecrã de progresso mostrar a falha definitiva.

Exemplos: `ContabilizarVendaLoteJob` e `ContabilizarPropostaAcrescimoJob`. Onde repetir não é seguro, `tries = 1` com um comentário a dizer porquê.
