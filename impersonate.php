<?php
// impersonate.php
require_once __DIR__ . '/config.php';
require_login();

$user = get_logged_user();
$originalUser = get_original_user();

// O usuário atual deve ser superadmin OU o usuário que iniciou a personificação deve ser superadmin
if (!is_superadmin($user) && !($originalUser && is_superadmin($originalUser))) {
    set_flash_message('error', 'Você não tem permissão para acessar esta funcionalidade.');
    header("Location: dashboard.php");
    exit;
}

$action = $_GET['action'] ?? '';

if ($action === 'start') {
    $target_id = intval($_GET['id'] ?? 0);
    if ($target_id <= 0) {
        set_flash_message('error', 'Usuário alvo inválido.');
        header("Location: dashboard.php");
        exit;
    }
    
    // Buscar usuário alvo
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$target_id]);
    $targetUser = $stmt->fetch();
    
    if (!$targetUser) {
        set_flash_message('error', 'Usuário não encontrado.');
        header("Location: dashboard.php");
        exit;
    }
    
    // Se o usuário original já está definido (por estar trocando de um personificado para outro), mantemos o original original
    if (!isset($_SESSION['original_user_id'])) {
        $_SESSION['original_user_id'] = $_SESSION['user_id'];
    }
    
    // Define o ID ativo como o ID do usuário alvo
    $_SESSION['user_id'] = $target_id;
    
    set_flash_message('success', "Você agora está visualizando o sistema como: " . htmlspecialchars($targetUser['name']) . " (" . htmlspecialchars($targetUser['role']) . ").");
    header("Location: dashboard.php");
    exit;

} elseif ($action === 'exit') {
    if (isset($_SESSION['original_user_id'])) {
        $original_id = $_SESSION['original_user_id'];
        
        // Restaura o usuário original
        $_SESSION['user_id'] = $original_id;
        unset($_SESSION['original_user_id']);
        
        set_flash_message('success', 'Você retornou ao seu perfil de administrador.');
        header("Location: users.php");
        exit;
    } else {
        set_flash_message('error', 'Nenhuma personificação ativa.');
        header("Location: dashboard.php");
        exit;
    }
} else {
    header("Location: dashboard.php");
    exit;
}
