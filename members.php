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
    $balance = floatval($_POST['balance'] ?? 0.00);
    
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
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("INSERT INTO users (choir_id, name, email, phone, voice_type, username, password, role, status, balance) VALUES (?, ?, ?, ?, ?, ?, ?, 'membro', ?, ?)");
                        $stmt->execute([$choir_id, $name, $email, $phone, $voice_type, $username, $hashedPassword, $status, $balance]);
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
                    
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE users SET choir_id = ?, name = ?, email = ?, phone = ?, voice_type = ?, username = ?, password = ?, status = ?, balance = ? WHERE id = ?");
                        $stmt->execute([$choir_id, $name, $email, $phone, $voice_type, $username, $hashedPassword, $status, $balance, $edit_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET choir_id = ?, name = ?, email = ?, phone = ?, voice_type = ?, username = ?, status = ?, balance = ? WHERE id = ?");
                        $stmt->execute([$choir_id, $name, $email, $phone, $voice_type, $username, $status, $balance, $edit_id]);
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
        
        $stmt = $pdo->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'membro'");
        $stmt->execute([$edit_id]);
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

// Listar membros (cantores)
$members = [];
if ($action === 'list') {
    try {
        if (is_superadmin()) {
            $stmt = $pdo->query("SELECT u.*, c.name as choir_name FROM users u 
                                 LEFT JOIN choirs c ON u.choir_id = c.id 
                                 WHERE u.role = 'membro' 
                                 ORDER BY u.status DESC, u.id DESC");
        } else {
            $stmt = $pdo->prepare("SELECT u.*, c.name as choir_name FROM users u 
                                   LEFT JOIN choirs c ON u.choir_id = c.id 
                                   WHERE u.choir_id = ? AND u.role = 'membro' 
                                   ORDER BY u.status DESC, u.id DESC");
            $stmt->execute([$loggedUser['choir_id']]);
        }
        $members = $stmt->fetchAll();
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
                    <input type="number" name="balance" id="balance" step="0.01" min="0" value="<?= htmlspecialchars($member_data['balance'] ?? '0.00') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="0.00">
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
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800 dark:text-white"><?= htmlspecialchars($m['name']) ?></td>
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
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
