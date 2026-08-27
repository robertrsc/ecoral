<?php
// config.php
// Arquivo de inicialização, conexão de banco de dados, sessões e funções globais

// Configurações do Banco de Dados
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Fallback padrão genérico (nunca coloque senhas reais aqui)
    define('DB_HOST', 'localhost');
    define('DB_USER', 'usuario_desenvolvimento');
    define('DB_PASS', 'senha_desenvolvimento');
    define('DB_NAME', 'ecoral');
}

// Iniciar Sessão se não estiver iniciada
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Conectar ao Banco de Dados e criar se não existir
try {
    // Tenta conectar ao servidor MySQL sem banco especificado primeiro
    $pdo = new PDO("mysql:host=" . DB_HOST, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
    
    // Cria o banco se não existir
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
    
    // Conecta especificando o banco de dados
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Falha na conexão com o banco de dados: " . $e->getMessage());
}

// --- Funções Utilitárias de Sessão e Auth ---

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function get_logged_user() {
    global $pdo;
    if (!is_logged_in()) {
        return null;
    }
    
    // Usar cache de sessão simples para reduzir queries, ou consultar do banco
    static $currentUser = null;
    if ($currentUser === null) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $currentUser = $stmt->fetch();
        
        // Se o usuário foi removido do banco, destrói a sessão
        if (!$currentUser) {
            session_destroy();
            header("Location: login.php");
            exit;
        }
    }
    return $currentUser;
}

function require_login() {
    if (!is_logged_in()) {
        set_flash_message('warning', 'Você precisa fazer login para acessar esta página.');
        header("Location: login.php");
        exit;
    }
}

// Verifica se o usuário logado tem um dos papéis especificados
function require_role($roles = []) {
    require_login();
    $user = get_logged_user();
    
    if (is_string($roles)) {
        $roles = [$roles];
    }
    
    if (!in_array($user['role'], $roles)) {
        set_flash_message('error', 'Você não tem permissão para acessar esta página.');
        header("Location: dashboard.php");
        exit;
    }
}

// Retorna se o usuário é um dos administradores: superadmin, administrador, financeiro
function is_admin_user($user = null) {
    if ($user === null) {
        $user = get_logged_user();
    }
    if (!$user) return false;
    return in_array($user['role'], ['superadmin', 'administrador', 'financeiro']);
}

// Retorna se o usuário é superadmin
function is_superadmin($user = null) {
    if ($user === null) {
        $user = get_logged_user();
    }
    if (!$user) return false;
    return $user['role'] === 'superadmin';
}

// Retorna se há uma personificação ativa na sessão
function is_impersonating() {
    return isset($_SESSION['original_user_id']);
}

// Retorna os dados do administrador original
function get_original_user() {
    global $pdo;
    if (!is_impersonating()) {
        return null;
    }
    static $originalUser = null;
    if ($originalUser === null) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['original_user_id']]);
        $originalUser = $stmt->fetch();
    }
    return $originalUser;
}

// --- Funções de Notificação (Flash Messages) ---

function set_flash_message($type, $message) {
    $_SESSION['flash'] = [
        'type' => $type, // 'success', 'error', 'info', 'warning'
        'message' => $message
    ];
}

function get_flash_message() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// --- Funções Auxiliares Gerais ---

function format_currency($value) {
    return 'R$ ' . number_format($value, 2, ',', '.');
}

function format_date($date) {
    return date('d/m/Y', strtotime($date));
}

