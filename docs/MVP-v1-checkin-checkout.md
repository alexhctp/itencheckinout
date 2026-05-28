# MVP v1 - Check-in / Check-out em Reservation Items

## Objetivo
Adicionar dois botoes por linha na listagem de itens reservaveis em `front/reservationitem.php` para permitir `Checkout` (retirada) e `Checkin` (devolucao).

Tela alvo atual:
- Colunas: Reservable Item, Location, Comment, Entity, Booking Calendar
- Nova acao por linha: Checkout / Checkin

## Escopo funcional v1
1. Exibir botoes por linha da listagem de itens reservaveis.
2. Atores permitidos:
   - Usuario que fez a reserva.
   - Gestor/validador.
3. Regra de habilitacao do `Checkout`:
   - Somente no intervalo data/hora da reserva.
4. Regra de habilitacao do `Checkin`:
   - Somente apos existir um checkout previo do mesmo item/reserva.
5. UX minima:
   - Sem modal de confirmacao.
   - Exibir apenas mensagem final de sucesso/erro.
6. Auditoria minima obrigatoria:
   - Usuario que executou a acao.
   - Data/hora da acao.
   - Reserva relacionada.

## Fora do escopo v1
- Acao em massa.
- Comentario obrigatorio.
- Registro de condicao fisica do item no retorno.
- Fluxos complexos de aprovacao.
- Confirmacoes em modal e experiencia avancada.

## Regras de estado (modelo simples)
Estados logicos por reserva/item:
- `reserved`: reserva criada e dentro/fora da janela.
- `checked_out`: item retirado.
- `checked_in`: item devolvido.

Transicoes permitidas no MVP:
- `reserved` -> `checked_out` (se horario atual dentro da janela da reserva e ator autorizado).
- `checked_out` -> `checked_in` (se ator autorizado).

Transicoes bloqueadas:
- `reserved` -> `checked_in`.
- `checked_in` -> `checked_in`.
- `checked_out` -> `checked_out`.

## Criterios de aceite v1
1. Em cada linha elegivel da tela, os botoes aparecem com estado coerente (habilitado/desabilitado).
2. Usuario nao autorizado nao consegue executar acao (UI e backend).
3. Checkout fora da janela da reserva falha com mensagem de erro.
4. Checkin sem checkout previo falha com mensagem de erro.
5. Cada acao bem-sucedida grava auditoria minima.
6. Mensagem final de sucesso/erro exibida apos tentativa.

## Proposta tecnica inicial (sem implementacao ainda)
- Hook para injetar acoes na listagem de reservation items.
- Endpoint dedicado no plugin para processar `checkout` e `checkin`.
- Validacoes sempre no backend (nao confiar no estado da UI).
- Tabela de log de movimentos do plugin (acao, reserva, item, ator, timestamp).

## Decisoes v1.1 - Consolidadas

### 1. Gestor/Validador - Grupo "equipment_keeper"
**Resposta**: O usuário gestor deve estar atrelado a um grupo denominado `equipment_keeper`.
- Este grupo será criado manualmente no GLPI admin.
- Usuários atribuídos a este grupo ganham direito de checkout/checkin de todos os itens.
- Necessário documentar passos de criação desse grupo e link com usuários.

### 2. Relacionamento Reserva-Item - Confirmado no Schema
**Resposta**: Relacionamento exato mapeado:
- `glpi_reservationitems` (tabela do ReservationItem):
  - Colunas: `id` (PK), `itemtype` (tipo: Computer|Monitor|etc), `items_id` (FK item real), `comment`, `is_active`, `entities_id`
  - Uma linha = um item reservável
- `glpi_reservations` (tabela do Reservation):
  - Colunas: `id` (PK), `reservationitems_id` (FK ReservationItem), `users_id` (quem reservou), `begin`, `end`, `comment`, `group` (periodicidade)
  - Uma linha = uma reserva concreta
- Lógica MVP: para cada ReservationItem ID, localizar Reservation ativas cujo NOW está em [begin, end]

### 3. Timezone - Usar do Ambiente
**Resposta**: Considerar o timezone configurado no ambiente GLPI.
- Usar `Session::getCurrentTime()` nativo do GLPI (já respeita config timezone).
- Refinamentos de UTC/offset podem ser feitos em v1.2.

### 4. Reserva Expirada Sem Checkout - Tolerância Parametrizavel
**Resposta**: Implementar tolerância após fim da janela.
- Campo parametrizável em config do plugin: `tolerance_minutes_after_end` (ex: 60 minutos).
- Após `end + tolerance`, o equipamento é liberado automaticamente para próxima locação.
- Comportamento: checkout ainda permitido durante a tolerância; após, rejeita com mensagem clara.

## Metodo de detalhamento nas proximas interacoes
Para cada regra nova, vamos preencher este mini-template:
- Contexto:
- Ator:
- Pre-condicao:
- Acao:
- Resultado esperado:
- Mensagem para usuario:
- Log de auditoria:

## Next Actions
1. Criar documento de setup: como criar grupo "equipment_keeper" e atribuir usuários.
2. Atualizar plano técnico com regras de timezone e tolerância pós-expiração.
3. Iniciar implementação Fase 1: setup.php com hooks + composer.
