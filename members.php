<?php
// members.php
require_once __DIR__ . '/config.php';

// Apenas superadmin, administrador e financeiro de cada coral podem gerenciar membros
require_login();
$loggedUser = get_logged_user();
if (!is_superadmin() && !in_array($loggedUser['role'], ['administrador', 'financeiro'])) {
    set_flash_message('error', 'Você não tem permissão para acessar esta página.');
    header("Location: dashboard.php");
    exit;
}

$error = null;
$success = null;
$action = $_GET['action'] ?? 'list';
$edit_id = intval($_GET['id'] ?? 0);

// Carregar corais para dropdown se for superadmin
$choirs = [];
if (is_superadmin()) {
    $choirs = $pdo->query("SELECT id, name FROM choirs ORDER BY name ASC")->fetchAll();
}

// Inserir / Editar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $voice_type = trim($_POST['voice_type'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $status = trim($_POST['status'] ?? 'active');
    
    $cpf = trim($_POST['cpf'] ?? '');
    $rg = trim($_POST['rg'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $address_number = trim($_POST['address_number'] ?? '');
    $address_neighborhood = trim($_POST['address_neighborhood'] ?? '');
    $address_zip_code = trim($_POST['address_zip_code'] ?? '');
    $address_city = trim($_POST['address_city'] ?? '');
    $address_state = trim($_POST['address_state'] ?? '');
    
    // Suporta: hidden da máscara (1234.56), campo display (1.234,56) ou numérico puro
    $raw_balance = $_POST['balance'] ?? $_POST['balance_display'] ?? '0';
    $raw_balance = str_replace('.', '', $raw_balance);
    $raw_balance = str_replace(',', '.', $raw_balance);
    $balance = floatval($raw_balance);
    
    if (is_superadmin()) {
        $choir_id = intval($_POST['choir_id'] ?? 0);
    } else {
        $choir_id = $loggedUser['choir_id'];
    }
    
    if (empty($name) || empty($email) || empty($username) || empty($choir_id)) {
        $error = 'Por favor, preencha todos os campos obrigatórios (*).';
    } else {
        // Verificar duplicados
        $stmt = $pdo->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ? LIMIT 1");
        $stmt->execute([$email, $username, $edit_id]);
        if ($stmt->fetch()) {
            $error = 'E-mail ou Nome de Usuário já está em uso.';
        } else {
            try {
                if ($action === 'new') {
                    if (empty($password)) {
                        $error = 'A senha é obrigatória para novos membros.';
                    } else {
                        $member_code = get_or_generate_member_code($pdo, $voice_type, $choir_id);
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("INSERT INTO users (choir_id, name, email, phone, voice_type, member_code, username, password, role, status, balance, cpf, rg, address, address_number, address_neighborhood, address_zip_code, address_city, address_state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'membro', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$choir_id, $name, $email, $phone, $voice_type, $member_code, $username, $hashedPassword, $status, $balance, $cpf, $rg, $address, $address_number, $address_neighborhood, $address_zip_code, $address_city, $address_state]);
                        $new_member_id = $pdo->lastInsertId();
                        if ($status === 'active') {
                            sync_recurring_billings($pdo, $choir_id, $new_member_id);
                        }
                        set_flash_message('success', "Membro '$name' cadastrado com sucesso.");
                        header("Location: members.php");
                        exit;
                    }
                } elseif ($action === 'edit' && $edit_id > 0) {
                    // Verificar se pertence ao mesmo coral
                    if (!is_superadmin()) {
                        $stmtCheck = $pdo->prepare("SELECT choir_id FROM users WHERE id = ?");
                        $stmtCheck->execute([$edit_id]);
                        if ($stmtCheck->fetchColumn() != $loggedUser['choir_id']) {
                            set_flash_message('error', 'Acesso negado.');
                            header("Location: members.php");
                            exit;
                        }
                    }
                    
                    // Buscar dados antigos para ver se voice_type ou choir_id mudou, ou se não tem código
                    $stmtOld = $pdo->prepare("SELECT choir_id, voice_type, member_code FROM users WHERE id = ?");
                    $stmtOld->execute([$edit_id]);
                    $old_member = $stmtOld->fetch();
                    
                    $member_code = $old_member['member_code'];
                    if (!$member_code || $old_member['choir_id'] != $choir_id || $old_member['voice_type'] != $voice_type) {
                        $member_code = get_or_generate_member_code($pdo, $voice_type, $choir_id, $edit_id);
                    }
                    
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE users SET choir_id = ?, name = ?, email = ?, phone = ?, voice_type = ?, member_code = ?, username = ?, password = ?, status = ?, balance = ?, cpf = ?, rg = ?, address = ?, address_number = ?, address_neighborhood = ?, address_zip_code = ?, address_city = ?, address_state = ? WHERE id = ?");
                        $stmt->execute([$choir_id, $name, $email, $phone, $voice_type, $member_code, $username, $hashedPassword, $status, $balance, $cpf, $rg, $address, $address_number, $address_neighborhood, $address_zip_code, $address_city, $address_state, $edit_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET choir_id = ?, name = ?, email = ?, phone = ?, voice_type = ?, member_code = ?, username = ?, status = ?, balance = ?, cpf = ?, rg = ?, address = ?, address_number = ?, address_neighborhood = ?, address_zip_code = ?, address_city = ?, address_state = ? WHERE id = ?");
                        $stmt->execute([$choir_id, $name, $email, $phone, $voice_type, $member_code, $username, $status, $balance, $cpf, $rg, $address, $address_number, $address_neighborhood, $address_zip_code, $address_city, $address_state, $edit_id]);
                    }
                    if ($status === 'active') {
                        sync_recurring_billings($pdo, $choir_id, $edit_id);
                    }
                    set_flash_message('success', "Membro '$name' atualizado com sucesso.");
                    header("Location: members.php");
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar membro: ' . $e->getMessage();
            }
        }
    }
}

// Ações rápidas (Aprovar / Excluir)
if ($action === 'approve' && $edit_id > 0) {
    try {
        if (!is_superadmin()) {
            $stmtCheck = $pdo->prepare("SELECT choir_id FROM users WHERE id = ?");
            $stmtCheck->execute([$edit_id]);
            if ($stmtCheck->fetchColumn() != $loggedUser['choir_id']) {
                set_flash_message('error', 'Acesso negado.');
                header("Location: members.php");
                exit;
            }
        }
        
        // Buscar choir_id do membro para rodar a sincronização
        $stmtChoir = $pdo->prepare("SELECT choir_id FROM users WHERE id = ?");
        $stmtChoir->execute([$edit_id]);
        $member_choir_id = $stmtChoir->fetchColumn();

        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'membro'");
        $stmt->execute([$edit_id]);

        if ($member_choir_id) {
            sync_recurring_billings($pdo, $member_choir_id, $edit_id);
        }
        set_flash_message('success', 'Membro aprovado com sucesso!');
    } catch (PDOException $e) {
        set_flash_message('error', 'Erro ao aprovar membro: ' . $e->getMessage());
    }
    header("Location: members.php");
    exit;
}

if ($action === 'delete' && $edit_id > 0) {
    try {
        if (!is_superadmin()) {
            $stmtCheck = $pdo->prepare("SELECT choir_id FROM users WHERE id = ?");
            $stmtCheck->execute([$edit_id]);
            if ($stmtCheck->fetchColumn() != $loggedUser['choir_id']) {
                set_flash_message('error', 'Acesso negado.');
                header("Location: members.php");
                exit;
            }
        }
        
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'membro'");
        $stmt->execute([$edit_id]);
        set_flash_message('success', 'Membro removido com sucesso.');
    } catch (PDOException $e) {
        set_flash_message('error', 'Erro ao excluir membro: ' . $e->getMessage());
    }
    header("Location: members.php");
    exit;
}

// Buscar dados se for edição
$member_data = null;
if ($action === 'edit' && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'membro'");
    $stmt->execute([$edit_id]);
    $member_data = $stmt->fetch();
    if (!$member_data) {
        set_flash_message('error', 'Membro não encontrado.');
        header("Location: members.php");
        exit;
    }
    if (!is_superadmin() && $member_data['choir_id'] != $loggedUser['choir_id']) {
        set_flash_message('error', 'Acesso negado.');
        header("Location: members.php");
        exit;
    }
}

// Listar membros (cantores) com busca e paginação
$members = [];
$total_records = 0;
$limit = 15;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$search = trim($_GET['search'] ?? '');

if ($action === 'list') {
    try {
        $params = [];
        $where_clauses = ["u.role = 'membro'"];
        
        if (!is_superadmin()) {
            $where_clauses[] = "u.choir_id = :logged_choir_id";
            $params[':logged_choir_id'] = $loggedUser['choir_id'];
        }
        
        if (!empty($search)) {
            $where_clauses[] = "(u.name LIKE :search 
                                OR u.email LIKE :search 
                                OR u.voice_type LIKE :search 
                                OR u.member_code LIKE :search 
                                OR c.name LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        
        $where_sql = implode(' AND ', $where_clauses);
        
        // Obter contagem total
        $count_sql = "SELECT COUNT(*) FROM users u 
                      LEFT JOIN choirs c ON u.choir_id = c.id 
                      WHERE $where_sql";
        $stmtCount = $pdo->prepare($count_sql);
        $stmtCount->execute($params);
        $total_records = intval($stmtCount->fetchColumn());
        
        // Obter registros paginados
        $select_sql = "SELECT u.*, c.name as choir_name FROM users u 
                       LEFT JOIN choirs c ON u.choir_id = c.id 
                       WHERE $where_sql 
                       ORDER BY u.status DESC, u.id DESC 
                       LIMIT $limit OFFSET $offset";
        $stmtSelect = $pdo->prepare($select_sql);
        $stmtSelect->execute($params);
        $members = $stmtSelect->fetchAll();
    } catch (PDOException $e) {
        $error = 'Erro ao buscar membros: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>🎤</span> Cantores (Membros)
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Administração dos cantores do coral, aprovação de auto-cadastros e gerenciamento de saldos.
        </p>
    </div>
    
    <?php if ($action === 'list'): ?>
        <a href="members.php?action=new" 
           class="px-3.5 py-2 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
            + Novo Cantor
        </a>
    <?php else: ?>
        <a href="members.php" 
           class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-white font-semibold rounded-lg text-xs transition-all">
            Voltar para a Lista
        </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- ==============================================
     FORMULÁRIO: CADASTRAR / EDITAR
     ============================================== -->
<?php if ($action === 'new' || $action === 'edit'): ?>
    <div class="max-w-xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
        <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white mb-4">
            <?= $action === 'edit' ? 'Editar Cadastro de Cantor' : 'Cadastrar Novo Cantor' ?>
        </h2>
        
        <form action="members.php?action=<?= $action ?><?= $action === 'edit' ? '&id=' . $edit_id : '' ?>" method="POST" class="space-y-4">
            
            <?php if (is_superadmin()): ?>
                <div>
                    <label for="choir_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Vincular ao Coral *</label>
                    <select name="choir_id" id="choir_id" required
                            class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        <option value="">Selecione...</option>
                        <?php foreach ($choirs as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= isset($member_data['choir_id']) && $member_data['choir_id'] == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome Completo *</label>
                    <input type="text" name="name" id="name" required value="<?= htmlspecialchars($member_data['name'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="Ex: Roberto Silva">
                </div>
                <div>
                    <label for="voice_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Naipe / Tipo de Voz</label>
                    <select name="voice_type" id="voice_type"
                            class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        <option value="">Não sei / Indefinido</option>
                        <option value="Soprano" <?= isset($member_data['voice_type']) && $member_data['voice_type'] === 'Soprano' ? 'selected' : '' ?>>Soprano</option>
                        <option value="Contralto" <?= isset($member_data['voice_type']) && $member_data['voice_type'] === 'Contralto' ? 'selected' : '' ?>>Contralto</option>
                        <option value="Tenor" <?= isset($member_data['voice_type']) && $member_data['voice_type'] === 'Tenor' ? 'selected' : '' ?>>Tenor</option>
                        <option value="Baixo" <?= isset($member_data['voice_type']) && $member_data['voice_type'] === 'Baixo' ? 'selected' : '' ?>>Baixo</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">E-mail *</label>
                    <input type="email" name="email" id="email" required value="<?= htmlspecialchars($member_data['email'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="roberto@email.com">
                </div>
                <div>
                    <label for="phone" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Telefone</label>
                    <input type="text" name="phone" id="phone" value="<?= htmlspecialchars($member_data['phone'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="(11) 99999-9999">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100 dark:border-slate-700/50">
                <div>
                    <label for="status" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Status do Cadastro</label>
                    <select name="status" id="status" required
                            class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        <option value="active" <?= isset($member_data['status']) && $member_data['status'] === 'active' ? 'selected' : '' ?>>Ativo (Liberado)</option>
                        <option value="pending" <?= isset($member_data['status']) && $member_data['status'] === 'pending' ? 'selected' : '' ?>>Pendente de Aprovação</option>
                    </select>
                </div>
                <div>
                    <label for="balance" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Saldo em Conta (R$)</label>
                    <input type="text" inputmode="numeric" name="balance" id="balance"
                           data-currency-mask data-allow-zero="true"
                           data-initial-value="<?= htmlspecialchars($member_data['balance'] ?? '0.00') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="0,00">
                </div>
            </div>

            <!-- Seção de Documentação -->
            <div class="pt-4 border-t border-slate-100 dark:border-slate-700/50">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Documentação</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="cpf" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">CPF</label>
                        <input type="text" name="cpf" id="cpf" value="<?= htmlspecialchars($member_data['cpf'] ?? '') ?>" placeholder="000.000.000-00"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    <div>
                        <label for="rg" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Identidade (RG)</label>
                        <input type="text" name="rg" id="rg" value="<?= htmlspecialchars($member_data['rg'] ?? '') ?>" placeholder="Número da identidade"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                </div>
            </div>

            <!-- Seção de Endereço -->
            <div class="pt-4 border-t border-slate-100 dark:border-slate-700/50">
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Endereço Residencial</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label for="address_zip_code" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">CEP</label>
                        <input type="text" name="address_zip_code" id="address_zip_code" value="<?= htmlspecialchars($member_data['address_zip_code'] ?? '') ?>" placeholder="00000-000"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    <div class="md:col-span-2">
                        <label for="address" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Endereço Completo (Rua/Avenida)</label>
                        <input type="text" name="address" id="address" value="<?= htmlspecialchars($member_data['address'] ?? '') ?>" placeholder="Rua, Avenida, etc."
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="address_number" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Número</label>
                        <input type="text" name="address_number" id="address_number" value="<?= htmlspecialchars($member_data['address_number'] ?? '') ?>" placeholder="Ex: 123, Apto 4"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    <div>
                        <label for="address_neighborhood" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Bairro</label>
                        <input type="text" name="address_neighborhood" id="address_neighborhood" value="<?= htmlspecialchars($member_data['address_neighborhood'] ?? '') ?>" placeholder="Ex: Centro"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label for="address_city" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Cidade</label>
                        <input type="text" name="address_city" id="address_city" value="<?= htmlspecialchars($member_data['address_city'] ?? '') ?>" placeholder="Ex: São Paulo"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    <div>
                        <label for="address_state" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Estado (UF)</label>
                        <select name="address_state" id="address_state"
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                            <option value="">Selecione...</option>
                            <?php
                            $ufs = [
                                'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas', 'BA' => 'Bahia',
                                'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo', 'GO' => 'Goiás',
                                'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul', 'MG' => 'Minas Gerais',
                                'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná', 'PE' => 'Pernambuco', 'PI' => 'Piauí',
                                'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte', 'RS' => 'Rio Grande do Sul',
                                'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina', 'SP' => 'São Paulo',
                                'SE' => 'Sergipe', 'TO' => 'Tocantins'
                            ];
                            foreach ($ufs as $uf => $ufName) {
                                $selected = (isset($member_data['address_state']) && $member_data['address_state'] === $uf) ? 'selected' : '';
                                echo "<option value=\"$uf\" $selected>$uf - $ufName</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100 dark:border-slate-700/50">
                <div>
                    <label for="username" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome de Usuário *</label>
                    <input type="text" name="username" id="username" required value="<?= htmlspecialchars($member_data['username'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="robertosilva">
                </div>
                <div>
                    <label for="password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">
                        Senha <?= $action === 'edit' ? '(Em branco para manter)' : '*' ?>
                    </label>
                    <input type="password" name="password" id="password" <?= $action === 'new' ? 'required' : '' ?>
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="Senha de acesso">
                </div>
            </div>
            
            <div class="flex justify-end gap-2 pt-4">
                <a href="members.php"
                   class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                    Cancelar
                </a>
                <button type="submit"
                        class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                    <?= $action === 'edit' ? 'Salvar Alterações' : 'Criar Cantor' ?>
                </button>
            </div>
        </form>
    </div>

<!-- ==============================================
     LISTA DE CANTORAS E CANTORES (MEMBROS)
     ============================================== -->
<?php else: ?>
    <!-- Painel de Filtros e Busca -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 mb-6">
        <form action="members.php" method="GET" class="flex flex-col sm:flex-row gap-4 items-center justify-between">
            <div class="w-full sm:max-w-md relative">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="Pesquise por nome, email, naipe, id..." 
                       class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                <span class="absolute left-3.5 top-3.5 text-slate-400 dark:text-slate-500 text-sm">🔍</span>
            </div>
            
            <div class="flex items-center gap-2 w-full sm:w-auto justify-end">
                <?php if (!empty($search)): ?>
                    <a href="members.php" 
                       class="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-semibold text-xs rounded-lg transition-colors">
                        Limpar
                    </a>
                <?php endif; ?>
                <button type="submit" 
                        class="px-4 py-2 bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs rounded-lg transition-colors shadow-sm">
                    Buscar
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
        <?php if (empty($members)): ?>
            <div class="text-center py-12 text-slate-400">
                Nenhum cantor cadastrado. Clique em "+ Novo Cantor" para começar ou divulgue o link "Cadastre-se" no login.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                    <thead class="bg-slate-50 dark:bg-slate-900/60">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Cantor</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Contato / Naipe</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Coral</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Saldo</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                        <?php foreach ($members as $m): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800 dark:text-white">
                                    <div><?= htmlspecialchars($m['name']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono mt-1 flex items-center gap-1 select-none">
                                        <span>Código:</span>
                                        <?php if (!empty($m['member_code'])): ?>
                                            <span class="inline-flex items-center gap-1 cursor-pointer bg-slate-50 hover:bg-slate-100 dark:bg-slate-900/60 dark:hover:bg-slate-900 text-[10px] text-slate-600 dark:text-slate-300 font-mono px-1.5 py-0.5 rounded border border-slate-200 dark:border-slate-700/50 transition-all active:scale-95" 
                                                  onclick="copyToClipboard('<?= htmlspecialchars($m['member_code']) ?>', this)"
                                                  title="Clique para copiar o código">
                                                <span class="code-text"><?= htmlspecialchars($m['member_code']) ?></span>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5 text-slate-400 copy-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                                </svg>
                                                <svg xmlns="http://www.w3.org/2000/svg" class="h-2.5 w-2.5 text-emerald-500 check-icon hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-slate-400 dark:text-slate-500">Não gerado</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                    <div><?= htmlspecialchars($m['email']) ?></div>
                                    <div class="text-xs text-slate-400">
                                        <?= htmlspecialchars($m['phone'] ?? '-') ?> | 
                                        <span class="font-semibold text-coral-500"><?= htmlspecialchars($m['voice_type'] ?? 'Indefinido') ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                    <?= htmlspecialchars($m['choir_name'] ?? 'Sem Coral') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-bold text-emerald-500">
                                    <?= format_currency($m['balance']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                    <span class="px-2.5 py-0.5 rounded-full font-semibold capitalize
                                        <?= $m['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-950/20 dark:text-green-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300 animate-pulse' ?>">
                                        <?= $m['status'] === 'active' ? 'Ativo' : 'Pendente' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium space-x-2">
                                    <?php if ((is_superadmin() || (is_impersonating() && is_superadmin(get_original_user()))) && $m['id'] != $loggedUser['id']): ?>
                                        <a href="impersonate.php?action=start&id=<?= $m['id'] ?>" class="text-indigo-500 hover:text-indigo-600 font-bold">Usar como</a>
                                    <?php endif; ?>
                                    <?php if ($m['status'] === 'pending'): ?>
                                        <a href="members.php?action=approve&id=<?= $m['id'] ?>" class="text-green-500 hover:text-green-600 font-bold">Aprovar</a>
                                    <?php endif; ?>
                                    <a href="members.php?action=edit&id=<?= $m['id'] ?>" class="text-coral-500 hover:text-coral-600 font-bold">Editar</a>
                                    <a href="members.php?action=delete&id=<?= $m['id'] ?>" onclick="return confirm('Deseja realmente excluir este cantor? Todas as suas cobranças e histórico serão apagados permanentemente.')" class="text-red-500 hover:text-red-600 font-bold">Excluir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Paginação -->
            <?php
            $total_pages = ceil($total_records / $limit);
            if ($total_pages > 1):
            ?>
                <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/60 border-t border-slate-100 dark:border-slate-700/40 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
                    <div class="text-slate-500 dark:text-slate-400 text-center sm:text-left">
                        Mostrando <span class="font-bold text-slate-700 dark:text-slate-300"><?= min($total_records, $offset + 1) ?></span> a 
                        <span class="font-bold text-slate-700 dark:text-slate-300"><?= min($total_records, $offset + count($members)) ?></span> de 
                        <span class="font-bold text-slate-700 dark:text-slate-300"><?= $total_records ?></span> cantores
                    </div>
                    
                    <div class="flex items-center gap-1.5 flex-wrap justify-center">
                        <!-- Botão Anterior -->
                        <?php if ($page > 1): ?>
                            <a href="members.php?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                               class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold transition-all text-slate-600 dark:text-slate-300">
                                Anterior
                            </a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-800 text-slate-300 dark:text-slate-600 font-bold select-none cursor-not-allowed">
                                Anterior
                            </span>
                        <?php endif; ?>
                        
                        <!-- Páginas Numéricas -->
                        <?php
                        $range = 2;
                        for ($i = 1; $i <= $total_pages; $i++):
                            if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)):
                                if ($i == $page):
                        ?>
                                    <span class="px-3 py-1.5 rounded-lg bg-coral-500 text-white font-bold transition-all">
                                        <?= $i ?>
                                    </span>
                        <?php   else: ?>
                                    <a href="members.php?page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                                       class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold transition-all">
                                        <?= $i ?>
                                    </a>
                        <?php
                                endif;
                            elseif (($i == 2 && $page - $range > 2) || ($i == $total_pages - 1 && $page + $range < $total_pages - 1)):
                        ?>
                                <span class="px-2 text-slate-400 select-none">...</span>
                        <?php
                            endif;
                        endfor;
                        ?>
                        
                        <!-- Botão Próximo -->
                        <?php if ($page < $total_pages): ?>
                            <a href="members.php?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" 
                               class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 font-bold transition-all text-slate-600 dark:text-slate-300">
                                Próxima
                            </a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-lg border border-slate-100 dark:border-slate-800 text-slate-300 dark:text-slate-600 font-bold select-none cursor-not-allowed">
                                Próxima
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Máscara CPF: 000.000.000-00
    const cpfInput = document.getElementById('cpf');
    if (cpfInput) {
        cpfInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            if (value.length > 9) {
                value = value.replace(/^(\d{3})(\d{3})(\d{3})(\d{1,2})$/, '$1.$2.$3-$4');
            } else if (value.length > 6) {
                value = value.replace(/^(\d{3})(\d{3})(\d{1,3})$/, '$1.$2.$3');
            } else if (value.length > 3) {
                value = value.replace(/^(\d{3})(\d{1,3})$/, '$1.$2');
            }
            e.target.value = value;
        });
    }

    // Máscara CEP: 00000-000
    const cepInput = document.getElementById('address_zip_code');
    if (cepInput) {
        cepInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 8) value = value.slice(0, 8);
            if (value.length > 5) {
                value = value.replace(/^(\d{5})(\d{1,3})$/, '$1-$2');
            }
            e.target.value = value;
        });
    }
});

function copyToClipboard(text, element) {
    if (!navigator.clipboard) {
        // Fallback para navegadores antigos
        var textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";  // evitar rolagem
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showCopiedState(element);
        } catch (err) {
            console.error('Erro ao copiar código: ', err);
        }
        document.body.removeChild(textArea);
        return;
    }
    
    navigator.clipboard.writeText(text).then(function() {
        showCopiedState(element);
    }, function(err) {
        console.error('Erro ao copiar código: ', err);
    });
}

function showCopiedState(element) {
    const copyIcon = element.querySelector('.copy-icon');
    const checkIcon = element.querySelector('.check-icon');
    const codeText = element.querySelector('.code-text');
    
    if (copyIcon && checkIcon && codeText) {
        const originalText = codeText.textContent;
        codeText.textContent = 'Copiado!';
        element.classList.add('bg-emerald-50', 'dark:bg-emerald-950/20', 'border-emerald-200', 'dark:border-emerald-900/30');
        element.classList.remove('bg-slate-50', 'hover:bg-slate-100', 'dark:bg-slate-900/60', 'dark:hover:bg-slate-900', 'border-slate-200', 'dark:border-slate-700/50');
        copyIcon.classList.add('hidden');
        checkIcon.classList.remove('hidden');
        
        setTimeout(function() {
            codeText.textContent = originalText;
            element.classList.remove('bg-emerald-50', 'dark:bg-emerald-950/20', 'border-emerald-200', 'dark:border-emerald-900/30');
            element.classList.add('bg-slate-50', 'hover:bg-slate-100', 'dark:bg-slate-900/60', 'dark:hover:bg-slate-900', 'border-slate-200', 'dark:border-slate-700/50');
            copyIcon.classList.remove('hidden');
            checkIcon.classList.add('hidden');
        }, 1500);
    }
}
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
