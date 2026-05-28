# Setup: Grupo "equipment_keeper" para Gestores

## Objetivo
Documentar o procedimento para criar e configurar o grupo `equipment_keeper` que autoriza usuários a executar checkout/checkin de itens reserváveis no plugin itencheckinout.

## Relacionamento Conceitual
- **Ator tipo B (Gestor/Validador)**: Usuário que faz parte do grupo `equipment_keeper`.
- **Direito concedido**: Checkout/Checkin de qualquer item reservável (não limitado a sua própria reserva).
- **Escopo**: Todos os itens em todas as entidades (respeitando hierarquia de entidades do GLPI).

## Passo 1: Criar Grupo no GLPI

### Via Admin UI (Recomendado para usuários)
1. Acesse: **Administration > Users, groups and roles > Groups**
2. Clique no botão **Add** (ou equivalente)
3. Preencha os campos:
   - **Name**: `equipment_keeper`
   - **Comments**: `Grupo para gestores que realizam checkout/checkin de equipamentos`
   - **Parent group**: deixe vazio (raiz)
   - **Visible in hierarchy**: marcado
4. Clique **Save**

### Via SQL (Para ambientes automatizados/CI)
```sql
INSERT INTO glpi_groups (name, comment, level, date_creation, date_mod)
VALUES ('equipment_keeper', 'Grupo para gestores que realizam checkout/checkin de equipamentos', 1, NOW(), NOW());
```

## Passo 2: Atribuir Usuários ao Grupo

### Via Admin UI
1. Acesse: **Administration > Users, groups and roles > Users**
2. Selecione o usuário que será gestor
3. Na seção **Groups**, adicione o grupo `equipment_keeper` (pode estar em uma aba "Groups" ou similar)
4. Salve o usuário

### Via Relação M:M (SQL)
Após criar o grupo, obtenha seu ID:
```sql
SELECT id FROM glpi_groups WHERE name = 'equipment_keeper';
```

Atribua usuários ao grupo:
```sql
INSERT INTO glpi_groups_users (groups_id, users_id, date_creation, date_mod)
SELECT 
  (SELECT id FROM glpi_groups WHERE name = 'equipment_keeper'),
  id,
  NOW(),
  NOW()
FROM glpi_users
WHERE login IN ('usuario1', 'usuario2'); -- substitua pelos logins reais
```

## Passo 3: Validar Configuração no Plugin

O plugin itencheckinout realizará a seguinte verificação na autorização:
1. Recupera o grupo `equipment_keeper` por nome.
2. Verifica se o usuário atual é membro do grupo.
3. Se sim, permite checkout/checkin de qualquer item (respeitando entidades).

Código de validação (lado plugin):
```php
// Pseudocódigo
$equipment_keeper_group = Group::search(['name' => 'equipment_keeper'], 1);
if ($equipment_keeper_group) {
    $is_manager = $user->isMemberOf($equipment_keeper_group['id']);
}
```

## Passo 4: Teste de Funcionalidade

### Cenário 1: Usuário comum (não membro do grupo)
1. Login com usuário NÃO atribuído ao `equipment_keeper`
2. Acesse a tela de reservable items
3. **Esperado**: Botões de Checkout/Checkin devem estar visíveis mas **desabilitados** para itens que não são de sua reserva

### Cenário 2: Gestor (membro do grupo)
1. Login com usuário atribuído ao `equipment_keeper`
2. Acesse a tela de reservable items
3. **Esperado**: Botões de Checkout/Checkin devem estar **habilitados** para todos os itens com reservas ativas

### Cenário 3: Owner (dono da reserva)
1. Login com usuário que fez a reserva
2. Acesse a tela de reservable items
3. **Esperado**: Botões de Checkout/Checkin devem estar **habilitados** para sua própria reserva, independentemente do grupo

## Passo 5: Limpeza/Remoção

Se necessário remover o grupo:

### Via Admin UI
1. Acesse: **Administration > Users, groups and roles > Groups**
2. Localize `equipment_keeper`
3. Clique no X ou botão de delete
4. Confirme

### Via SQL
```sql
-- Remover membros do grupo (opcional, se quiser cascata)
DELETE FROM glpi_groups_users
WHERE groups_id = (SELECT id FROM glpi_groups WHERE name = 'equipment_keeper');

-- Remover o grupo
DELETE FROM glpi_groups WHERE name = 'equipment_keeper';
```

## Referência: Estrutura de Dados

### Tabela: glpi_groups
| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT | PK |
| name | VARCHAR | Nome do grupo (aqui: `equipment_keeper`) |
| comment | TEXT | Descrição |
| level | INT | Nível na hierarquia |
| date_creation | DATETIME | Data de criação |
| date_mod | DATETIME | Data de modificação |

### Tabela: glpi_groups_users (Relação M:M)
| Campo | Tipo | Descrição |
|-------|------|-----------|
| id | INT | PK |
| groups_id | INT | FK para glpi_groups |
| users_id | INT | FK para glpi_users |
| date_creation | DATETIME | Data de criação |
| date_mod | DATETIME | Data de modificação |

## Integração com Plugin itencheckinout

O plugin validará o grupo no seguinte fluxo:
1. Ao processar ação de checkout/checkin
2. Checa se `users_id` é o dono da reserva OU
3. Checa se `users_id` é membro do grupo `equipment_keeper`
4. Se nenhuma condição atender, retorna erro 403 (Unauthorized)

Veja detalhes em: `/docs/Technical-Mapping-and-Implementation-Plan.md` (seção 7.2)
