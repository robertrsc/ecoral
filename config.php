<?php
// config.php
// Arquivo de inicialização, conexão de banco de dados, sessões e funções globais

// Configurações do Banco de Dados
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
} else {
    // Fallback padrão local
    define('DB_HOST', 'localhost');
    define('DB_USER', 'servidor');
    define('DB_PASS', 'Nv32125');
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