// Função de envio de e-mail mock/real
function send_email($toEmail, $toName, $subject, $body) {
    global $pdo;
    
    // Carregar configurações SMTP do banco
    try {
        $stmt = $pdo->query("SELECT * FROM config_smtp LIMIT 1");
        $smtp = $stmt->fetch();
    } catch (PDOException $e) {
        $smtp = null;
    }
    
    // Se o PHPMailer estiver instalado e tivermos SMTP configurado com credenciais reais
    if ($smtp && $smtp['host'] !== 'smtp.example.com') {
        require_once __DIR__ . '/vendor/autoload.php'; // Ou check normal
        
        // Vamos tentar enviar com PHPMailer se instalado
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host = $smtp['host'];
                $mail->SMTPAuth = !empty($smtp['username']);
                $mail->Username = $smtp['username'];
                $mail->Password = $smtp['password'];
                $mail->SMTPSecure = $smtp['encryption'];
                $mail->Port = $smtp['port'];
                $mail->CharSet = 'UTF-8';
                
                $mail->setFrom($smtp['from_email'], $smtp['from_name']);
                $mail->addAddress($toEmail, $toName);
                
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = $body;
                
                return $mail->send();
            } catch (Exception $e) {
                // Registrar erro em log ou sessão
                error_log("PHPMailer error: " . $mail->ErrorInfo);
            }
        }
    }
    
    // Fallback: Salva na sessão para simular envio em modo dev
    if (!isset($_SESSION['sent_emails'])) {
        $_SESSION['sent_emails'] = [];
    }
    
    $_SESSION['sent_emails'][] = [
        'to_email' => $toEmail,
        'to_name' => $toName,
        'subject' => $subject,
        'body' => $body,
        'time' => date('Y-m-d H:i:s')
    ];
    
    return true; // Retorna true para simular sucesso
}

// Gera ou atualiza um código identificador único para membros do coral
// Naipe (primeira letra) + ID do Coral + 4 dígitos aleatórios
function get_or_generate_member_code(PDO $pdo, $voice_type, $choir_id, $exclude_user_id = 0) {
    $first_letter = (isset($voice_type) && strlen(trim($voice_type)) > 0) ? strtoupper(substr(trim($voice_type), 0, 1)) : 'M';
    $choir_id = intval($choir_id);
    
    $attempts = 0;
    while ($attempts < 100) {
        $random_numbers = sprintf("%04d", rand(0, 9999));
        $code = $first_letter . $choir_id . $random_numbers;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE member_code = ? AND id != ?");
        $stmt->execute([$code, $exclude_user_id]);
        if ($stmt->fetchColumn() == 0) {
            return $code;
        }
        $attempts++;
    }
    // Fallback caso ocorra colisão improvável de tentativas
    return $first_letter . $choir_id . strval(rand(1000, 9999));
}

/**
 * Sincroniza e gera as cobranças recorrentes para os períodos que já foram alcançados.
 */
