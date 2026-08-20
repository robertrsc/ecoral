<?php
// smtp-settings.php
require_once __DIR__ . '/config.php';
require_role('superadmin');

$error = null;
$success = null;

// Salvar Configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
    $host = trim($_POST['host'] ?? '');
    $port = intval($_POST['port'] ?? 587);
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $encryption = trim($_POST['encryption'] ?? 'tls');
    $from_email = trim($_POST['from_email'] ?? '');
    $from_name = trim($_POST['from_name'] ?? '');
    
    if (empty($host) || empty($from_email) || empty($from_name)) {
        $error = 'Por favor, preencha o Host, E-mail do remetente e Nome do remetente.';
    } else {
        try {
            // Verifica se já existe registro
            $stmtCount = $pdo->query("SELECT COUNT(*) FROM config_smtp");
            if ($stmtCount->fetchColumn() > 0) {
                $stmt = $pdo->prepare("UPDATE config_smtp SET host = ?, port = ?, username = ?, password = ?, encryption = ?, from_email = ?, from_name = ? WHERE id = 1");
                $stmt->execute([$host, $port, $username, $password, $encryption, $from_email, $from_name]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO config_smtp (id, host, port, username, password, encryption, from_email, from_name) VALUES (1, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$host, $port, $username, $password, $encryption, $from_email, $from_name]);
            }
            set_flash_message('success', 'Configurações de SMTP salvas com sucesso.');
            header("Location: smtp-settings.php");
            exit;
        } catch (PDOException $e) {
            $error = 'Erro ao salvar no banco: ' . $e->getMessage();
        }
    }
}

// Enviar E-mail de Teste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_email') {
    $test_email = trim($_POST['test_email_address'] ?? '');
    
    if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, informe um endereço de e-mail de teste válido.';
    } else {
        $subject = "E-mail de Teste - eCoral SaaS";
        $body = "
            <h2>Parabéns!</h2>
            <p>Se você está lendo esta mensagem, o envio de e-mails via SMTP do sistema <strong>eCoral</strong> está funcionando corretamente.</p>
            <p>Data e Hora do teste: " . date('d/m/Y H:i:s') . "</p>
        ";
        
        $sent = send_email($test_email, "Destinatário de Teste", $subject, $body);
        
        if ($sent) {
            $success = "E-mail de teste processado! Verifique a caixa de entrada de '{$test_email}'.";
        } else {
            $error = "O envio do e-mail de teste falhou. Verifique as credenciais SMTP informadas ou consulte o log de erros.";
        }
    }
}

// Carregar Configurações SMTP Atuais
try {
    $smtp = $pdo->query("SELECT * FROM config_smtp LIMIT 1")->fetch();
} catch (PDOException $e) {
    $smtp = null;
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Título -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>⚙️</span> Configurações de Envio (SMTP)
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Configure as credenciais para o envio de e-mails de recuperação de senha e avisos automáticos do sistema.</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="mb-4 p-3 rounded-lg text-sm bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Formulário de Configuração -->
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Credenciais do Servidor</h2>
            
            <form action="smtp-settings.php" method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save">
                
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="md:col-span-2">
                        <label for="host" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Host SMTP *</label>
                        <input type="text" name="host" id="host" required value="<?= htmlspecialchars($smtp['host'] ?? '') ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: smtp.gmail.com">
                    </div>
                    <div>
                        <label for="port" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Porta *</label>
                        <input type="number" name="port" id="port" required value="<?= htmlspecialchars($smtp['port'] ?? 587) ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: 587">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="username" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Usuário SMTP</label>
                        <input type="text" name="username" id="username" value="<?= htmlspecialchars($smtp['username'] ?? '') ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: seuemail@gmail.com">
                    </div>
                    <div>
                        <label for="password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Senha SMTP</label>
                        <input type="password" name="password" id="password" value="<?= htmlspecialchars($smtp['password'] ?? '') ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Sua senha">
                    </div>
                    <div>
                        <label for="encryption" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Segurança</label>
                        <select name="encryption" id="encryption" required
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                            <option value="tls" <?= ($smtp['encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>TLS (Recomendado)</option>
                            <option value="ssl" <?= ($smtp['encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                            <option value="none" <?= ($smtp['encryption'] ?? '') === 'none' ? 'selected' : '' ?>>Nenhuma</option>
                        </select>
                    </div>
                </div>

                <hr class="border-slate-100 dark:border-slate-700/50 my-4">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="from_email" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">E-mail do Remetente *</label>
                        <input type="email" name="from_email" id="from_email" required value="<?= htmlspecialchars($smtp['from_email'] ?? '') ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: no-reply@ecoral.com.br">
                    </div>
                    <div>
                        <label for="from_name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome do Remetente *</label>
                        <input type="text" name="from_name" id="from_name" required value="<?= htmlspecialchars($smtp['from_name'] ?? '') ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: eCoral SaaS">
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-4">
                    <button type="submit"
                            class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <!-- Formulário de Envio de Teste -->
        <div class="lg:col-span-1 bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 flex flex-col justify-between">
            <div>
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-2">Testar SMTP</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Insira um e-mail para validar as configurações ativas e testar o funcionamento.</p>
                
                <form action="smtp-settings.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="test_email">
                    
                    <div>
                        <label for="test_email_address" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">E-mail de Destino</label>
                        <input type="email" name="test_email_address" id="test_email_address" required
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: test@email.com">
                    </div>
                    
                    <button type="submit"
                            class="w-full py-2 bg-indigo-500 hover:bg-indigo-600 text-white font-semibold rounded-lg text-xs shadow transition-colors">
                        Enviar E-mail de Teste
                    </button>
                </form>
            </div>
            
            <div class="mt-6 border-t border-slate-100 dark:border-slate-700/50 pt-4 text-[10px] text-slate-400">
                🛠️ Nota: Caso use o host de exemplo <code>smtp.example.com</code>, o sistema executará um envio simulado e exibirá os e-mails de recuperação na tela em modo depuração.
            </div>
        </div>
        
    </div>
</div>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
