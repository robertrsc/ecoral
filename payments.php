<?php
// payments.php
require_once __DIR__ . '/config.php';
require_login();

$user = get_logged_user();
$error = null;
$success = null;

// Criar pasta de uploads se não existir
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
if (!file_exists($uploadDir . '/.htaccess')) {
    // Escrever .htaccess para segurança
    file_put_to_file($uploadDir . '/.htaccess', "Deny from all\n<FilesMatch \"\.(jpeg|jpg|png|pdf)$\">\nOrder Allow,Deny\nAllow from all\n</FilesMatch>\nOptions -ExecCGI");
}

// Auxiliar: Escrever arquivo de forma simples
function file_put_to_file($path, $content) {
    file_put_contents($path, $content);
}

// ==============================================
// TRATAMENTO DE AÇÕES (MEMBROS E ADMINS)
// ==============================================

// 1. Membro: Pagar usando Saldo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pay_with_balance') {
    $member_billing_id = intval($_POST['member_billing_id'] ?? 0);
    
    if ($user['role'] !== 'membro') {
        $error = 'Operação permitida apenas para cantores (membros).';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Buscar a cobrança e validar se pertence ao membro e está aberta
            $stmt = $pdo->prepare("SELECT mb.*, bi.amount, bi.title FROM member_billing mb 
                                    JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                    WHERE mb.id = ? AND mb.member_id = ? AND mb.status = 'open'");
            $stmt->execute([$member_billing_id, $user['id']]);
            $billing = $stmt->fetch();
            
            if (!$billing) {
                throw new Exception('Cobrança inválida, fechada ou não pertence a você.');
            }
            
            $amount_needed = $billing['amount'] - $billing['paid_amount'];
            
            // Recarregar saldo do membro direto do banco para garantir consistência
            $stmtBal = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
            $stmtBal->execute([$user['id']]);
            $current_balance = floatval($stmtBal->fetchColumn());
            
            if ($current_balance < $amount_needed) {
                throw new Exception('Você não possui saldo suficiente para pagar esta cobrança integralmente.');
            }
            
            // Deduzir do saldo
            $new_balance = $current_balance - $amount_needed;
            $stmtUpdateBal = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $stmtUpdateBal->execute([$new_balance, $user['id']]);
            
            // Atualizar status da cobrança para paga
            $stmtUpdateBilling = $pdo->prepare("UPDATE member_billing SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?");
            $stmtUpdateBilling->execute([$billing['amount'], $member_billing_id]);
            
            $pdo->commit();
            set_flash_message('success', "Cobrança '{$billing['title']}' paga com sucesso utilizando seu saldo!");
            header("Location: dashboard.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// 2. Membro: Enviar Comprovante por Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_receipt') {
    $amount = floatval($_POST['amount'] ?? 0.00);
    $description = trim($_POST['description'] ?? '');
    $selected_billings = $_POST['selected_billings'] ?? []; // IDs de member_billing
    
    if ($user['role'] !== 'membro') {
        $error = 'Operação permitida apenas para cantores (membros).';
    } elseif ($amount <= 0) {
        $error = 'Por favor, informe um valor de comprovante válido.';
    } elseif (!isset($_FILES['receipt_file']) || $_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Por favor, selecione um arquivo de comprovante válido.';
    } else {
        $fileInfo = $_FILES['receipt_file'];
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $error = 'Formato de arquivo não suportado. Envie apenas JPG, PNG ou PDF.';
        } else {
            // Salvar arquivo
            $newFilename = uniqid('rec_', true) . '.' . $ext;
            $destination = $uploadDir . '/' . $newFilename;
            
            if (move_uploaded_file($fileInfo['tmp_name'], $destination)) {
                try {
                    $pdo->beginTransaction();
                    
                    // Inserir recibo pendente
                    $stmtRec = $pdo->prepare("INSERT INTO receipts (member_id, amount, filename, description, status) VALUES (?, ?, ?, ?, 'pending')");
                    $stmtRec->execute([$user['id'], $amount, $newFilename, $description]);
                    $receipt_id = $pdo->lastInsertId();
                    
                    // Vincular cobranças e mudar status para pending_approval
                    if (!empty($selected_billings)) {
                        $stmtLink = $pdo->prepare("INSERT INTO receipt_billing_items (receipt_id, member_billing_id) VALUES (?, ?)");
                        $stmtStatus = $pdo->prepare("UPDATE member_billing SET status = 'pending_approval' WHERE id = ? AND member_id = ? AND status = 'open'");
                        
                        foreach ($selected_billings as $mb_id) {
                            $mb_id = intval($mb_id);
                            // Garante que é do membro e está aberta
                            $stmtStatus->execute([$mb_id, $user['id']]);
                            if ($stmtStatus->rowCount() > 0) {
                                $stmtLink->execute([$receipt_id, $mb_id]);
                            }
                        }
                    }
                    
                    $pdo->commit();
                    set_flash_message('success', 'Comprovante enviado com sucesso! Aguarde a verificação por um administrador.');
                    header("Location: payments.php");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    if (file_exists($destination)) unlink($destination);
                    $error = 'Erro ao processar envio: ' . $e->getMessage();
                }
            } else {
                $error = 'Falha ao mover arquivo enviado.';
            }
        }
    }
}

// 3. Admin: Aprovar Comprovante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_receipt') {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);
    
    if (!is_admin_user()) {
        $error = 'Operação permitida apenas para usuários administradores.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Buscar comprovante pendente e dados do membro
            $stmt = $pdo->prepare("SELECT r.*, u.choir_id, u.balance as member_balance FROM receipts r 
                                    JOIN users u ON r.member_id = u.id 
                                    WHERE r.id = ? AND r.status = 'pending'");
            $stmt->execute([$receipt_id]);
            $receipt = $stmt->fetch();
            
            if (!$receipt) {
                throw new Exception('Comprovante inválido ou já processado.');
            }
            
            // Validar se pertence ao mesmo coral se não for superadmin
            if (!is_superadmin() && $receipt['choir_id'] != $user['choir_id']) {
                throw new Exception('Acesso negado.');
            }
            
            // Buscar itens de cobrança associados
            $stmtItems = $pdo->prepare("SELECT rbi.*, mb.billing_item_id, bi.amount, bi.title 
                                        FROM receipt_billing_items rbi 
                                        JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                        JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                        WHERE rbi.receipt_id = ?");
            $stmtItems->execute([$receipt_id]);
            $linked_items = $stmtItems->fetchAll();
            
            $total_debts = 0.00;
            
            // Baixar os itens vinculados
            $stmtUpdateBilling = $pdo->prepare("UPDATE member_billing SET status = 'paid', paid_amount = amount, paid_at = NOW() WHERE id = ?");
            foreach ($linked_items as $li) {
                $stmtUpdateBilling->execute([$li['member_billing_id']]);
                $total_debts += floatval($li['amount']);
            }
            
            // Calcular saldo restante
            $leftover = floatval($receipt['amount']) - $total_debts;
            
            if ($leftover > 0) {
                // Adiciona saldo ao membro
                $new_balance = floatval($receipt['member_balance']) + $leftover;
                $stmtAddBal = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $stmtAddBal->execute([$new_balance, $receipt['member_id']]);
            }
            
            // Aprovar o comprovante
            $stmtApprove = $pdo->prepare("UPDATE receipts SET status = 'approved', approved_at = NOW(), approved_by = ? WHERE id = ?");
            $stmtApprove->execute([$user['id'], $receipt_id]);
            
            $pdo->commit();
            set_flash_message('success', 'Comprovante aprovado com sucesso! Saldo e status de cobranças atualizados.');
            header("Location: payments.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// 4. Admin: Rejeitar Comprovante
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_receipt') {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);
    
    if (!is_admin_user()) {
        $error = 'Operação permitida apenas para usuários administradores.';
    } else {
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT r.*, u.choir_id FROM receipts r 
                                    JOIN users u ON r.member_id = u.id 
                                    WHERE r.id = ? AND r.status = 'pending'");
            $stmt->execute([$receipt_id]);
            $receipt = $stmt->fetch();
            
            if (!$receipt) {
                throw new Exception('Comprovante inválido ou já processado.');
            }
            
            // Validar se pertence ao mesmo coral se não for superadmin
            if (!is_superadmin() && $receipt['choir_id'] != $user['choir_id']) {
                throw new Exception('Acesso negado.');
            }
            
            // Buscar itens de cobrança associados e reverter para 'open'
            $stmtItems = $pdo->prepare("SELECT member_billing_id FROM receipt_billing_items WHERE receipt_id = ?");
            $stmtItems->execute([$receipt_id]);
            $linked_items = $stmtItems->fetchAll();
            
            $stmtRevert = $pdo->prepare("UPDATE member_billing SET status = 'open' WHERE id = ?");
            foreach ($linked_items as $li) {
                $stmtRevert->execute([$li['member_billing_id']]);
            }
            
            // Rejeitar comprovante
            $stmtReject = $pdo->prepare("UPDATE receipts SET status = 'rejected' WHERE id = ?");
            $stmtReject->execute([$receipt_id]);
            
            $pdo->commit();
            set_flash_message('success', 'Comprovante rejeitado. As cobranças associadas voltaram a constar em aberto.');
            header("Location: payments.php");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ==============================================
// CARREGAR DADOS DE VISUALIZAÇÃO
// ==============================================

if ($user['role'] === 'membro') {
    // Membro: Cobranças em aberto para selecionar no formulário
    $stmtMyBillings = $pdo->prepare("SELECT mb.*, bi.title, bi.amount as item_amount 
                                     FROM member_billing mb 
                                     JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                     WHERE mb.member_id = ? AND mb.status = 'open' 
                                     ORDER BY mb.due_date ASC");
    $stmtMyBillings->execute([$user['id']]);
    $myOpenBillings = $stmtMyBillings->fetchAll();
    
    // Membro: Histórico pessoal de comprovantes enviados
    $stmtMyReceipts = $pdo->prepare("SELECT r.*, 
                                     (SELECT GROUP_CONCAT(bi.title SEPARATOR ', ') 
                                      FROM receipt_billing_items rbi 
                                      JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                      JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                      WHERE rbi.receipt_id = r.id) as linked_items 
                                     FROM receipts r 
                                     WHERE r.member_id = ? 
                                     ORDER BY r.id DESC");
    $stmtMyReceipts->execute([$user['id']]);
    $receiptsList = $stmtMyReceipts->fetchAll();
} else {
    // Admins e Colaborador: Visualizar comprovantes enviados
    try {
        if (is_superadmin()) {
            $stmtRecs = $pdo->query("SELECT r.*, u.name as member_name, u.email as member_email, c.name as choir_name,
                                     (SELECT GROUP_CONCAT(bi.title SEPARATOR ', ') 
                                      FROM receipt_billing_items rbi 
                                      JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                      JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                      WHERE rbi.receipt_id = r.id) as linked_items 
                                     FROM receipts r 
                                     JOIN users u ON r.member_id = u.id 
                                     LEFT JOIN choirs c ON u.choir_id = c.id 
                                     ORDER BY r.status DESC, r.id DESC");
        } else {
            $stmtRecs = $pdo->prepare("SELECT r.*, u.name as member_name, u.email as member_email, c.name as choir_name,
                                     (SELECT GROUP_CONCAT(bi.title SEPARATOR ', ') 
                                      FROM receipt_billing_items rbi 
                                      JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                      JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                      WHERE rbi.receipt_id = r.id) as linked_items 
                                     FROM receipts r 
                                     JOIN users u ON r.member_id = u.id 
                                     LEFT JOIN choirs c ON u.choir_id = c.id 
                                     WHERE u.choir_id = ? 
                                     ORDER BY r.status DESC, r.id DESC");
            $stmtRecs->execute([$user['choir_id']]);
        }
        $receiptsList = $stmtRecs->fetchAll();
    } catch (PDOException $e) {
        $error = 'Erro ao carregar comprovantes: ' . $e->getMessage();
        $receiptsList = [];
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
        <span>🧾</span> Fluxo de Pagamentos e Comprovantes
    </h1>
    <p class="text-xs text-slate-500 dark:text-slate-400">
        <?php if ($user['role'] === 'membro'): ?>
            Envie seus comprovantes para quitação de mensalidades ou verifique seu extrato pessoal.
        <?php else: ?>
            Verifique e valide os comprovantes de pagamento enviados pelos cantores do coral.
        <?php endif; ?>
    </p>
</div>

<?php if ($error): ?>
    <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ==============================================
         1. PAINEL DO CANTOR (MEMBRO) - FORMULÁRIO DE ENVIO
         ============================================== -->
    <?php if ($user['role'] === 'membro'): ?>
        <div class="lg:col-span-1 bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50 h-fit">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Enviar Comprovante</h2>
            
            <form action="payments.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="upload_receipt">
                
                <div>
                    <label for="amount" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Valor Pago (R$) *</label>
                    <input type="number" name="amount" id="amount" step="0.01" min="0.01" required
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="0.00">
                </div>

                <div>
                    <label for="receipt_file" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Arquivo Comprovante (JPG, PNG ou PDF) *</label>
                    <input type="file" name="receipt_file" id="receipt_file" required accept=".jpg,.jpeg,.png,.pdf"
                           class="w-full px-2 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg dark:text-white file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-coral-100 file:text-coral-700 hover:file:bg-coral-200">
                </div>

                <div>
                    <label for="description" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Observações / Mensagem</label>
                    <textarea name="description" id="description" rows="2"
                              class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                              placeholder="Ex: Mensalidade paga via pix."></textarea>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1.5">Associar a quais cobranças em aberto?</label>
                    <?php if (empty($myOpenBillings)): ?>
                        <p class="text-[10px] text-slate-400">Nenhuma cobrança em aberto. O valor total será creditado em seu saldo!</p>
                    <?php else: ?>
                        <div class="border border-slate-200 dark:border-slate-700 rounded-lg p-3 space-y-2 max-h-36 overflow-y-auto bg-slate-50 dark:bg-slate-900/40">
                            <?php foreach ($myOpenBillings as $mob): ?>
                                <label class="flex items-center text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                    <input type="checkbox" name="selected_billings[]" value="<?= $mob['id'] ?>"
                                           class="w-4 h-4 rounded text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700">
                                    <span class="ml-2 font-medium">
                                        <?= htmlspecialchars($mob['title']) ?> (<?= format_currency($mob['item_amount'] - $mob['paid_amount']) ?>)
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[9px] text-slate-400 mt-1">Obs: Caso o valor do comprovante supere os itens marcados, a diferença irá para seu saldo.</p>
                    <?php endif; ?>
                </div>

                <button type="submit" 
                        class="w-full py-2.5 px-4 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-coral-400 focus:ring-offset-2 transition-all text-xs">
                    Enviar Comprovante
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- ==============================================
         LISTA DE COMPROVANTES (ADMINS OU HISTÓRICO MEMBRO)
         ============================================== -->
    <div class="<?= $user['role'] === 'membro' ? 'lg:col-span-2' : 'lg:col-span-3' ?> bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
        <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">
            <?= $user['role'] === 'membro' ? 'Meu Histórico de Comprovantes' : 'Todos os Comprovantes Recebidos' ?>
        </h2>
        
        <?php if (empty($receiptsList)): ?>
            <div class="text-center py-12 text-slate-400">Nenhum comprovante para exibir.</div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($receiptsList as $rl): ?>
                    <div class="border border-slate-100 dark:border-slate-700/50 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/50 dark:bg-slate-900/10">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-bold text-slate-800 dark:text-white"><?= format_currency($rl['amount']) ?></span>
                                <span class="px-2 py-0.5 text-[10px] rounded-full font-semibold capitalize
                                    <?= $rl['status'] === 'approved' ? 'bg-green-100 text-green-800 dark:bg-green-950/20 dark:text-green-300' : ($rl['status'] === 'rejected' ? 'bg-red-100 text-red-800 dark:bg-red-950/20 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300') ?>">
                                    <?= $rl['status'] === 'approved' ? 'Aprovado' : ($rl['status'] === 'rejected' ? 'Rejeitado' : 'Pendente') ?>
                                </span>
                            </div>
                            
                            <?php if ($user['role'] !== 'membro'): ?>
                                <p class="text-xs font-semibold text-slate-700 dark:text-slate-200">
                                    Membro: <?= htmlspecialchars($rl['member_name']) ?> <span class="text-[10px] text-slate-400 font-normal">(<?= htmlspecialchars($rl['member_email']) ?>)</span>
                                </p>
                                <p class="text-[10px] text-slate-400">Coral: <?= htmlspecialchars($rl['choir_name']) ?></p>
                            <?php endif; ?>

                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                <strong>Obs:</strong> <?= htmlspecialchars($rl['description'] ?? 'Sem observações.') ?>
                            </p>
                            
                            <?php if (!empty($rl['linked_items'])): ?>
                                <p class="text-[10px] text-coral-500 font-medium">
                                    <strong>Cobranças:</strong> <?= htmlspecialchars($rl['linked_items']) ?>
                                </p>
                            <?php else: ?>
                                <p class="text-[10px] text-emerald-500 font-medium">Credita em saldo</p>
                            <?php endif; ?>
                            
                            <p class="text-[10px] text-slate-400">Enviado em: <?= format_date($rl['created_at']) ?></p>
                        </div>
                        
                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full sm:w-auto">
                            <!-- Visualizar Arquivo -->
                            <a href="uploads/<?= $rl['filename'] ?>" target="_blank"
                               class="text-center px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                📄 Ver Comprovante
                            </a>
                            
                            <!-- Ações Administrativas (Apenas se pendente e se for usuário admin) -->
                            <?php if ($rl['status'] === 'pending' && is_admin_user()): ?>
                                <form action="payments.php" method="POST" class="inline flex gap-1 w-full sm:w-auto">
                                    <input type="hidden" name="receipt_id" value="<?= $rl['id'] ?>">
                                    <button type="submit" name="action" value="reject_receipt" onclick="return confirm('Deseja rejeitar este comprovante?')"
                                            class="flex-1 sm:flex-none px-3 py-1.5 rounded-lg bg-red-500 hover:bg-red-600 text-white text-xs font-semibold transition-colors">
                                        Rejeitar
                                    </button>
                                    <button type="submit" name="action" value="approve_receipt" onclick="return confirm('Deseja aprovar este comprovante? As cobranças associadas serão baixadas e leftovers inseridos no saldo.')"
                                            class="flex-1 sm:flex-none px-3 py-1.5 rounded-lg bg-green-500 hover:bg-green-600 text-white text-xs font-semibold transition-colors">
                                        Aprovar
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
