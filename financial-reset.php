<?php
// financial-reset.php — Reset Financeiro
// Limpa todas as cobranças, pagamentos e comprovantes físicos.
// Mantém os cadastros de usuários, corais e configurações.
// Acesso: superadmin, administrador, financeiro

require_once 'config.php';
require_login();

// Apenas administradores (não colaboradores, não membros)
if (!is_admin_user()) {
    header("Location: dashboard.php");
    exit;
}

// Identificar o coral_id do contexto (mesmo padrão dos outros módulos)
if (is_superadmin()) {
    $choir_id = intval($_GET['choir_id'] ?? $_SESSION['admin_choir_id'] ?? 0);
    if ($choir_id > 0) {
        $_SESSION['admin_choir_id'] = $choir_id;
    }
} else {
    $choir_id = intval($loggedUser['choir_id'] ?? 0);
}

$success_report = null;
$error_msg = null;

// ─────────────────────────────────────────────
// PROCESSAR O RESET
// ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'financial_reset') {
    
    // Dupla confirmação: token CSRF session + frase digitada pelo usuário
    $confirm_phrase = trim($_POST['confirm_phrase'] ?? '');
    $csrf_token     = trim($_POST['csrf_token'] ?? '');
    
    if (empty($_SESSION['reset_csrf']) || $csrf_token !== $_SESSION['reset_csrf']) {
        $error_msg = 'Token de segurança inválido. Recarregue a página e tente novamente.';
    } elseif (strtoupper($confirm_phrase) !== 'RESETAR FINANCEIRO') {
        $error_msg = 'Frase de confirmação incorreta. Digite exatamente: RESETAR FINANCEIRO';
    } else {
        // Invalidar token para prevenir duplo envio
        unset($_SESSION['reset_csrf']);
        
        try {
            $stats = [
                'receipts_deleted'     => 0,
                'billings_deleted'     => 0,
                'items_deleted'        => 0,
                'balances_zeroed'      => 0,
                'files_deleted'        => 0,
                'files_failed'         => 0,
            ];

            // ── Escopo do coral: superadmin limpa tudo, admin/financeiro limpa só o seu coral
            $scope_choir_id = is_superadmin() ? null : $choir_id;

            // ── 1. Coletar arquivos físicos de comprovantes antes de deletar registros
            if ($scope_choir_id) {
                $stmtFiles = $pdo->prepare("
                    SELECT r.filename FROM receipts r
                    JOIN users u ON r.member_id = u.id
                    WHERE u.choir_id = ?
                      AND r.filename NOT IN ('balance_deduction','voucher_deduction','manual_record')
                ");
                $stmtFiles->execute([$scope_choir_id]);
            } else {
                $stmtFiles = $pdo->query("
                    SELECT filename FROM receipts
                    WHERE filename NOT IN ('balance_deduction','voucher_deduction','manual_record')
                ");
            }
            $filenames = $stmtFiles->fetchAll(PDO::FETCH_COLUMN);

            $pdo->beginTransaction();

            // ── 2. Deletar receipt_billing_items (dependência de receipts e member_billing)
            if ($scope_choir_id) {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $stmtDel = $pdo->prepare("
                    DELETE rbi FROM receipt_billing_items rbi
                    JOIN receipts r ON rbi.receipt_id = r.id
                    JOIN users u ON r.member_id = u.id
                    WHERE u.choir_id = ?
                ");
                $stmtDel->execute([$scope_choir_id]);

                // ── 3. Deletar receipts
                $stmtDelR = $pdo->prepare("
                    DELETE r FROM receipts r
                    JOIN users u ON r.member_id = u.id
                    WHERE u.choir_id = ?
                ");
                $stmtDelR->execute([$scope_choir_id]);
                $stats['receipts_deleted'] = $stmtDelR->rowCount();

                // ── 4. Deletar member_billing
                $stmtDelMB = $pdo->prepare("
                    DELETE mb FROM member_billing mb
                    JOIN users u ON mb.member_id = u.id
                    WHERE u.choir_id = ?
                ");
                $stmtDelMB->execute([$scope_choir_id]);
                $stats['billings_deleted'] = $stmtDelMB->rowCount();

                // ── 5. Deletar billing_items
                $stmtDelBI = $pdo->prepare("DELETE FROM billing_items WHERE choir_id = ?");
                $stmtDelBI->execute([$scope_choir_id]);
                $stats['items_deleted'] = $stmtDelBI->rowCount();

                // ── 6. Zerar saldos dos membros do coral
                $stmtZero = $pdo->prepare("
                    UPDATE users SET balance = 0.00
                    WHERE choir_id = ? AND role = 'membro'
                ");
                $stmtZero->execute([$scope_choir_id]);
                $stats['balances_zeroed'] = $stmtZero->rowCount();

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            } else {
                // Superadmin: limpa tudo
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                $pdo->exec("TRUNCATE TABLE receipt_billing_items");
                $stats['receipts_deleted'] = $pdo->query("SELECT COUNT(*) FROM receipts")->fetchColumn();
                $pdo->exec("TRUNCATE TABLE receipts");
                $stats['billings_deleted'] = $pdo->query("SELECT COUNT(*) FROM member_billing")->fetchColumn();
                $pdo->exec("TRUNCATE TABLE member_billing");
                $stats['items_deleted'] = $pdo->query("SELECT COUNT(*) FROM billing_items")->fetchColumn();
                $pdo->exec("TRUNCATE TABLE billing_items");
                $stats['balances_zeroed'] = $pdo->exec("UPDATE users SET balance = 0.00 WHERE role = 'membro'");
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            }

            $pdo->commit();

            // ── 7. Deletar arquivos físicos de comprovantes
            $uploadDir = __DIR__ . '/uploads';
            foreach ($filenames as $fname) {
                $path = $uploadDir . '/' . $fname;
                if (file_exists($path)) {
                    if (unlink($path)) {
                        $stats['files_deleted']++;
                    } else {
                        $stats['files_failed']++;
                    }
                }
            }

            $success_report = $stats;

        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error_msg = 'Erro durante o reset: ' . $ex->getMessage();
        }
    }
}

// Gerar token CSRF para o formulário
if (!isset($_SESSION['reset_csrf']) || empty($_SESSION['reset_csrf'])) {
    $_SESSION['reset_csrf'] = bin2hex(random_bytes(24));
}
$csrf_token_value = $_SESSION['reset_csrf'];

// Contagens atuais para exibir o resumo do que será apagado
$scope_choir_id = is_superadmin() ? null : $choir_id;

if ($scope_choir_id) {
    $count_items    = $pdo->prepare("SELECT COUNT(*) FROM billing_items WHERE choir_id = ?");
    $count_items->execute([$scope_choir_id]);
    $count_billings = $pdo->prepare("SELECT COUNT(*) FROM member_billing mb JOIN users u ON mb.member_id=u.id WHERE u.choir_id = ?");
    $count_billings->execute([$scope_choir_id]);
    $count_receipts = $pdo->prepare("SELECT COUNT(*) FROM receipts r JOIN users u ON r.member_id=u.id WHERE u.choir_id = ?");
    $count_receipts->execute([$scope_choir_id]);
    $count_members  = $pdo->prepare("SELECT COUNT(*) FROM users WHERE choir_id = ? AND role='membro' AND balance > 0");
    $count_members->execute([$scope_choir_id]);
} else {
    $count_items    = $pdo->query("SELECT COUNT(*) FROM billing_items");
    $count_billings = $pdo->query("SELECT COUNT(*) FROM member_billing");
    $count_receipts = $pdo->query("SELECT COUNT(*) FROM receipts");
    $count_members  = $pdo->query("SELECT COUNT(*) FROM users WHERE role='membro' AND balance > 0");
}

$n_items    = intval($count_items->fetchColumn());
$n_billings = intval($count_billings->fetchColumn());
$n_receipts = intval($count_receipts->fetchColumn());
$n_members  = intval($count_members->fetchColumn());

$scope_label = $scope_choir_id ? 'do coral atual' : 'de todos os corais';

$pageTitle = "Reset Financeiro";
require_once 'layout_header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-10">

    <!-- Breadcrumb -->
    <nav class="text-xs text-slate-400 dark:text-slate-500 mb-6 flex items-center gap-1.5">
        <a href="dashboard.php" class="hover:text-coral-500 transition-colors">Dashboard</a>
        <span>›</span>
        <span class="text-slate-600 dark:text-slate-300">Reset Financeiro</span>
    </nav>

    <?php if ($success_report): ?>
    <!-- ─── RESULTADO DO RESET ─── -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-emerald-200 dark:border-emerald-700/40 shadow-xl overflow-hidden">
        <div class="bg-gradient-to-r from-emerald-500 to-teal-500 p-6 text-white">
            <div class="flex items-center gap-3">
                <span class="text-4xl">✅</span>
                <div>
                    <h1 class="text-2xl font-bold font-outfit">Reset Concluído com Sucesso</h1>
                    <p class="text-emerald-100 text-sm mt-0.5">Todos os registros financeiros foram removidos.</p>
                </div>
            </div>
        </div>
        <div class="p-6">
            <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 mb-4 uppercase tracking-wide">Resumo da Operação</h2>
            <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <?php
                $report_items = [
                    ['label' => 'Modelos de Cobrança', 'value' => $success_report['items_deleted'],    'icon' => '📋'],
                    ['label' => 'Lançamentos de Membros','value' => $success_report['billings_deleted'], 'icon' => '📄'],
                    ['label' => 'Comprovantes',          'value' => $success_report['receipts_deleted'], 'icon' => '🧾'],
                    ['label' => 'Saldos Zerados',        'value' => $success_report['balances_zeroed'],  'icon' => '💰'],
                    ['label' => 'Arquivos Removidos',    'value' => $success_report['files_deleted'],    'icon' => '🗑️'],
                    ['label' => 'Arquivos c/ Falha',     'value' => $success_report['files_failed'],     'icon' => '⚠️'],
                ];
                foreach ($report_items as $ri): ?>
                <div class="bg-slate-50 dark:bg-slate-900/60 rounded-xl p-4 text-center border border-slate-100 dark:border-slate-700/50">
                    <div class="text-2xl mb-1"><?= $ri['icon'] ?></div>
                    <div class="text-2xl font-bold font-outfit text-slate-800 dark:text-white"><?= $ri['value'] ?></div>
                    <div class="text-[11px] text-slate-400 mt-0.5"><?= $ri['label'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($success_report['files_failed'] > 0): ?>
            <div class="mt-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700/40 rounded-lg text-xs text-amber-700 dark:text-amber-400">
                ⚠️ <?= $success_report['files_failed'] ?> arquivo(s) não puderam ser removidos do disco (verifique permissões da pasta uploads).
            </div>
            <?php endif; ?>
            <div class="mt-6 flex gap-3">
                <a href="billing.php" class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-sm shadow transition-colors">
                    Ir para Cobranças
                </a>
                <a href="dashboard.php" class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                    Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- ─── FORMULÁRIO DE RESET ─── -->

    <!-- Cabeçalho com aviso crítico -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-red-200 dark:border-red-800/50 shadow-xl overflow-hidden">

        <!-- Header Vermelho -->
        <div class="bg-gradient-to-r from-rose-600 to-red-700 p-6 text-white">
            <div class="flex items-start gap-4">
                <span class="text-5xl leading-none mt-0.5">🗑️</span>
                <div>
                    <h1 class="text-2xl font-bold font-outfit">Reset Financeiro</h1>
                    <p class="text-rose-100 text-sm mt-1">
                        Remove <strong>permanentemente</strong> todos os registros financeiros <?= $scope_label ?>.
                        Os cadastros de membros, usuários e corais são preservados.
                    </p>
                </div>
            </div>
        </div>

        <div class="p-6 md:p-8 space-y-6">

            <?php if ($error_msg): ?>
            <div class="flex items-start gap-3 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700/50 rounded-xl text-sm text-red-700 dark:text-red-400">
                <span class="text-lg mt-0.5">❌</span>
                <span><?= htmlspecialchars($error_msg) ?></span>
            </div>
            <?php endif; ?>

            <!-- O que será apagado -->
            <div>
                <h2 class="text-sm font-bold text-slate-700 dark:text-slate-200 uppercase tracking-wide mb-3">O que será removido <?= $scope_label ?></h2>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php
                    $preview = [
                        ['label' => 'Modelos de Cobrança', 'value' => $n_items,    'icon' => '📋', 'color' => 'rose'],
                        ['label' => 'Lançamentos',         'value' => $n_billings, 'icon' => '📄', 'color' => 'orange'],
                        ['label' => 'Comprovantes',        'value' => $n_receipts, 'icon' => '🧾', 'color' => 'amber'],
                        ['label' => 'Saldos a Zerar',      'value' => $n_members,  'icon' => '💰', 'color' => 'yellow'],
                    ];
                    foreach ($preview as $p): ?>
                    <div class="bg-rose-50 dark:bg-rose-900/10 border border-rose-200 dark:border-rose-800/40 rounded-xl p-4 text-center">
                        <div class="text-2xl mb-1"><?= $p['icon'] ?></div>
                        <div class="text-2xl font-bold font-outfit text-rose-700 dark:text-rose-400"><?= $p['value'] ?></div>
                        <div class="text-[10px] text-slate-500 dark:text-slate-400 mt-0.5"><?= $p['label'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- O que NÃO será apagado -->
            <div class="p-4 bg-emerald-50 dark:bg-emerald-900/10 border border-emerald-200 dark:border-emerald-700/30 rounded-xl">
                <h3 class="text-xs font-bold text-emerald-700 dark:text-emerald-400 uppercase tracking-wide mb-2">✅ Preservado — não será alterado</h3>
                <ul class="text-xs text-emerald-700 dark:text-emerald-300 space-y-1">
                    <li>• Cadastros de membros e usuários (nome, e-mail, senha, voz, ID)</li>
                    <li>• Cadastros de corais e configurações do sistema</li>
                    <li>• Configurações de SMTP e e-mail</li>
                    <li>• Logos e imagens de identidade visual</li>
                </ul>
            </div>

            <!-- Alerta de irreversibilidade -->
            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border-l-4 border-amber-500 rounded-r-xl">
                <p class="text-sm font-bold text-amber-800 dark:text-amber-300">⚠️ Esta operação é irreversível!</p>
                <p class="text-xs text-amber-700 dark:text-amber-400 mt-1">
                    Todos os arquivos físicos de comprovantes enviados por membros serão <strong>apagados do disco</strong>.
                    Recomendamos realizar um backup antes de prosseguir.
                </p>
            </div>

            <!-- Formulário com confirmação dupla -->
            <form method="POST" action="financial-reset.php" id="resetForm" class="space-y-5" onsubmit="return validateReset()">
                <input type="hidden" name="action" value="financial_reset">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token_value) ?>">

                <!-- Frase de confirmação -->
                <div>
                    <label for="confirm_phrase" class="block text-sm font-semibold text-slate-700 dark:text-slate-200 mb-2">
                        Para confirmar, digite exatamente: <code class="bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded text-rose-600 dark:text-rose-400 font-mono text-xs">RESETAR FINANCEIRO</code>
                    </label>
                    <input type="text" id="confirm_phrase" name="confirm_phrase" autocomplete="off" spellcheck="false"
                           class="w-full px-4 py-3 text-sm font-mono bg-slate-50 dark:bg-slate-900 border-2 border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:border-rose-400 dark:text-white transition-all tracking-wide placeholder-slate-300"
                           placeholder="Digite a frase aqui..."
                           oninput="checkPhrase(this)">
                    <span id="phrase_status" class="text-xs mt-1 block text-slate-400">Aguardando frase de confirmação...</span>
                </div>

                <!-- Checkbox de ciência -->
                <label class="flex items-start gap-3 cursor-pointer group">
                    <input type="checkbox" id="aware_check" required
                           class="mt-0.5 w-4 h-4 rounded border-slate-300 text-rose-500 focus:ring-rose-400">
                    <span class="text-sm text-slate-600 dark:text-slate-300">
                        Estou ciente de que esta ação é <strong>irreversível</strong> e que todos os registros financeiros serão permanentemente removidos.
                    </span>
                </label>

                <!-- Botões -->
                <div class="flex items-center justify-between pt-2 border-t border-slate-100 dark:border-slate-700/50">
                    <a href="dashboard.php" class="px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-700 text-slate-600 dark:text-slate-300 font-semibold text-sm hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                        Cancelar
                    </a>
                    <button type="submit" id="reset_submit_btn" disabled
                            class="px-6 py-2.5 rounded-xl bg-slate-300 dark:bg-slate-700 text-slate-500 dark:text-slate-400 font-bold text-sm cursor-not-allowed transition-all duration-300 flex items-center gap-2"
                            title="Preencha a frase de confirmação e marque a caixa de ciência para continuar">
                        <span>🗑️</span> Executar Reset Financeiro
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
const EXPECTED = 'RESETAR FINANCEIRO';

function checkPhrase(input) {
    const status = document.getElementById('phrase_status');
    const btn = document.getElementById('reset_submit_btn');
    const aware = document.getElementById('aware_check');

    if (input.value.toUpperCase() === EXPECTED) {
        input.classList.remove('border-slate-200', 'dark:border-slate-700', 'border-red-400');
        input.classList.add('border-emerald-400');
        status.textContent = '✅ Frase correta!';
        status.className = 'text-xs mt-1 block text-emerald-600 dark:text-emerald-400';
    } else if (input.value.length > 0) {
        input.classList.remove('border-slate-200', 'dark:border-slate-700', 'border-emerald-400');
        input.classList.add('border-red-400');
        status.textContent = '❌ Frase incorreta — verifique maiúsculas e acentos.';
        status.className = 'text-xs mt-1 block text-red-500';
    } else {
        input.classList.remove('border-emerald-400', 'border-red-400');
        input.classList.add('border-slate-200');
        status.textContent = 'Aguardando frase de confirmação...';
        status.className = 'text-xs mt-1 block text-slate-400';
    }

    updateSubmitButton();
}

function updateSubmitButton() {
    const phrase = document.getElementById('confirm_phrase').value.toUpperCase() === EXPECTED;
    const aware = document.getElementById('aware_check').checked;
    const btn = document.getElementById('reset_submit_btn');

    if (phrase && aware) {
        btn.disabled = false;
        btn.classList.remove('bg-slate-300', 'dark:bg-slate-700', 'text-slate-500', 'dark:text-slate-400', 'cursor-not-allowed');
        btn.classList.add('bg-rose-600', 'hover:bg-rose-700', 'text-white', 'shadow-lg', 'cursor-pointer');
    } else {
        btn.disabled = true;
        btn.classList.add('bg-slate-300', 'dark:bg-slate-700', 'text-slate-500', 'dark:text-slate-400', 'cursor-not-allowed');
        btn.classList.remove('bg-rose-600', 'hover:bg-rose-700', 'text-white', 'shadow-lg', 'cursor-pointer');
    }
}

document.getElementById('aware_check').addEventListener('change', updateSubmitButton);

function validateReset() {
    const phrase = document.getElementById('confirm_phrase').value.toUpperCase() === EXPECTED;
    const aware = document.getElementById('aware_check').checked;
    if (!phrase || !aware) {
        alert('Por favor, preencha a frase de confirmação e marque a caixa de ciência antes de continuar.');
        return false;
    }
    return confirm('⚠️ ÚLTIMA CONFIRMAÇÃO\n\nTodos os registros financeiros serão PERMANENTEMENTE removidos.\nEsta ação não pode ser desfeita.\n\nDeseja continuar?');
}
</script>

<?php require_once 'layout_footer.php'; ?>
