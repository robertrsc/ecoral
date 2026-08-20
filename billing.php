<?php
// billing.php
require_once __DIR__ . '/config.php';

// Apenas usuários administradores (superadmin, administrador, financeiro)
require_login();
if (!is_admin_user()) {
    set_flash_message('error', 'Você não tem permissão para acessar esta página.');
    header("Location: dashboard.php");
    exit;
}

$loggedUser = get_logged_user();
$error = null;
$success = null;
$action = $_GET['action'] ?? 'list';

// Identificar o coral_id do contexto
if (is_superadmin()) {
    $choir_id = intval($_GET['choir_id'] ?? $_SESSION['admin_choir_id'] ?? 0);
    // Se for listagem e não tiver coral selecionado, mostra seletor de coral primeiro
    if ($choir_id > 0) {
        $_SESSION['admin_choir_id'] = $choir_id;
    }
} else {
    $choir_id = $loggedUser['choir_id'];
}

// Carregar corais se for superadmin
$choirs = [];
if (is_superadmin()) {
    $choirs = $pdo->query("SELECT id, name FROM choirs ORDER BY name ASC")->fetchAll();
}

// Se tiver coral selecionado ou se for usuário de coral
$members = [];
if ($choir_id > 0) {
    // Carregar membros ativos do coral para atribuição individual
    $stmt = $pdo->prepare("SELECT id, name, voice_type FROM users WHERE choir_id = ? AND role = 'membro' AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$choir_id]);
    $members = $stmt->fetchAll();
}

// Processar criação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'new') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0.00);
    $type = trim($_POST['type'] ?? 'eventual'); // eventual ou recurring
    
    // Alocação de membros
    $target_type = trim($_POST['target_type'] ?? 'all'); // all ou selected
    $selected_members = $_POST['selected_members'] ?? [];
    
    if (empty($title) || $amount <= 0 || empty($choir_id)) {
        $error = 'Por favor, preencha o título, um valor positivo e selecione o coral.';
    } else {
        // Obter membros alvo
        $target_ids = [];
        if ($target_type === 'all') {
            foreach ($members as $m) {
                $target_ids[] = $m['id'];
            }
        } else {
            $target_ids = array_map('intval', $selected_members);
        }
        
        if (empty($target_ids)) {
            $error = 'Você precisa associar esta cobrança a pelo menos um cantor.';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Gerar cobranças
                if ($type === 'eventual') {
                    $due_date = $_POST['due_date'] ?? date('Y-m-d');
                    
                    // Insere item de cobrança pai
                    $stmtBI = $pdo->prepare("INSERT INTO billing_items (choir_id, title, description, amount, type, due_date) VALUES (?, ?, ?, ?, 'eventual', ?)");
                    $stmtBI->execute([$choir_id, $title, $description, $amount, $due_date]);
                    $billing_item_id = $pdo->lastInsertId();
                    
                    // Associa aos membros
                    $stmtMB = $pdo->prepare("INSERT INTO member_billing (member_id, billing_item_id, status, due_date) VALUES (?, ?, 'open', ?)");
                    foreach ($target_ids as $m_id) {
                        $stmtMB->execute([$m_id, $billing_item_id, $due_date]);
                    }
                } else {
                    // Recorrente por data (Mensalidades em intervalo de datas)
                    $start_date_str = $_POST['start_date'] ?? date('Y-m-d');
                    $end_date_str = $_POST['end_date'] ?? date('Y-m-d');
                    
                    $start = new DateTime($start_date_str);
                    $end = new DateTime($end_date_str);
                    
                    // Gerar cobranças mensais
                    $interval = new DateInterval('P1M');
                    $period = new DatePeriod($start, $interval, $end->modify('+1 day')); // +1 day para incluir o último mês
                    
                    foreach ($period as $dt) {
                        $current_due = $dt->format('Y-m-d');
                        $month_year = $dt->format('m/Y');
                        $current_title = "$title - $month_year";
                        
                        // Insere item de cobrança pai para o mês
                        $stmtBI = $pdo->prepare("INSERT INTO billing_items (choir_id, title, description, amount, type, due_date) VALUES (?, ?, ?, ?, 'recurring', ?)");
                        $stmtBI->execute([$choir_id, $current_title, $description, $amount, $current_due]);
                        $billing_item_id = $pdo->lastInsertId();
                        
                        // Associa aos membros para o mês
                        $stmtMB = $pdo->prepare("INSERT INTO member_billing (member_id, billing_item_id, status, due_date) VALUES (?, ?, 'open', ?)");
                        foreach ($target_ids as $m_id) {
                            $stmtMB->execute([$m_id, $billing_item_id, $current_due]);
                        }
                    }
                }
                
                $pdo->commit();
                set_flash_message('success', 'Itens de cobrança cadastrados e atribuídos aos cantores com sucesso!');
                header("Location: billing.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Erro ao processar cobranças: ' . $e->getMessage();
            }
        }
    }
}

