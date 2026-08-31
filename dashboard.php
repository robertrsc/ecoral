<?php
// dashboard.php
require_once __DIR__ . '/config.php';
require_login();
$loggedUser = get_logged_user();

// Endpoint AJAX: Carregar histórico de uma cobrança
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_billing_history') {
    header('Content-Type: application/json; charset=utf-8');
    $mb_id = intval($_GET['billing_id'] ?? 0);
    
    if (!$loggedUser || $mb_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Parâmetros inválidos ou não autenticado.']);
        exit;
    }
    
    try {
        // Obter detalhes da cobrança
        if (is_admin_user()) {
            if (is_superadmin()) {
                $stmtMB = $pdo->prepare("
                    SELECT mb.*, bi.title, bi.description as billing_desc, bi.amount as billing_amount, bi.type as billing_type, u.name as member_name, u.member_code as member_code
                    FROM member_billing mb
                    JOIN billing_items bi ON mb.billing_item_id = bi.id
                    JOIN users u ON mb.member_id = u.id
                    WHERE mb.id = ?
                ");
                $stmtMB->execute([$mb_id]);
            } else {
                $stmtMB = $pdo->prepare("
                    SELECT mb.*, bi.title, bi.description as billing_desc, bi.amount as billing_amount, bi.type as billing_type, u.name as member_name, u.member_code as member_code
                    FROM member_billing mb
                    JOIN billing_items bi ON mb.billing_item_id = bi.id
                    JOIN users u ON mb.member_id = u.id
                    WHERE mb.id = ? AND u.choir_id = ?
                ");
                $stmtMB->execute([$mb_id, $loggedUser['choir_id']]);
            }
        } else {
            $stmtMB = $pdo->prepare("
                SELECT mb.*, bi.title, bi.description as billing_desc, bi.amount as billing_amount, bi.type as billing_type, u.name as member_name, u.member_code as member_code
                FROM member_billing mb
                JOIN billing_items bi ON mb.billing_item_id = bi.id
                JOIN users u ON mb.member_id = u.id
                WHERE mb.id = ? AND mb.member_id = ?
            ");
            $stmtMB->execute([$mb_id, $loggedUser['id']]);
        }
        $billing = $stmtMB->fetch(PDO::FETCH_ASSOC);
        
        if (!$billing) {
            echo json_encode(['success' => false, 'error' => 'Cobrança não encontrada ou acesso negado.']);
            exit;
        }
        
        // Obter histórico de pagamentos/recibos vinculados
        $stmtHistory = $pdo->prepare("
            SELECT r.id, r.amount, r.filename, r.description, r.status, r.created_at, r.approved_at, u.name as depositor_name, u.member_code as depositor_code
            FROM receipt_billing_items rbi
            JOIN receipts r ON rbi.receipt_id = r.id
            JOIN users u ON r.member_id = u.id
            WHERE rbi.member_billing_id = ?
            ORDER BY r.created_at ASC
        ");
        $stmtHistory->execute([$mb_id]);
        $payments = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatar valores e datas
        $billing['formatted_amount'] = format_currency($billing['billing_amount']);
        $billing['formatted_paid'] = format_currency($billing['paid_amount']);
        $billing['formatted_remaining'] = format_currency($billing['billing_amount'] - $billing['paid_amount']);
        $billing['formatted_due_date'] = format_date($billing['due_date']);
        $billing['formatted_created_at'] = format_date(date('Y-m-d', strtotime($billing['created_at'])));
        
        if ($billing['billing_type'] === 'recurring') {
            $billing['title'] = $billing['title'] . ' - ' . date('m/Y', strtotime($billing['due_date']));
        }
        
        foreach ($payments as &$p) {
            $p['formatted_amount'] = format_currency($p['amount']);
            $p['formatted_created_at'] = date('d/m/Y H:i', strtotime($p['created_at']));
            if (!empty($p['approved_at'])) {
                $p['formatted_approved_at'] = date('d/m/Y H:i', strtotime($p['approved_at']));
            }
        }
        unset($p);
        
        echo json_encode([
            'success' => true,
            'billing' => $billing,
            'history' => $payments
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

require_once __DIR__ . '/layout_header.php';

$user = get_logged_user();
$choir_id = $user['choir_id'];

// Consultas específicas para cada perfil de Dashboard
if (is_superadmin()) {
    // Superadmin: contadores globais
    $totalChoirs = $pdo->query("SELECT COUNT(*) FROM choirs")->fetchColumn();
    $totalUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'membro'")->fetchColumn();
    $totalMembers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'membro'")->fetchColumn();
    
    // Lista de últimos corais criados
    $latestChoirs = $pdo->query("SELECT * FROM choirs ORDER BY id DESC LIMIT 5")->fetchAll();
} else {
    // Usuários vinculados a um coral (administrador, financeiro, colaborador, membro)
    // Obter informações do coral
    $stmt = $pdo->prepare("SELECT * FROM choirs WHERE id = ?");
    $stmt->execute([$choir_id]);
    $choir = $stmt->fetch();
    
    if (!$choir) {
        die("Coral não encontrado.");
    }
    
    if (is_admin_user()) {
        // Administradores do coral (Administrador e Financeiro)
        // Contador de cantores do coral
        $stmtCantores = $pdo->prepare("SELECT COUNT(*) FROM users WHERE choir_id = ? AND role = 'membro'");
        $stmtCantores->execute([$choir_id]);
        $totalMembers = $stmtCantores->fetchColumn();
        
        // Cobranças em aberto (Valor total)
        $stmtCobrancas = $pdo->prepare("SELECT SUM(bi.amount - mb.paid_amount) FROM member_billing mb 
                                        JOIN users u ON mb.member_id = u.id 
                                        JOIN billing_items bi ON mb.billing_item_id = bi.id
                                        WHERE u.choir_id = ? AND mb.status = 'open'");
        $stmtCobrancas->execute([$choir_id]);
        $openBillings = $stmtCobrancas->fetchColumn() ?? 0.00;
        
        // Comprovantes pendentes de validação
        $stmtComprovantes = $pdo->prepare("SELECT COUNT(*) FROM receipts r 
                                            JOIN users u ON r.member_id = u.id 
                                            WHERE u.choir_id = ? AND r.status = 'pending'");
        $stmtComprovantes->execute([$choir_id]);
        $pendingReceipts = $stmtComprovantes->fetchColumn();
        
        // Saldo total dos comprovantes aprovados do coral (Caixa do Coral)
        $stmtCaixa = $pdo->prepare("SELECT SUM(amount) FROM receipts r 
                                    JOIN users u ON r.member_id = u.id 
                                    WHERE u.choir_id = ? AND r.status = 'approved'");
        $stmtCaixa->execute([$choir_id]);
        $totalRevenue = $stmtCaixa->fetchColumn() ?? 0.00;
        
        // Valor total de cobranças vencidas (em atraso)
        $stmtVencidas = $pdo->prepare("SELECT SUM(bi.amount - mb.paid_amount) FROM member_billing mb 
                                       JOIN users u ON mb.member_id = u.id 
                                       JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                       WHERE u.choir_id = ? AND mb.status = 'open' AND mb.due_date < CURRENT_DATE");
        $stmtVencidas->execute([$choir_id]);
        $totalOverdueAmount = $stmtVencidas->fetchColumn() ?? 0.00;
        
        // Últimos comprovantes recebidos
        $stmtLatestReceipts = $pdo->prepare("SELECT r.*, u.name as member_name FROM receipts r 
                                            JOIN users u ON r.member_id = u.id 
                                            WHERE u.choir_id = ? 
                                            ORDER BY r.id DESC LIMIT 5");
        $stmtLatestReceipts->execute([$choir_id]);
        $latestReceipts = $stmtLatestReceipts->fetchAll();
    } elseif ($user['role'] === 'colaborador') {
        // Colaborador do coral
        $stmtCantores = $pdo->prepare("SELECT COUNT(*) FROM users WHERE choir_id = ? AND role = 'membro'");
        $stmtCantores->execute([$choir_id]);
        $totalMembers = $stmtCantores->fetchColumn();
        
        $stmtLatestReceipts = $pdo->prepare("SELECT r.*, u.name as member_name FROM receipts r 
                                            JOIN users u ON r.member_id = u.id 
                                            WHERE u.choir_id = ? 
                                            ORDER BY r.id DESC LIMIT 5");
        $stmtLatestReceipts->execute([$choir_id]);
        $latestReceipts = $stmtLatestReceipts->fetchAll();
    } else {
        // Membro cantor
        // Saldo disponível na conta do membro
        $memberBalance = $user['balance'];
        
        // Valor total das cobranças em aberto vencidas ou com vencimento para hoje
        $stmtOpenCobrancas = $pdo->prepare("SELECT SUM(bi.amount - mb.paid_amount) as total_open_amount 
                                             FROM member_billing mb 
                                             JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                             WHERE mb.member_id = ? AND mb.status = 'open' AND mb.due_date <= CURRENT_DATE");
        $stmtOpenCobrancas->execute([$user['id']]);
        $billingStats = $stmtOpenCobrancas->fetch();
        $totalOpenAmount = $billingStats['total_open_amount'] ?? 0.00;
        
        // Últimos comprovantes dele
        $stmtMyReceipts = $pdo->prepare("SELECT * FROM receipts WHERE member_id = ? ORDER BY id DESC LIMIT 5");
        $stmtMyReceipts->execute([$user['id']]);
        $myReceipts = $stmtMyReceipts->fetchAll();
        
        // Cobranças em aberto para listagem direta e pagamento rápido
        $stmtMyBillings = $pdo->prepare("SELECT mb.*, bi.title, bi.amount as item_amount, bi.type as billing_type 
                                         FROM member_billing mb 
                                         JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                         WHERE mb.member_id = ? AND mb.status = 'open' 
                                         ORDER BY mb.due_date ASC");
        $stmtMyBillings->execute([$user['id']]);
        $myOpenBillings = $stmtMyBillings->fetchAll();
        
        // Cobranças pagas para histórico
        $stmtMyPaid = $pdo->prepare("SELECT mb.*, bi.title, bi.amount as item_amount, bi.type as billing_type 
                                     FROM member_billing mb 
                                     JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                     WHERE mb.member_id = ? AND mb.status = 'paid' 
                                     ORDER BY mb.paid_at DESC LIMIT 10");
        $stmtMyPaid->execute([$user['id']]);
        $myPaidBillings = $stmtMyPaid->fetchAll();
    }
}
?>

<!-- Saudação Principal -->
<div class="mb-6">
    <h1 class="text-2xl md:text-3xl font-extrabold font-outfit text-slate-900 dark:text-white">
        Olá, <?= htmlspecialchars($user['name']) ?>!
    </h1>
    <p class="text-sm text-slate-500 dark:text-slate-400">
        <?php if (is_superadmin()): ?>
            Painel Geral do Administrador do Sistema.
        <?php else: ?>
            Coral: <strong class="text-coral-500 font-bold"><?= htmlspecialchars($choir['name']) ?></strong> (Perfil: <span class="capitalize"><?= $user['role'] ?></span>)
        <?php endif; ?>
    </p>
</div>

<!-- ==============================================
     1. DASHBOARD DO SUPERADMIN
     ============================================== -->
<?php if (is_superadmin()): ?>
    <!-- Cards de Estatísticas -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
        <!-- Card Corais -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wider">Total de Corais</p>
                <h3 class="text-3xl font-bold font-outfit text-slate-800 dark:text-white mt-1"><?= $totalChoirs ?></h3>
            </div>
            <div class="p-3 bg-coral-500/10 text-coral-500 rounded-xl">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            </div>
        </div>
        
        <!-- Card Usuários Administrativos -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wider">Equipe Admin (Corais)</p>
                <h3 class="text-3xl font-bold font-outfit text-slate-800 dark:text-white mt-1"><?= $totalUsers ?></h3>
            </div>
            <div class="p-3 bg-indigo-500/10 text-indigo-500 rounded-xl">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            </div>
        </div>

        <!-- Card Membros Cantores -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wider">Cantores Registrados</p>
                <h3 class="text-3xl font-bold font-outfit text-slate-800 dark:text-white mt-1"><?= $totalMembers ?></h3>
            </div>
            <div class="p-3 bg-teal-500/10 text-teal-500 rounded-xl">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
        </div>
    </div>

    <!-- Seção de Ações e Listagens -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Atalhos Rápidos -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50 md:col-span-1">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Gerenciamento do Sistema</h2>
            <div class="flex flex-col gap-2">
                <a href="choirs.php?action=new" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-coral-500 hover:text-white dark:bg-slate-900 dark:hover:bg-coral-600 transition-all font-medium text-sm">
                    <span class="p-1 bg-coral-500 text-white rounded-lg group-hover:bg-white text-xs">+</span>
                    Novo Coral
                </a>
                <a href="smtp-settings.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-indigo-500 hover:text-white dark:bg-slate-900 dark:hover:bg-indigo-600 transition-all font-medium text-sm">
                    <span>⚙️</span>
                    Configurações SMTP
                </a>
                <a href="backups.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-teal-500 hover:text-white dark:bg-slate-900 dark:hover:bg-teal-600 transition-all font-medium text-sm">
                    <span>📦</span>
                    Gerar e Restaurar Backup
                </a>
                <a href="db-sync.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-purple-500 hover:text-white dark:bg-slate-900 dark:hover:bg-purple-600 transition-all font-medium text-sm">
                    <span>🔄</span>
                    Sincronizar Banco de Dados
                </a>
            </div>
        </div>
        
        <!-- Últimos Corais Cadastrados -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50 md:col-span-2">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white">Últimos Corais Criados</h2>
                <a href="choirs.php" class="text-xs text-coral-500 font-semibold hover:underline">Ver Todos</a>
            </div>
            
            <?php if (empty($latestChoirs)): ?>
                <div class="text-center py-8 text-sm text-slate-400">Nenhum coral cadastrado ainda.</div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-700/50">
                    <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                        <thead class="bg-slate-50 dark:bg-slate-900/60">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">ID</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Nome</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Cadastrado em</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                            <?php foreach ($latestChoirs as $lc): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-4 py-3 text-sm text-slate-500">#<?= $lc['id'] ?></td>
                                    <td class="px-4 py-3 text-sm font-semibold text-slate-800 dark:text-white"><?= htmlspecialchars($lc['name']) ?></td>
                                    <td class="px-4 py-3 text-sm text-slate-500"><?= format_date($lc['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<!-- ==============================================
     2. DASHBOARD DO ADMINISTRADOR / FINANCEIRO / COLABORADOR
     ============================================== -->
<?php elseif (is_admin_user() || $user['role'] === 'colaborador'): ?>
    
    <!-- Cards de Estatísticas -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4 mb-6">
        <!-- Cantores -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50">
            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Total de Cantores</p>
            <h3 class="text-2xl font-bold font-outfit text-slate-800 dark:text-white mt-1"><?= $totalMembers ?></h3>
        </div>
        
        <?php if (is_admin_user()): ?>
        <!-- Cobranças abertas -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50">
            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Cobranças em Aberto</p>
            <h3 class="text-2xl font-bold font-outfit text-slate-800 dark:text-white mt-1"><?= format_currency($openBillings) ?></h3>
        </div>

        <!-- Comprovantes pendentes -->
        <a href="payments.php" class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50 block hover:border-coral-500 transition-colors">
            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider flex items-center gap-1.5">
                Comprovantes Pendentes
                <?php if ($pendingReceipts > 0): ?>
                    <span class="w-2.5 h-2.5 bg-coral-500 rounded-full animate-ping"></span>
                <?php endif; ?>
            </p>
            <h3 class="text-2xl font-bold font-outfit text-slate-800 dark:text-white mt-1 flex items-center gap-2">
                <?= $pendingReceipts ?>
                <?php if ($pendingReceipts > 0): ?>
                    <span class="text-xs text-coral-500 font-normal">Aprovar</span>
                <?php endif; ?>
            </h3>
        </a>

        <!-- Caixa do Coral -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50">
            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Caixa Total (Aprovado)</p>
            <h3 class="text-2xl font-bold font-outfit text-emerald-500 mt-1"><?= format_currency($totalRevenue) ?></h3>
        </div>

        <!-- Cobranças Vencidas -->
        <a href="billing.php?tab=singers&view_type=all&status_filter=overdue"
           class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50 block hover:border-rose-500 transition-colors">
            <p class="text-[10px] text-slate-400 font-semibold uppercase tracking-wider">Total em Atraso (Vencido)</p>
            <h3 class="text-2xl font-bold font-outfit text-rose-500 mt-1"><?= format_currency($totalOverdueAmount) ?></h3>
        </a>
        <?php endif; ?>
    </div>

    <!-- Ações Rápidas de Admin -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Ações Rápidas</h2>
            <div class="flex flex-col gap-2">
                <?php if ($user['role'] === 'administrador'): ?>
                    <a href="users.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-coral-500 hover:text-white dark:bg-slate-900 dark:hover:bg-coral-600 transition-all font-medium text-sm">
                        <span>👥</span> Gerenciar Equipe (Users)
                    </a>
                <?php endif; ?>
                
                <?php if (is_admin_user()): ?>
                    <a href="members.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-indigo-500 hover:text-white dark:bg-slate-900 dark:hover:bg-indigo-600 transition-all font-medium text-sm">
                        <span>🎤</span> Gerenciar Cantores (Membros)
                    </a>
                    <a href="billing.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-teal-500 hover:text-white dark:bg-slate-900 dark:hover:bg-teal-600 transition-all font-medium text-sm">
                        <span>💰</span> Criar Itens de Cobrança
                    </a>
                <?php endif; ?>
                
                <a href="payments.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-purple-500 hover:text-white dark:bg-slate-900 dark:hover:bg-purple-600 transition-all font-medium text-sm">
                    <span>🧾</span> Comprovantes de Pagamento
                </a>
            </div>
        </div>

        <!-- Últimos Comprovantes Enviados (Para Coral) -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50 md:col-span-2">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white">Últimos Encomendados / Comprovantes</h2>
                <a href="payments.php" class="text-xs text-coral-500 font-semibold hover:underline">Ver Todos</a>
            </div>
            
            <?php if (empty($latestReceipts)): ?>
                <div class="text-center py-8 text-sm text-slate-400">Nenhum comprovante enviado ainda para este coral.</div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-700/50">
                    <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                        <thead class="bg-slate-50 dark:bg-slate-900/60">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Membro</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Data</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                            <?php foreach ($latestReceipts as $rec): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-4 py-3 text-sm font-semibold text-slate-800 dark:text-white"><?= htmlspecialchars($rec['member_name']) ?></td>
                                    <td class="px-4 py-3 text-sm font-bold text-slate-800 dark:text-white"><?= format_currency($rec['amount']) ?></td>
                                    <td class="px-4 py-3 text-sm text-slate-500"><?= format_date($rec['created_at']) ?></td>
                                    <td class="px-4 py-3 text-xs">
                                        <span class="px-2.5 py-0.5 rounded-full font-semibold <?= $rec['status'] === 'approved' ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-300' : ($rec['status'] === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300') ?>">
                                            <?= $rec['status'] === 'approved' ? 'Aprovado' : ($rec['status'] === 'rejected' ? 'Rejeitado' : 'Pendente') ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<!-- ==============================================
     3. DASHBOARD DO CANTOR / MEMBRO
     ============================================== -->
<?php else: ?>
    
    <!-- Cards de Estatísticas Membro -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
        <!-- Saldo Pessoal -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wider">Meu Saldo no Coral</p>
                <h3 class="text-3xl font-bold font-outfit text-emerald-500 mt-1"><?= format_currency($memberBalance) ?></h3>
                <p class="text-[10px] text-slate-400 mt-1">Créditos de comprovantes aprovados livre de cobrança.</p>
            </div>
            <div class="p-3 bg-emerald-500/10 text-emerald-500 rounded-xl">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
        </div>
        
        <!-- Total Aberto -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 flex items-center justify-between">
            <div>
                <p class="text-xs text-slate-400 font-semibold uppercase tracking-wider">Total a Pagar</p>
                <h3 class="text-3xl font-bold font-outfit text-rose-500 mt-1"><?= format_currency($totalOpenAmount) ?></h3>
                <p class="text-[10px] text-slate-400 mt-1">Cobranças vencidas ou com vencimento para hoje.</p>
            </div>
            <div class="p-3 bg-rose-500/10 text-rose-500 rounded-xl">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
            </div>
        </div>
    </div>

    <!-- Listagem e Envio -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <!-- Ações Rápidas Membro -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Minhas Operações</h2>
            <div class="flex flex-col gap-2.5">
                <a href="payments.php" class="flex items-center gap-3 p-3 rounded-xl bg-coral-500 text-white hover:bg-coral-600 transition-all font-semibold text-sm justify-center shadow-md">
                    <span>📤</span> Enviar Novo Comprovante
                </a>
                
                <?php if ($memberBalance > 0 && !empty($myOpenBillings)): ?>
                    <button onclick="document.getElementById('modal-pay-balance').classList.remove('hidden')" class="flex items-center gap-3 p-3 rounded-xl bg-emerald-500 text-white hover:bg-emerald-600 transition-all font-semibold text-sm justify-center shadow-md">
                        <span>💰</span> Pagar Itens Usando Saldo
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Minhas Cobranças em Aberto -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50 md:col-span-2">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Minhas Cobranças em Aberto</h2>
            
            <?php if (empty($myOpenBillings)): ?>
                <div class="text-center py-8 text-sm text-slate-400">Parabéns! Nenhuma cobrança em aberto no momento.</div>
            <?php else: ?>
                <div class="overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-700/50">
                    <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                        <thead class="bg-slate-50 dark:bg-slate-900/60">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Item</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Vencimento</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Valor</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                            <?php foreach ($myOpenBillings as $mb): 
                                $display_title = $mb['title'];
                                if (($mb['billing_type'] ?? '') === 'recurring') {
                                    $display_title .= ' - ' . date('m/Y', strtotime($mb['due_date']));
                                }
                            ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 cursor-pointer" onclick="openHistoryModal(<?= $mb['id'] ?>)">
                                    <td class="px-4 py-3 text-sm font-semibold text-slate-800 dark:text-white flex items-center gap-1.5">
                                        <?= htmlspecialchars($display_title) ?>
                                        <span class="text-[10px] text-slate-400">🔍</span>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-slate-500 <?= strtotime($mb['due_date']) < time() ? 'text-red-500 font-semibold' : '' ?>">
                                        <?= format_date($mb['due_date']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm font-bold text-slate-800 dark:text-white">
                                        <?= format_currency($mb['item_amount'] - $mb['paid_amount']) ?>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        <span class="px-2.5 py-0.5 rounded-full font-semibold <?= $mb['status'] === 'pending_approval' ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/20 dark:text-amber-300' : 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-300' ?>">
                                            <?= $mb['status'] === 'pending_approval' ? 'Aguardando Aprovação' : 'Aberto' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Histórico de Cobranças Pagas -->
            <div class="mt-8 border-t border-slate-100 dark:border-slate-700/50 pt-6">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Minhas Cobranças Pagas (Últimas 10)</h2>
                
                <?php if (empty($myPaidBillings)): ?>
                    <div class="text-center py-6 text-sm text-slate-400">Nenhuma cobrança paga registrada ainda.</div>
                <?php else: ?>
                    <div class="overflow-x-auto rounded-xl border border-slate-100 dark:border-slate-700/50">
                        <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                            <thead class="bg-slate-50 dark:bg-slate-900/60">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Item</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Pago em</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Valor Pago</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                                <?php foreach ($myPaidBillings as $mb): 
                                    $display_title = $mb['title'];
                                    if (($mb['billing_type'] ?? '') === 'recurring') {
                                        $display_title .= ' - ' . date('m/Y', strtotime($mb['due_date']));
                                    }
                                ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 cursor-pointer" onclick="openHistoryModal(<?= $mb['id'] ?>)">
                                        <td class="px-4 py-3 text-sm font-semibold text-slate-800 dark:text-white flex items-center gap-1.5">
                                            <?= htmlspecialchars($display_title) ?>
                                            <span class="text-[10px] text-slate-400">🔍</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-slate-500">
                                            <?= format_date($mb['paid_at']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm font-bold text-emerald-600 dark:text-emerald-400">
                                            <?= format_currency($mb['paid_amount']) ?>
                                        </td>
                                        <td class="px-4 py-3 text-xs">
                                            <span class="px-2.5 py-0.5 rounded-full font-semibold bg-green-100 text-green-800 dark:bg-green-950/20 dark:text-green-300">
                                                Pago
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal para Pagar usando Saldo -->
    <?php if ($memberBalance > 0 && !empty($myOpenBillings)): ?>
        <div id="modal-pay-balance" class="fixed inset-0 z-50 overflow-y-auto hidden">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity bg-slate-900/80 backdrop-blur-sm" onclick="document.getElementById('modal-pay-balance').classList.add('hidden')"></div>
                <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
                
                <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full border border-slate-100 dark:border-slate-700/50 p-6">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white mb-2">Pagar com Saldo</h3>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                        Seu saldo atual é de <strong class="text-emerald-500"><?= format_currency($memberBalance) ?></strong>. 
                        Escolha uma cobrança abaixo para efetuar o abatimento usando esse saldo.
                    </p>
                    
                    <form action="payments.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="pay_with_balance">
                        <div>
                            <label for="member_billing_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Item de Cobrança</label>
                            <select name="member_billing_id" id="member_billing_id" required
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="">Selecione...</option>
                                <?php foreach ($myOpenBillings as $mb): 
                                    if ($mb['status'] === 'open'): 
                                        $display_title = $mb['title'];
                                        if (($mb['billing_type'] ?? '') === 'recurring') {
                                            $display_title .= ' - ' . date('m/Y', strtotime($mb['due_date']));
                                        }
                                    ?>
                                        <option value="<?= $mb['id'] ?>">
                                            <?= htmlspecialchars($display_title) ?> (Resta: <?= format_currency($mb['item_amount'] - $mb['paid_amount']) ?>)
                                        </option>
                                    <?php endif; 
                                endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="flex justify-end gap-2 pt-4">
                            <button type="button" onclick="document.getElementById('modal-pay-balance').classList.add('hidden')"
                                    class="px-4 py-2 text-xs font-semibold rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white transition-colors">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="px-4 py-2 text-xs font-semibold rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white transition-colors">
                                Confirmar Pagamento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<!-- Modal de Histórico de Cobrança (Fancy Timeline Style) -->
<div id="modal-billing-history" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <!-- Backdrop Blur -->
        <div class="fixed inset-0 transition-opacity bg-slate-900/60 backdrop-blur-sm" onclick="closeHistoryModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
        
        <!-- Modal Card -->
        <div class="inline-block align-middle bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8 relative">
            
            <!-- Botão Fechar -->
            <button onclick="closeHistoryModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            
            <!-- Header do Modal -->
            <div class="mb-6">
                <div class="flex items-center justify-between gap-4">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white" id="history-title">...</h3>
                    <span id="history-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold">...</span>
                </div>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1" id="history-description">...</p>
            </div>
            
            <!-- Tabela Resumo Financeiro da Cobrança -->
            <div class="bg-slate-50 dark:bg-slate-900/60 p-4 rounded-xl border border-slate-100 dark:border-slate-800 text-xs mb-6 grid grid-cols-3 gap-2 text-center">
                <div>
                    <span class="text-slate-400 block mb-0.5">Valor Original</span>
                    <span class="font-bold text-slate-800 dark:text-white text-sm" id="history-amount">R$ 0,00</span>
                </div>
                <div>
                    <span class="text-slate-400 block mb-0.5">Total Pago</span>
                    <span class="font-bold text-emerald-600 dark:text-emerald-400 text-sm" id="history-paid">R$ 0,00</span>
                </div>
                <div>
                    <span class="text-slate-400 block mb-0.5">Saldo Restante</span>
                    <span class="font-bold text-rose-500 dark:text-rose-400 text-sm" id="history-remaining">R$ 0,00</span>
                </div>
            </div>
            
            <!-- Timeline de Eventos -->
            <div class="space-y-4">
                <h4 class="text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-3">Histórico e Linha do Tempo</h4>
                
                <div class="relative border-l border-slate-200 dark:border-slate-700 ml-3.5 space-y-6" id="history-timeline-container">
                    <!-- Gerado Dinamicamente -->
                </div>
            </div>
            
            <!-- Botão de Fechar -->
            <div class="flex justify-end pt-6 mt-4 border-t border-slate-100 dark:border-slate-700/50">
                <button type="button" onclick="closeHistoryModal()"
                        class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-semibold text-xs transition-colors">
                    Fechar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openHistoryModal(billingId) {
    // Exibir loading ou limpar container
    const container = document.getElementById('history-timeline-container');
    container.innerHTML = `
        <div class="flex items-center justify-center py-4">
            <span class="text-xs text-slate-400">Carregando histórico...</span>
        </div>
    `;
    
    document.getElementById('modal-billing-history').classList.remove('hidden');
    
    // Fetch AJAX
    fetch('dashboard.php?ajax_action=get_billing_history&billing_id=' + billingId)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.error || 'Erro ao carregar histórico.');
                closeHistoryModal();
                return;
            }
            
            // Preencher detalhes
            document.getElementById('history-title').innerText = data.billing.title;
            document.getElementById('history-description').innerText = data.billing.billing_desc || 'Sem descrição adicional.';
            document.getElementById('history-amount').innerText = data.billing.formatted_amount;
            document.getElementById('history-paid').innerText = data.billing.formatted_paid;
            document.getElementById('history-remaining').innerText = data.billing.formatted_remaining;
            
            // Badge Status
            const badge = document.getElementById('history-badge');
            badge.className = "px-2.5 py-0.5 rounded-full text-xs font-semibold ";
            if (data.billing.status === 'paid') {
                badge.innerText = 'Pago';
                badge.classList.add('bg-green-100', 'text-green-800', 'dark:bg-green-900/20', 'dark:text-green-300');
            } else if (data.billing.status === 'pending_approval') {
                badge.innerText = 'Aguardando Aprovação';
                badge.classList.add('bg-amber-100', 'text-amber-800', 'dark:bg-amber-900/20', 'dark:text-amber-300');
            } else {
                badge.innerText = 'Em Aberto';
                badge.classList.add('bg-red-100', 'text-red-800', 'dark:bg-red-900/20', 'dark:text-red-300');
            }
            
            // Montar Timeline
            let timelineHtml = '';
            
            // Evento 1: Criação da cobrança
            timelineHtml += createTimelineItem(
                '🆕',
                'Cobrança Gerada',
                `A cobrança foi atribuída ao cantor em ${data.billing.formatted_created_at} com vencimento original definido para <strong>${data.billing.formatted_due_date}</strong>.`,
                data.billing.formatted_created_at
            );
            
            // Eventos de pagamento
            if (data.history && data.history.length > 0) {
                data.history.forEach(p => {
                    let icon = '💵';
                    let title = 'Envio de Comprovante';
                    let desc = '';
                    
                    if (p.filename === 'balance_deduction') {
                        icon = '🔄';
                        title = 'Baixa Manual com Saldo';
                        desc = `Abatimento de <strong>${p.formatted_amount}</strong> debitado do saldo da conta do cantor. Realizado por administrador/financeiro.`;
                    } else if (p.filename === 'voucher_deduction') {
                        icon = '🎫';
                        title = 'Pagamento via Voucher / Cortesia';
                        const obsMatch = p.description ? p.description.match(/Observação: (.+)$/) : null;
                        const obsText = obsMatch ? ` Observação: <em>"${obsMatch[1]}"</em>` : '';
                        desc = `Abatimento de <strong>${p.formatted_amount}</strong> registrado como cortesia/voucher por administrador/financeiro.${obsText}`;
                    } else {
                        const depName = p.depositor_name ? (p.depositor_code ? `${p.depositor_name} (${p.depositor_code})` : p.depositor_name) : 'Cantor';
                        if (p.status === 'approved') {
                            icon = '✅';
                            title = 'Pagamento Confirmado';
                            desc = `Comprovante enviado por <strong>${depName}</strong> no valor total de <strong>${p.formatted_amount}</strong> aprovado e homologado pelo administrador.`;
                            if (p.formatted_approved_at) {
                                desc += ` (Aprovado em ${p.formatted_approved_at})`;
                            }
                        } else if (p.status === 'rejected') {
                            icon = '❌';
                            title = 'Comprovante Rejeitado';
                            desc = `Comprovante enviado por <strong>${depName}</strong> no valor total de <strong>${p.formatted_amount}</strong> rejeitado pelo administrador.`;
                            if (p.description) {
                                desc += ` Motivo: <em>"${p.description}"</em>`;
                            }
                        } else {
                            icon = '⏳';
                            title = 'Aguardando Validação';
                            desc = `Comprovante enviado por <strong>${depName}</strong> no valor total de <strong>${p.formatted_amount}</strong> aguardando verificação do administrador.`;
                        }
                    }
                    
                    timelineHtml += createTimelineItem(icon, title, desc, p.formatted_created_at);
                });
            }
            
            // Evento Final: Quitação se estiver pago
            if (data.billing.status === 'paid') {
                const paidDate = data.billing.paid_at ? formatDateString(data.billing.paid_at) : '';
                timelineHtml += createTimelineItem(
                    '🎉',
                    'Cobrança Quitada',
                    `A cobrança foi totalmente baixada e regularizada.`,
                    paidDate
                );
            }
            
            container.innerHTML = timelineHtml;
        })
        .catch(err => {
            console.error(err);
            container.innerHTML = `
                <div class="flex flex-col items-center justify-center py-4 text-rose-500 font-semibold text-xs">
                    ⚠️ Erro ao carregar o histórico de cobrança.
                </div>
            `;
        });
}

function closeHistoryModal() {
    document.getElementById('modal-billing-history').classList.add('hidden');
}

function createTimelineItem(icon, title, desc, dateStr) {
    return `
        <div class="relative pl-7 pb-2">
            <!-- Ponto marcador -->
            <span class="absolute left-[-16px] top-0 bg-white dark:bg-slate-800 border-2 border-slate-200 dark:border-slate-700 w-8 h-8 rounded-full flex items-center justify-center text-sm shadow-sm">
                ${icon}
            </span>
            <!-- Conteúdo -->
            <div class="bg-slate-50 dark:bg-slate-900/40 p-3 rounded-xl border border-slate-100 dark:border-slate-800/80 text-xs">
                <div class="flex justify-between items-center mb-1">
                    <span class="font-bold text-slate-800 dark:text-white">${title}</span>
                    <span class="text-[10px] text-slate-400">${dateStr}</span>
                </div>
                <p class="text-slate-600 dark:text-slate-400 font-normal leading-relaxed">${desc}</p>
            </div>
        </div>
    `;
}

function formatDateString(dateStr) {
    if (!dateStr) return '';
    try {
        const parts = dateStr.split(' ');
        const dateParts = parts[0].split('-');
        const timeParts = parts[1] ? parts[1].split(':') : null;
        let formatted = `${dateParts[2]}/${dateParts[1]}/${dateParts[0]}`;
        if (timeParts) {
            formatted += ` ${timeParts[0]}:${timeParts[1]}`;
        }
        return formatted;
    } catch(e) {
        return dateStr;
    }
}
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
