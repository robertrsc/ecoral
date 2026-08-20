<?php
// users.php
require_once __DIR__ . '/config.php';

// Apenas superadmin e administrador podem gerenciar usuários
require_login();
$loggedUser = get_logged_user();
if (!is_superadmin() && $loggedUser['role'] !== 'administrador') {
    set_flash_message('error', 'Você não tem permissão para acessar esta página.');
    header("Location: dashboard.php");
    exit;
}

$error = null;
$success = null;
$action = $_GET['action'] ?? 'list';
$edit_id = intval($_GET['id'] ?? 0);

// Carregar corais para dropdown (apenas superadmin pode selecionar)
$choirs = [];
if (is_superadmin()) {
    $choirs = $pdo->query("SELECT id, name FROM choirs ORDER BY name ASC")->fetchAll();
}

// Inserir / Editar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? '');
    
    // Identificar coral_id
    if (is_superadmin()) {
        $choir_id = intval($_POST['choir_id'] ?? 0);
        if ($choir_id === 0) $choir_id = null; // caso superadmin queira criar outro superadmin
    } else {
        $choir_id = $loggedUser['choir_id'];
    }
    
    // Validações
    if (empty($name) || empty($email) || empty($username) || empty($role)) {
        $error = 'Por favor, preencha todos os campos obrigatórios (*).';
    } elseif (!in_array($role, ['administrador', 'financeiro', 'colaborador', 'superadmin'])) {
        $error = 'Nível de permissão inválido.';
    } elseif ($role === 'superadmin' && !is_superadmin()) {
        $error = 'Apenas superadmins podem criar outros superadmins.';
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
                        $error = 'A senha é obrigatória para novos usuários.';
                    } else {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("INSERT INTO users (choir_id, name, email, username, password, role, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
                        $stmt->execute([$choir_id, $name, $email, $username, $hashedPassword, $role]);
                        set_flash_message('success', "Usuário '$name' criado com sucesso.");
                        header("Location: users.php");
                        exit;
                    }
                } elseif ($action === 'edit' && $edit_id > 0) {
                    // Verificar se o usuário editado pertence ao coral do administrador (se não for superadmin)
                    if (!is_superadmin()) {
                        $stmtCheck = $pdo->prepare("SELECT choir_id FROM users WHERE id = ?");
                        $stmtCheck->execute([$edit_id]);
                        $usrChoir = $stmtCheck->fetchColumn();
                        if ($usrChoir != $loggedUser['choir_id']) {
                            set_flash_message('error', 'Acesso negado.');
                            header("Location: users.php");
                            exit;
                        }
                    }
                    
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                        $stmt = $pdo->prepare("UPDATE users SET choir_id = ?, name = ?, email = ?, username = ?, password = ?, role = ? WHERE id = ?");
                        $stmt->execute([$choir_id, $name, $email, $username, $hashedPassword, $role, $edit_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET choir_id = ?, name = ?, email = ?, username = ?, role = ? WHERE id = ?");
                        $stmt->execute([$choir_id, $name, $email, $username, $role, $edit_id]);
                    }
                    set_flash_message('success', "Usuário '$name' atualizado com sucesso.");
                    header("Location: users.php");
                    exit;
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar usuário: ' . $e->getMessage();
            }
        }
    }
}

// Excluir
if ($action === 'delete' && $edit_id > 0) {
    if ($edit_id == $loggedUser['id']) {
        set_flash_message('error', 'Você não pode excluir sua própria conta.');
    } else {
        try {
            // Se for admin, verificar se o usuário é do mesmo coral
            if (!is_superadmin()) {
                $stmtCheck = $pdo->prepare("SELECT choir_id FROM users WHERE id = ?");
                $stmtCheck->execute([$edit_id]);
                $usrChoir = $stmtCheck->fetchColumn();
                if ($usrChoir != $loggedUser['choir_id']) {
                    set_flash_message('error', 'Acesso negado.');
                    header("Location: users.php");
                    exit;
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$edit_id]);
            set_flash_message('success', 'Usuário removido com sucesso.');
        } catch (PDOException $e) {
            set_flash_message('error', 'Erro ao excluir usuário: ' . $e->getMessage());
        }
    }
    header("Location: users.php");
    exit;
}

// Buscar dados se for edição
$user_data = null;
if ($action === 'edit' && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$edit_id]);
    $user_data = $stmt->fetch();
    if (!$user_data || $user_data['role'] === 'membro') {
        set_flash_message('error', 'Usuário administrativo não encontrado.');
        header("Location: users.php");
        exit;
    }
    // Administrador do coral não pode editar usuários de outros corais
    if (!is_superadmin() && $user_data['choir_id'] != $loggedUser['choir_id']) {
        set_flash_message('error', 'Acesso negado.');
        header("Location: users.php");
        exit;
    }
}

