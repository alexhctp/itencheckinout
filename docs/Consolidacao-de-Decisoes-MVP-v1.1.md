# Consolidação de Decisões - MVP v1.1

**Data**: 6 de maio de 2026  
**Status**: ✅ Todas as 4 decisões em aberto estão respondidas e consolidadas.

## Sumário Executivo

| Decisão | Resposta | Status | Referência |
|---------|----------|--------|-----------|
| 1. Gestor/Validador | Grupo GLPI `equipment_keeper` | ✅ Decidido | [Setup-equipment_keeper-Group.md](Setup-equipment_keeper-Group.md) |
| 2. Relacionamento Reserva-Item | Schema mapeado: FK `reservations_id` + `reservationitems_id` | ✅ Confirmado | [Technical-Mapping-and-Implementation-Plan.md](Technical-Mapping-and-Implementation-Plan.md#3-data-model) |
| 3. Timezone | Usar `Session::getCurrentTime()` (respeita env GLPI) | ✅ Decidido | [MVP-v1-checkin-checkout.md](MVP-v1-checkin-checkout.md) |
| 4. Reserva Expirada | Tolerância parametrizável pós-expiração | ✅ Decidido | [MVP-v1-checkin-checkout.md](MVP-v1-checkin-checkout.md) |

---

## Detalhes por Decisão

### 1️⃣ Gestor/Validador - Grupo `equipment_keeper`

**Recomendação do usuário**:
> O usuário gestor deve estar atrelado a um grupo chamado "equipment_keeper". Necessário colocar o passo para a criação desse grupo e fazer o link entre usuário e grupo na documentação.

**Ação tomada**:
- ✅ Criado documento completo: `Setup-equipment_keeper-Group.md`
- ✅ Inclusos passos via UI e via SQL
- ✅ Inclusos procedimentos de teste e validação
- ✅ Integração com plugin já documentada no plano técnico

**Fluxo de autorização implementado**:
```
Acesso ao Checkout/Checkin = Dono da Reserva OU Membro do grupo "equipment_keeper"
```

---

### 2️⃣ Relacionamento Reserva-Item - Schema Confirmado

**Recomendação do usuário**:
> Não tenho a informação sobre relacionamento. Será necessário uma análise no código fonte para identificar os devidos relacionamentos.

**Ação tomada**:
- ✅ Análise do código-fonte realizada
- ✅ Schema mapeado e confirmado em `Technical-Mapping-and-Implementation-Plan.md`

**Relacionamento confirmado**:
```
glpi_reservationitems (item reservável)
  - id (PK)
  - itemtype (Computer, Monitor, etc)
  - items_id (FK para item concreto)

glpi_reservations (reserva concreta)
  - id (PK)
  - reservationitems_id (FK para ReservationItem)
  - users_id (quem reservou)
  - begin, end (janela de tempo)

Fluxo MVP: Para cada ReservationItem ID da tela, encontre Reservation ativa com NOW ∈ [begin, end]
```

---

### 3️⃣ Timezone - Usar do Ambiente GLPI

**Recomendação do usuário**:
> Considerar o timezone do ambiente. Posteriormente fazemos o refinamento do timezone.

**Ação tomada**:
- ✅ Definido uso de `Session::getCurrentTime()` (já respeita config GLPI)
- ✅ Refatoração de timezone postponida para v1.2+

**Implementação confirmada**:
- O GLPI já gerencia timezone globalmente
- MVP usará `Session::getCurrentTime()` para validar janelas de checkout
- Sem necessidade de configuração adicional no plugin

---

### 4️⃣ Reserva Expirada Sem Checkout - Tolerância Parametrizável

**Recomendação do usuário**:
> Considere uma tolerância parametrizavel. Após o periodo de tolerância, liberar o equipamento para locação.

**Ação tomada**:
- ✅ Definida nova config plugin: `tolerance_minutes_after_end`
- ✅ Lógica: após `reservation.end + tolerance`, rejeita checkout
- ✅ Mensagens de erro específicas para este cenário

**Implementação confirmada**:
```php
// Pseudocódigo
$config_tolerance = $CFG_GLPI['plugin_itencheckinout_tolerance_minutes_after_end'] ?? 60;
$expiry_deadline = strtotime($reservation->end) + ($config_tolerance * MINUTE_TIMESTAMP);

if ($current_time > $expiry_deadline) {
    return error("Item checkout period has expired. This equipment is now available for the next reservation.");
}
```

---

## Próximas Ações

### ✅ Concluído
- [x] MVP v1 funcional definido
- [x] Plano técnico criado
- [x] Todas as 4 decisões em aberto respondidas
- [x] Documentação de setup criada
- [x] Schema mapeado e confirmado

### 🚀 Pronto para Implementação
1. **Fase 1**: Setup hooks + CSRF (setup.php)
2. **Fase 2**: Tabela de movimentos + install (hook.php)
3. **Fase 3**: Service layer com validações (MovementService.php)
4. **Fase 4**: Endpoint backend (front/movement.php)
5. **Fase 5**: Debug page temporária (front/debug.php)
6. **Fase 6**: JS injetor de botões (public/js/reservationitem-actions.js)
7. **Fase 7**: Testes unitários
8. **Fase 8**: E2E manual

### 📚 Documentação Criada
- `MVP-v1-checkin-checkout.md` - Escopo e critérios de aceite
- `Technical-Mapping-and-Implementation-Plan.md` - Arquitetura e plano faseado
- `Setup-equipment_keeper-Group.md` - Procedimento de setup do grupo gestor
- `Consolidacao-de-Decisoes-MVP-v1.1.md` - Este documento

---

## Matriz de Rastreabilidade

| Requisito | MVP Doc | Tech Plan | Setup Doc | Status |
|-----------|---------|-----------|-----------|--------|
| 2 botões por linha | ✅ | ✅ | - | Pronto |
| Atores: Owner + Manager | ✅ | ✅ | ✅ | Pronto |
| Checkout: só na janela | ✅ | ✅ | - | Pronto |
| Checkin: só após checkout | ✅ | ✅ | - | Pronto |
| UX: msg final sem modal | ✅ | ✅ | - | Pronto |
| Auditoria: user, time, reservation | ✅ | ✅ | - | Pronto |
| Grupo equipment_keeper | ✅ | ✅ | ✅ | Pronto |
| Timezone: GLPI env | ✅ | ✅ | - | Pronto |
| Tolerância pós-exp | ✅ | ✅ | - | Pronto |

---

## Checklist Pré-Codificação

- [x] MVP funcional definido com aceitação clara
- [x] Todas as perguntas respondidas pelo usuário
- [x] Schema GLPI completamente mapeado
- [x] Decisões documentadas (grupo, timezone, tolerância)
- [x] Plano técnico faseado e pronto
- [x] Procedimento de setup criado
- [x] Riscos identificados
- [ ] Começar Fase 1: setup.php
- [ ] Começar Fase 2: hook.php + migrations

**Status Geral**: ✅ **PRONTO PARA IMPLEMENTAÇÃO**

---

## Referências Rápidas

```bash
# Visualizar documentação
cat /var/www/html/glpi/plugins/itencheckinout/docs/MVP-v1-checkin-checkout.md
cat /var/www/html/glpi/plugins/itencheckinout/docs/Technical-Mapping-and-Implementation-Plan.md
cat /var/www/html/glpi/plugins/itencheckinout/docs/Setup-equipment_keeper-Group.md

# Iniciar implementação
cd /var/www/html/glpi/plugins/itencheckinout
# Fase 1: actualizar setup.php com hooks + csrf
# Fase 2: criar migration table em hook.php
# ...
```