// Excluir Cobrança Pai (E todas as vinculadas de membros)
if ($action === 'delete') {
    $delete_id = intval($_GET['id'] ?? 0);
    if ($delete_id > 0) {
        try {
            // Verificar se pertence ao coral
            if (!is_superadmin()) {
                $stmtCheck = $pdo->prepare("SELECT choir_id FROM billing_items WHERE id = ?");
                $stmtCheck->execute([$delete_id]);
                if ($stmtCheck->fetchColumn() != $choir_id) {
                    set_flash_message('error', 'Acesso negado.');
                    header("Location: billing.php");
                    exit;
                }
            }
            
            $stmt = $pdo->prepare("DELETE FROM billing_items WHERE id = ?");
            $stmt->execute([$delete_id]);
            set_flash_message('success', 'Item de cobrança removido com sucesso.');
        } catch (PDOException $e) {
            set_flash_message('error', 'Erro ao excluir item: ' . $e->getMessage());
        }
    }
    header("Location: billing.php");
    exit;
}

// Carregar cobranças criadas para o coral ativo
$billing_items = [];
if ($action === 'list' && $choir_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT bi.*, 
            (SELECT COUNT(*) FROM member_billing mb WHERE mb.billing_item_id = bi.id) as assigned_count,
            (SELECT COUNT(*) FROM member_billing mb WHERE mb.billing_item_id = bi.id AND mb.status = 'paid') as paid_count
            FROM billing_items bi 
            WHERE bi.choir_id = ? 
            ORDER BY bi.due_date DESC, bi.id DESC");
        $stmt->execute([$choir_id]);
        $billing_items = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Erro ao carregar cobranças: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<!-- Seletor de Coral para Superadmin -->
<?php if (is_superadmin() && $action === 'list'): ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50 mb-6">
        <form action="billing.php" method="GET" class="flex flex-col sm:flex-row items-end gap-3">
            <div class="flex-1 w-full">
                <label for="choir_id_select" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Coral para Gestão de Cobranças</label>
                <select name="choir_id" id="choir_id_select" onchange="this.form.submit()"
                        class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    <option value="">Selecione um coral...</option>
                    <?php foreach ($choirs as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $choir_id == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>💰</span> Itens de Cobrança
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Cadastre mensalidades, uniformes, custos de viagens, etc. e atribua aos cantores.
        </p>
    </div>
    
    <?php if ($action === 'list' && $choir_id > 0): ?>
        <a href="billing.php?action=new" 
           class="px-3.5 py-2 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
            + Criar Cobrança
        </a>
    <?php elseif ($action !== 'list'): ?>
        <a href="billing.php" 
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

<?php if ($choir_id == 0 && $action === 'list'): ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-8 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50">
        Selecione um coral para gerenciar e criar itens de cobrança.
    </div>
<?php else: ?>

    <!-- ==============================================
         FORMULÁRIO: CRIAR COBRANÇA
         ============================================== -->
    <?php if ($action === 'new'): ?>
        <div class="max-w-3xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
            <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white mb-4">Nova Cobrança</h2>
            
            <form action="billing.php?action=new" method="POST" class="space-y-4">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="title" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Título da Cobrança *</label>
                        <input type="text" name="title" id="title" required
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: Mensalidade, Camisa do Coral">
                    </div>
                    <div>
                        <label for="amount" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Valor Unitário (R$) *</label>
                        <input type="number" name="amount" id="amount" step="0.01" min="0.01" required
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="0.00">
                    </div>
                </div>

                <div>
                    <label for="description" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Descrição detalhada</label>
                    <textarea name="description" id="description" rows="2"
                              class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                              placeholder="Informações adicionais para os cantores."></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-2">
                    <div>
                        <label for="type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Periodicidade *</label>
                        <select name="type" id="type" onchange="toggleDateInputs(this.value)" required
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                            <option value="eventual">Eventual (Cobrança Única)</option>
                            <option value="recurring">Recorrente Mensal (Intervalo de Datas)</option>
                        </select>
                    </div>
                    
                    <!-- Vencimento para Eventual -->
                    <div id="due_date_container">
                        <label for="due_date" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Data de Vencimento *</label>
                        <input type="date" name="due_date" id="due_date" value="<?= date('Y-m-d') ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>

                    <!-- Intervalo de Datas para Recorrente (Oculto por padrão) -->
                    <div id="recurring_dates_container" class="hidden grid grid-cols-2 gap-2">
                        <div>
                            <label for="start_date" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Início *</label>
                            <input type="date" name="start_date" id="start_date" value="<?= date('Y-m-d') ?>"
                                   class="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        </div>
                        <div>
                            <label for="end_date" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Fim *</label>
                            <input type="date" name="end_date" id="end_date" value="<?= date('Y-m-d', strtotime('+3 months')) ?>"
                                   class="w-full px-3 py-2 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        </div>
                    </div>
                </div>

                <!-- Atribuição de Membros -->
                <div class="pt-4 border-t border-slate-100 dark:border-slate-700/50">
                    <h3 class="text-xs font-semibold text-slate-600 dark:text-slate-300 mb-2">Atribuição de Membros</h3>
                    
                    <div class="flex gap-4 mb-4">
                        <label class="inline-flex items-center text-xs font-medium cursor-pointer">
                            <input type="radio" name="target_type" value="all" checked onchange="toggleMemberSelection('all')"
                                   class="w-4 h-4 text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700">
                            <span class="ml-2 text-slate-700 dark:text-slate-300">Todos os cantores ativos</span>
                        </label>
                        <label class="inline-flex items-center text-xs font-medium cursor-pointer">
                            <input type="radio" name="target_type" value="selected" onchange="toggleMemberSelection('selected')"
                                   class="w-4 h-4 text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700">
                            <span class="ml-2 text-slate-700 dark:text-slate-300">Selecionar cantores específicos</span>
                        </label>
                    </div>

                    <!-- Lista de Checkboxes de Cantores (Oculta por padrão) -->
                    <div id="members_selection_list" class="hidden border border-slate-200 dark:border-slate-700/50 rounded-xl p-4 max-h-48 overflow-y-auto bg-slate-50 dark:bg-slate-900/40 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <?php if (empty($members)): ?>
                            <p class="text-xs text-slate-400 col-span-2">Não há membros ativos cadastrados neste coral.</p>
                        <?php else: ?>
                            <?php foreach ($members as $m): ?>
                                <label class="inline-flex items-center text-xs text-slate-700 dark:text-slate-300 cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 p-1.5 rounded">
                                    <input type="checkbox" name="selected_members[]" value="<?= $m['id'] ?>"
                                           class="w-4 h-4 rounded text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700">
                                    <span class="ml-2">
                                        <?= htmlspecialchars($m['name']) ?> 
                                        <span class="text-[10px] text-slate-400 capitalize">(<?= $m['voice_type'] ?? 'Indefinido' ?>)</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-6">
                    <a href="billing.php"
                       class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                        Cancelar
                    </a>
                    <button type="submit"
                            class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                        Gerar Cobrança
                    </button>
                </div>
            </form>
        </div>

        <script>
            function toggleDateInputs(value) {
                const single = document.getElementById('due_date_container');
                const multiple = document.getElementById('recurring_dates_container');
                
                if (value === 'eventual') {
                    single.classList.remove('hidden');
                    multiple.classList.add('hidden');
                } else {
                    single.classList.add('hidden');
                    multiple.classList.remove('hidden');
                }
            }

            function toggleMemberSelection(value) {
                const list = document.getElementById('members_selection_list');
                if (value === 'all') {
                    list.classList.add('hidden');
                } else {
                    list.classList.remove('hidden');
                }
            }
        </script>

    <!-- ==============================================
         LISTA DE COBRANÇAS
         ============================================== -->
    <?php else: ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
            <?php if (empty($billing_items)): ?>
                <div class="text-center py-12 text-slate-400">
                    Nenhum item de cobrança criado. Clique em "+ Criar Cobrança" para começar.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                        <thead class="bg-slate-50 dark:bg-slate-900/60">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Item / Descrição</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Vencimento</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Valor</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Periodicidade</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Quitados</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                            <?php foreach ($billing_items as $bi): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="font-semibold text-slate-800 dark:text-white"><?= htmlspecialchars($bi['title']) ?></div>
                                        <div class="text-xs text-slate-400 max-w-xs truncate"><?= htmlspecialchars($bi['description'] ?? '-') ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                        <?= format_date($bi['due_date']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-bold text-slate-800 dark:text-white">
                                        <?= format_currency($bi['amount']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-xs font-medium">
                                        <span class="px-2 py-0.5 rounded capitalize
                                            <?= $bi['type'] === 'recurring' ? 'bg-purple-100 text-purple-800 dark:bg-purple-950/20 dark:text-purple-300' : 'bg-slate-100 text-slate-800 dark:bg-slate-700/50 dark:text-slate-300' ?>">
                                            <?= $bi['type'] === 'recurring' ? 'Recorrente' : 'Eventual' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-semibold text-slate-500">
                                        <span class="text-emerald-500"><?= $bi['paid_count'] ?></span> / <?= $bi['assigned_count'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium">
                                        <a href="billing.php?action=delete&id=<?= $bi['id'] ?>" 
                                           onclick="return confirm('Deseja realmente excluir este item de cobrança? Isso apagará a cobrança correspondente de TODOS os cantores associados.')"
                                           class="text-red-500 hover:text-red-600 font-bold">Excluir</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