// Listar usuários administrativos
$users = [];
if ($action === 'list') {
    try {
        if (is_superadmin()) {
            $stmt = $pdo->query("SELECT u.*, c.name as choir_name FROM users u 
                                 LEFT JOIN choirs c ON u.choir_id = c.id 
                                 WHERE u.role != 'membro' 
                                 ORDER BY u.id DESC");
        } else {
            $stmt = $pdo->prepare("SELECT u.*, c.name as choir_name FROM users u 
                                   LEFT JOIN choirs c ON u.choir_id = c.id 
                                   WHERE u.choir_id = ? AND u.role != 'membro' 
                                   ORDER BY u.id DESC");
            $stmt->execute([$loggedUser['choir_id']]);
        }
        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Erro ao listar usuários: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>👥</span> Equipe Administrativa
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Gerenciamento de Administradores, Financeiros e Colaboradores do coral.
        </p>
    </div>
    
    <?php if ($action === 'list'): ?>
        <a href="users.php?action=new" 
           class="px-3.5 py-2 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
            + Novo Usuário
        </a>
    <?php else: ?>
        <a href="users.php" 
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
            <?= $action === 'edit' ? 'Editar Usuário' : 'Criar Novo Usuário' ?>
        </h2>
        
        <form action="users.php?action=<?= $action ?><?= $action === 'edit' ? '&id=' . $edit_id : '' ?>" method="POST" class="space-y-4">
            
            <?php if (is_superadmin()): ?>
                <div>
                    <label for="choir_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Vincular ao Coral</label>
                    <select name="choir_id" id="choir_id"
                            class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        <option value="0">Nenhum (Apenas para Super Admin global)</option>
                        <?php foreach ($choirs as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= isset($user_data['choir_id']) && $user_data['choir_id'] == $c['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div>
                <label for="name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome Completo *</label>
                <input type="text" name="name" id="name" required value="<?= htmlspecialchars($user_data['name'] ?? '') ?>"
                       class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                       placeholder="Ex: Alberto Torres">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">E-mail *</label>
                    <input type="email" name="email" id="email" required value="<?= htmlspecialchars($user_data['email'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="alberto@email.com">
                </div>
                <div>
                    <label for="role" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nível de Permissão *</label>
                    <select name="role" id="role" required
                            class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        <option value="">Selecione...</option>
                        <option value="administrador" <?= isset($user_data['role']) && $user_data['role'] === 'administrador' ? 'selected' : '' ?>>Administrador</option>
                        <option value="financeiro" <?= isset($user_data['role']) && $user_data['role'] === 'financeiro' ? 'selected' : '' ?>>Financeiro</option>
                        <option value="colaborador" <?= isset($user_data['role']) && $user_data['role'] === 'colaborador' ? 'selected' : '' ?>>Colaborador</option>
                        <?php if (is_superadmin()): ?>
                            <option value="superadmin" <?= isset($user_data['role']) && $user_data['role'] === 'superadmin' ? 'selected' : '' ?>>Super Admin Global</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t border-slate-100 dark:border-slate-700/50">
                <div>
                    <label for="username" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome de Usuário *</label>
                    <input type="text" name="username" id="username" required value="<?= htmlspecialchars($user_data['username'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="albertotorres">
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
                <a href="users.php"
                   class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                    Cancelar
                </a>
                <button type="submit"
                        class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                    <?= $action === 'edit' ? 'Salvar Alterações' : 'Criar Usuário' ?>
                </button>
            </div>
        </form>
    </div>

<!-- ==============================================
     LISTA DE USUÁRIOS
     ============================================== -->
<?php else: ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
        <?php if (empty($users)): ?>
            <div class="text-center py-12 text-slate-400">
                Nenhum usuário cadastrado. Clique em "+ Novo Usuário" para começar.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                    <thead class="bg-slate-50 dark:bg-slate-900/60">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Nome</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Username / E-mail</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Coral</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Permissão</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                        <?php foreach ($users as $u): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800 dark:text-white"><?= htmlspecialchars($u['name']) ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                    <div>@<?= htmlspecialchars($u['username']) ?></div>
                                    <div class="text-xs text-slate-400"><?= htmlspecialchars($u['email']) ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                    <?= htmlspecialchars($u['choir_name'] ?? 'Global (Sadmin)') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-xs">
                                    <span class="px-2.5 py-0.5 rounded-full font-semibold capitalize
                                        <?= $u['role'] === 'superadmin' ? 'bg-red-100 text-red-800 dark:bg-red-950/20 dark:text-red-300' : ($u['role'] === 'administrador' ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/20 dark:text-indigo-300' : 'bg-slate-100 text-slate-800 dark:bg-slate-700/50 dark:text-slate-300') ?>">
                                        <?= $u['role'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium space-x-2">
                                    <?php if ((is_superadmin() || (is_impersonating() && is_superadmin(get_original_user()))) && $u['id'] != $loggedUser['id']): ?>
                                        <a href="impersonate.php?action=start&id=<?= $u['id'] ?>" class="text-indigo-500 hover:text-indigo-600 font-bold">Usar como</a>
                                    <?php endif; ?>
                                    <a href="users.php?action=edit&id=<?= $u['id'] ?>" class="text-coral-500 hover:text-coral-600 font-bold">Editar</a>
                                    
                                    <?php if ($u['id'] != $loggedUser['id']): ?>
                                        <a href="users.php?action=delete&id=<?= $u['id'] ?>" onclick="return confirm('Deseja realmente excluir este usuário?')" class="text-red-500 hover:text-red-600 font-bold">Excluir</a>
                                    <?php endif; ?>
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
