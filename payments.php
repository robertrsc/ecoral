<?php
// payments.php
require_once __DIR__ . '/config.php';
require_login();

$user = get_logged_user();

// Endpoint AJAX para buscar cobranças de outro membro do mesmo coral
if (isset($_GET['action']) && $_GET['action'] === 'fetch_member_billings') {
    header('Content-Type: application/json');
    $code = trim($_GET['code'] ?? '');
    
    if (empty($code)) {
        echo json_encode(['success' => false, 'message' => 'Código não informado.']);
        exit;
    }
    
    try {
        // Encontrar membro ativo do mesmo coral
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE member_code = ? AND choir_id = ? AND role = 'membro' AND status = 'active' LIMIT 1");
        $stmt->execute([$code, $user['choir_id']]);
        $targetUser = $stmt->fetch();
        
        if (!$targetUser) {
            echo json_encode(['success' => false, 'message' => 'Membro não encontrado ou não pertence ao seu coral.']);
            exit;
        }
        
        if ($targetUser['id'] == $user['id']) {
            echo json_encode(['success' => false, 'message' => 'Você já pode selecionar suas próprias cobranças abaixo.']);
            exit;
        }
        
        // Buscar cobranças em aberto
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
        }
        unset($b);
        
        echo json_encode([
            'success' => true,
            'member_name' => $targetUser['name'],
            'billings' => $billings
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar dados: ' . $e->getMessage()]);
        exit;
    }
}
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
            $stmt = $pdo->prepare("SELECT mb.*, bi.amount, bi.title, bi.type as billing_type FROM member_billing mb 
                                    JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                    WHERE mb.id = ? AND mb.member_id = ? AND mb.status = 'open'");
            $stmt->execute([$member_billing_id, $user['id']]);
            $billing = $stmt->fetch();
            
            if ($billing) {
                if (($billing['billing_type'] ?? '') === 'recurring') {
                    $billing['title'] .= ' - ' . date('m/Y', strtotime($billing['due_date']));
                }
            }
            
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
    // Suporta: hidden da máscara (1234.56), campo display (1.234,56), ou campo numérico puro
    $amount = parse_currency_input($_POST['amount'] ?? $_POST['amount_display'] ?? '0');
    
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
            // Calcular soma dos itens selecionados para validação
            $sum_selected_items = 0.00;
            if (!empty($selected_billings)) {
                $placeholders = implode(',', array_fill(0, count($selected_billings), '?'));
                $stmtSum = $pdo->prepare("
                    SELECT SUM(bi.amount - mb.paid_amount) 
                    FROM member_billing mb 
                    JOIN billing_items bi ON mb.billing_item_id = bi.id 
                    JOIN users u ON mb.member_id = u.id 
                    WHERE mb.id IN ($placeholders) AND u.choir_id = ? AND mb.status = 'open'
                ");
                $params = array_map('intval', $selected_billings);
                $params[] = intval($user['choir_id']);
                $stmtSum->execute($params);
                $sum_selected_items = floatval($stmtSum->fetchColumn());
            }

            if ($amount < $sum_selected_items) {
                $error = 'O valor do comprovante (' . format_currency($amount) . ') não pode ser menor que a soma dos itens selecionados (' . format_currency($sum_selected_items) . ').';
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
                        $stmtStatus = $pdo->prepare("UPDATE member_billing mb 
                                                     JOIN users u ON mb.member_id = u.id 
                                                     SET mb.status = 'pending_approval' 
                                                     WHERE mb.id = ? AND u.choir_id = ? AND mb.status = 'open'");
                        
                        foreach ($selected_billings as $mb_id) {
                            $mb_id = intval($mb_id);
                            // Garante que é de um membro do mesmo coral e está aberta
                            $stmtStatus->execute([$mb_id, $user['choir_id']]);
                            if ($stmtStatus->rowCount() > 0) {
                                $stmtLink->execute([$receipt_id, $mb_id]);
                            }
                        }
                    }
                    
                    $pdo->commit();
                    
                    // Envio de e-mail informativo com pré-recibo
                    try {
                        // Buscar detalhes das cobranças associadas para o e-mail
                        $stmtFetchedItems = $pdo->prepare("
                            SELECT bi.title, (bi.amount - mb.paid_amount) as remaining_amount, u.name as member_name
                            FROM receipt_billing_items rbi
                            JOIN member_billing mb ON rbi.member_billing_id = mb.id
                            JOIN billing_items bi ON mb.billing_item_id = bi.id
                            JOIN users u ON mb.member_id = u.id
                            WHERE rbi.receipt_id = ?
                        ");
                        $stmtFetchedItems->execute([$receipt_id]);
                        $linkedItems = $stmtFetchedItems->fetchAll();
                        
                        $sum_items = 0.00;
                        foreach ($linkedItems as $item) {
                            $sum_items += floatval($item['remaining_amount']);
                        }
                        
                        // Buscar informações do coral para o e-mail
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

                        $leftover = $amount - $sum_items;
                        $receipt_number = sprintf("%06d", $receipt_id);
                        $subject = "Pré-Recibo de Comprovante de Pagamento #" . $receipt_number;

                        $items_html = "";
                        if (empty($linkedItems)) {
                            $items_html = "<li>Nenhum item de cobrança selecionado. O valor total será creditado em seu saldo.</li>";
                        } else {
                            foreach ($linkedItems as $item) {
                                $items_html .= "<li>" . htmlspecialchars($item['title']) . " (" . htmlspecialchars($item['member_name']) . "): <strong>" . format_currency($item['remaining_amount']) . "</strong></li>";
                            }
                        }

                        $leftover_message = "";
                        if ($leftover > 0) {
                            $leftover_message = "<p>Como o valor do comprovante (" . format_currency($amount) . ") é maior do que o somatório das cobranças selecionadas (" . format_currency($sum_items) . "), após aprovado o seu pagamento você terá um saldo positivo (troco ou resíduo) no valor de <strong>" . format_currency($leftover) . "</strong>.</p>";
                        }

                        $body = "
                            <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                                " . $emailLogoHtml . "
                                <h2 style='color: #f43f5e; margin-top: 0;'>Olá, " . htmlspecialchars($user['name']) . "!</h2>
                                <p>Recebemos o envio do seu comprovante de pagamento no valor de <strong>" . format_currency($amount) . "</strong>.</p>
                                <p style='background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px; color: #78350f; font-size: 13px;'>
                                    <strong>ATENÇÃO:</strong> Este e-mail constitui um <strong>Pré-Recibo Número #" . $receipt_number . "</strong>, servindo apenas para comprovar o envio das informações. Ele <strong>somente terá valor de recibo oficial</strong> após a análise e confirmação manual do pagamento por parte do administrador responsável.
                                </p>
                                
                                <h3 style='color: #1e293b; margin-top: 24px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;'>Itens selecionados para pagamento:</h3>
                                <ul style='padding-left: 20px; color: #475569;'>" . $items_html . "</ul>
                                " . $leftover_message . "
                                
                                <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($emailChoirName) . "</p>
                            </div>
                        ";

                        send_email($user['email'], $user['name'], $subject, $body);
                    } catch (Exception $mailEx) {
                        error_log("Failed to send pre-receipt email: " . $mailEx->getMessage());
                    }

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
            $stmtUpdateBilling = $pdo->prepare("UPDATE member_billing SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?");
            foreach ($linked_items as $li) {
                $stmtUpdateBilling->execute([$li['amount'], $li['member_billing_id']]);
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
            
            // Enviar e-mail de confirmação ao membro
            try {
                // Buscar dados do membro e do coral
                $stmtMember = $pdo->prepare("SELECT u.name, u.email, u.choir_id FROM users u WHERE u.id = ?");
                $stmtMember->execute([$receipt['member_id']]);
                $memberData = $stmtMember->fetch();
                
                if ($memberData) {
                    $stmtChoirApproval = $pdo->prepare("SELECT name, logo FROM choirs WHERE id = ?");
                    $stmtChoirApproval->execute([$memberData['choir_id']]);
                    $choirApproval = $stmtChoirApproval->fetch();
                    $choirNameApproval = $choirApproval ? $choirApproval['name'] : 'eCoral';
                    
                    $logoApprovalHtml = "";
                    if ($choirApproval && !empty($choirApproval['logo'])) {
                        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $logoUrl = $protocol . "://" . $host . "/www/ecoral/uploads/" . $choirApproval['logo'];
                        $logoApprovalHtml = "<div style='text-align: center; margin-bottom: 20px;'><img src='" . htmlspecialchars($logoUrl) . "' style='max-height: 80px; object-fit: contain; background-color: #ffffff; padding: 4px; border-radius: 8px;' alt='Logo " . htmlspecialchars($choirNameApproval) . "'></div>";
                    }
                    
                    $receipt_number_formatted = sprintf("%06d", $receipt_id);
                    $subjectApproval = "Pagamento Aprovado — Recibo #" . $receipt_number_formatted;
                    
                    $bodyApproval = "
                        <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                            " . $logoApprovalHtml . "
                            <h2 style='color: #10b981; margin-top: 0;'>Pagamento Confirmado! ✅</h2>
                            <p>Olá, <strong>" . htmlspecialchars($memberData['name']) . "</strong>!</p>
                            <p>Informamos que o seu pagamento referente ao <strong>Recibo #" . $receipt_number_formatted . "</strong> foi <strong>aprovado com sucesso</strong> pelo administrador do coral.</p>
                            <div style='background-color: #f0fdf4; border-left: 4px solid #10b981; padding: 16px; margin: 20px 0; border-radius: 4px;'>
                                <p style='margin: 0; color: #065f46; font-weight: bold;'>🎉 Seu comprovante foi verificado e o pagamento está regularizado.</p>
                            </div>
                            <p style='font-size: 13px; color: #64748b;'>Caso tenha alguma dúvida, entre em contato com a administração do coral.</p>
                            <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($choirNameApproval) . "</p>
                        </div>
                    ";
                    
                    send_email($memberData['email'], $memberData['name'], $subjectApproval, $bodyApproval);
                }
            } catch (Exception $mailApprovalEx) {
                error_log("Failed to send approval notification email: " . $mailApprovalEx->getMessage());
            }
            
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
    }
    unset($mob);
    
    // Membro: Histórico pessoal de comprovantes enviados
    $stmtMyReceipts = $pdo->prepare("SELECT r.*, 
                                     (SELECT GROUP_CONCAT(CONCAT(IF(bi.type = 'recurring', CONCAT(bi.title, ' - ', DATE_FORMAT(mb.due_date, '%m/%Y')), bi.title), ' (', u2.name, ')') SEPARATOR ', ') 
                                      FROM receipt_billing_items rbi 
                                      JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                      JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                      JOIN users u2 ON mb.member_id = u2.id
                                      WHERE rbi.receipt_id = r.id) as linked_items 
                                     FROM receipts r 
                                     WHERE r.member_id = ? 
                                     ORDER BY r.id DESC");
    $stmtMyReceipts->execute([$user['id']]);
    $receiptsList = $stmtMyReceipts->fetchAll();

    // Membro: Pagamentos realizados com sucesso (cobranças quitadas)
    $stmtMyPaid = $pdo->prepare("SELECT mb.*, bi.title, bi.amount as item_amount, bi.type as billing_type 
                                 FROM member_billing mb 
                                 JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                 WHERE mb.member_id = ? AND mb.status = 'paid' 
                                 ORDER BY mb.paid_at DESC, mb.due_date DESC");
    $stmtMyPaid->execute([$user['id']]);
    $myPaidBillings = $stmtMyPaid->fetchAll();
    foreach ($myPaidBillings as &$mpb) {
        if (($mpb['billing_type'] ?? '') === 'recurring') {
            $mpb['title'] .= ' - ' . date('m/Y', strtotime($mpb['due_date']));
        }
    }
    unset($mpb);
} else {
    // Admins e Colaborador: Visualizar comprovantes enviados
    try {
        if (is_superadmin()) {
            $stmtRecs = $pdo->query("SELECT r.*, u.name as member_name, u.email as member_email, c.name as choir_name,
                                     (SELECT GROUP_CONCAT(CONCAT(IF(bi.type = 'recurring', CONCAT(bi.title, ' - ', DATE_FORMAT(mb.due_date, '%m/%Y')), bi.title), ' (', u2.name, ')') SEPARATOR ', ') 
                                      FROM receipt_billing_items rbi 
                                      JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                      JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                      JOIN users u2 ON mb.member_id = u2.id
                                      WHERE rbi.receipt_id = r.id) as linked_items 
                                     FROM receipts r 
                                     JOIN users u ON r.member_id = u.id 
                                     LEFT JOIN choirs c ON u.choir_id = c.id 
                                     ORDER BY r.status DESC, r.id DESC");
        } else {
            $stmtRecs = $pdo->prepare("SELECT r.*, u.name as member_name, u.email as member_email, c.name as choir_name,
                                     (SELECT GROUP_CONCAT(CONCAT(IF(bi.type = 'recurring', CONCAT(bi.title, ' - ', DATE_FORMAT(mb.due_date, '%m/%Y')), bi.title), ' (', u2.name, ')') SEPARATOR ', ') 
                                      FROM receipt_billing_items rbi 
                                      JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                                      JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                      JOIN users u2 ON mb.member_id = u2.id
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
                    <input type="text" inputmode="numeric" name="amount" id="amount" required
                           data-currency-mask
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="0,00">
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
                                    <input type="checkbox" name="selected_billings[]" value="<?= $mob['id'] ?>" data-amount="<?= floatval($mob['item_amount'] - $mob['paid_amount']) ?>"
                                           class="w-4 h-4 rounded text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700 billing-checkbox">
                                    <span class="ml-2 font-medium">
                                        <?= htmlspecialchars($mob['title']) ?> (<?= format_currency($mob['item_amount'] - $mob['paid_amount']) ?>)
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-[9px] text-slate-400 mt-1">Obs: Caso o valor do comprovante supere os itens marcados, a diferença irá para seu saldo.</p>
                    <?php endif; ?>
                </div>

                <div class="pt-4 border-t border-slate-100 dark:border-slate-700/50">
                    <label for="other_member_code" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Pagar para outro cantor? Informe o código:</label>
                    <div class="flex gap-2">
                        <input type="text" id="other_member_code" placeholder="Ex: T1029"
                               class="flex-1 px-3 py-2 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white uppercase transition-all">
                        <button type="button" onclick="searchOtherMember()"
                                class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-semibold rounded-lg text-xs transition-all">
                            Buscar
                        </button>
                    </div>
                    <div id="other_member_result" class="mt-3 hidden p-3 rounded-lg border border-slate-200 dark:border-slate-700/50 bg-slate-50 dark:bg-slate-900/40 space-y-2">
                        <!-- Conteúdo dinâmico via JS -->
                    </div>
                </div>

                <div id="billing_sum_container" class="hidden p-3 rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 text-xs font-semibold text-slate-700 dark:text-slate-300 transition-all">
                    Soma dos itens selecionados: <span id="billing_sum_val" class="text-coral-500 font-bold">R$ 0,00</span>
                    <p class="text-[10px] font-normal text-red-500 dark:text-red-400 mt-1 hidden" id="amount_warning">
                        ⚠️ O valor declarado é menor do que a soma das cobranças selecionadas!
                    </p>
                </div>

                <button type="submit" 
                        class="w-full py-2.5 px-4 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-coral-400 focus:ring-offset-2 transition-all text-xs">
                    Enviar Comprovante
                </button>
            </form>
        </div>
    <?php endif; ?>

    <!-- ==============================================
         LISTA DE COMPROVANTES (ADMINS OU HISTÓRICO MEMBRO) E HISTÓRICO DE PAGAMENTOS REALIZADOS
         ============================================== -->
    <div class="<?= $user['role'] === 'membro' ? 'lg:col-span-2' : 'lg:col-span-3' ?> space-y-6">
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
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
                                        <button type="submit" name="action" value="approve_receipt" onclick="return confirm('Deseja aprovar este comprovante? As cobranças associadas serão baixadas e o troco ou resíduo inserido no saldo.')"
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

        <?php if ($user['role'] === 'membro'): ?>
            <!-- HISTÓRICO DE PAGAMENTOS REALIZADOS (MEMBRO APENAS) -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">
                    🎉 Pagamentos Realizados (Mensalidades/Taxas Quitadas)
                </h2>
                
                <?php if (empty($myPaidBillings)): ?>
                    <div class="text-center py-8 text-slate-400 text-sm">Nenhum pagamento realizado ainda.</div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="border-b border-slate-100 dark:border-slate-700 text-slate-400 font-semibold uppercase tracking-wider">
                                    <th class="py-3 px-2">Título da Cobrança</th>
                                    <th class="py-3 px-2 text-right">Valor Pago</th>
                                    <th class="py-3 px-2 text-center">Vencimento</th>
                                    <th class="py-3 px-2 text-right">Pago Em</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50 text-slate-700 dark:text-slate-300">
                                <?php foreach ($myPaidBillings as $mpb): ?>
                                    <tr class="hover:bg-slate-50/50 dark:hover:bg-slate-900/10 transition-colors">
                                        <td class="py-3 px-2 font-medium text-slate-900 dark:text-white">
                                            <?= htmlspecialchars($mpb['title']) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right font-bold text-emerald-500">
                                            <?= format_currency($mpb['paid_amount']) ?>
                                        </td>
                                        <td class="py-3 px-2 text-center text-slate-400">
                                            <?= format_date($mpb['due_date']) ?>
                                        </td>
                                        <td class="py-3 px-2 text-right text-slate-500 dark:text-slate-400">
                                            <?= $mpb['paid_at'] ? format_date($mpb['paid_at']) : '-' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function searchOtherMember() {
    const codeInput = document.getElementById('other_member_code');
    const resultDiv = document.getElementById('other_member_result');
    const code = codeInput.value.trim();
    
    if (!code) {
        alert('Por favor, informe o código do cantor.');
        return;
    }
    
    resultDiv.innerHTML = '<div class="text-xs text-slate-500 animate-pulse">Buscando...</div>';
    resultDiv.classList.remove('hidden');
    
    fetch('payments.php?action=fetch_member_billings&code=' + encodeURIComponent(code))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = `<p class="font-bold text-xs text-slate-800 dark:text-white flex items-center gap-1">
                                <span>👤</span> Cantor: ${escapeHtml(data.member_name)}
                            </p>`;
                
                if (data.billings.length === 0) {
                    html += `<p class="text-[10px] text-slate-400">Nenhuma cobrança em aberto para este cantor. O valor do comprovante será usado apenas para o seu saldo/suas cobranças.</p>`;
                } else {
                    html += `<p class="text-[10px] text-slate-500 font-semibold mb-1">Selecione as cobranças dele para incluir no comprovante:</p>
                             <div class="space-y-1.5 pt-1">`;
                    data.billings.forEach(b => {
                        html += `
                            <label class="flex items-center text-xs text-slate-700 dark:text-slate-300 cursor-pointer">
                                <input type="checkbox" name="selected_billings[]" value="${b.id}" data-amount="${b.remaining_amount}"
                                       class="w-4 h-4 rounded text-coral-500 border-slate-300 focus:ring-coral-500 dark:bg-slate-900 dark:border-slate-700 billing-checkbox">
                                <span class="ml-2 font-medium">
                                    ${escapeHtml(b.title)} (${b.formatted_amount})
                                </span>
                            </label>
                        `;
                    });
                    html += `</div>`;
                }
                resultDiv.innerHTML = html;
            } else {
                resultDiv.innerHTML = `<div class="text-xs text-red-500 font-semibold flex items-center gap-1">
                                            <span>⚠️</span> ${escapeHtml(data.message)}
                                       </div>`;
            }
        })
        .catch(err => {
            resultDiv.innerHTML = `<div class="text-xs text-red-500 font-semibold">Erro ao processar a busca de cobranças.</div>`;
            console.error(err);
        });
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}

function updateSelectedTotal() {
    const sumContainer = document.getElementById('billing_sum_container');
    const sumVal = document.getElementById('billing_sum_val');
    const warning = document.getElementById('amount_warning');
    const amountInput = document.getElementById('amount');
    
    if (!sumContainer || !sumVal || !warning || !amountInput) return;
    
    let total = 0;
    document.querySelectorAll('.billing-checkbox:checked').forEach(function(cb) {
        total += parseFloat(cb.getAttribute('data-amount')) || 0;
    });
    
    if (total > 0) {
        sumContainer.classList.remove('hidden');
        sumVal.innerText = 'R$ ' + total.toFixed(2).replace('.', ',');
        
        const declared = parseFloat(amountInput.value) || 0;
        if (declared < total) {
            warning.classList.remove('hidden');
        } else {
            warning.classList.add('hidden');
        }
    } else {
        sumContainer.classList.add('hidden');
        warning.classList.add('hidden');
    }
}

// Escutar mudanças nos inputs de checkbox (estáticos e dinâmicos)
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList.contains('billing-checkbox')) {
        updateSelectedTotal();
    }
});

// Escutar mudanças no input de valor
document.addEventListener('DOMContentLoaded', function() {
    const amountInput = document.getElementById('amount');
    if (amountInput) {
        amountInput.addEventListener('input', updateSelectedTotal);
    }
    
    // Escutar envio do formulário para validação final
    const form = document.querySelector('form[action="payments.php"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            const actionInput = form.querySelector('input[name="action"]');
            if (actionInput && actionInput.value === 'upload_receipt') {
                const amountVal = parseFloat(amountInput.value) || 0;
                let total = 0;
                document.querySelectorAll('.billing-checkbox:checked').forEach(function(cb) {
                    total += parseFloat(cb.getAttribute('data-amount')) || 0;
                });
                
                if (amountVal < total) {
                    e.preventDefault();
                    alert('Erro: O valor declarado (R$ ' + amountVal.toFixed(2).replace('.', ',') + ') não pode ser menor que o somatório dos itens selecionados (R$ ' + total.toFixed(2).replace('.', ',') + ').');
                }
            }
        });
    }
});
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
