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
            $redirect = ($_POST['redirect_to'] ?? '') === 'payments' ? 'payments.php?tab=financeiro' : 'dashboard.php';
            header("Location: " . $redirect);
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

// 5. Admin: Desfazer Baixa Manual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revert_manual_payment') {
    $receipt_id = intval($_POST['receipt_id'] ?? 0);
    
    if (!is_admin_user()) {
        $error = 'Operação permitida apenas para usuários administradores.';
    } elseif ($receipt_id <= 0) {
        $error = 'Parâmetros inválidos para estorno da baixa.';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Buscar dados do recibo, cobrança e membro garantindo acesso do coral (ou bypass superadmin)
            $stmtRec = $pdo->prepare("
                SELECT r.*, rbi.member_billing_id, mb.paid_amount as mb_paid_amount, bi.amount as mb_total_amount, 
                       mb.due_date as mb_due_date, bi.title as billing_title, bi.type as billing_type,
                       u.id as member_id, u.name as member_name, u.email as member_email, u.balance as member_balance, 
                       u.choir_id as member_choir_id
                FROM receipts r
                JOIN receipt_billing_items rbi ON rbi.receipt_id = r.id
                JOIN member_billing mb ON rbi.member_billing_id = mb.id
                JOIN billing_items bi ON mb.billing_item_id = bi.id
                JOIN users u ON r.member_id = u.id
                WHERE r.id = ? AND (u.choir_id = ? OR ?)
            ");
            $stmtRec->execute([$receipt_id, $user['choir_id'] ?? 0, is_superadmin() ? 1 : 0]);
            $recData = $stmtRec->fetch(PDO::FETCH_ASSOC);
            
            if (!$recData) {
                throw new Exception('Registro de baixa não encontrado ou acesso negado.');
            }
            
            $pay_amount = floatval($recData['amount']);
            $mb_id = intval($recData['member_billing_id']);
            $member_id = intval($recData['member_id']);
            $filename = $recData['filename'];
            
            // 1. Se foi baixa com saldo em conta, devolver valor ao saldo do cantor
            $new_balance = floatval($recData['member_balance']);
            if ($filename === 'balance_deduction') {
                $new_balance = floatval($recData['member_balance']) + $pay_amount;
                $stmtUpdateBal = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
                $stmtUpdateBal->execute([$new_balance, $member_id]);
            }
            
            // 2. Subtrair valor pago da cobrança do membro e retornar status para 'open'
            $new_paid_amount = max(0.00, floatval($recData['mb_paid_amount']) - $pay_amount);
            $stmtUpdateMB = $pdo->prepare("UPDATE member_billing SET paid_amount = ?, status = 'open', paid_at = NULL WHERE id = ?");
            $stmtUpdateMB->execute([$new_paid_amount, $mb_id]);
            
            // 3. Remover vínculo e o registro do comprovante
            $stmtDelLink = $pdo->prepare("DELETE FROM receipt_billing_items WHERE receipt_id = ?");
            $stmtDelLink->execute([$receipt_id]);
            
            $stmtDelRec = $pdo->prepare("DELETE FROM receipts WHERE id = ?");
            $stmtDelRec->execute([$receipt_id]);
            
            $pdo->commit();
            
            // 4. Enviar e-mail notificando o cantor sobre a reversão da baixa manual
            try {
                $stmtChoirInfo = $pdo->prepare("SELECT name, logo FROM choirs WHERE id = ?");
                $stmtChoirInfo->execute([$recData['member_choir_id']]);
                $choirObj = $stmtChoirInfo->fetch();
                $choirNameEmail = $choirObj ? $choirObj['name'] : 'eCoral';
                
                $logoEmailHtml = "";
                if ($choirObj && !empty($choirObj['logo'])) {
                    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $logoUrl = $protocol . "://" . $host . "/www/ecoral/uploads/" . $choirObj['logo'];
                    $logoEmailHtml = "<div style='text-align: center; margin-bottom: 20px;'><img src='" . htmlspecialchars($logoUrl) . "' style='max-height: 80px; object-fit: contain; background-color: #ffffff; padding: 4px; border-radius: 8px;' alt='Logo " . htmlspecialchars($choirNameEmail) . "'></div>";
                }
                
                $billingTitleFormatted = $recData['billing_title'];
                if ($recData['billing_type'] === 'recurring') {
                    $billingTitleFormatted .= ' - ' . date('m/Y', strtotime($recData['mb_due_date']));
                }
                
                $subject = "Notificação: Baixa Manual Desfeita - " . $billingTitleFormatted;
                $balanceNotice = ($filename === 'balance_deduction') 
                    ? "<p style='margin: 0 0 8px 0;'><strong>Valor Estornado para Seu Saldo:</strong> " . format_currency($pay_amount) . " (Novo Saldo: " . format_currency($new_balance) . ")</p>"
                    : "";
                    
                $body = "
                    <div style='font-family: sans-serif; color: #333; line-height: 1.6; max-width: 600px; margin: 0 auto; border: 1px solid #f1f5f9; padding: 24px; border-radius: 12px; background-color: #ffffff;'>
                        " . $logoEmailHtml . "
                        <h2 style='color: #f43f5e; margin-top: 0;'>Olá, " . htmlspecialchars($recData['member_name']) . "!</h2>
                        <p>Informamos que um lançamento de <strong>baixa manual efetuado anteriormente em sua cobrança foi desfeito/estornado</strong> pela administração do coral.</p>
                        
                        <div style='background-color: #fff1f2; border-left: 4px solid #f43f5e; padding: 16px; margin: 20px 0; border-radius: 4px;'>
                            <p style='margin: 0 0 8px 0;'><strong>Cobrança:</strong> " . htmlspecialchars($billingTitleFormatted) . "</p>
                            <p style='margin: 0 0 8px 0;'><strong>Valor da Baixa Estornada:</strong> " . format_currency($pay_amount) . "</p>
                            " . $balanceNotice . "
                            <p style='margin: 0;'><strong>Status Atual da Cobrança:</strong> Em Aberto (Total Pago: " . format_currency($new_paid_amount) . " de " . format_currency($recData['mb_total_amount']) . ")</p>
                        </div>
                        
                        <p style='margin-top: 32px; font-size: 12px; color: #94a3b8;'>Atenciosamente,<br>Equipe " . htmlspecialchars($choirNameEmail) . "</p>
                    </div>
                ";
                send_email($recData['member_email'], $recData['member_name'], $subject, $body);
            } catch (Exception $mailEx) {
                error_log("Failed to send revert manual payment email: " . $mailEx->getMessage());
            }
            
            set_flash_message('success', 'Baixa manual desfeita com sucesso! A cobrança retornou para em aberto e os valores foram estornados.');
            header("Location: payments.php");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

// ==============================================
// CARREGAR DADOS DE VISUALIZAÇÃO
// ==============================================

if ($user['role'] === 'membro') {
    // Membro: Cobranças em aberto ou aguardando aprovação
    $stmtMyBillings = $pdo->prepare("SELECT mb.*, bi.title, bi.amount as item_amount, bi.type as billing_type 
                                     FROM member_billing mb 
                                     JOIN billing_items bi ON mb.billing_item_id = bi.id 
                                     WHERE mb.member_id = ? AND mb.status IN ('open', 'pending_approval') 
                                     ORDER BY mb.due_date ASC");
    $stmtMyBillings->execute([$user['id']]);
    $myOpenBillings = $stmtMyBillings->fetchAll();
    $totalOpenAmount = 0.0;
    foreach ($myOpenBillings as &$mob) {
        if (($mob['billing_type'] ?? '') === 'recurring') {
            $mob['title'] .= ' - ' . date('m/Y', strtotime($mob['due_date']));
        }
        $mob['remaining_amount'] = floatval($mob['item_amount'] - $mob['paid_amount']);
        if ($mob['status'] === 'open') {
            $totalOpenAmount += $mob['remaining_amount'];
        }
    }
    unset($mob);
    $selectableBillings = array_values(array_filter($myOpenBillings, fn($b) => $b['status'] === 'open'));
    $memberBalance = floatval($user['balance'] ?? 0);
    
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
} else {
    // Admins, Financeiro e Colaborador: Visualizar comprovantes enviados com filtro
    $receiptStatusFilter = trim($_GET['receipt_status'] ?? 'all');
    if (!in_array($receiptStatusFilter, ['all', 'pending', 'approved', 'rejected'])) {
        $receiptStatusFilter = 'all';
    }

    try {
        $statusCondition = "";
        if ($receiptStatusFilter === 'pending') {
            $statusCondition = " AND r.status = 'pending'";
        } elseif ($receiptStatusFilter === 'approved') {
            $statusCondition = " AND r.status = 'approved'";
        } elseif ($receiptStatusFilter === 'rejected') {
            $statusCondition = " AND r.status = 'rejected'";
        }

        if (is_superadmin()) {
            $sql = "SELECT r.*, u.name as member_name, u.email as member_email, c.name as choir_name,
                           (SELECT GROUP_CONCAT(CONCAT(IF(bi.type = 'recurring', CONCAT(bi.title, ' - ', DATE_FORMAT(mb.due_date, '%m/%Y')), bi.title), ' (', u2.name, ')') SEPARATOR ', ') 
                            FROM receipt_billing_items rbi 
                            JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                            JOIN billing_items bi ON mb.billing_item_id = bi.id 
                            JOIN users u2 ON mb.member_id = u2.id
                            WHERE rbi.receipt_id = r.id) as linked_items 
                    FROM receipts r 
                    JOIN users u ON r.member_id = u.id 
                    LEFT JOIN choirs c ON u.choir_id = c.id 
                    WHERE 1=1 {$statusCondition}
                    ORDER BY r.status DESC, r.id DESC";
            $stmtRecs = $pdo->query($sql);
        } else {
            $sql = "SELECT r.*, u.name as member_name, u.email as member_email, c.name as choir_name,
                           (SELECT GROUP_CONCAT(CONCAT(IF(bi.type = 'recurring', CONCAT(bi.title, ' - ', DATE_FORMAT(mb.due_date, '%m/%Y')), bi.title), ' (', u2.name, ')') SEPARATOR ', ') 
                            FROM receipt_billing_items rbi 
                            JOIN member_billing mb ON rbi.member_billing_id = mb.id 
                            JOIN billing_items bi ON mb.billing_item_id = bi.id 
                            JOIN users u2 ON mb.member_id = u2.id
                            WHERE rbi.receipt_id = r.id) as linked_items 
                    FROM receipts r 
                    JOIN users u ON r.member_id = u.id 
                    LEFT JOIN choirs c ON u.choir_id = c.id 
                    WHERE u.choir_id = ? {$statusCondition}
                    ORDER BY r.status DESC, r.id DESC";
            $stmtRecs = $pdo->prepare($sql);
            $stmtRecs->execute([$user['choir_id']]);
        }
        $receiptsList = $stmtRecs->fetchAll();

        // Contagens por status para os filtros rápidos
        $countQuery = is_superadmin() 
            ? "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as total_approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as total_rejected
               FROM receipts"
            : "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN r.status = 'pending' THEN 1 ELSE 0 END) as total_pending,
                SUM(CASE WHEN r.status = 'approved' THEN 1 ELSE 0 END) as total_approved,
                SUM(CASE WHEN r.status = 'rejected' THEN 1 ELSE 0 END) as total_rejected
               FROM receipts r
               JOIN users u ON r.member_id = u.id
               WHERE u.choir_id = ?";
        $stmtCounts = $pdo->prepare($countQuery);
        if (is_superadmin()) {
            $stmtCounts->execute();
        } else {
            $stmtCounts->execute([$user['choir_id']]);
        }
        $receiptCounts = $stmtCounts->fetch() ?: ['total' => 0, 'total_pending' => 0, 'total_approved' => 0, 'total_rejected' => 0];
    } catch (PDOException $e) {
        $error = 'Erro ao carregar comprovantes: ' . $e->getMessage();
        $receiptsList = [];
    }
}

$activeTab = strtolower(trim($_GET['tab'] ?? $_GET['view'] ?? $_GET['from'] ?? ''));
$isFinanceiroTab = in_array($activeTab, ['financeiro', 'historico']);
// Se o usuário veio pelo botão Financeiro, inicia o card Enviar Comprovante collapsado
$collapseUploadCard = ($user['role'] === 'membro') && $isFinanceiroTab && empty($error);

require_once __DIR__ . '/layout_header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
        <span>🧾</span> Pagamentos & Comprovantes
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

<div class="space-y-6">

        <?php if ($user['role'] === 'membro'): ?>
            <!-- ==============================================
                 CARD: MINHAS COBRANÇAS EM ABERTO (MEMBRO)
                 ============================================== -->
            <div id="cobrancas-aberto" class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white">
                                Minhas Cobranças em Aberto
                            </h2>
                            <?php if (!empty($myOpenBillings)): ?>
                                <span class="px-2 py-0.5 text-xs font-bold rounded-full bg-rose-100 dark:bg-rose-950/40 text-rose-600 dark:text-rose-400">
                                    <?= count($myOpenBillings) ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                            <?php if ($totalOpenAmount > 0): ?>
                                Total a pagar: <strong class="text-rose-600 dark:text-rose-400"><?= format_currency($totalOpenAmount) ?></strong>
                            <?php else: ?>
                                Nenhum valor pendente de pagamento
                            <?php endif; ?>
                        </p>
                    </div>
                    
                    <div class="flex items-center gap-2 flex-wrap self-start sm:self-auto">
                        <a href="submit-receipt.php"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                            <span>📤</span> Enviar Comprovante
                        </a>
                        <?php if ($memberBalance > 0 && !empty($selectableBillings)): ?>
                            <button type="button" onclick="openBalanceModal()"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white text-xs font-semibold shadow-sm transition-colors cursor-pointer">
                                <span>💰</span> Pagar com saldo (<?= format_currency($memberBalance) ?>)
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($myOpenBillings)): ?>
                    <div class="text-center py-8 text-slate-400 text-sm">
                        <span class="text-2xl block mb-2">🎉</span>
                        Tudo em dia! Você não possui cobranças em aberto no momento.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto -mx-2 sm:mx-0">
                        <table class="w-full text-left text-xs border-collapse min-w-[500px]">
                            <thead>
                                <tr class="border-b border-slate-100 dark:border-slate-700 text-slate-400 font-semibold uppercase tracking-wider">
                                    <th class="py-3 px-3">Título da Cobrança</th>
                                    <th class="py-3 px-3 text-center">Vencimento</th>
                                    <th class="py-3 px-3 text-right">Valor Restante</th>
                                    <th class="py-3 px-3 text-center">Status</th>
                                    <th class="py-3 px-3 text-right">Ação</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-700/50 text-slate-700 dark:text-slate-300" id="billing-table-body">
                                <?php foreach ($myOpenBillings as $index => $mob): 
                                    $isOverdue = strtotime($mob['due_date']) < strtotime(date('Y-m-d'));
                                    $isPending = $mob['status'] === 'pending_approval';
                                    $remAmount = $mob['remaining_amount'];
                                ?>
                                    <tr class="billing-table-row hover:bg-slate-50/50 dark:hover:bg-slate-900/10 transition-colors" data-billing-index="<?= $index ?>">
                                        <td class="py-3 px-3 font-semibold text-slate-900 dark:text-white">
                                            <div><?= htmlspecialchars($mob['title']) ?></div>
                                            <?php if ($mob['paid_amount'] > 0): ?>
                                                <span class="block text-[10px] text-emerald-500 font-normal">
                                                    Parcialmente pago: <?= format_currency($mob['paid_amount']) ?> (Total: <?= format_currency($mob['item_amount']) ?>)
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 text-center whitespace-nowrap">
                                            <span class="block font-medium <?= ($isOverdue && !$isPending) ? 'text-rose-600 dark:text-rose-400 font-semibold' : 'text-slate-600 dark:text-slate-300' ?>">
                                                <?= format_date($mob['due_date']) ?>
                                            </span>
                                            <?php if ($isPending): ?>
                                                <span class="inline-block mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
                                                    Comprovante enviado
                                                </span>
                                            <?php elseif ($isOverdue): ?>
                                                <span class="inline-block mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">
                                                    Vencida
                                                </span>
                                            <?php else: ?>
                                                <span class="inline-block mt-0.5 px-1.5 py-0.5 rounded text-[10px] font-normal text-slate-400">
                                                    Em dia
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 text-right font-bold text-slate-900 dark:text-white whitespace-nowrap">
                                            <?= format_currency($remAmount) ?>
                                        </td>
                                        <td class="py-3 px-3 text-center whitespace-nowrap">
                                            <?php if ($isPending): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300">
                                                    ⏳ Em Análise
                                                </span>
                                            <?php elseif ($isOverdue): ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-rose-100 text-rose-800 dark:bg-rose-950/20 dark:text-rose-300">
                                                    ⚠️ Em Aberto
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-blue-100 text-blue-800 dark:bg-blue-950/20 dark:text-blue-300">
                                                    ℹ️ A Vencer
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="py-3 px-3 text-right whitespace-nowrap">
                                            <?php if ($isPending): ?>
                                                <span class="text-[11px] text-slate-400 italic">Aguardando</span>
                                            <?php else: ?>
                                                <div class="flex items-center justify-end gap-1.5">
                                                    <?php if ($memberBalance >= $remAmount): ?>
                                                        <button type="button" onclick="openBalanceModal(<?= $mob['id'] ?>)"
                                                                title="Pagar integralmente com saldo"
                                                                class="px-2.5 py-1 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold transition-colors cursor-pointer">
                                                            Saldo
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" onclick="selectBillingToPay(<?= $mob['id'] ?>, <?= $remAmount ?>)"
                                                            class="px-2.5 py-1 rounded-lg bg-coral-500 hover:bg-coral-600 text-white text-xs font-semibold shadow-sm transition-colors flex items-center gap-1 cursor-pointer">
                                                        <span>📎</span> Pagar
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- PAGINAÇÃO DE COBRANÇAS EM ABERTO -->
                    <div id="billing-pagination-bar" class="px-3 sm:px-4 py-3 mt-4 bg-slate-50 dark:bg-slate-900/50 rounded-xl border border-slate-100 dark:border-slate-700/40 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs">
                        <div class="flex items-center gap-3">
                            <span class="text-slate-500 dark:text-slate-400">
                                Mostrando <span id="billing-page-start" class="font-bold text-slate-700 dark:text-slate-300">1</span> a 
                                <span id="billing-page-end" class="font-bold text-slate-700 dark:text-slate-300">3</span> de 
                                <span id="billing-page-total" class="font-bold text-slate-700 dark:text-slate-300"><?= count($myOpenBillings) ?></span> cobranças
                            </span>
                            <div class="flex items-center gap-1">
                                <label for="billing-page-size" class="text-slate-400 text-[11px] hidden sm:inline">Exibir:</label>
                                <select id="billing-page-size" onchange="changeBillingPageSize(this.value)"
                                        class="px-2 py-1 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-700 dark:text-slate-300 text-xs focus:ring-1 focus:ring-coral-500 cursor-pointer">
                                    <option value="3" selected>3 p/ pág.</option>
                                    <option value="5">5 p/ pág.</option>
                                    <option value="10">10 p/ pág.</option>
                                    <option value="all">Todas</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex items-center gap-1.5 flex-wrap justify-center" id="billing-pagination-controls">
                            <!-- Botão Anterior -->
                            <button type="button" id="billing-page-prev" onclick="changeBillingPage(currentBillingPage - 1)"
                                    class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 font-bold transition-all text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                                Anterior
                            </button>
                            
                            <!-- Botões de páginas dinâmicos -->
                            <div id="billing-page-numbers" class="flex items-center gap-1"></div>
                            
                            <!-- Botão Próximo -->
                            <button type="button" id="billing-page-next" onclick="changeBillingPage(currentBillingPage + 1)"
                                    class="px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 font-bold transition-all text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer">
                                Próximo
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-6 pb-4 border-b border-slate-100 dark:border-slate-700/50">
                <div>
                    <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white">
                        <?= $user['role'] === 'membro' ? 'Meu Histórico de Comprovantes' : 'Todos os Comprovantes Recebidos' ?>
                    </h2>
                    <p class="text-xs text-slate-400">
                        <?= $user['role'] === 'membro' ? 'Acompanhe o status de validação dos seus envios.' : 'Filtre e gerencie os comprovantes anexados pelos coralistas.' ?>
                    </p>
                </div>

                <?php if ($user['role'] !== 'membro'): ?>
                    <!-- Filtros Rápidos por Status (Pendentes / Confirmados / Todos) -->
                    <div class="flex items-center gap-1.5 p-1 bg-slate-100 dark:bg-slate-900/60 rounded-xl self-start md:self-auto overflow-x-auto max-w-full">
                        <a href="payments.php?receipt_status=all"
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 shrink-0 <?= $receiptStatusFilter === 'all' ? 'bg-white dark:bg-slate-800 text-slate-900 dark:text-white shadow-xs' : 'text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white' ?>">
                            <span>Todos</span>
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $receiptStatusFilter === 'all' ? 'bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300' : 'bg-slate-200/60 dark:bg-slate-800 text-slate-500' ?>">
                                <?= intval($receiptCounts['total'] ?? 0) ?>
                            </span>
                        </a>

                        <a href="payments.php?receipt_status=pending"
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 shrink-0 <?= $receiptStatusFilter === 'pending' ? 'bg-amber-500 text-white shadow-xs' : 'text-amber-600 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/30' ?>">
                            <span>⏳ Pendentes</span>
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $receiptStatusFilter === 'pending' ? 'bg-white/20 text-white' : 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300' ?>">
                                <?= intval($receiptCounts['total_pending'] ?? 0) ?>
                            </span>
                        </a>

                        <a href="payments.php?receipt_status=approved"
                           class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-all flex items-center gap-1.5 shrink-0 <?= $receiptStatusFilter === 'approved' ? 'bg-emerald-600 text-white shadow-xs' : 'text-emerald-600 dark:text-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/30' ?>">
                            <span>✅ Confirmados</span>
                            <span class="px-1.5 py-0.2 rounded-full text-[10px] <?= $receiptStatusFilter === 'approved' ? 'bg-white/20 text-white' : 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300' ?>">
                                <?= intval($receiptCounts['total_approved'] ?? 0) ?>
                            </span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
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
                                <?php if (in_array($rl['filename'], ['balance_deduction', 'voucher_deduction', 'manual_record'])): ?>
                                    <span class="px-2.5 py-1 rounded-lg text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 border border-slate-200 dark:border-slate-700 flex items-center gap-1">
                                        <?= $rl['filename'] === 'balance_deduction' ? '💳 Baixa via Saldo' : ($rl['filename'] === 'voucher_deduction' ? '🎫 Voucher' : '📝 Baixa Manual') ?>
                                    </span>
                                    <?php if (is_admin_user()): ?>
                                        <form action="payments.php" method="POST" class="inline" onsubmit="return confirm('Deseja realmente desfazer esta baixa manual? O valor de <?= format_currency($rl['amount']) ?> será estornado.');">
                                            <input type="hidden" name="action" value="revert_manual_payment">
                                            <input type="hidden" name="receipt_id" value="<?= $rl['id'] ?>">
                                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/60 text-rose-600 dark:text-rose-300 text-xs font-semibold border border-rose-200 dark:border-rose-800 transition-colors flex items-center gap-1">
                                                <span>↩️</span> Desfazer Baixa
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <!-- Visualizar Arquivo -->
                                    <a href="uploads/<?= $rl['filename'] ?>" target="_blank"
                                       class="text-center px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 text-xs font-semibold text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                                        📄 Ver Comprovante
                                    </a>
                                <?php endif; ?>
                                
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

<?php if ($user['role'] === 'membro' && $memberBalance > 0 && !empty($selectableBillings)): ?>
    <!-- Modal para Pagar usando Saldo -->
    <div id="modal-pay-balance" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-slate-900/80 backdrop-blur-sm" onclick="closeBalanceModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full border border-slate-100 dark:border-slate-700/50 p-6">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white">Pagar com Saldo</h3>
                    <button type="button" onclick="closeBalanceModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-lg font-bold p-1 cursor-pointer">
                        &times;
                    </button>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">
                    Seu saldo atual é de <strong class="text-emerald-500 font-bold"><?= format_currency($memberBalance) ?></strong>. 
                    Escolha uma cobrança em aberto para quitar integralmente usando seu saldo.
                </p>
                
                <form action="payments.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="pay_with_balance">
                    <input type="hidden" name="redirect_to" value="payments">
                    <div>
                        <label for="balance_member_billing_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione a Cobrança</label>
                        <select name="member_billing_id" id="balance_member_billing_id" required
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                            <option value="">Selecione...</option>
                            <?php foreach ($selectableBillings as $mb): 
                                $rem = $mb['remaining_amount'];
                                $canPay = ($memberBalance >= $rem);
                            ?>
                                <option value="<?= $mb['id'] ?>" data-amount="<?= $rem ?>" <?= !$canPay ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($mb['title']) ?> (Resta: <?= format_currency($rem) ?>)<?= !$canPay ? ' - Saldo insuficiente' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex justify-end gap-2 pt-4">
                        <button type="button" onclick="closeBalanceModal()"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white transition-colors cursor-pointer">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white transition-colors font-medium cursor-pointer">
                            Confirmar Pagamento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
function toggleUploadCard() {
    const body = document.getElementById('upload-card-body');
    const chevron = document.getElementById('upload-card-chevron');
    const badge = document.getElementById('upload-card-badge');
    const subtitle = document.getElementById('upload-card-subtitle');
    const btn = document.getElementById('toggle-upload-card-btn');
    if (!body) return;

    const isHidden = body.classList.contains('hidden');
    if (isHidden) {
        body.classList.remove('hidden');
        if (chevron) {
            chevron.classList.remove('-rotate-90');
            chevron.classList.add('rotate-0');
        }
        if (badge) badge.classList.add('hidden');
        if (subtitle) subtitle.textContent = 'Anexe seu comprovante de pagamento';
        if (btn) btn.setAttribute('aria-expanded', 'true');
    } else {
        body.classList.add('hidden');
        if (chevron) {
            chevron.classList.remove('rotate-0');
            chevron.classList.add('-rotate-90');
        }
        if (badge) badge.classList.remove('hidden');
        if (subtitle) subtitle.textContent = 'Toque para expandir e enviar';
        if (btn) btn.setAttribute('aria-expanded', 'false');
    }
}

// ==============================================
// PAGINAÇÃO DO CARD MINHAS COBRANÇAS EM ABERTO
// ==============================================
let currentBillingPage = 1;
let billingPageSize = 3;

function initBillingPagination() {
    const tableBody = document.getElementById('billing-table-body');
    if (!tableBody) return;
    
    // Ler preferência salva em localStorage se houver
    const savedSize = localStorage.getItem('ecoral_billing_page_size');
    const sizeSelect = document.getElementById('billing-page-size');
    if (savedSize && sizeSelect) {
        sizeSelect.value = savedSize;
        billingPageSize = savedSize === 'all' ? 999999 : parseInt(savedSize, 10);
    }
    
    updateBillingPagination();
}

function changeBillingPageSize(newVal) {
    localStorage.setItem('ecoral_billing_page_size', newVal);
    billingPageSize = newVal === 'all' ? 999999 : parseInt(newVal, 10);
    currentBillingPage = 1;
    updateBillingPagination();
}

function changeBillingPage(page) {
    const rows = document.querySelectorAll('.billing-table-row');
    const totalRows = rows.length;
    const totalPages = Math.max(1, Math.ceil(totalRows / billingPageSize));
    
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    
    currentBillingPage = page;
    updateBillingPagination();
}

function updateBillingPagination() {
    const rows = document.querySelectorAll('.billing-table-row');
    const totalRows = rows.length;
    if (totalRows === 0) return;
    
    const totalPages = Math.max(1, Math.ceil(totalRows / billingPageSize));
    if (currentBillingPage > totalPages) currentBillingPage = totalPages;
    
    const startIdx = (currentBillingPage - 1) * billingPageSize;
    const endIdx = startIdx + billingPageSize;
    
    rows.forEach((row, idx) => {
        if (idx >= startIdx && idx < endIdx) {
            row.classList.remove('hidden');
        } else {
            row.classList.add('hidden');
        }
    });
    
    // Atualizar textos informativos
    const startEl = document.getElementById('billing-page-start');
    const endEl = document.getElementById('billing-page-end');
    const totalEl = document.getElementById('billing-page-total');
    if (startEl) startEl.textContent = totalRows === 0 ? 0 : startIdx + 1;
    if (endEl) endEl.textContent = Math.min(endIdx, totalRows);
    if (totalEl) totalEl.textContent = totalRows;
    
    // Atualizar botões Anterior e Próximo
    const prevBtn = document.getElementById('billing-page-prev');
    const nextBtn = document.getElementById('billing-page-next');
    if (prevBtn) prevBtn.disabled = (currentBillingPage <= 1);
    if (nextBtn) nextBtn.disabled = (currentBillingPage >= totalPages);
    
    // Renderizar botões numéricos
    const numbersContainer = document.getElementById('billing-page-numbers');
    if (numbersContainer) {
        numbersContainer.innerHTML = '';
        if (totalPages <= 1) {
            const btn = document.createElement('span');
            btn.className = 'px-3 py-1.5 rounded-lg bg-coral-500 text-white font-bold transition-all text-xs';
            btn.textContent = '1';
            numbersContainer.appendChild(btn);
        } else {
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentBillingPage - 1 && i <= currentBillingPage + 1)) {
                    if (i === currentBillingPage) {
                        const activeBtn = document.createElement('span');
                        activeBtn.className = 'px-3 py-1.5 rounded-lg bg-coral-500 text-white font-bold transition-all text-xs';
                        activeBtn.textContent = i;
                        numbersContainer.appendChild(activeBtn);
                    } else {
                        const pageBtn = document.createElement('button');
                        pageBtn.type = 'button';
                        pageBtn.className = 'px-3 py-1.5 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-600 dark:text-slate-300 font-bold transition-all text-xs cursor-pointer';
                        pageBtn.textContent = i;
                        pageBtn.onclick = () => changeBillingPage(i);
                        numbersContainer.appendChild(pageBtn);
                    }
                } else if (i === currentBillingPage - 2 || i === currentBillingPage + 2) {
                    const dots = document.createElement('span');
                    dots.className = 'px-1 text-slate-400 font-bold';
                    dots.textContent = '...';
                    numbersContainer.appendChild(dots);
                }
            }
        }
    }
}

