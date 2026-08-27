<?php
// profile.php
require_once __DIR__ . '/config.php';
require_login();

require_once __DIR__ . '/layout_header.php';

$user = get_logged_user();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $voice_type = trim($_POST['voice_type'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($name) || empty($email)) {
        $error = 'Por favor, preencha o Nome e o E-mail.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, insira um e-mail válido.';
    } else {
        // Verificar se e-mail já existe em outro usuário
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $error = 'Este e-mail já está sendo utilizado por outro usuário.';
        } else {
            // Verificar mudança de senha
            $passwordChange = false;
            $hashedPassword = null;
            
            if (!empty($password)) {
                if (strlen($password) < 6) {
                    $error = 'A nova senha deve possuir pelo menos 6 caracteres.';
                } elseif ($password !== $confirm_password) {
                    $error = 'As senhas não coincidem.';
                } else {
                    $passwordChange = true;
                    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                }
            }
            
            if (!$error) {
                try {
                    $member_code = $user['member_code'];
                    if ($user['role'] === 'membro' && (!$member_code || $user['voice_type'] !== $voice_type)) {
                        $member_code = get_or_generate_member_code($pdo, $voice_type, $user['choir_id'], $user['id']);
                    }
                    
                    if ($passwordChange) {
                        $stmtUpdate = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, voice_type = ?, member_code = ?, password = ? WHERE id = ?");
                        $stmtUpdate->execute([$name, $email, $phone, $voice_type, $member_code, $hashedPassword, $user['id']]);
                    } else {
                        $stmtUpdate = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, voice_type = ?, member_code = ? WHERE id = ?");
                        $stmtUpdate->execute([$name, $email, $phone, $voice_type, $member_code, $user['id']]);
                    }
                    
                    $success = 'Perfil atualizado com sucesso!';
                    // Forçar atualização do cache de usuário logado
                    $_SESSION['user_id'] = $user['id']; 
                    $user = get_logged_user(); // Recarrega
                } catch (PDOException $e) {
                    $error = 'Erro ao atualizar dados: ' . $e->getMessage();
                }
            }
        }
    }
}
?>

<div class="max-w-2xl mx-auto">
    <!-- Título -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>👤</span> Meu Perfil
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Atualize seus dados pessoais e altere sua senha de acesso.</p>
    </div>

    <!-- Card Principal -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
        
        <?php if ($success): ?>
            <div class="mb-4 p-3 rounded-lg text-sm bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($user['role'] === 'membro'): ?>
            <div class="mb-6 p-4 rounded-xl border border-slate-100 dark:border-slate-700/50 bg-slate-50/50 dark:bg-slate-900/10 flex items-center justify-between select-none">
                <div>
                    <h3 class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Código Identificador de Membro</h3>
                    <p class="text-[10px] text-slate-400 mt-0.5">Use este código para pagamentos de terceiros e identificação.</p>
                </div>
                <?php if (!empty($user['member_code'])): ?>
                    <span class="inline-flex items-center gap-1.5 cursor-pointer bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-xs text-slate-700 dark:text-slate-300 font-mono font-bold px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700/50 transition-all active:scale-95" 
                          onclick="copyToClipboard('<?= htmlspecialchars($user['member_code']) ?>', this)"
                          title="Clique para copiar o código">
                        <span class="code-text"><?= htmlspecialchars($user['member_code']) ?></span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-slate-400 copy-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                        </svg>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-emerald-500 check-icon hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </span>
                <?php else: ?>
                    <span class="text-xs text-slate-400 dark:text-slate-500">Não gerado</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form action="profile.php" method="POST" class="space-y-4">
            
            <!-- Dados fixos que não mudam -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Nome de Usuário (Username)</label>
                    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" disabled
                           class="w-full px-3 py-2 text-sm bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-500 cursor-not-allowed">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Tipo de Usuário (Permissão)</label>
                    <input type="text" value="<?= htmlspecialchars(ucfirst($user['role'])) ?>" disabled
                           class="w-full px-3 py-2 text-sm bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-500 cursor-not-allowed capitalize">
                </div>
            </div>

            <div>
                <label for="name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome Completo *</label>
                <input type="text" name="name" id="name" value="<?= htmlspecialchars($user['name']) ?>" required
                       class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">E-mail *</label>
                    <input type="email" name="email" id="email" value="<?= htmlspecialchars($user['email']) ?>" required
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                </div>

                <div>
                    <label for="phone" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Telefone / WhatsApp</label>
                    <input type="text" name="phone" id="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                </div>
            </div>

            <?php if ($user['role'] === 'membro'): ?>
                <div>
                    <label for="voice_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Naipe / Tipo de Voz</label>
                    <select name="voice_type" id="voice_type"
                            class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        <option value="">Não sei / Indefinido</option>
                        <option value="Soprano" <?= $user['voice_type'] === 'Soprano' ? 'selected' : '' ?>>Soprano</option>
                        <option value="Contralto" <?= $user['voice_type'] === 'Contralto' ? 'selected' : '' ?>>Contralto</option>
                        <option value="Tenor" <?= $user['voice_type'] === 'Tenor' ? 'selected' : '' ?>>Tenor</option>
                        <option value="Baixo" <?= $user['voice_type'] === 'Baixo' ? 'selected' : '' ?>>Baixo</option>
                    </select>
                </div>
            <?php endif; ?>

            <div class="pt-4 border-t border-slate-100 dark:border-slate-700/50">
                <h3 class="text-sm font-bold text-slate-800 dark:text-white mb-3">Alterar Senha</h3>
                <p class="text-xs text-slate-400 mb-4">Caso não deseje alterar sua senha, deixe os campos abaixo em branco.</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nova Senha</label>
                        <input type="password" name="password" id="password"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Mínimo 6 caracteres">
                    </div>
                    <div>
                        <label for="confirm_password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Confirme a Nova Senha</label>
                        <input type="password" name="confirm_password" id="confirm_password"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Confirmação de senha">
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-6">
                <a href="dashboard.php"
                   class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                    Cancelar
                </a>
                <button type="submit"
                        class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                    Salvar Alterações
                </button>
            </div>

        </form>
    </div>
</div>

<script>
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
        element.classList.add('bg-emerald-500', 'text-white', 'border-emerald-600');
        element.classList.remove('bg-slate-100', 'hover:bg-slate-200', 'dark:bg-slate-800', 'dark:hover:bg-slate-700', 'text-slate-700', 'dark:text-slate-300', 'border-slate-200', 'dark:border-slate-700/50');
        copyIcon.classList.add('hidden');
        checkIcon.classList.remove('hidden');
        
        setTimeout(function() {
            codeText.textContent = originalText;
            element.classList.remove('bg-emerald-500', 'text-white', 'border-emerald-600');
            element.classList.add('bg-slate-100', 'hover:bg-slate-200', 'dark:bg-slate-800', 'dark:hover:bg-slate-700', 'text-slate-700', 'dark:text-slate-300', 'border-slate-200', 'dark:border-slate-700/50');
            copyIcon.classList.remove('hidden');
            checkIcon.classList.add('hidden');
        }, 1500);
    }
}
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
