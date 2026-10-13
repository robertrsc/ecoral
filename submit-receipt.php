<?php
// submit-receipt.php
// Página dedicada para envio de comprovante com Wizard em passos
require_once __DIR__ . '/config.php';
require_login();

$user = get_logged_user();

if ($user['role'] !== 'membro') {
    set_flash_message('error', 'O envio de comprovantes está disponível apenas para membros/coralistas.');
    header("Location: payments.php");
    exit;
}

// Endpoint AJAX: Buscar cobranças de outro membro pelo código (Passo 2)
if (isset($_GET['action']) && $_GET['action'] === 'fetch_member_billings') {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim($_GET['code'] ?? '');
    
    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Código não informado.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, name, member_code FROM users WHERE member_code = ? AND choir_id = ? AND role = 'membro' AND status = 'active' LIMIT 1");
        $stmt->execute([$code, $user['choir_id']]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            echo json_encode(['success' => false, 'message' => 'Coralista não encontrado ou não pertence ao mesmo coral. Verifique o código digitado.']);
            exit;
        }
        
        if ($targetUser['id'] == $user['id']) {
            echo json_encode(['success' => false, 'message' => 'Este é o seu próprio código! Suas cobranças já estão listadas na seleção.']);
            exit;
        }
        
        // Cobranças em aberto do outro coralista
        $stmtB = $pdo->prepare("SELECT mb.id, bi.title, bi.amount, mb.due_date, bi.type as billing_type, mb.paid_amount
                                 FROM member_billing mb 
                                 JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                 WHERE mb.member_id = ? AND mb.status = 'open' 
                                 ORDER BY mb.due_date ASC");
        $stmtB->execute([$targetUser['id']]);
        $billings = $stmtB->fetchAll();
        
        foreach ($billings as &$b) {
            if (($b['billing_type'] ?? '') === 'recurring') {
                $b['title'] .= ' - ' . date('m/Y', strtotime($b['due_date']));
            }
            $remaining = floatval($b['amount']) - floatval($b['paid_amount']);
            $b['remaining_amount'] = $remaining;
            $b['formatted_amount'] = format_currency($remaining);
            $b['formatted_due_date'] = format_date($b['due_date']);
        }
        unset($b);
        
        echo json_encode([
            'success' => true,
            'member_id' => $targetUser['id'],
            'member_name' => $targetUser['name'],
            'member_code' => $targetUser['member_code'],
            'billings' => $billings
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao consultar coralista: ' . $e->getMessage()]);
        exit;
    }
}

$error = null;
$successSubmitted = false;
$submittedReceiptData = null;

// Diretório de uploads
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
if (!file_exists($uploadDir . '/.htaccess')) {
    file_put_contents($uploadDir . '/.htaccess', "Deny from all\n<FilesMatch \"\.(jpeg|jpg|png|pdf)$\">\nOrder Allow,Deny\nAllow from all\n</FilesMatch>\nOptions -ExecCGI");
}

// Processamento do envio do comprovante (Finalização do Wizard)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_wizard_receipt') {
    $amount = parse_currency_input($_POST['amount'] ?? $_POST['amount_display'] ?? '0');
    $description = trim($_POST['description'] ?? '');
    $selected_billings = $_POST['selected_billings'] ?? []; // IDs de member_billing
    $is_shared = isset($_POST['is_shared']) && $_POST['is_shared'] === '1';
    $shared_member_code = trim($_POST['shared_member_code'] ?? '');
    
    if ($amount <= 0) {
        $error = 'Por favor, informe um valor de pagamento válido e maior que zero.';
    } elseif (!isset($_FILES['receipt_file']) || $_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Por favor, selecione e anexe um arquivo de comprovante válido.';
    } else {
        $fileInfo = $_FILES['receipt_file'];
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
            $error = 'Formato de arquivo não suportado. Por favor, envie apenas comprovantes em formato JPG, PNG ou PDF.';
        } else {
            // Validar cobranças selecionadas
            $sum_selected_items = 0.00;
            $items_details = [];
            
            if (!empty($selected_billings)) {
                $placeholders = implode(',', array_fill(0, count($selected_billings), '?'));
                $stmtSum = $pdo->prepare("
                    SELECT mb.id, mb.member_id, (bi.amount - mb.paid_amount) as remaining, bi.title, bi.type as billing_type, mb.due_date, u.name as member_name
                    FROM member_billing mb 
                    JOIN billing_items bi ON mb.billing_item_id = bi.id 
                    JOIN users u ON mb.member_id = u.id 
                    WHERE mb.id IN ($placeholders) AND u.choir_id = ? AND mb.status = 'open'
                ");
                $params = array_map('intval', $selected_billings);
                $params[] = intval($user['choir_id']);
                $stmtSum->execute($params);
                $fetchedRows = $stmtSum->fetchAll();
                
                foreach ($fetchedRows as $row) {
                    $rem = floatval($row['remaining']);
                    $sum_selected_items += $rem;
                    $t = $row['title'];
                    if ($row['billing_type'] === 'recurring') {
                        $t .= ' - ' . date('m/Y', strtotime($row['due_date']));
                    }
                    $items_details[] = [
                        'id' => $row['id'],
                        'title' => $t,
                        'amount' => $rem,
                        'member_name' => $row['member_name']
                    ];
                }
            }

            // Regra: Não pode ultrapassar o valor informado
            if ($sum_selected_items > $amount + 0.0001) {
                $error = 'A soma dos itens selecionados (' . format_currency($sum_selected_items) . ') excede o valor informado para o pagamento (' . format_currency($amount) . ').';
            } else {
                $newFilename = uniqid('rec_', true) . '.' . $ext;
                $destination = $uploadDir . '/' . $newFilename;
                
                if (move_uploaded_file($fileInfo['tmp_name'], $destination)) {
                    try {
                        $pdo->beginTransaction();
                        
                        // Descrição complementar se for compartilhado
                        $finalDescription = $description;
                        if ($is_shared && !empty($shared_member_code)) {
                            $sharedTag = "[Pagamento compartilhado com código: " . $shared_member_code . "]";
                            $finalDescription = $finalDescription ? ($finalDescription . " " . $sharedTag) : $sharedTag;
                        }
                        
                        // Inserir registro na tabela receipts
                        $stmtRec = $pdo->prepare("INSERT INTO receipts (member_id, amount, filename, description, status) VALUES (?, ?, ?, ?, 'pending')");
                        $stmtRec->execute([$user['id'], $amount, $newFilename, $finalDescription]);
                        $receipt_id = $pdo->lastInsertId();
                        
                        // Vincular cobranças e atualizar para status 'pending_approval'
                        if (!empty($selected_billings)) {
                            $stmtLink = $pdo->prepare("INSERT INTO receipt_billing_items (receipt_id, member_billing_id) VALUES (?, ?)");
                            $stmtStatus = $pdo->prepare("UPDATE member_billing mb 
                                                         JOIN users u ON mb.member_id = u.id 
                                                         SET mb.status = 'pending_approval' 
                                                         WHERE mb.id = ? AND u.choir_id = ? AND mb.status = 'open'");
                            
                            foreach ($selected_billings as $mb_id) {
                                $mb_id = intval($mb_id);
                                $stmtStatus->execute([$mb_id, $user['choir_id']]);
                                if ($stmtStatus->rowCount() > 0) {
                                    $stmtLink->execute([$receipt_id, $mb_id]);
                                }
                            }
                        }
                        
                        $pdo->commit();
                        
                        // Disparar envio de e-mail do pré-recibo
                        try {
                            $stmtEmailChoir = $pdo->prepare("SELECT name, logo FROM choirs WHERE id = ?");
                            $stmtEmailChoir->execute([$user['choir_id']]);
                            $emailChoir = $stmtEmailChoir->fetch();
                            $emailChoirName = $emailChoir ? $emailChoir['name'] : 'eCoral';
                            $emailLogoHtml = "";
                            if ($emailChoir && !empty($emailChoir['logo'])) {
                                $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                $logoUrl = $protocol . "://" . $host . "/www/ecoral/uploads/" . $emailChoir['logo'];
                                $emailLogoHtml = "<div style='text-align: center; margin-bottom: 20px;'><img src='" . htmlspecialchars($logoUrl) . "' style='max-height: 80px; object-fit: contain; background-color: #ffffff; padding: 4px; border-radius: 8px;' alt='Logo " . htmlspecialchars($emailChoirName) . "'></div>";
                            }

                            $leftover = max(0, $amount - $sum_selected_items);
                            $receipt_number = sprintf("%06d", $receipt_id);
                            $subject = "Pré-Recibo de Comprovante de Pagamento #" . $receipt_number;

                            $items_html = "";
                            if (empty($items_details)) {
                                $items_html = "<li>Nenhum item de cobrança selecionado. O valor total será creditado em seu saldo.</li>";
                            } else {
                                foreach ($items_details as $item) {
                                    $items_html .= "<li>" . htmlspecialchars($item['title']) . " (" . htmlspecialchars($item['member_name']) . "): <strong>" . format_currency($item['amount']) . "</strong></li>";
                                }
                            }

                            $leftover_message = "";
                            if ($leftover > 0) {
                                $leftover_message = "<p>Como o valor do comprovante (" . format_currency($amount) . ") é maior do que o somatório das cobranças selecionadas (" . format_currency($sum_selected_items) . "), após aprovado o seu pagamento você terá um saldo positivo (troco ou resíduo) no valor de <strong>" . format_currency($leftover) . "</strong>.</p>";
                            }

                            $body = "
                                <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                                    " . $emailLogoHtml . "
                                    <h2 style='color: #f43f5e; margin-top: 0;'>Olá, " . htmlspecialchars($user['name']) . "!</h2>
                                    <p>Recebemos o envio do seu comprovante de pagamento no valor de <strong>" . format_currency($amount) . "</strong>.</p>
                                    <p style='background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px; color: #78350f; font-size: 13px;'>
                                        <strong>ATENÇÃO:</strong> Este e-mail constitui um <strong>Pré-Recibo Número #" . $receipt_number . "</strong>, servindo apenas para comprovar o envio das informações. Ele <strong>somente terá valor de recibo oficial</strong> após a análise e confirmação manual do pagamento por parte da administração do coral.
                                    </p>
                                    
                                    <h3 style='color: #1e293b; margin-top: 24px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;'>Itens selecionados para pagamento:</h3>
                                    <ul style='padding-left: 20px; color: #475569;'>" . $items_html . "</ul>
                                    " . $leftover_message . "
                                    
                                    <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($emailChoirName) . "</p>
                                </div>
                            ";

                            send_email($user['email'], $user['name'], $subject, $body);
                        } catch (Exception $mailEx) {
                            error_log("Wizard receipt email failure: " . $mailEx->getMessage());
                        }

                        // Sinalizar exibição da tela final de sucesso com confirmação manual
                        $successSubmitted = true;
                        $submittedReceiptData = [
                            'id' => $receipt_id,
                            'number' => sprintf("%06d", $receipt_id),
                            'amount' => $amount,
                            'sum_items' => $sum_selected_items,
                            'leftover' => max(0, $amount - $sum_selected_items),
                            'items' => $items_details,
                            'description' => $description,
                            'filename' => $newFilename,
                            'is_shared' => $is_shared,
                            'shared_code' => $shared_member_code
                        ];
                    } catch (Exception $e) {
                        $pdo->rollBack();
                        if (file_exists($destination)) {
                            @unlink($destination);
                        }
                        $error = 'Erro ao registrar envio do comprovante: ' . $e->getMessage();
                    }
                } else {
                    $error = 'Não foi possível salvar o arquivo do comprovante. Verifique as permissões de upload.';
                }
            }
        }
    }
}

// Carregar cobranças em aberto do próprio usuário logado
$stmtMyBillings = $pdo->prepare("SELECT mb.*, bi.title, bi.amount as item_amount, bi.type as billing_type 
                                 FROM member_billing mb 
                                 JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                 WHERE mb.member_id = ? AND mb.status = 'open' 
                                 ORDER BY mb.due_date ASC");
$stmtMyBillings->execute([$user['id']]);
$myOpenBillings = $stmtMyBillings->fetchAll();

foreach ($myOpenBillings as &$mob) {
    if (($mob['billing_type'] ?? '') === 'recurring') {
        $mob['title'] .= ' - ' . date('m/Y', strtotime($mob['due_date']));
    }
    $mob['remaining_amount'] = floatval($mob['item_amount'] - $mob['paid_amount']);
    $mob['formatted_amount'] = format_currency($mob['remaining_amount']);
    $mob['formatted_due_date'] = format_date($mob['due_date']);
}
unset($mob);

// Parâmetros opcionais pré-selecionados via GET (ex: vindo da tabela de pagamentos)
$preselectBillingId = intval($_GET['billing_id'] ?? 0);
$preselectAmount = floatval($_GET['amount'] ?? 0);

require_once __DIR__ . '/layout_header.php';
?>

<div class="max-w-4xl mx-auto pb-12">

    <!-- Navegação de Retorno -->
    <div class="mb-4">
        <a href="payments.php" class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-coral-600 dark:text-slate-400 dark:hover:text-coral-400 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Voltar para Cobranças & Pagamentos
        </a>
    </div>

    <!-- Título da Página -->
    <div class="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-coral-50 dark:bg-coral-950/40 text-coral-600 dark:text-coral-400 flex items-center justify-center text-lg shrink-0">
                    📤
                </span>
                Enviar Comprovante de Pagamento
            </h1>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                Siga as etapas abaixo para informar seu comprovante de transferência ou Pix.
            </p>
        </div>
        
        <?php if (!$successSubmitted): ?>
            <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-100 dark:bg-slate-800 text-xs text-slate-600 dark:text-slate-300">
                <span class="w-2 h-2 rounded-full bg-coral-500 animate-pulse"></span>
                <span>Processo em 4 Passos</span>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-xl text-sm bg-rose-50 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300 border border-rose-200 dark:border-rose-900/50 flex items-start gap-3 shadow-sm">
            <span class="text-lg">⚠️</span>
            <div class="flex-1 font-medium"><?= htmlspecialchars($error) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($successSubmitted && $submittedReceiptData): ?>
        <!-- ==============================================================
             TELA FINAL DE SUCESSO / CONFIRMAÇÃO MANUAL
             ============================================================== -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 sm:p-10 shadow-sm border border-slate-100 dark:border-slate-700/50 text-center">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-emerald-50 dark:bg-emerald-950/40 text-emerald-500 dark:text-emerald-400 flex items-center justify-center text-3xl shadow-inner">
                ✓
            </div>
            
            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border border-amber-200 dark:border-amber-800/50 mb-3">
                Comprovante Enviado com Sucesso!
            </span>
            
            <h2 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white mb-2">
                Pré-Recibo Registrado: #<?= htmlspecialchars($submittedReceiptData['number']) ?>
            </h2>

            <!-- Mensagem explicativa destacada: depende de confirmação manual -->
            <div class="max-w-2xl mx-auto my-6 p-4 sm:p-5 rounded-2xl bg-gradient-to-r from-amber-50 to-orange-50 dark:from-amber-950/30 dark:to-orange-950/30 border border-amber-200 dark:border-amber-800/50 text-left">
                <div class="flex items-start gap-3.5">
                    <span class="text-2xl shrink-0">⏳</span>
                    <div class="space-y-1.5 text-xs text-amber-900 dark:text-amber-200 leading-relaxed">
                        <p class="font-bold text-sm text-amber-950 dark:text-amber-100">
                            Aguardando Confirmação Manual da Administração
                        </p>
                        <p>
                            Seu comprovante foi enviado com sucesso e encontra-se registrado sob o status <strong>Em Análise</strong>.
                        </p>
                        <p>
                            Um administrador ou responsável financeiro do coral fará a conferência bancária e a <strong>validação manual</strong> dos dados. Assim que confirmado, as cobranças selecionadas serão baixadas automaticamente e você receberá o recibo definitivo.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Resumo das informações enviadas -->
            <div class="max-w-2xl mx-auto bg-slate-50 dark:bg-slate-900/50 rounded-xl p-5 border border-slate-100 dark:border-slate-700/50 text-left text-xs space-y-3">
                <div class="flex justify-between items-center py-1 border-b border-slate-200 dark:border-slate-700/40">
                    <span class="text-slate-500 dark:text-slate-400">Valor Total do Comprovante:</span>
                    <span class="font-bold text-sm text-slate-900 dark:text-white"><?= format_currency($submittedReceiptData['amount']) ?></span>
                </div>
                
                <div class="flex justify-between items-center py-1 border-b border-slate-200 dark:border-slate-700/40">
                    <span class="text-slate-500 dark:text-slate-400">Total Alocado nas Cobranças:</span>
                    <span class="font-semibold text-slate-800 dark:text-slate-200"><?= format_currency($submittedReceiptData['sum_items']) ?></span>
                </div>

                <?php if ($submittedReceiptData['leftover'] > 0): ?>
                    <div class="flex justify-between items-center py-1 border-b border-slate-200 dark:border-slate-700/40 text-emerald-600 dark:text-emerald-400">
                        <span>Saldo Restante a Ser Creditado:</span>
                        <span class="font-bold"><?= format_currency($submittedReceiptData['leftover']) ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($submittedReceiptData['items'])): ?>
                    <div class="pt-2">
                        <span class="font-semibold text-slate-700 dark:text-slate-300 block mb-1.5">Cobranças Vinculadas:</span>
                        <ul class="space-y-1 pl-2">
                            <?php foreach ($submittedReceiptData['items'] as $item): ?>
                                <li class="text-slate-600 dark:text-slate-400 flex items-center justify-between">
                                    <span>• <?= htmlspecialchars($item['title']) ?> <span class="text-[10px] text-slate-400">(<?= htmlspecialchars($item['member_name']) ?>)</span></span>
                                    <strong class="text-slate-800 dark:text-slate-200"><?= format_currency($item['amount']) ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if (!empty($submittedReceiptData['description'])): ?>
                    <div class="pt-2 border-t border-slate-200 dark:border-slate-700/40">
                        <span class="text-slate-500 dark:text-slate-400 block mb-0.5">Observações:</span>
                        <p class="text-slate-700 dark:text-slate-300 italic"><?= nl2br(htmlspecialchars($submittedReceiptData['description'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Botões de Ação Final -->
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-3">
                <a href="payments.php" class="w-full sm:w-auto px-6 py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-xl text-xs shadow-md transition-all">
                    Ir para Minhas Cobranças & Histórico
                </a>
                <a href="submit-receipt.php" class="w-full sm:w-auto px-6 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-semibold rounded-xl text-xs transition-colors">
                    Enviar Outro Comprovante
                </a>
            </div>
        </div>

    <?php else: ?>

        <!-- ==============================================================
             WIZARD DE 4 PASSOS
             ============================================================== -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
            
            <!-- Barra de Progresso / Stepper -->
            <div class="border-b border-slate-100 dark:border-slate-700/50 bg-slate-50/70 dark:bg-slate-900/30 px-4 sm:px-8 py-4">
                <div class="grid grid-cols-4 gap-2 sm:gap-4 text-center">
                    
                    <!-- Passo 1 Indicator -->
                    <div id="step-indicator-1" class="step-indicator active flex flex-col items-center gap-1 transition-all">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-coral-500 text-white shadow-sm ring-4 ring-coral-100 dark:ring-coral-950/60">
                            1
                        </div>
                        <span class="text-[11px] font-semibold step-title text-coral-600 dark:text-coral-400">Comprovante</span>
                    </div>

                    <!-- Passo 2 Indicator -->
                    <div id="step-indicator-2" class="step-indicator flex flex-col items-center gap-1 transition-all opacity-50">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                            2
                        </div>
                        <span class="text-[11px] font-semibold step-title text-slate-500 dark:text-slate-400">Coralista</span>
                    </div>

                    <!-- Passo 3 Indicator -->
                    <div id="step-indicator-3" class="step-indicator flex flex-col items-center gap-1 transition-all opacity-50">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                            3
                        </div>
                        <span class="text-[11px] font-semibold step-title text-slate-500 dark:text-slate-400">Cobranças</span>
                    </div>

                    <!-- Passo 4 Indicator -->
                    <div id="step-indicator-4" class="step-indicator flex flex-col items-center gap-1 transition-all opacity-50">
                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300">
                            4
                        </div>
                        <span class="text-[11px] font-semibold step-title text-slate-500 dark:text-slate-400">Resumo</span>
                    </div>
                </div>
            </div>

            <!-- Formulário Principal do Wizard -->
            <form id="wizard-receipt-form" action="submit-receipt.php" method="POST" enctype="multipart/form-data" class="p-5 sm:p-8">
                <input type="hidden" name="action" value="process_wizard_receipt">

                <!-- ==============================================================
                     PASSO 1: Anexar Comprovante, Informar Valor, Checkbox Compartilhado
                     ============================================================== -->
                <div id="wizard-step-1" class="wizard-step space-y-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-coral-50 text-coral-600 dark:bg-coral-950/40 dark:text-coral-400">Passo 1 de 4</span>
                            <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white">Dados do Pagamento</h2>
                        </div>
                        <p class="text-xs text-slate-400">Anexe o arquivo do comprovante e informe o valor transferido.</p>
                    </div>

                    <!-- Arquivo Comprovante -->
                    <div>
                        <label for="receipt_file" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Anexar Comprovante (JPG, PNG ou PDF) *
                        </label>
                        <div class="relative border-2 border-dashed border-slate-200 dark:border-slate-700 hover:border-coral-400 dark:hover:border-coral-500 rounded-2xl p-5 text-center transition-all bg-slate-50/50 dark:bg-slate-900/30 group">
                            <input type="file" name="receipt_file" id="receipt_file" required accept=".jpg,.jpeg,.png,.pdf"
                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                   onchange="handleReceiptFileChange(this)">
                            <div id="file-dropzone-content" class="space-y-2 pointer-events-none">
                                <div class="w-12 h-12 mx-auto rounded-full bg-coral-50 dark:bg-coral-950/40 text-coral-600 dark:text-coral-400 flex items-center justify-center text-xl group-hover:scale-110 transition-transform">
                                    📁
                                </div>
                                <div class="text-xs font-semibold text-slate-700 dark:text-slate-200">
                                    <span class="text-coral-600 dark:text-coral-400">Clique para selecionar</span> ou arraste o arquivo aqui
                                </div>
                                <p class="text-[11px] text-slate-400">Suporta JPG, PNG ou PDF (até 10MB)</p>
                            </div>
                            <div id="file-preview-info" class="hidden text-xs font-medium text-emerald-600 dark:text-emerald-400 flex items-center justify-center gap-2">
                                <span>📄</span>
                                <span id="file-name-display" class="font-bold"></span>
                                <span id="file-size-display" class="text-[10px] text-slate-400"></span>
                            </div>
                        </div>
                        <p id="error-file" class="text-[11px] text-rose-500 mt-1 hidden">Por favor, anexe o comprovante de pagamento.</p>
                    </div>

                    <!-- Valor do Pagamento -->
                    <div>
                        <label for="amount" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Qual o valor do pagamento realizado? (R$) *
                        </label>
                        <div class="relative">
                            <span class="absolute left-3.5 top-1/2 -translate-y-1/2 font-bold text-sm text-slate-400">R$</span>
                            <input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" name="amount" id="amount" required
                                   data-currency-mask
                                   value="<?= $preselectAmount > 0 ? number_format($preselectAmount, 2, ',', '.') : '' ?>"
                                   class="w-full pl-11 pr-4 py-3 text-base font-bold bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                                   placeholder="0,00"
                                   oninput="handleAmountInput()">
                        </div>
                        <p class="text-[11px] text-slate-400 mt-1">Ex: 160,00 (Informe o valor exato que consta no comprovante).</p>
                        <p id="error-amount" class="text-[11px] text-rose-500 mt-1 hidden">Informe um valor válido maior que zero.</p>
                    </div>

                    <!-- Checkbox de Pagamento Compartilhado -->
                    <div class="p-4 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/30">
                        <label class="flex items-start gap-3 cursor-pointer select-none">
                            <input type="checkbox" name="is_shared" id="is_shared" value="1"
                                   class="w-4 h-4 mt-0.5 rounded text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700"
                                   onchange="handleSharedToggle(this)">
                            <div>
                                <span class="text-xs font-bold text-slate-800 dark:text-white block">
                                    Este pagamento será compartilhado com outro coralista?
                                </span>
                                <span class="text-[11px] text-slate-500 dark:text-slate-400 leading-relaxed block mt-0.5">
                                    Marque caso você tenha pago mensalidades ou taxas de outro integrante do coral junto com as suas no mesmo comprovante (ex: cônjuge, filho ou colega).
                                </span>
                            </div>
                        </label>
                    </div>

                    <!-- Botão Avançar Passo 1 -->
                    <div class="flex justify-end pt-4 border-t border-slate-100 dark:border-slate-700/50">
                        <button type="button" onclick="goToStep(2)"
                                class="px-6 py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-xl text-xs shadow-md transition-all flex items-center gap-2 cursor-pointer">
                            <span>Avançar</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ==============================================================
                     PASSO 2: Código do Coralista Compartilhado (ou Pula para Passo 3 se não compartilhado)
                     ============================================================== -->
                <div id="wizard-step-2" class="wizard-step hidden space-y-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-coral-50 text-coral-600 dark:bg-coral-950/40 dark:text-coral-400">Passo 2 de 4</span>
                            <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white">Pagamento Compartilhado</h2>
                        </div>
                        <p class="text-xs text-slate-400">Informe o código do coralista cujas cobranças serão pagas junto com este comprovante.</p>
                    </div>

                    <div id="step-2-active-content" class="space-y-4">
                        <div>
                            <label for="shared_member_code" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                                Código do Coralista Beneficiado (Ex: B15612) *
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                                <input type="text" name="shared_member_code" id="shared_member_code" placeholder="Ex: B15612"
                                       class="w-full px-4 py-3 sm:py-2.5 text-xs font-bold uppercase bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all tracking-wider"
                                       onkeydown="if(event.key==='Enter'){event.preventDefault(); fetchSharedMember();}">
                                <button type="button" onclick="fetchSharedMember()" id="btn-search-member"
                                        class="w-full px-5 py-3 sm:py-2.5 bg-slate-800 hover:bg-slate-900 text-white dark:bg-slate-700 dark:hover:bg-slate-600 font-semibold rounded-xl text-xs shadow-sm transition-all flex items-center justify-center gap-2 cursor-pointer">
                                    <span>🔍</span> Buscar Coralista
                                </button>
                            </div>
                            <p class="text-[11px] text-slate-400 mt-1">Peça o código ao colega de coral ou confira no cadastro dele.</p>
                            <p id="error-step2-member" class="text-[11px] text-rose-500 mt-1 hidden">Por favor, busque e selecione um coralista válido antes de continuar.</p>
                        </div>

                        <!-- Card de Resultado da Busca do Coralista -->
                        <div id="shared-member-result" class="hidden rounded-xl border border-slate-200 dark:border-slate-700 p-4 bg-slate-50 dark:bg-slate-900/40 space-y-2">
                            <!-- Inserido dinamicamente via JS -->
                        </div>
                    </div>

                    <!-- Mensagem informativa caso o checkbox não tenha sido marcado -->
                    <div id="step-2-skipped-content" class="hidden p-5 rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/70 dark:bg-slate-900/30 text-center space-y-2">
                        <span class="text-2xl block">👤</span>
                        <h3 class="text-sm font-bold text-slate-800 dark:text-white">Pagamento Individual</h3>
                        <p class="text-xs text-slate-500 dark:text-slate-400 max-w-md mx-auto">
                            Você indicou no Passo 1 que este comprovante é exclusivo para as suas próprias mensalidades. 
                            Você pode avançar diretamente para escolher suas cobranças.
                        </p>
                    </div>

                    <!-- Navegação Passo 2 -->
                    <div class="flex items-center justify-between pt-4 border-t border-slate-100 dark:border-slate-700/50">
                        <button type="button" onclick="goToStep(1)"
                                class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-300 font-semibold rounded-xl text-xs transition-colors cursor-pointer">
                            ← Voltar
                        </button>
                        <button type="button" onclick="goToStep(3)"
                                class="px-6 py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-xl text-xs shadow-md transition-all flex items-center gap-2 cursor-pointer">
                            <span>Avançar para Cobranças</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ==============================================================
                     PASSO 3: Selecionar Itens de Cobrança (com Consumo do Valor Informado)
                     ============================================================== -->
                <div id="wizard-step-3" class="wizard-step hidden space-y-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-coral-50 text-coral-600 dark:bg-coral-950/40 dark:text-coral-400">Passo 3 de 4</span>
                            <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white">Selecionar Cobranças para Pagar</h2>
                        </div>
                        <p class="text-xs text-slate-400">
                            Marque os itens que deseja quitar. À medida que você seleciona, o valor pago será consumido.
                        </p>
                    </div>

                    <!-- Painel Superior de Consumo do Saldo Informado -->
                    <div class="p-4 sm:p-5 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 text-white shadow-md space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div class="p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-[10px] text-slate-400 uppercase font-semibold block tracking-wider">Valor Informado</span>
                                <span id="display-step3-declared" class="text-lg font-extrabold text-white">R$ 0,00</span>
                            </div>
                            <div class="p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-[10px] text-slate-400 uppercase font-semibold block tracking-wider">Itens Selecionados</span>
                                <span id="display-step3-selected" class="text-lg font-extrabold text-coral-400">R$ 0,00</span>
                            </div>
                            <div class="p-3 rounded-xl bg-white/5 border border-white/10">
                                <span class="text-[10px] text-slate-400 uppercase font-semibold block tracking-wider">Valor Restante (Troco/Saldo)</span>
                                <span id="display-step3-remaining" class="text-lg font-extrabold text-emerald-400">R$ 0,00</span>
                            </div>
                        </div>

                        <!-- Barra visual de progresso de consumo -->
                        <div class="space-y-1">
                            <div class="w-full bg-slate-700 rounded-full h-2 overflow-hidden">
                                <div id="step3-progress-bar" class="bg-coral-500 h-2 rounded-full transition-all duration-300" style="width: 0%;"></div>
                            </div>
                            <div class="flex justify-between text-[10px] text-slate-400">
                                <span id="step3-progress-text">0% consumido</span>
                                <span id="step3-items-count">0 item selecionado</span>
                            </div>
                        </div>

                        <!-- Alerta se exceder o valor informado -->
                        <div id="step3-exceeded-alert" class="hidden p-3 rounded-xl bg-rose-500/20 border border-rose-500/40 text-rose-200 text-xs flex items-center gap-2">
                            <span>🚫</span>
                            <span><strong>Atenção:</strong> O valor dos itens selecionados ultrapassa o valor do pagamento! Desmarque algum item para prosseguir.</span>
                        </div>
                    </div>

                    <!-- Cards Collapsáveis de Cobranças -->
                    <div class="space-y-4">
                        <!-- Card Collapsável: Minhas Cobranças em Aberto -->
                        <div class="border border-slate-200 dark:border-slate-700/80 rounded-2xl overflow-hidden bg-white dark:bg-slate-900/40 shadow-sm transition-all">
                            <button type="button" onclick="toggleBillingAccordion('own-billings-collapse', 'own-billings-chevron')"
                                    class="w-full p-4 flex items-center justify-between text-left bg-slate-50/70 hover:bg-slate-100/80 dark:bg-slate-800/50 dark:hover:bg-slate-800/80 transition-colors cursor-pointer select-none">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-coral-50 dark:bg-coral-950/40 text-coral-600 dark:text-coral-400 flex items-center justify-center text-sm font-bold shrink-0">
                                        👤
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-800 dark:text-white uppercase tracking-wider flex items-center gap-2">
                                            <span>Suas Cobranças em Aberto</span>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-coral-100 text-coral-700 dark:bg-coral-950/60 dark:text-coral-300">
                                                <?= count($myOpenBillings) ?>
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                                            <?= htmlspecialchars($user['name']) ?> (Você)
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 hidden sm:inline">Exibir / Ocultar</span>
                                    <svg id="own-billings-chevron" class="w-4 h-4 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>
                            </button>

                            <div id="own-billings-collapse" class="p-4 pt-3 border-t border-slate-100 dark:border-slate-800/60 space-y-2">
                                <?php if (empty($myOpenBillings)): ?>
                                    <div class="p-4 rounded-xl border border-slate-100 dark:border-slate-700/50 bg-slate-50 dark:bg-slate-900/20 text-center text-xs text-slate-400">
                                        Você não possui cobranças em aberto no momento. O valor do comprovante será lançado como saldo na sua conta.
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                        <?php foreach ($myOpenBillings as $mob): 
                                            $isPreselected = ($preselectBillingId > 0 && $mob['id'] == $preselectBillingId);
                                        ?>
                                            <label class="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-coral-300 dark:hover:border-coral-600 bg-slate-50/50 dark:bg-slate-900/30 cursor-pointer transition-all hover:shadow-xs">
                                                <div class="flex items-center gap-3 min-w-0">
                                                    <input type="checkbox" name="selected_billings[]" value="<?= $mob['id'] ?>"
                                                           data-amount="<?= $mob['remaining_amount'] ?>"
                                                           data-title="<?= htmlspecialchars($mob['title']) ?>"
                                                           data-owner="<?= htmlspecialchars($user['name']) ?> (Você)"
                                                           <?= $isPreselected ? 'checked' : '' ?>
                                                           class="w-4 h-4 rounded text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700 wizard-billing-checkbox"
                                                           onchange="recalculateWizardConsumption()">
                                                    <div class="min-w-0">
                                                        <div class="text-xs font-bold text-slate-800 dark:text-white truncate">
                                                            <?= htmlspecialchars($mob['title']) ?>
                                                        </div>
                                                        <div class="text-[10px] text-slate-400">
                                                            Vencimento: <?= $mob['formatted_due_date'] ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <span class="text-xs font-bold text-slate-900 dark:text-white shrink-0 ml-3">
                                                    <?= $mob['formatted_amount'] ?>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Card Collapsável: Cobranças do Coralista Compartilhado (se houver) -->
                        <div id="shared-member-billings-container" class="hidden border border-slate-200 dark:border-slate-700/80 rounded-2xl overflow-hidden bg-white dark:bg-slate-900/40 shadow-sm transition-all">
                            <button type="button" onclick="toggleBillingAccordion('shared-billings-collapse', 'shared-billings-chevron')"
                                    class="w-full p-4 flex items-center justify-between text-left bg-slate-50/70 hover:bg-slate-100/80 dark:bg-slate-800/50 dark:hover:bg-slate-800/80 transition-colors cursor-pointer select-none">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 rounded-xl bg-purple-50 dark:bg-purple-950/40 text-purple-600 dark:text-purple-400 flex items-center justify-center text-sm font-bold shrink-0">
                                        👥
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-800 dark:text-white uppercase tracking-wider flex items-center gap-2">
                                            <span>Cobranças de: <span id="shared-member-title-name" class="text-purple-600 dark:text-purple-400 font-bold normal-case"></span></span>
                                            <span id="shared-member-billings-badge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-purple-100 text-purple-700 dark:bg-purple-950/60 dark:text-purple-300">
                                                0
                                            </span>
                                        </div>
                                        <p class="text-[11px] text-slate-400 dark:text-slate-500 mt-0.5">
                                            Pagamento compartilhado
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] font-semibold text-slate-500 dark:text-slate-400 hidden sm:inline">Exibir / Ocultar</span>
                                    <svg id="shared-billings-chevron" class="w-4 h-4 text-slate-400 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </div>
                            </button>

                            <div id="shared-billings-collapse" class="p-4 pt-3 border-t border-slate-100 dark:border-slate-800/60 space-y-2">
                                <div id="shared-member-billings-list" class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                    <!-- Inserido dinamicamente via JS -->
                                </div>
                            </div>
                        </div>
                    </div>

                    <p id="error-step3-exceeded" class="text-[11px] text-rose-500 font-semibold hidden">
                        ⚠️ O total das cobranças marcadas excede o valor informado para o pagamento. Ajuste a seleção para continuar.
                    </p>

                    <!-- Navegação Passo 3 -->
                    <div class="flex items-center justify-between pt-4 border-t border-slate-100 dark:border-slate-700/50">
                        <button type="button" onclick="goToStep(2)"
                                class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-300 font-semibold rounded-xl text-xs transition-colors cursor-pointer">
                            ← Voltar
                        </button>
                        <button type="button" id="btn-next-step-3" onclick="goToStep(4)"
                                class="px-6 py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-xl text-xs shadow-md transition-all flex items-center gap-2 cursor-pointer">
                            <span>Avançar para Resumo</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </div>

                <!-- ==============================================================
                     PASSO 4: Resumo do que está sendo pago, Campo Observações e Botão Finalizar
                     ============================================================== -->
                <div id="wizard-step-4" class="wizard-step hidden space-y-6">
                    <div>
                        <div class="flex items-center gap-2 mb-1">
                            <span class="px-2 py-0.5 rounded text-[11px] font-bold bg-coral-50 text-coral-600 dark:bg-coral-950/40 dark:text-coral-400">Passo 4 de 4</span>
                            <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white">Resumo e Finalização</h2>
                        </div>
                        <p class="text-xs text-slate-400">
                            Revise todas as informações antes de enviar o comprovante para análise.
                        </p>
                    </div>

                    <!-- Resumo Geral em Cards -->
                    <div class="bg-slate-50 dark:bg-slate-900/40 rounded-2xl p-5 border border-slate-100 dark:border-slate-700/50 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <span class="text-[11px] text-slate-400 block">Comprovante Anexado:</span>
                                <span id="summary-file-name" class="text-xs font-bold text-slate-800 dark:text-white flex items-center gap-1.5 mt-0.5">
                                    <span>📎</span> Nenhum arquivo
                                </span>
                            </div>
                            <div>
                                <span class="text-[11px] text-slate-400 block">Valor Declarado do Pagamento:</span>
                                <span id="summary-declared-amount" class="text-sm font-extrabold text-coral-600 dark:text-coral-400 mt-0.5 block">
                                    R$ 0,00
                                </span>
                            </div>
                        </div>

                        <!-- Lista de Cobranças que serão quitadas -->
                        <div class="pt-3 border-t border-slate-200 dark:border-slate-700/50">
                            <span class="text-xs font-bold text-slate-700 dark:text-slate-200 block mb-2">
                                Cobranças Selecionadas para Quitação:
                            </span>
                            <div id="summary-selected-items-list" class="space-y-1.5 text-xs text-slate-600 dark:text-slate-300">
                                <!-- Preenchido dinamicamente via JS -->
                            </div>
                        </div>

                        <!-- Saldo restante / Troco -->
                        <div id="summary-leftover-container" class="pt-3 border-t border-slate-200 dark:border-slate-700/50 hidden">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-emerald-600 dark:text-emerald-400 font-semibold">
                                    Saldo restante a ser adicionado à sua conta:
                                </span>
                                <span id="summary-leftover-amount" class="font-extrabold text-emerald-600 dark:text-emerald-400">
                                    R$ 0,00
                                </span>
                            </div>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                A diferença entre o comprovante e os itens marcados ficará disponível para futuras cobranças.
                            </p>
                        </div>
                    </div>

                    <!-- Campo Observações / Mensagem -->
                    <div>
                        <label for="description" class="block text-xs font-bold text-slate-700 dark:text-slate-200 mb-1.5">
                            Observações Adicionais (Opcional)
                        </label>
                        <textarea name="description" id="description" rows="3"
                                  class="w-full px-4 py-2.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all placeholder:text-slate-400"
                                  placeholder="Ex: Mensalidades de Abril e Maio pagas via Pix Itaú, comprovante conjunto com Maria Silva."></textarea>
                    </div>

                    <!-- Aviso de Confirmação Manual antes do Envio -->
                    <div class="p-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/30 border border-amber-200 dark:border-amber-800/40 text-[11px] text-amber-800 dark:text-amber-200 flex items-start gap-2.5">
                        <span class="text-base shrink-0">ℹ️</span>
                        <div>
                            Ao clicar em <strong>Finalizar e Enviar Comprovante</strong>, seu comprovante será encaminhado para a administração do coral para conferência manual. Você receberá um e-mail com o pré-recibo comprovando a entrega.
                        </div>
                    </div>

                    <!-- Navegação Passo 4 e Botão Finalizar -->
                    <div class="flex items-center justify-between pt-4 border-t border-slate-100 dark:border-slate-700/50">
                        <button type="button" onclick="goToStep(3)"
                                class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-300 font-semibold rounded-xl text-xs transition-colors cursor-pointer">
                            ← Voltar
                        </button>
                        <button type="submit" id="btn-submit-final"
                                class="px-8 py-3 bg-gradient-to-r from-coral-500 to-rose-600 hover:from-coral-600 hover:to-rose-700 text-white font-bold rounded-xl text-xs shadow-lg shadow-coral-500/25 transition-all flex items-center gap-2 cursor-pointer hover:scale-[1.02]">
                            <span>Finalizar e Enviar Comprovante</span>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    </div>
                </div>

            </form>
        </div>

    <?php endif; ?>

</div>

<script>
// Estado do Wizard
let currentStep = 1;
let sharedMemberData = null;

// Formatação BRL
function formatBRL(val) {
    return 'R$ ' + val.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function getDeclaredAmount() {
    const hiddenAmount = document.querySelector('input[type="hidden"][name="amount"]');
    if (hiddenAmount && hiddenAmount.value !== '') {
        return parseFloat(hiddenAmount.value) || 0;
    }
    const displayInput = document.getElementById('amount');
    if (displayInput && displayInput.value) {
        return parseFloat(displayInput.value.replace(/\./g, '').replace(',', '.')) || 0;
    }
    return 0;
}

// Manipulação do arquivo
function handleReceiptFileChange(input) {
    const dropzoneContent = document.getElementById('file-dropzone-content');
    const previewInfo = document.getElementById('file-preview-info');
    const nameDisplay = document.getElementById('file-name-display');
    const sizeDisplay = document.getElementById('file-size-display');
    const errorFile = document.getElementById('error-file');
    
    if (input.files && input.files[0]) {
        const file = input.files[0];
        if (dropzoneContent) dropzoneContent.classList.add('hidden');
        if (previewInfo) previewInfo.classList.remove('hidden');
        if (nameDisplay) nameDisplay.textContent = file.name;
        if (sizeDisplay) {
            const kb = (file.size / 1024).toFixed(1);
            sizeDisplay.textContent = '(' + (kb > 1024 ? (kb / 1024).toFixed(2) + ' MB' : kb + ' KB') + ')';
        }
        if (errorFile) errorFile.classList.add('hidden');
    } else {
        if (dropzoneContent) dropzoneContent.classList.remove('hidden');
        if (previewInfo) previewInfo.classList.add('hidden');
    }
}

// Manipulação do Checkbox Compartilhado
function handleSharedToggle(checkbox) {
    const step2Active = document.getElementById('step-2-active-content');
    const step2Skipped = document.getElementById('step-2-skipped-content');
    
    if (checkbox.checked) {
        if (step2Active) step2Active.classList.remove('hidden');
        if (step2Skipped) step2Skipped.classList.add('hidden');
    } else {
        if (step2Active) step2Active.classList.add('hidden');
        if (step2Skipped) step2Skipped.classList.remove('hidden');
        // Limpar dados do membro compartilhado caso desmarque
        clearSharedMemberData();
    }
}

function handleAmountInput() {
    const errorAmount = document.getElementById('error-amount');
    if (errorAmount) errorAmount.classList.add('hidden');
    // Pequeno timeout caso a máscara monetária ainda esteja atualizando o hidden no mesmo tick
    setTimeout(recalculateWizardConsumption, 10);
}

// Busca AJAX do outro coralista
function fetchSharedMember() {
    const codeInput = document.getElementById('shared_member_code');
    const resultDiv = document.getElementById('shared-member-result');
    const errEl = document.getElementById('error-step2-member');
    const code = codeInput ? codeInput.value.trim() : '';
    
    if (!code) {
        alert('Por favor, informe o código do coralista.');
        if (codeInput) codeInput.focus();
        return;
    }
    
    if (errEl) errEl.classList.add('hidden');
    resultDiv.innerHTML = '<div class="text-xs text-slate-500 animate-pulse py-2 text-center">Buscando coralista...</div>';
    resultDiv.classList.remove('hidden');
    
    fetch('submit-receipt.php?action=fetch_member_billings&code=' + encodeURIComponent(code))
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                sharedMemberData = data;
                renderSharedMemberResult(data);
                populateSharedMemberBillings(data);
            } else {
                sharedMemberData = null;
                resultDiv.innerHTML = `
                    <div class="text-xs text-rose-500 font-semibold flex items-center gap-2 p-2">
                        <span>⚠️</span>
                        <span>${escapeHtml(data.message)}</span>
                    </div>
                `;
                clearSharedMemberBillingsList();
            }
        })
        .catch(err => {
            sharedMemberData = null;
            resultDiv.innerHTML = '<div class="text-xs text-rose-500 font-semibold p-2">Erro de conexão ao buscar coralista.</div>';
            clearSharedMemberBillingsList();
            console.error(err);
        });
}

function renderSharedMemberResult(data) {
    const resultDiv = document.getElementById('shared-member-result');
    if (!resultDiv) return;
    
    resultDiv.innerHTML = `
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-full bg-coral-100 dark:bg-coral-950/60 text-coral-600 dark:text-coral-400 flex items-center justify-center font-bold text-xs">
                    👤
                </div>
                <div>
                    <h4 class="text-xs font-bold text-slate-900 dark:text-white">${escapeHtml(data.member_name)}</h4>
                    <span class="text-[10px] text-slate-400 font-semibold">Código: ${escapeHtml(data.member_code)}</span>
                </div>
            </div>
            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                Confirmado
            </span>
        </div>
        <p class="text-[11px] text-slate-500 dark:text-slate-400 mt-2">
            ${data.billings.length > 0 
                ? 'Foram encontradas <strong>' + data.billings.length + ' cobrança(s)</strong> em aberto deste coralista. Elas estarão disponíveis no próximo passo para você selecionar!' 
                : 'Este coralista não possui cobranças em aberto no momento. (Caso queira enviar saldo para ele, indique nas observações).'}
        </p>
    `;
}

function populateSharedMemberBillings(data) {
    const container = document.getElementById('shared-member-billings-container');
    const titleName = document.getElementById('shared-member-title-name');
    const badgeEl = document.getElementById('shared-member-billings-badge');
    const listEl = document.getElementById('shared-member-billings-list');
    
    if (!container || !listEl) return;
    
    container.classList.remove('hidden');
    if (titleName) titleName.textContent = data.member_name;
    if (badgeEl) badgeEl.textContent = data.billings.length;
    
    // Garantir que o accordion do membro compartilhado esteja aberto ao carregar
    const collapseEl = document.getElementById('shared-billings-collapse');
    const chevronEl = document.getElementById('shared-billings-chevron');
    if (collapseEl) collapseEl.classList.remove('hidden');
    if (chevronEl) chevronEl.classList.add('rotate-180');
    
    if (data.billings.length === 0) {
        listEl.innerHTML = '<div class="p-3 text-center text-xs text-slate-400 bg-slate-50 dark:bg-slate-900/30 rounded-xl">Nenhuma cobrança em aberto deste coralista.</div>';
        return;
    }
    
    let html = '';
    data.billings.forEach(b => {
        html += `
            <label class="flex items-center justify-between p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-purple-300 dark:hover:border-purple-600 bg-slate-50/50 dark:bg-slate-900/30 cursor-pointer transition-all hover:shadow-xs">
                <div class="flex items-center gap-3 min-w-0">
                    <input type="checkbox" name="selected_billings[]" value="${b.id}"
                           data-amount="${b.remaining_amount}"
                           data-title="${escapeHtml(b.title)}"
                           data-owner="${escapeHtml(data.member_name)}"
                           class="w-4 h-4 rounded text-purple-600 border-slate-300 focus:ring-purple-500 dark:bg-slate-900 dark:border-slate-700 wizard-billing-checkbox"
                           onchange="recalculateWizardConsumption()">
                    <div class="min-w-0">
                        <div class="text-xs font-bold text-slate-800 dark:text-white truncate">
                            ${escapeHtml(b.title)}
                        </div>
                        <div class="text-[10px] text-slate-400">
                            Coralista: <strong>${escapeHtml(data.member_name)}</strong> | Vencimento: ${b.formatted_due_date}
                        </div>
                    </div>
                </div>
                <span class="text-xs font-bold text-slate-900 dark:text-white shrink-0 ml-3">
                    ${b.formatted_amount}
                </span>
            </label>
        `;
    });
    listEl.innerHTML = html;
}

function toggleBillingAccordion(collapseId, chevronId) {
    const collapse = document.getElementById(collapseId);
    const chevron = document.getElementById(chevronId);
    if (!collapse) return;
    
    const isHidden = collapse.classList.contains('hidden');
    if (isHidden) {
        collapse.classList.remove('hidden');
        if (chevron) chevron.classList.add('rotate-180');
    } else {
        collapse.classList.add('hidden');
        if (chevron) chevron.classList.remove('rotate-180');
    }
}

function clearSharedMemberData() {
    sharedMemberData = null;
    const codeInput = document.getElementById('shared_member_code');
    const resultDiv = document.getElementById('shared-member-result');
    if (codeInput) codeInput.value = '';
    if (resultDiv) {
        resultDiv.innerHTML = '';
        resultDiv.classList.add('hidden');
    }
    clearSharedMemberBillingsList();
    recalculateWizardConsumption();
}

function clearSharedMemberBillingsList() {
    const container = document.getElementById('shared-member-billings-container');
    const listEl = document.getElementById('shared-member-billings-list');
    if (container) container.classList.add('hidden');
    if (listEl) listEl.innerHTML = '';
}

// Cálculo do consumo do saldo informado no Passo 3
function recalculateWizardConsumption() {
    const declared = getDeclaredAmount();
    let sumSelected = 0;
    let selectedCount = 0;
    
    document.querySelectorAll('.wizard-billing-checkbox:checked').forEach(cb => {
        sumSelected += parseFloat(cb.getAttribute('data-amount')) || 0;
        selectedCount++;
    });
    
    const remaining = declared - sumSelected;
    const isExceeded = (sumSelected > declared + 0.0001) && (declared > 0);
    
    // Atualizar labels no painel
    const dispDeclared = document.getElementById('display-step3-declared');
    const dispSelected = document.getElementById('display-step3-selected');
    const dispRemaining = document.getElementById('display-step3-remaining');
    const progressBar = document.getElementById('step3-progress-bar');
    const progressText = document.getElementById('step3-progress-text');
    const itemsCountEl = document.getElementById('step3-items-count');
    const alertExceeded = document.getElementById('step3-exceeded-alert');
    const errorStep3 = document.getElementById('error-step3-exceeded');
    const btnNext = document.getElementById('btn-next-step-3');
    
    if (dispDeclared) dispDeclared.textContent = formatBRL(declared);
    if (dispSelected) dispSelected.textContent = formatBRL(sumSelected);
    
    if (dispRemaining) {
        dispRemaining.textContent = formatBRL(Math.max(0, remaining));
        if (remaining < 0) {
            dispRemaining.classList.remove('text-emerald-400');
            dispRemaining.classList.add('text-rose-400');
            dispRemaining.textContent = '- ' + formatBRL(Math.abs(remaining));
        } else {
            dispRemaining.classList.remove('text-rose-400');
            dispRemaining.classList.add('text-emerald-400');
        }
    }
    
    // Barra de progresso
    let percent = 0;
    if (declared > 0) {
        percent = Math.min(100, Math.round((sumSelected / declared) * 100));
    }
    if (progressBar) {
        progressBar.style.width = percent + '%';
        if (isExceeded) {
            progressBar.classList.remove('bg-coral-500');
            progressBar.classList.add('bg-rose-500');
        } else {
            progressBar.classList.remove('bg-rose-500');
            progressBar.classList.add('bg-coral-500');
        }
    }
    if (progressText) progressText.textContent = percent + '% consumido';
    if (itemsCountEl) itemsCountEl.textContent = selectedCount + (selectedCount === 1 ? ' item selecionado' : ' itens selecionados');
    
    // Bloqueio se excedido
    if (isExceeded) {
        if (alertExceeded) alertExceeded.classList.remove('hidden');
        if (errorStep3) errorStep3.classList.remove('hidden');
        if (btnNext) {
            btnNext.disabled = true;
            btnNext.classList.add('opacity-50', 'cursor-not-allowed');
        }
    } else {
        if (alertExceeded) alertExceeded.classList.add('hidden');
        if (errorStep3) errorStep3.classList.add('hidden');
        if (btnNext) {
            btnNext.disabled = false;
            btnNext.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }
}

// Navegação entre os passos do Wizard com validações
function goToStep(step) {
    if (step > currentStep) {
        // Validação ao sair do Passo 1
        if (currentStep === 1) {
            const fileInput = document.getElementById('receipt_file');
            const errorFile = document.getElementById('error-file');
            const declared = getDeclaredAmount();
            const errorAmount = document.getElementById('error-amount');
            let hasError = false;
            
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                if (errorFile) errorFile.classList.remove('hidden');
                hasError = true;
            } else {
                if (errorFile) errorFile.classList.add('hidden');
            }
            
            if (declared <= 0) {
                if (errorAmount) errorAmount.classList.remove('hidden');
                hasError = true;
            } else {
                if (errorAmount) errorAmount.classList.add('hidden');
            }
            
            if (hasError) return;
            
            // Se o checkbox de compartilhado não estiver marcado e o usuário tentar ir pro passo 2,
            // pode passar pelo passo 2 mostrando o resumo ou permitir ir direto para o passo 3
            const isShared = document.getElementById('is_shared')?.checked;
            if (!isShared && step === 2) {
                // Prepara a tela informativa do passo 2
                handleSharedToggle(document.getElementById('is_shared'));
            }
        }
        
        // Validação ao sair do Passo 2
        if (currentStep === 2) {
            const isShared = document.getElementById('is_shared')?.checked;
            const errEl = document.getElementById('error-step2-member');
            if (isShared && !sharedMemberData) {
                if (errEl) errEl.classList.remove('hidden');
                alert('Você marcou pagamento compartilhado! Por favor, informe o código do outro coralista e clique em Buscar.');
                return;
            }
        }
        
        // Validação ao sair do Passo 3
        if (currentStep === 3) {
            const declared = getDeclaredAmount();
            let sumSelected = 0;
            document.querySelectorAll('.wizard-billing-checkbox:checked').forEach(cb => {
                sumSelected += parseFloat(cb.getAttribute('data-amount')) || 0;
            });
            if (sumSelected > declared + 0.0001 && declared > 0) {
                alert('Não é possível prosseguir: a soma dos itens selecionados (' + formatBRL(sumSelected) + ') ultrapassa o valor do pagamento (' + formatBRL(declared) + '). Desmarque cobranças para continuar.');
                return;
            }
            // Monta o resumo no Passo 4
            renderStep4Summary();
        }
    }
    
    // Transição de tela
    for (let i = 1; i <= 4; i++) {
        const stepDiv = document.getElementById('wizard-step-' + i);
        const indicator = document.getElementById('step-indicator-' + i);
        if (stepDiv) {
            if (i === step) {
                stepDiv.classList.remove('hidden');
            } else {
                stepDiv.classList.add('hidden');
            }
        }
        if (indicator) {
            const bubble = indicator.querySelector('.step-bubble');
            const title = indicator.querySelector('.step-title');
            if (i < step) {
                indicator.classList.remove('opacity-50');
                bubble.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-emerald-500 text-white';
                bubble.innerHTML = '✓';
                title.className = 'text-[11px] font-semibold step-title text-emerald-600 dark:text-emerald-400';
            } else if (i === step) {
                indicator.classList.remove('opacity-50');
                bubble.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-coral-500 text-white shadow-sm ring-4 ring-coral-100 dark:ring-coral-950/60';
                bubble.innerHTML = i;
                title.className = 'text-[11px] font-semibold step-title text-coral-600 dark:text-coral-400';
            } else {
                indicator.classList.add('opacity-50');
                bubble.className = 'w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold transition-all step-bubble bg-slate-200 dark:bg-slate-700 text-slate-600 dark:text-slate-300';
                bubble.innerHTML = i;
                title.className = 'text-[11px] font-semibold step-title text-slate-500 dark:text-slate-400';
            }
        }
    }
    
    currentStep = step;
    window.scrollTo({ top: 0, behavior: 'smooth' });
    
    if (step === 3) {
        recalculateWizardConsumption();
    }
}

// Renderiza o resumo no Passo 4
function renderStep4Summary() {
    const fileInput = document.getElementById('receipt_file');
    const summaryFileName = document.getElementById('summary-file-name');
    const summaryDeclared = document.getElementById('summary-declared-amount');
    const summaryList = document.getElementById('summary-selected-items-list');
    const summaryLeftoverContainer = document.getElementById('summary-leftover-container');
    const summaryLeftoverAmount = document.getElementById('summary-leftover-amount');
    
    if (fileInput && fileInput.files && fileInput.files[0]) {
        summaryFileName.innerHTML = '<span>📄</span> ' + escapeHtml(fileInput.files[0].name);
    } else {
        summaryFileName.innerHTML = '<span class="text-rose-500">Nenhum arquivo anexado</span>';
    }
    
    const declared = getDeclaredAmount();
    if (summaryDeclared) summaryDeclared.textContent = formatBRL(declared);
    
    let sumSelected = 0;
    const selectedCheckboxes = document.querySelectorAll('.wizard-billing-checkbox:checked');
    
    if (summaryList) {
        if (selectedCheckboxes.length === 0) {
            summaryList.innerHTML = '<p class="text-slate-400 italic">Nenhuma cobrança selecionada. O valor total de ' + formatBRL(declared) + ' será lançado integralmente em seu saldo de coralista.</p>';
        } else {
            let html = '<ul class="space-y-1.5">';
            selectedCheckboxes.forEach(cb => {
                const amt = parseFloat(cb.getAttribute('data-amount')) || 0;
                const title = cb.getAttribute('data-title') || '';
                const owner = cb.getAttribute('data-owner') || '';
                sumSelected += amt;
                html += `
                    <li class="flex items-center justify-between p-2 rounded-lg bg-white dark:bg-slate-800/80 border border-slate-100 dark:border-slate-700/60">
                        <div>
                            <span class="font-semibold text-slate-800 dark:text-white">${escapeHtml(title)}</span>
                            <span class="text-[10px] text-slate-400 block">${escapeHtml(owner)}</span>
                        </div>
                        <span class="font-bold text-slate-900 dark:text-white">${formatBRL(amt)}</span>
                    </li>
                `;
            });
            html += '</ul>';
            summaryList.innerHTML = html;
        }
    }
    
    const leftover = Math.max(0, declared - sumSelected);
    if (summaryLeftoverContainer && summaryLeftoverAmount) {
        if (leftover > 0) {
            summaryLeftoverContainer.classList.remove('hidden');
            summaryLeftoverAmount.textContent = formatBRL(leftover);
        } else {
            summaryLeftoverContainer.classList.add('hidden');
        }
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
    return String(text).replace(/[&<>"']/g, m => map[m]);
}

// Inicialização automática
document.addEventListener('DOMContentLoaded', function() {
    recalculateWizardConsumption();
    
    // Tratativa de submit do formulário
    const form = document.getElementById('wizard-receipt-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const declared = getDeclaredAmount();
            let sumSelected = 0;
            document.querySelectorAll('.wizard-billing-checkbox:checked').forEach(cb => {
                sumSelected += parseFloat(cb.getAttribute('data-amount')) || 0;
            });
            
            if (declared <= 0) {
                e.preventDefault();
                alert('O valor do pagamento deve ser maior que zero.');
                goToStep(1);
                return;
            }
            
            if (sumSelected > declared + 0.0001) {
                e.preventDefault();
                alert('A soma das cobranças excede o valor informado do comprovante.');
                goToStep(3);
                return;
            }
            
            const btn = document.getElementById('btn-submit-final');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="inline-block animate-spin mr-1">🔄</span> Enviando...';
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