function openBalanceModal(billingId = null) {
    const modal = document.getElementById('modal-pay-balance');
    if (!modal) return;
    if (billingId) {
        const select = document.getElementById('balance_member_billing_id');
        if (select) {
            select.value = billingId;
        }
    }
    modal.classList.remove('hidden');
}

function closeBalanceModal() {
    const modal = document.getElementById('modal-pay-balance');
    if (modal) modal.classList.add('hidden');
}

function selectBillingToPay(billingId, amount) {
    // Redireciona diretamente para o novo Wizard de Envio de Comprovante com o item e valor pré-selecionados
    window.location.href = 'submit-receipt.php?billing_id=' + encodeURIComponent(billingId) + '&amount=' + encodeURIComponent(amount);
}

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
        
        let declared = 0;
        const hiddenAmount = document.querySelector('input[type="hidden"][name="amount"]');
        if (hiddenAmount && hiddenAmount.value) {
            declared = parseFloat(hiddenAmount.value) || 0;
        } else if (amountInput.value) {
            declared = parseFloat(amountInput.value.replace(/\./g, '').replace(',', '.')) || 0;
        }
        
        if (declared > 0 && declared < total) {
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
    initBillingPagination();
    
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
                let amountVal = 0;
                const hiddenAmount = form.querySelector('input[type="hidden"][name="amount"]');
                if (hiddenAmount && hiddenAmount.value) {
                    amountVal = parseFloat(hiddenAmount.value) || 0;
                } else if (amountInput && amountInput.value) {
                    amountVal = parseFloat(amountInput.value.replace(/\./g, '').replace(',', '.')) || 0;
                }
                
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
