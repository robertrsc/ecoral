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
    // Suporta: hidden da máscara (1234.56), campo display (1.234,56) ou numérico puro
    $amount = parse_currency_input($_POST['amount'] ?? $_POST['amount_display'] ?? '0');
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
                    
                    // Converte target_ids para JSON se target_type for 'selected'
                    $target_members_json = ($target_type === 'selected') ? json_encode($target_ids) : null;
                    
                    // Insere item de cobrança pai
                    $stmtBI = $pdo->prepare("INSERT INTO billing_items (choir_id, title, description, amount, type, due_date, target_type, target_members) VALUES (?, ?, ?, ?, 'eventual', ?, ?, ?)");
                    $stmtBI->execute([$choir_id, $title, $description, $amount, $due_date, $target_type, $target_members_json]);
                    $billing_item_id = $pdo->lastInsertId();
                    
                    // Associa aos membros
                    $stmtMB = $pdo->prepare("INSERT INTO member_billing (member_id, billing_item_id, status, due_date) VALUES (?, ?, 'open', ?)");
                    foreach ($target_ids as $m_id) {
                        $stmtMB->execute([$m_id, $billing_item_id, $due_date]);
                    }
                } else {
                    // Recorrente por data (Salva modelo de cobrança recorrente)
                    $start_date_str = $_POST['start_date'] ?? date('Y-m-d');
                    $end_date_str = $_POST['end_date'] ?? date('Y-m-d');
                    
                    // Converte target_ids para JSON se target_type for 'selected'
                    $target_members_json = ($target_type === 'selected') ? json_encode($target_ids) : null;
                    
                    // Insere item de cobrança pai (template)
                    $stmtBI = $pdo->prepare("INSERT INTO billing_items (choir_id, title, description, amount, type, due_date, start_date, end_date, target_type, target_members) VALUES (?, ?, ?, ?, 'recurring', ?, ?, ?, ?, ?)");
                    $stmtBI->execute([$choir_id, $title, $description, $amount, $start_date_str, $start_date_str, $end_date_str, $target_type, $target_members_json]);
                    
                    // Executa a sincronização para gerar as cobranças vigentes imediatamente
                    sync_recurring_billings($pdo, $choir_id);
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

// Tratar pagamento parcial manual com saldo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_payment') {
    $mb_id = intval($_POST['member_billing_id'] ?? 0);
    // Suporta: hidden da máscara (1234.56), campo display (1.234,56) ou numérico puro
    $pay_amount = parse_currency_input($_POST['pay_amount'] ?? $_POST['pay_amount_display'] ?? '0');
    $payment_source = trim($_POST['payment_source_hidden'] ?? $_POST['payment_source'] ?? 'balance');
    $voucher_code = trim($_POST['voucher_code'] ?? '');
    
    if (!is_admin_user()) {
        set_flash_message('error', 'Operação permitida apenas para administradores/financeiros.');
    } elseif ($mb_id <= 0 || $pay_amount <= 0) {
        set_flash_message('error', 'Por favor, informe valores válidos.');
    } else {
        try {
            $pdo->beginTransaction();
            
            // Buscar dados da cobrança e do membro garantindo que pertencem ao coral gerido (ou bypass se superadmin)
            $stmtMB = $pdo->prepare("
                SELECT mb.*, bi.title, bi.amount, bi.type as billing_type, u.id as member_id, u.name as member_name, u.email as member_email, u.balance as member_balance, u.choir_id as member_choir_id
                FROM member_billing mb
                JOIN billing_items bi ON mb.billing_item_id = bi.id
                JOIN users u ON mb.member_id = u.id
                WHERE mb.id = ? AND (u.choir_id = ? OR ?)
            ");
            $stmtMB->execute([$mb_id, $choir_id, is_superadmin() ? 1 : 0]);
            $mbData = $stmtMB->fetch();
            
            if (!$mbData) {
                throw new Exception('Cobrança não encontrada ou acesso negado.');
            }
            
            $remaining = floatval($mbData['amount']) - floatval($mbData['paid_amount']);
            
            if ($payment_source === 'balance') {
                if ($pay_amount > floatval($mbData['member_balance'])) {
                    throw new Exception('O valor a pagar não pode ser maior que o saldo disponível do membro (' . format_currency($mbData['member_balance']) . ').');
                }
            }
            
            if ($pay_amount > $remaining) {
                throw new Exception('O valor a pagar não pode ser maior que o valor restante da cobrança (' . format_currency($remaining) . ').');
            }
            
            $member_choir_id = $mbData['member_choir_id'];
            $new_balance = floatval($mbData['member_balance']);
            
            if ($payment_source === 'balance') {
                // Deduzir do saldo do membro
                $new_balance = floatval($mbData['member_balance']) - $pay_amount;
                $stmtUpdateBal = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $stmtUpdateBal->execute([$new_balance, $mbData['member_id']]);
                
                $desc = "Baixa manual realizada por " . $loggedUser['name'] . " usando saldo da conta.";
                $receipt_filename = 'balance_deduction';
            } elseif ($payment_source === 'voucher') {
                // Voucher: saldo do membro não é tocado
                $obs_part = !empty($voucher_code) ? " Observação: " . $voucher_code : "";
                $desc = "Baixa manual via Voucher / Cortesia (Registrado por " . $loggedUser['name'] . ")." . $obs_part;
                $receipt_filename = 'voucher_deduction';
            } else {
                // Registro manual / Baixa sem exigência de saldo em conta
                $obs_part = !empty($voucher_code) ? " Observação: " . $voucher_code : "";
                $desc = "Baixa manual (Registro direto / transporte de registros) realizada por " . $loggedUser['name'] . "." . $obs_part;
                $receipt_filename = 'manual_record';
            }
            
            // Adicionar ao valor pago da cobrança
            $new_paid_amount = floatval($mbData['paid_amount']) + $pay_amount;
            $new_status = (abs($new_paid_amount - floatval($mbData['amount'])) < 0.001) ? 'paid' : 'open';
            $paid_at_sql = ($new_status === 'paid') ? ', paid_at = NOW()' : '';
            
            $stmtUpdateMB = $pdo->prepare("UPDATE member_billing SET paid_amount = ?, status = ? $paid_at_sql WHERE id = ?");
            $stmtUpdateMB->execute([$new_paid_amount, $new_status, $mb_id]);
            
            // Salvar no histórico de comprovantes
            $stmtInsertReceipt = $pdo->prepare("INSERT INTO receipts (member_id, amount, filename, description, status) VALUES (?, ?, ?, ?, 'approved')");
            $stmtInsertReceipt->execute([$mbData['member_id'], $pay_amount, $receipt_filename, $desc]);
            $receipt_id = $pdo->lastInsertId();
            
            // Vincular item
            $stmtLinkReceipt = $pdo->prepare("INSERT INTO receipt_billing_items (receipt_id, member_billing_id) VALUES (?, ?)");
            $stmtLinkReceipt->execute([$receipt_id, $mb_id]);
            
            $pdo->commit();
            
            // Enviar e-mail de notificação para o cantor
            try {
                // Obter dados do coral para o e-mail
                $stmtChoirInfo = $pdo->prepare("SELECT name, logo FROM choirs WHERE id = ?");
                $stmtChoirInfo->execute([$member_choir_id]);
                $choirObj = $stmtChoirInfo->fetch();
                $choirNameEmail = $choirObj ? $choirObj['name'] : 'eCoral';
                
                $logoEmailHtml = "";
                if ($choirObj && !empty($choirObj['logo'])) {
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $logoUrl = $protocol . "://" . $host . "/www/ecoral/uploads/" . $choirObj['logo'];
                    $logoEmailHtml = "<div style='text-align: center; margin-bottom: 20px;'><img src='" . htmlspecialchars($logoUrl) . "' style='max-height: 80px; object-fit: contain; background-color: #ffffff; padding: 4px; border-radius: 8px;' alt='Logo " . htmlspecialchars($choirNameEmail) . "'></div>";
                }
                
                $billingTitleFormatted = $mbData['title'];
                if ($mbData['billing_type'] === 'recurring') {
                    $billingTitleFormatted .= ' - ' . date('m/Y', strtotime($mbData['due_date']));
                }
                
                $subject = "Notificação de Pagamento Manual - " . $billingTitleFormatted;
                
                $remaining_after_payment = floatval($mbData['amount']) - $new_paid_amount;
                $status_message = $new_status === 'paid' 
                    ? "<p style='color: #10b981; font-weight: bold;'>Esta cobrança foi totalmente quitada.</p>" 
                    : "<p>Valor restante em aberto nesta cobrança: <strong>" . format_currency($remaining_after_payment) . "</strong>.</p>";
                
                if ($payment_source === 'balance') {
                    $body = "
                        <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                            " . $logoEmailHtml . "
                            <h2 style='color: #f43f5e; margin-top: 0;'>Olá, " . htmlspecialchars($mbData['member_name']) . "!</h2>
                            <p>Informamos que foi realizado um <strong>pagamento parcial / baixa manual</strong> em sua cobrança utilizando seu saldo disponível no coral.</p>
                            
                            <div style='background-color: #f8fafc; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0; border-radius: 4px;'>
                                <p style='margin: 0 0 8px 0;'><strong>Cobrança:</strong> " . htmlspecialchars($billingTitleFormatted) . "</p>
                                <p style='margin: 0 0 8px 0;'><strong>Valor Deduzido do Saldo:</strong> " . format_currency($pay_amount) . "</p>
                                <p style='margin: 0 0 8px 0;'><strong>Seu Saldo Restante:</strong> " . format_currency($new_balance) . "</p>
                                <p style='margin: 0;'><strong>Total Já Pago neste Item:</strong> " . format_currency($new_paid_amount) . " (de " . format_currency($mbData['amount']) . ")</p>
                            </div>
                            
                            " . $status_message . "
                            
                            <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($choirNameEmail) . "</p>
                        </div>
                    ";
                } elseif ($payment_source === 'voucher') {
                    $body = "
                        <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                            " . $logoEmailHtml . "
                            <h2 style='color: #f43f5e; margin-top: 0;'>Olá, " . htmlspecialchars($mbData['member_name']) . "!</h2>
                            <p>Informamos que foi realizado um <strong>pagamento / baixa via Voucher ou Cortesia</strong> em sua cobrança.</p>
                            
                            <div style='background-color: #f8fafc; border-left: 4px solid #10b981; padding: 16px; margin: 20px 0; border-radius: 4px;'>
                                <p style='margin: 0 0 8px 0;'><strong>Cobrança:</strong> " . htmlspecialchars($billingTitleFormatted) . "</p>
                                <p style='margin: 0 0 8px 0;'><strong>Valor Abatido:</strong> " . format_currency($pay_amount) . "</p>
                                " . (!empty($voucher_code) ? "<p style='margin: 0 0 8px 0;'><strong>Observação:</strong> " . htmlspecialchars($voucher_code) . "</p>" : "") . "
                                <p style='margin: 0;'><strong>Total Já Pago neste Item:</strong> " . format_currency($new_paid_amount) . " (de " . format_currency($mbData['amount']) . ")</p>
                            </div>
                            
                            " . $status_message . "
                            
                            <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($choirNameEmail) . "</p>
                        </div>
                    ";
                } else {
                    $body = "
                        <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                            " . $logoEmailHtml . "
                            <h2 style='color: #f43f5e; margin-top: 0;'>Olá, " . htmlspecialchars($mbData['member_name']) . "!</h2>
                            <p>Informamos que foi registrada uma <strong>baixa manual de pagamento</strong> em sua cobrança pela administração do coral.</p>
                            
                            <div style='background-color: #f8fafc; border-left: 4px solid #f43f5e; padding: 16px; margin: 20px 0; border-radius: 4px;'>
                                <p style='margin: 0 0 8px 0;'><strong>Cobrança:</strong> " . htmlspecialchars($billingTitleFormatted) . "</p>
                                <p style='margin: 0 0 8px 0;'><strong>Valor Registrado/Baixado:</strong> " . format_currency($pay_amount) . "</p>
                                " . (!empty($voucher_code) ? "<p style='margin: 0 0 8px 0;'><strong>Observação:</strong> " . htmlspecialchars($voucher_code) . "</p>" : "") . "
                                <p style='margin: 0;'><strong>Total Já Pago neste Item:</strong> " . format_currency($new_paid_amount) . " (de " . format_currency($mbData['amount']) . ")</p>
                            </div>
                            
                            " . $status_message . "
                            
                            <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($choirNameEmail) . "</p>
                        </div>
                    ";
                }
                
                send_email($mbData['member_email'], $mbData['member_name'], $subject, $body);
            } catch (Exception $emailEx) {
                error_log("Failed to send manual payment notification email: " . $emailEx->getMessage());
            }
            
            set_flash_message('success', 'Pagamento manual registrado com sucesso!');
            $view_type_filter = $_GET['view_type'] ?? '';
            $member_id_filter = intval($_GET['member_id'] ?? 0);
            $search_filter = trim($_GET['search'] ?? '');
            header("Location: billing.php?tab=singers&view_type=" . urlencode($view_type_filter) . "&member_id=" . $member_id_filter . "&search=" . urlencode($search_filter));
            exit;
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            set_flash_message('error', $ex->getMessage());
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
$active_tab = $_GET['tab'] ?? 'singers';
$billing_items = [];
$member_billings = [];
$info_message = null;

$view_type = $_GET['view_type'] ?? ''; // 'all' ou 'member'
$filter_member_id = intval($_GET['member_id'] ?? 0);
$search_query = trim($_GET['search'] ?? '');
$status_filter = $_GET['status_filter'] ?? '';

if ($action === 'list' && $choir_id > 0) {
    try {
        // Sempre carregar itens de cobrança (templates)
        $stmt = $pdo->prepare("SELECT bi.*, 
            (SELECT COUNT(*) FROM member_billing mb WHERE mb.billing_item_id = bi.id) as assigned_count,
            (SELECT COUNT(*) FROM member_billing mb WHERE mb.billing_item_id = bi.id AND mb.status = 'paid') as paid_count
            FROM billing_items bi 
            WHERE bi.choir_id = ? 
            ORDER BY bi.due_date DESC, bi.id DESC");
        $stmt->execute([$choir_id]);
        $billing_items = $stmt->fetchAll();

        // Carregar cobranças dos membros com base no filtro
        if (in_array($view_type, ['all', 'member'])) {
            if ($view_type === 'member' && $filter_member_id === 0) {
                $info_message = 'Por favor, selecione um cantor no menu para visualizar suas cobranças.';
            } else {
                $sql = "SELECT mb.*, bi.title as billing_title, bi.amount as billing_amount, bi.type as billing_type, u.name as member_name, u.balance as member_balance
                        FROM member_billing mb
                        JOIN billing_items bi ON mb.billing_item_id = bi.id
                        JOIN users u ON mb.member_id = u.id
                        WHERE u.choir_id = :choir_id";
                
                $params = [':choir_id' => $choir_id];
                
                if ($view_type === 'member') {
                    $sql .= " AND mb.member_id = :member_id";
                    $params[':member_id'] = $filter_member_id;
                }
                
                if ($status_filter === 'overdue') {
                    $sql .= " AND mb.status = 'open' AND mb.due_date < CURRENT_DATE";
                }
                
                if (!empty($search_query)) {
                    $sql .= " AND (bi.title LIKE :search OR u.name LIKE :search)";
                    $params[':search'] = '%' . $search_query . '%';
                }
                
                $sql .= " ORDER BY mb.due_date DESC, mb.id DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $member_billings = $stmt->fetchAll();

                // Formatar títulos dinamicamente para cobranças recorrentes
                foreach ($member_billings as &$mb) {
                    if ($mb['billing_type'] === 'recurring') {
                        $mb['billing_title'] = $mb['billing_title'] . ' - ' . date('m/Y', strtotime($mb['due_date']));
                    }
                }
                unset($mb);
            }
        }
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
                        <input type="text" inputmode="numeric" name="amount" id="amount" required
                               data-currency-mask
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="0,00">
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
        <!-- Barra de Abas (Tabs) -->
        <div class="border-b border-slate-200 dark:border-slate-700 mb-6">
            <nav class="flex space-x-6" aria-label="Abas de Gestão">
                <a href="billing.php?tab=singers<?= !empty($view_type) ? '&view_type=' . $view_type : '' ?><?= $filter_member_id > 0 ? '&member_id=' . $filter_member_id : '' ?><?= !empty($search_query) ? '&search=' . urlencode($search_query) : '' ?>" 
                   class="pb-3 px-1 border-b-2 font-bold text-sm transition-all flex items-center gap-1.5 <?= $active_tab === 'singers' ? 'border-coral-500 text-coral-500' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-300' ?>">
                    <span>👥</span> Cobranças por Cantor
                </a>
                <a href="billing.php?tab=items" 
                   class="pb-3 px-1 border-b-2 font-bold text-sm transition-all flex items-center gap-1.5 <?= $active_tab === 'items' ? 'border-coral-500 text-coral-500' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-300' ?>">
                    <span>⚙️</span> Modelos de Cobrança (Templates)
                </a>
            </nav>
        </div>

        <!-- ==============================================
             ABA 1: COBRANÇAS POR CANTOR
             ============================================== -->
        <?php if ($active_tab === 'singers'): ?>
            
            <!-- Painel de Filtros e Busca -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl p-5 shadow-sm border border-slate-100 dark:border-slate-700/50 mb-6">
                <form action="billing.php" method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4 items-end">
                    <input type="hidden" name="tab" value="singers">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($status_filter) ?>">
                    
                    <div>
                        <label for="view_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Visualizar por *</label>
                        <select name="view_type" id="view_type" onchange="toggleFilterMemberDropdown(this.value)" required
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                            <option value="">Selecione...</option>
                            <option value="all" <?= $view_type === 'all' ? 'selected' : '' ?>>Todos os membros</option>
                            <option value="member" <?= $view_type === 'member' ? 'selected' : '' ?>>Membro específico</option>
                        </select>
                    </div>
                    
                    <div id="filter_member_select_container" class="<?= $view_type === 'member' ? '' : 'hidden' ?>">
                        <label for="member_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Cantor *</label>
                        <select name="member_id" id="member_id"
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                            <option value="">Selecione um cantor...</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= $filter_member_id === intval($m['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['voice_type'] ?? 'Indefinido') ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="sm:col-span-2 md:col-span-2">
                        <label for="search" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Caixa de pesquisa (Filtrar por cobrança ou cantor)</label>
                        <input type="text" name="search" id="search" value="<?= htmlspecialchars($search_query) ?>"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Digite o título ou nome...">
                    </div>
                    
                    <div class="flex gap-2">
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
                            Filtrar
                        </button>
                        <a href="billing.php?tab=singers"
                           class="px-4 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-white font-semibold rounded-lg text-xs transition-all text-center">
                            Limpar
                        </a>
                    </div>
                </form>
            </div>

            <!-- Exibição de Resultados -->
            <?php if ($status_filter === 'overdue' && !empty($view_type)): ?>
                <div class="mb-4 p-3.5 rounded-xl bg-rose-50 dark:bg-rose-950/20 text-rose-800 dark:text-rose-300 text-xs font-semibold flex items-center justify-between border border-rose-100 dark:border-rose-900/30">
                    <span class="flex items-center gap-1.5">
                        <span>⚠️</span> Exibindo apenas cobranças em atraso (vencidas).
                    </span>
                    <a href="billing.php?tab=singers&view_type=<?= htmlspecialchars($view_type) ?>&member_id=<?= $filter_member_id ?>" class="px-2.5 py-1 bg-white hover:bg-slate-50 dark:bg-slate-800 dark:hover:bg-slate-700 border border-rose-200 dark:border-rose-800 text-[10px] text-rose-700 dark:text-rose-400 font-bold rounded-lg shadow-sm transition-all">
                        Mostrar todas as cobranças
                    </a>
                </div>
            <?php endif; ?>
            <?php if (empty($view_type)): ?>
                <!-- Estado Inicial: Sem cobranças carregadas -->
                <div class="bg-white dark:bg-slate-800 rounded-2xl p-8 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50 flex flex-col items-center justify-center min-h-[250px]">
                    <div class="w-16 h-16 bg-slate-100 dark:bg-slate-900/60 text-slate-400 rounded-full flex items-center justify-center text-3xl mb-4">
                        🔍
                    </div>
                    <h3 class="font-bold text-slate-800 dark:text-white text-base mb-1">Visualização de Cobranças por Cantor</h3>
                    <p class="text-xs text-slate-400 max-w-md">
                        As cobranças não são exibidas inicialmente. Escolha se deseja visualizar por um membro específico ou por todos os membros acima para começar a buscar.
                    </p>
                </div>
            <?php elseif ($info_message): ?>
                <div class="bg-amber-50 dark:bg-amber-950/20 text-amber-800 dark:text-amber-300 p-4 rounded-xl text-sm border border-amber-100 dark:border-amber-900/30 text-center">
                    <?= htmlspecialchars($info_message) ?>
                </div>
            <?php elseif (empty($member_billings)): ?>
                <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 text-center text-slate-400 dark:text-slate-500 border border-slate-100 dark:border-slate-700/50">
                    Nenhuma cobrança encontrada para os filtros aplicados.
                </div>
            <?php else: ?>
                <!-- Tabela de Cobranças de Membros -->
                <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                            <thead class="bg-slate-50 dark:bg-slate-900/60">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Cantor</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Item de Cobrança</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Vencimento</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Valor / Pago</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                                <?php foreach ($member_billings as $mb): ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 cursor-pointer" onclick="openHistoryModal(<?= $mb['id'] ?>)">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800 dark:text-white">
                                            <?= htmlspecialchars($mb['member_name']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <div class="font-semibold text-slate-800 dark:text-white flex items-center gap-1.5">
                                                <?= htmlspecialchars($mb['billing_title']) ?>
                                                <span class="text-[10px] text-slate-400">🔍</span>
                                            </div>
                                            <div class="text-[10px] text-slate-400 capitalize"><?= $mb['billing_type'] === 'recurring' ? 'Recorrente' : 'Eventual' ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                                            <?= format_date($mb['due_date']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                            <span class="font-bold text-slate-800 dark:text-white"><?= format_currency($mb['billing_amount']) ?></span>
                                            <?php if ($mb['paid_amount'] > 0): ?>
                                                <span class="block text-[10px] text-emerald-500">Pago: <?= format_currency($mb['paid_amount']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-xs font-medium">
                                            <?php if ($mb['status'] === 'paid'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full bg-green-100 text-green-800 dark:bg-green-950/20 dark:text-green-300">
                                                    Pago
                                                </span>
                                            <?php elseif ($mb['status'] === 'pending_approval'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                                    Aguardando Aprovação
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full bg-red-100 text-red-800 dark:bg-red-950/20 dark:text-red-300">
                                                    Em Aberto
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium space-x-2" onclick="event.stopPropagation()">
                                            <?php if ($mb['status'] === 'pending_approval'): ?>
                                                <a href="payments.php" class="text-coral-500 hover:text-coral-600 font-bold">Validar Comprovante</a>
                                            <?php endif; ?>
                                            
                                            <?php if ($mb['status'] !== 'paid'): ?>
                                                <button type="button" onclick="openManualPaymentModal(<?= htmlspecialchars(json_encode([
                                                    'id' => $mb['id'],
                                                    'title' => $mb['billing_title'],
                                                    'member_name' => $mb['member_name'],
                                                    'member_balance' => floatval($mb['member_balance']),
                                                    'remaining_amount' => floatval($mb['billing_amount']) - floatval($mb['paid_amount'])
                                                ])) ?>)" class="text-emerald-600 hover:text-emerald-700 font-bold focus:outline-none">
                                                    Baixa Manual
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($mb['status'] === 'paid'): ?>
                                                <span class="text-slate-400">Quitada</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php
                                $total_amount = 0.00;
                                $total_paid = 0.00;
                                foreach ($member_billings as $mb) {
                                    $total_amount += floatval($mb['billing_amount']);
                                    $total_paid += floatval($mb['paid_amount']);
                                }
                                ?>
                                <tr class="bg-slate-50 dark:bg-slate-900/60 font-bold border-t border-slate-200 dark:border-slate-700">
                                    <td colspan="3" class="px-6 py-4 whitespace-nowrap text-xs font-bold text-slate-500 uppercase tracking-wider text-right">
                                        Total Geral:
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                        <span class="font-bold text-slate-900 dark:text-white"><?= format_currency($total_amount) ?></span>
                                        <?php if ($total_paid > 0): ?>
                                            <span class="block text-[10px] text-emerald-500 font-semibold">Pago: <?= format_currency($total_paid) ?></span>
                                        <?php endif; ?>
                                        <span class="block text-[10px] text-rose-500 font-semibold">A pagar: <?= format_currency($total_amount - $total_paid) ?></span>
                                    </td>
                                    <td colspan="2" class="px-6 py-4 whitespace-nowrap"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <script>
            function toggleFilterMemberDropdown(value) {
                const container = document.getElementById('filter_member_select_container');
                const memberSelect = document.getElementById('member_id');
                if (value === 'member') {
                    container.classList.remove('hidden');
                    memberSelect.setAttribute('required', 'required');
                } else {
                    container.classList.add('hidden');
                    memberSelect.removeAttribute('required');
                    memberSelect.value = '';
                }
            }
            </script>

        <!-- ==============================================
             ABA 2: MODELOS DE COBRANÇA (ORIGINAL)
             ============================================== -->
        <?php else: ?>
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
                <?php if (empty($billing_items)): ?>
                    <div class="text-center py-12 text-slate-400">
                        Nenhum modelo de cobrança criado. Clique em "+ Criar Cobrança" para começar.
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
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 font-medium">
                                            <?php if ($bi['type'] === 'recurring' && !empty($bi['start_date'])): ?>
                                                <span class="text-xs"><?= format_date($bi['start_date']) ?> a <?= format_date($bi['end_date']) ?></span>
                                            <?php else: ?>
                                                <?= format_date($bi['due_date']) ?>
                                            <?php endif; ?>
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
<?php endif; ?>

<!-- Modal de Pagamento Manual com Saldo (Fancy Box/Glassmorphic Style) -->
<div id="manual_payment_modal" class="fixed inset-0 z-50 hidden overflow-y-auto p-4 flex items-center justify-center min-h-screen">
    <!-- Backdrop com Blur -->
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closeManualPaymentModal()"></div>
    
    <!-- Modal Card -->
    <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-xl max-w-md w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8 transform transition-all max-h-[90vh] flex flex-col my-auto z-10 overflow-hidden">
        <!-- Close Button -->
        <button onclick="closeManualPaymentModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none z-20">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        
        <!-- Header -->
        <div class="mb-4 flex-shrink-0 pr-6">
            <h3 class="text-base font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
                <span>💰</span> Registrar Pagamento Manual / Baixa
            </h3>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Realize baixas manuais diretas, transporte de registros antigos ou deduções de saldo.</p>
        </div>
        
        <form action="billing.php?tab=singers<?= !empty($_SERVER['QUERY_STRING']) ? '&' . htmlspecialchars(preg_replace('/^\?/', '', $_SERVER['QUERY_STRING'])) : '' ?>" method="POST" class="flex flex-col flex-1 min-h-0 overflow-hidden">
            <input type="hidden" name="action" value="manual_payment">
            <input type="hidden" name="member_billing_id" id="modal_mb_id">
            <input type="hidden" name="payment_source" id="payment_source_hidden" value="manual">
            
            <div class="overflow-y-auto flex-1 min-h-0 pr-1 space-y-4">
                <!-- Detalhes da Cobrança -->
                <div class="bg-slate-50 dark:bg-slate-900/60 p-4 rounded-xl space-y-2 border border-slate-100 dark:border-slate-800 text-xs">
                    <div>
                        <span class="text-slate-400">Cantor:</span>
                        <span class="font-semibold text-slate-800 dark:text-white ml-1" id="modal_member_name"></span>
                    </div>
                    <div>
                        <span class="text-slate-400">Cobrança:</span>
                        <span class="font-semibold text-slate-800 dark:text-white ml-1" id="modal_billing_title"></span>
                    </div>
                    <div class="flex justify-between border-t border-slate-200 dark:border-slate-700/60 pt-2 mt-2">
                        <div>
                            <span class="text-slate-400">Saldo Disponível:</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400 block text-sm" id="modal_member_balance">R$ 0,00</span>
                        </div>
                        <div class="text-right">
                            <span class="text-slate-400">Valor Restante:</span>
                            <span class="font-bold text-rose-500 dark:text-rose-400 block text-sm" id="modal_remaining_amount">R$ 0,00</span>
                        </div>
                    </div>
                </div>
                
                <!-- Seleção da Origem da Baixa -->
                <div class="space-y-2">
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Tipo de Baixa *</label>
                    
                    <div class="space-y-2">
                        <!-- Opção 1: Registro Manual (Default) -->
                        <label id="opt_manual_label" class="flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-coral-500 bg-coral-50/40 dark:bg-coral-950/20 transition-all group">
                            <input type="radio" name="payment_source_radio" value="manual" checked onchange="setPaymentSource('manual')" class="mt-0.5 text-coral-500 focus:ring-coral-500">
                            <div>
                                <p class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                    <span>📝</span> Registro Manual / Baixa Direta
                                    <span class="px-1.5 py-0.5 text-[9px] rounded bg-coral-100 dark:bg-coral-900/40 text-coral-700 dark:text-coral-300 font-semibold">Sem Exigência de Saldo</span>
                                </p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Para recebimentos diretos, transporte de registros antigos ou quando não houver comprovante anexo.</p>
                            </div>
                        </label>
                        
                        <!-- Opção 2: Abater do Saldo em Conta -->
                        <label id="opt_balance_label" class="flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 transition-all group">
                            <input type="radio" name="payment_source_radio" value="balance" onchange="setPaymentSource('balance')" class="mt-0.5 text-coral-500 focus:ring-coral-500">
                            <div>
                                <p class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                    <span>💳</span> Abater do Saldo em Conta do Cantor
                                </p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Desconta o valor do saldo disponível no portal. (Saldo atual: <strong id="opt_balance_display_val">R$ 0,00</strong>)</p>
                            </div>
                        </label>
                        
                        <!-- Opção 3: Voucher / Cortesia -->
                        <label id="opt_voucher_label" class="flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 transition-all group">
                            <input type="radio" name="payment_source_radio" value="voucher" onchange="setPaymentSource('voucher')" class="mt-0.5 text-coral-500 focus:ring-coral-500">
                            <div>
                                <p class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5">
                                    <span>🎫</span> Voucher / Cortesia / Isenção
                                </p>
                                <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">Baixa registrada como desconto ou cortesia concedida ao cantor (não altera o saldo).</p>
                            </div>
                        </label>
                    </div>
                </div>
                
                <!-- Campo de Observações (opcional) -->
                <div id="voucher_obs_container" class="transition-all">
                    <label for="voucher_code" id="voucher_obs_label" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Observação / Histórico do Lançamento <span class="text-slate-400 font-normal">(opcional)</span></label>
                    <textarea name="voucher_code" id="voucher_code" rows="2"
                              class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all resize-none"
                              placeholder="Ex: Transporte de registro antigo do sistema anterior, recebido em mãos no ensaio..."></textarea>
                    <span class="text-[10px] text-slate-400 dark:text-slate-500 block mt-1">Deixe em branco se não quiser registrar observações adicionais.</span>
                </div>
                
                <!-- Input de Valor -->
                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label for="pay_amount" id="pay_amount_label" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Valor a Baixar (R$)</label>
                        <button type="button" onclick="useMaxPayAmount()" id="btn_use_all_balance" class="text-xs text-coral-500 hover:text-coral-600 font-bold focus:outline-none transition-colors">
                            Preencher valor restante
                        </button>
                    </div>
                    <input type="text" inputmode="numeric" name="pay_amount" id="pay_amount" required
                           data-currency-mask
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="0,00">
                    <span id="pay_amount_desc" class="text-[10px] text-slate-400 dark:text-slate-500 block mt-1">Este valor será registrado como pago na cobrança (transporte de dados / sem exigência de saldo).</span>
                </div>
            </div>
            
            <!-- Botões -->
            <div class="flex justify-end gap-2 pt-4 border-t border-slate-100 dark:border-slate-700/50 flex-shrink-0 mt-4">
                <button type="button" onclick="closeManualPaymentModal()"
                        class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                    Cancelar
                </button>
                <button type="submit" id="modal_submit_btn"
                        class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                    Confirmar Baixa
                </button>
            </div>
        </form>
    </div>
</div>

<script>
let currentModalData = null;

function openManualPaymentModal(data) {
    currentModalData = data;
    
    document.getElementById('modal_mb_id').value = data.id;
    document.getElementById('modal_member_name').innerText = data.member_name;
    document.getElementById('modal_billing_title').innerText = data.title;
    document.getElementById('modal_member_balance').innerText = 'R$ ' + data.member_balance.toFixed(2).replace('.', ',');
    const optBalVal = document.getElementById('opt_balance_display_val');
    if (optBalVal) optBalVal.innerText = 'R$ ' + data.member_balance.toFixed(2).replace('.', ',');
    document.getElementById('modal_remaining_amount').innerText = 'R$ ' + data.remaining_amount.toFixed(2).replace('.', ',');
    
    // Resetar estado
    document.getElementById('voucher_code').value = '';
    const payInput = document.getElementById('pay_amount');
    payInput.value = '';
    const payHidden = payInput.parentElement ? payInput.parentElement.querySelector('input[type="hidden"][name="pay_amount"]') : null;
    if (payHidden) payHidden.value = '';
    
    // Selecionar por padrão 'manual' (Registro Manual / Baixa Direta)
    const radioManual = document.querySelector('input[name="payment_source_radio"][value="manual"]');
    if (radioManual) radioManual.checked = true;
    
    setPaymentSource('manual');
    
    document.getElementById('manual_payment_modal').classList.remove('hidden');
}

function closeManualPaymentModal() {
    document.getElementById('manual_payment_modal').classList.add('hidden');
}

function setPaymentSource(source) {
    document.getElementById('payment_source_hidden').value = source;
    
    const optManual = document.getElementById('opt_manual_label');
    const optBalance = document.getElementById('opt_balance_label');
    const optVoucher = document.getElementById('opt_voucher_label');
    
    const payAmountLabel = document.getElementById('pay_amount_label');
    const payAmountDesc = document.getElementById('pay_amount_desc');
    const btnUseAll = document.getElementById('btn_use_all_balance');
    const submitBtn = document.getElementById('modal_submit_btn');
    const obsContainer = document.getElementById('voucher_obs_container');
    const obsLabel = document.getElementById('voucher_obs_label');
    
    const defaultClass = "flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-slate-300 dark:hover:border-slate-600 transition-all group";
    if (optManual) optManual.className = defaultClass;
    if (optBalance) optBalance.className = defaultClass;
    if (optVoucher) optVoucher.className = defaultClass;
    
    if (source === 'manual') {
        if (optManual) optManual.className = "flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-coral-500 bg-coral-50/40 dark:bg-coral-950/20 transition-all group";
        payAmountLabel.innerText = 'Valor a Baixar (R$)';
        payAmountDesc.innerText = 'Este valor será registrado como pago na cobrança (transporte de dados / sem exigência de saldo).';
        btnUseAll.innerText = 'Preencher valor restante';
        btnUseAll.classList.remove('opacity-50', 'pointer-events-none', 'text-amber-500', 'hover:text-amber-600', 'text-emerald-600', 'hover:text-emerald-700');
        btnUseAll.classList.add('text-coral-500', 'hover:text-coral-600');
        submitBtn.className = "px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors";
        obsContainer.classList.remove('hidden');
        if (obsLabel) obsLabel.innerText = 'Observação / Histórico do Lançamento (opcional)';
    } else if (source === 'balance') {
        if (optBalance) optBalance.className = "flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-emerald-500 bg-emerald-50/40 dark:bg-emerald-950/20 transition-all group";
        payAmountLabel.innerText = 'Valor a Deduzir do Saldo (R$)';
        payAmountDesc.innerText = 'Este valor será descontado do saldo disponível do membro no portal.';
        btnUseAll.innerText = 'Usar saldo disponível';
        btnUseAll.classList.remove('text-amber-500', 'hover:text-amber-600', 'text-coral-500', 'hover:text-coral-600');
        btnUseAll.classList.add('text-emerald-600', 'hover:text-emerald-700');
        submitBtn.className = "px-4 py-2.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs shadow-md transition-colors";
        obsContainer.classList.add('hidden');
        if (currentModalData) {
            if (currentModalData.member_balance <= 0) {
                btnUseAll.classList.add('opacity-50', 'pointer-events-none');
            } else {
                btnUseAll.classList.remove('opacity-50', 'pointer-events-none');
            }
        }
    } else if (source === 'voucher') {
        if (optVoucher) optVoucher.className = "flex items-start gap-3 cursor-pointer p-3 rounded-xl border border-amber-500 bg-amber-50/40 dark:bg-amber-950/20 transition-all group";
        payAmountLabel.innerText = 'Valor a Isentar via Voucher (R$)';
        payAmountDesc.innerText = 'Não deduz saldo. Registrado como cortesia/voucher no histórico do cantor.';
        btnUseAll.innerText = 'Isentar valor restante';
        btnUseAll.classList.remove('opacity-50', 'pointer-events-none', 'text-coral-500', 'hover:text-coral-600', 'text-emerald-600', 'hover:text-emerald-700');
        btnUseAll.classList.add('text-amber-500', 'hover:text-amber-600');
        submitBtn.className = "px-4 py-2.5 rounded-lg bg-amber-500 hover:bg-amber-600 text-white font-semibold text-xs shadow-md transition-colors";
        obsContainer.classList.remove('hidden');
        if (obsLabel) obsLabel.innerText = 'Observação do Voucher / Cortesia (opcional)';
    }
}

function useMaxPayAmount() {
    if (!currentModalData) return;
    const source = document.getElementById('payment_source_hidden').value;
    let val;
    if (source === 'balance') {
        val = Math.min(currentModalData.member_balance, currentModalData.remaining_amount);
    } else { // 'manual' or 'voucher'
        val = currentModalData.remaining_amount;
    }
    if (window.ecoralCurrencyMask && window.ecoralCurrencyMask.setValue) {
        window.ecoralCurrencyMask.setValue('#pay_amount', val);
    } else {
        const formatted = val.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        const payInput = document.getElementById('pay_amount');
        payInput.value = formatted;
        const hiddenInput = payInput.parentElement ? payInput.parentElement.querySelector('input[type="hidden"][name="pay_amount"]') : null;
        if (hiddenInput) hiddenInput.value = val.toFixed(2);
    }
}
</script>

<!-- Modal de Histórico de Cobrança (Fancy Timeline Style) -->
<div id="modal-billing-history" class="fixed inset-0 z-50 overflow-y-auto hidden flex items-center justify-center p-4 min-h-screen">
    <!-- Backdrop Blur -->
    <div class="fixed inset-0 transition-opacity bg-slate-900/60 backdrop-blur-sm" onclick="closeHistoryModal()"></div>
    
    <!-- Modal Card -->
    <div class="relative bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all max-w-lg w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8 max-h-[90vh] flex flex-col my-auto z-10">
        
        <!-- Botão Fechar -->
        <button onclick="closeHistoryModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 focus:outline-none z-20">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
        
        <!-- Header do Modal -->
        <div class="mb-4 flex-shrink-0 pr-6">
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white" id="history-title">...</h3>
                <span id="history-badge" class="px-2.5 py-0.5 rounded-full text-xs font-semibold">...</span>
            </div>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1" id="history-description">...</p>
        </div>
        
        <div class="overflow-y-auto flex-1 min-h-0 pr-1 space-y-4">
            <!-- Tabela Resumo Financeiro da Cobrança -->
            <div class="bg-slate-50 dark:bg-slate-900/60 p-4 rounded-xl border border-slate-100 dark:border-slate-800 text-xs grid grid-cols-3 gap-2 text-center">
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
        </div>
        
        <!-- Botão de Fechar -->
        <div class="flex justify-end pt-4 mt-4 border-t border-slate-100 dark:border-slate-700/50 flex-shrink-0">
            <button type="button" onclick="closeHistoryModal()"
                    class="px-4 py-2 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-semibold text-xs transition-colors">
                Fechar
            </button>
        </div>
    </div>
</div>

<script>
function openHistoryModal(billingId) {
    const container = document.getElementById('history-timeline-container');
    container.innerHTML = `
        <div class="flex items-center justify-center py-4">
            <span class="text-xs text-slate-400">Carregando histórico...</span>
        </div>
    `;
    
    document.getElementById('modal-billing-history').classList.remove('hidden');
    
    // Fetch AJAX de dashboard.php
    fetch('dashboard.php?ajax_action=get_billing_history&billing_id=' + billingId)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                alert(data.error || 'Erro ao carregar histórico.');
                closeHistoryModal();
                return;
            }
            
            let displayTitle = data.billing.title;
            if (data.billing.member_name) {
                const codePart = data.billing.member_code ? ` (${data.billing.member_code})` : '';
                displayTitle += ` (${data.billing.member_name}${codePart})`;
            }
            
            document.getElementById('history-title').innerText = displayTitle;
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
                    } else if (p.filename === 'manual_record') {
                        icon = '📝';
                        title = 'Registro / Baixa Manual';
                        const obsMatch = p.description ? p.description.match(/Observação: (.+)$/) : null;
                        const obsText = obsMatch ? ` Observação: <em>"${obsMatch[1]}"</em>` : '';
                        desc = `Baixa de <strong>${p.formatted_amount}</strong> registrada manualmente por administrador/financeiro (transporte de dados / sem exigência de saldo).${obsText}`;
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