function sync_recurring_billings(PDO $pdo, $choir_id = null, $member_id = null) {
    // Sincroniza também as cobranças eventuais em aberto
    sync_eventual_billings($pdo, $choir_id, $member_id);

    try {
        // Busca todos os modelos de cobrança recorrente ativos
        $sql = "SELECT * FROM billing_items WHERE type = 'recurring' AND start_date <= CURRENT_DATE";
        $params = [];
        if ($choir_id !== null) {
            $sql .= " AND choir_id = ?";
            $params[] = $choir_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $templates = $stmt->fetchAll();
        
        if (empty($templates)) {
            return;
        }
        
        $today = date('Y-m-d');
        
        foreach ($templates as $t) {
            if (empty($t['start_date']) || empty($t['end_date'])) {
                continue;
            }
            
            $start = new DateTime($t['start_date']);
            $end = new DateTime($t['end_date']);
            
            $interval = new DateInterval('P1M');
            $end_mod = (clone $end)->modify('+1 day');
            $period = new DatePeriod($start, $interval, $end_mod);
            
            // Determina membros alvo
            $target_ids = [];
            if ($t['target_type'] === 'all') {
                $sqlMembers = "SELECT id FROM users WHERE choir_id = ? AND role = 'membro' AND status = 'active'";
                $paramsMembers = [$t['choir_id']];
                if ($member_id !== null) {
                    $sqlMembers .= " AND id = ?";
                    $paramsMembers[] = $member_id;
                }
                $stmtM = $pdo->prepare($sqlMembers);
                $stmtM->execute($paramsMembers);
                $target_ids = $stmtM->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $selected = json_decode($t['target_members'] ?? '[]', true);
                if (is_array($selected)) {
                    $selected = array_map('intval', $selected);
                    if ($member_id !== null) {
                        if (in_array(intval($member_id), $selected)) {
                            $stmtM = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
                            $stmtM->execute([$member_id]);
                            $m_id = $stmtM->fetchColumn();
                            if ($m_id) {
                                $target_ids[] = intval($m_id);
                            }
                        }
                    } else {
                        if (!empty($selected)) {
                            $in_clause = implode(',', array_fill(0, count($selected), '?'));
                            $stmtM = $pdo->prepare("SELECT id FROM users WHERE id IN ($in_clause) AND status = 'active'");
                            $stmtM->execute($selected);
                            $target_ids = $stmtM->fetchAll(PDO::FETCH_COLUMN);
                        }
                    }
                }
            }
            
            if (empty($target_ids)) {
                continue;
            }
            
            // Carrega em cache as cobranças já geradas para este template
            $stmtExisting = $pdo->prepare("SELECT member_id, due_date FROM member_billing WHERE billing_item_id = ?");
            $stmtExisting->execute([$t['id']]);
            $existing = [];
            while ($row = $stmtExisting->fetch()) {
                $existing[$row['member_id']][$row['due_date']] = true;
            }
            
            // Verifica os períodos alcançados
            foreach ($period as $dt) {
                $period_due_date = $dt->format('Y-m-d');
                if ($period_due_date > $today) {
                    break;
                }
                if ($period_due_date > $t['end_date']) {
                    break;
                }
                
                // Insere se não existir
                foreach ($target_ids as $m_id) {
                    if (!isset($existing[$m_id][$period_due_date])) {
                        $stmtInsert = $pdo->prepare("INSERT INTO member_billing (member_id, billing_item_id, status, due_date) VALUES (?, ?, 'open', ?)");
                        $stmtInsert->execute([$m_id, $t['id'], $period_due_date]);
                        $existing[$m_id][$period_due_date] = true;
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Ignora erros de colunas inexistentes caso o banco ainda não esteja sincronizado
    }
}

/**
 * Sincroniza e gera cobranças eventuais para membros que ainda não as receberam.
 */
function sync_eventual_billings(PDO $pdo, $choir_id = null, $member_id = null) {
    try {
        // Busca todos os itens de cobrança eventual com vencimento futuro ou hoje
        $sql = "SELECT * FROM billing_items WHERE type = 'eventual' AND due_date >= CURRENT_DATE";
        $params = [];
        if ($choir_id !== null) {
            $sql .= " AND choir_id = ?";
            $params[] = $choir_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $eventuals = $stmt->fetchAll();
        
        if (empty($eventuals)) {
            return;
        }
        
        foreach ($eventuals as $ev) {
            // Determina membros alvo
            $target_ids = [];
            if ($ev['target_type'] === 'all') {
                $sqlMembers = "SELECT id FROM users WHERE choir_id = ? AND role = 'membro' AND status = 'active'";
                $paramsMembers = [$ev['choir_id']];
                if ($member_id !== null) {
                    $sqlMembers .= " AND id = ?";
                    $paramsMembers[] = $member_id;
                }
                $stmtM = $pdo->prepare($sqlMembers);
                $stmtM->execute($paramsMembers);
                $target_ids = $stmtM->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $selected = json_decode($ev['target_members'] ?? '[]', true);
                if (is_array($selected)) {
                    $selected = array_map('intval', $selected);
                    if ($member_id !== null) {
                        if (in_array(intval($member_id), $selected)) {
                            $stmtM = $pdo->prepare("SELECT id FROM users WHERE id = ? AND status = 'active'");
                            $stmtM->execute([$member_id]);
                            $m_id = $stmtM->fetchColumn();
                            if ($m_id) {
                                $target_ids[] = intval($m_id);
                            }
                        }
                    } else {
                        if (!empty($selected)) {
                            $in_clause = implode(',', array_fill(0, count($selected), '?'));
                            $stmtM = $pdo->prepare("SELECT id FROM users WHERE id IN ($in_clause) AND status = 'active'");
                            $stmtM->execute($selected);
                            $target_ids = $stmtM->fetchAll(PDO::FETCH_COLUMN);
                        }
                    }
                }
            }
            
            if (empty($target_ids)) {
                continue;
            }
            
            // Carrega em cache as cobranças já geradas para este item eventual
            $stmtExisting = $pdo->prepare("SELECT member_id FROM member_billing WHERE billing_item_id = ?");
            $stmtExisting->execute([$ev['id']]);
            $existing = $stmtExisting->fetchAll(PDO::FETCH_COLUMN);
            $existing = array_map('intval', $existing);
            
            // Insere se não existir
            foreach ($target_ids as $m_id) {
                if (!in_array($m_id, $existing)) {
                    $stmtInsert = $pdo->prepare("INSERT INTO member_billing (member_id, billing_item_id, status, due_date) VALUES (?, ?, 'open', ?)");
                    $stmtInsert->execute([$m_id, $ev['id'], $ev['due_date']]);
                }
            }
        }
    } catch (PDOException $e) {
        // Ignora erros de colunas inexistentes caso o banco ainda não esteja sincronizado
    }
}

/**
 * Reseta o cadastro de um coral (exclui todos os cantores, equipe, comprovantes, faturamento e arquivos).
 * Mantém somente o usuário administrador fornecido (ou todos se null).
 */
function reset_choir_registry(PDO $pdo, $choir_id, $admin_id_to_keep = null) {
    $choir_id = intval($choir_id);
    if ($choir_id <= 0) {
        return false;
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Buscar todos os usuários do coral para identificar quem será excluído
        $stmtUsers = $pdo->prepare("SELECT id, role FROM users WHERE choir_id = ?");
        $stmtUsers->execute([$choir_id]);
        $users = $stmtUsers->fetchAll();
        
        $user_ids_to_delete = [];
        $admin_ids_to_keep = [];
        
        if ($admin_id_to_keep !== null) {
            $admin_ids_to_keep[] = intval($admin_id_to_keep);
        } else {
            // Se nenhum admin específico for informado para manter, mantemos todos os administradores do coral
            foreach ($users as $u) {
                if ($u['role'] === 'administrador') {
                    $admin_ids_to_keep[] = intval($u['id']);
                }
            }
        }
        
        foreach ($users as $u) {
            $uid = intval($u['id']);
            if (!in_array($uid, $admin_ids_to_keep)) {
                $user_ids_to_delete[] = $uid;
            }
        }
        
        // 2. Excluir os arquivos físicos de comprovantes do disco
        if (!empty($user_ids_to_delete)) {
            $in_clause = implode(',', array_fill(0, count($user_ids_to_delete), '?'));
            $stmtReceipts = $pdo->prepare("SELECT filename FROM receipts WHERE member_id IN ($in_clause)");
            $stmtReceipts->execute($user_ids_to_delete);
            $receiptFiles = $stmtReceipts->fetchAll(PDO::FETCH_COLUMN);
            
            $uploadDir = __DIR__ . '/uploads';
            foreach ($receiptFiles as $filename) {
                if (!empty($filename)) {
                    $filePath = $uploadDir . '/' . $filename;
                    if (file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
        }
        
        // 3. Excluir itens de cobrança (faturamento) do coral
        // Isso irá propagar o DELETE em cascata para member_billing e receipt_billing_items via banco
        $stmtDeleteBI = $pdo->prepare("DELETE FROM billing_items WHERE choir_id = ?");
        $stmtDeleteBI->execute([$choir_id]);
        
        if (!empty($user_ids_to_delete)) {
            $in_clause = implode(',', array_fill(0, count($user_ids_to_delete), '?'));
            
            // 4. Excluir comprovantes associados
            $stmtDeleteRecs = $pdo->prepare("DELETE FROM receipts WHERE member_id IN ($in_clause)");
            $stmtDeleteRecs->execute($user_ids_to_delete);
            
            // 5. Excluir os usuários
            $stmtDeleteUsers = $pdo->prepare("DELETE FROM users WHERE id IN ($in_clause)");
            $stmtDeleteUsers->execute($user_ids_to_delete);
        }
        
        // 6. Resetar o saldo para os administradores remanescentes
        $stmtResetBal = $pdo->prepare("UPDATE users SET balance = 0.00 WHERE choir_id = ? AND role = 'administrador'");
        $stmtResetBal->execute([$choir_id]);
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}


// Sincronizar cobranças recorrentes se houver usuário logado
if (is_logged_in()) {
    $loggedUser = get_logged_user();
    if ($loggedUser) {
        if ($loggedUser['role'] === 'superadmin') {
            sync_recurring_billings($pdo, $_SESSION['admin_choir_id'] ?? null);
        } else {
            sync_recurring_billings($pdo, $loggedUser['choir_id']);
        }
    }
}

