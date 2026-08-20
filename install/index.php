<?php
// install/index.php
// Instalador simples para configurar o banco de dados do eCoral

$configPath = __DIR__ . '/../config.local.php';
$installed = false;
$error = null;
$success = null;
$dbResults = [];

// Dados padrão do formulário
$db_host = $_POST['db_host'] ?? 'localhost';
$db_user = $_POST['db_user'] ?? '';
$db_pass = $_POST['db_pass'] ?? '';
$db_name = $_POST['db_name'] ?? 'ecoral';

$admin_name = $_POST['admin_name'] ?? 'Super Admin';
$admin_email = $_POST['admin_email'] ?? 'sadmin@ecoral.com';
$admin_user = $_POST['admin_user'] ?? 'sadmin';
$admin_pass = $_POST['admin_pass'] ?? 'coral123';

// Verifica se já está instalado
if (file_exists($configPath) && !isset($_POST['install'])) {
    $installed = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['install'])) {
    // 1. Tentar conectar ao servidor MySQL para criar o banco de dados se necessário
    try {
        // Conexão temporária sem banco especificado para poder criá-lo
        $pdo = new PDO("mysql:host=$db_host", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // Criação do banco
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . $db_name . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        
        // Reconectar apontando para o banco criado/existente
        $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        
        // 2. Gravar o config.local.php
        $configContent = "<?php\n"
                       . "// config.local.php\n"
                       . "// Gerado automaticamente pelo Instalador do eCoral\n\n"
                       . "define('DB_HOST', " . var_export($db_host, true) . ");\n"
                       . "define('DB_USER', " . var_export($db_user, true) . ");\n"
                       . "define('DB_PASS', " . var_export($db_pass, true) . ");\n"
                       . "define('DB_NAME', " . var_export($db_name, true) . ");\n";
                       
        if (file_put_contents($configPath, $configContent) === false) {
            throw new Exception("Não foi possível gravar o arquivo config.local.php. Verifique as permissões de escrita do diretório raiz.");
        }
        
        // 3. Incluir db_schema.php e sincronizar o banco
        $schemaPath = __DIR__ . '/../db_schema.php';
        if (!file_exists($schemaPath)) {
            throw new Exception("Arquivo db_schema.php não encontrado no diretório do projeto.");
        }
        
        require_once $schemaPath;
        $dbResults = sync_database($pdo);
        
        // 4. Atualizar o superadmin caso os dados inseridos sejam diferentes do padrão
        $hashedPassword = password_hash($admin_pass, PASSWORD_BCRYPT);
        
        // Limpar possíveis superadmins padrão para garantir que apenas as credenciais digitadas funcionem
        $stmtDelete = $pdo->prepare("DELETE FROM users WHERE role = 'superadmin'");
        $stmtDelete->execute();
        
        // Inserir o novo superadmin
        $stmtInsert = $pdo->prepare("INSERT INTO users (name, email, username, password, role, status) 
                                    VALUES (?, ?, ?, ?, 'superadmin', 'active')");
        $stmtInsert->execute([$admin_name, $admin_email, $admin_user, $hashedPassword]);
        
        $success = true;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador eCoral</title>
    
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --bg-color: #070a13;
            --card-bg: rgba(15, 23, 42, 0.65);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --coral-main: #f43f5e;
            --coral-hover: #e11d48;
            --coral-glow: rgba(244, 63, 94, 0.2);
            --success-color: #10b981;
            --error-color: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            min-h-screen: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-x: hidden;
            position: relative;
        }

        /* Orbes Decorativos Luminosos */
        body::before, body::after {
            content: '';
            position: absolute;
            width: 300px;
            height: 300px;
            border-radius: 50%;
            filter: blur(120px);
            z-index: -1;
            opacity: 0.35;
        }
        body::before {
            top: 10%;
            left: 15%;
            background-color: var(--coral-main);
        }
        body::after {
            bottom: 10%;
            right: 15%;
            background-color: #6366f1;
        }

        .container {
            width: 100%;
            max-width: 580px;
            z-index: 10;
        }

        .logo-wrapper {
            text-align: center;
            margin-bottom: 25px;
        }

        .logo {
            font-family: 'Outfit', sans-serif;
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--coral-main);
            letter-spacing: -1px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .logo span {
            color: var(--text-main);
        }

        .logo svg {
            width: 32px;
            height: 32px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2.5;
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 35px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            animation: fadeIn 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-align: center;
        }

        .subtitle {
            color: var(--text-muted);
            font-size: 0.9rem;
            text-align: center;
            margin-bottom: 30px;
            line-height: 1.4;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            font-size: 0.875rem;
            line-height: 1.5;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .alert-error {
            background-color: rgba(239, 68, 68, 0.1);
            border-left: 4px solid var(--error-color);
            color: #fca5a5;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border-left: 4px solid var(--success-color);
            color: #a7f3d0;
        }

        .alert-warning {
            background-color: rgba(245, 158, 11, 0.1);
            border-left: 4px solid #f59e0b;
            color: #fde047;
        }

        .alert-title {
            font-weight: 700;
            font-family: 'Outfit', sans-serif;
        }

        .section-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            margin: 20px 0 12px;
            color: var(--coral-main);
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            padding-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 480px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }

        label {
            display: block;
            font-size: 0.775rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        input {
            width: 100%;
            background-color: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 12px 16px;
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }

        input:focus {
            outline: none;
            background-color: rgba(255, 255, 255, 0.05);
            border-color: var(--coral-main);
            box-shadow: 0 0 10px var(--coral-glow);
        }

        .btn {
            display: block;
            width: 100%;
            background-color: var(--coral-main);
            color: white;
            border: none;
            border-radius: 14px;
            padding: 14px 20px;
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 14px rgba(244, 63, 94, 0.3);
            text-align: center;
            text-decoration: none;
            margin-top: 15px;
        }

        .btn:hover {
            background-color: var(--coral-hover);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(244, 63, 94, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn-secondary {
            background-color: rgba(255, 255, 255, 0.05);
            color: var(--text-main);
            border: 1px solid var(--border-color);
            box-shadow: none;
        }

        .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.2);
            box-shadow: none;
        }

        .sync-result-list {
            margin-top: 20px;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 10px;
            background-color: rgba(0, 0, 0, 0.2);
        }

        .sync-result-item {
            font-size: 0.8rem;
            padding: 8px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .sync-result-item:last-child {
            border-bottom: none;
        }

        .status-badge {
            font-weight: 700;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .status-badge.success {
            background-color: rgba(16, 185, 129, 0.2);
            color: var(--success-color);
        }

        .status-badge.error {
            background-color: rgba(239, 68, 68, 0.2);
            color: var(--error-color);
        }
    </style>
</head>
<body>

<div class="container">
    <div class="logo-wrapper">
        <h1 class="logo">
            <svg viewBox="0 0 24 24">
                <path d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
            </svg>
            e<span>Coral</span>
        </h1>
    </div>

    <div class="card">
        <?php if ($installed): ?>
            <h2>eCoral já Configurado</h2>
            <p class="subtitle">O arquivo de configurações <code>config.local.php</code> já foi encontrado no servidor.</p>
            
            <div class="alert alert-warning">
                <span class="alert-title">⚠️ Atenção:</span>
                O sistema já se encontra instalado e ativo. Para reinstalar ou refazer as configurações do banco de dados, você deve excluir o arquivo <code>config.local.php</code> no diretório raiz do seu servidor de forma manual.
            </div>

            <a href="../login.php" class="btn">Ir para o Login</a>

        <?php elseif ($success): ?>
            <h2>Instalação Concluída! 🚀</h2>
            <p class="subtitle">O eCoral foi instalado com sucesso e o banco de dados está pronto para uso.</p>

            <div class="alert alert-success">
                <span class="alert-title">✓ Sucesso:</span>
                O arquivo de configuração <code>config.local.php</code> foi criado e as tabelas estruturais foram sincronizadas com êxito.
            </div>

            <h3 class="section-title">Log do Banco de Dados</h3>
            <div class="sync-result-list">
                <?php foreach ($dbResults as $component => $res): ?>
                    <div class="sync-result-item">
                        <span><?= htmlspecialchars($component) ?></span>
                        <span class="status-badge <?= $res['success'] ? 'success' : 'error' ?>">
                            <?= $res['success'] ? 'ok' : 'falha' ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-warning" style="margin-top: 20px;">
                <span class="alert-title">🔒 Recomendação de Segurança:</span>
                Para garantir a segurança do sistema contra tentativas de reinstalação, <strong>exclua a pasta <code>/install</code></strong> do seu servidor agora mesmo.
            </div>

            <a href="../login.php" class="btn">Fazer Login no Sistema</a>

        <?php else: ?>
            <h2>Instalador eCoral</h2>
            <p class="subtitle">Configure o banco de dados e crie o usuário Super Administrador do sistema de forma rápida e segura.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <span class="alert-title">❌ Erro na Instalação:</span>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="" method="POST">
                <h3 class="section-title">Conexão MySQL</h3>
                
                <div class="form-group">
                    <label for="db_host">Host do Banco de Dados</label>
                    <input type="text" id="db_host" name="db_host" value="<?= htmlspecialchars($db_host) ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="db_name">Nome do Banco de Dados</label>
                    <input type="text" id="db_name" name="db_name" value="<?= htmlspecialchars($db_name) ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="db_user">Usuário do Banco</label>
                        <input type="text" id="db_user" name="db_user" value="<?= htmlspecialchars($db_user) ?>" placeholder="Ex: root" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_pass">Senha do Banco</label>
                        <input type="password" id="db_pass" name="db_pass" value="<?= htmlspecialchars($db_pass) ?>" placeholder="Sua senha">
                    </div>
                </div>

                <h3 class="section-title">Super Administrador</h3>
                
                <div class="form-group">
                    <label for="admin_name">Nome Completo</label>
                    <input type="text" id="admin_name" name="admin_name" value="<?= htmlspecialchars($admin_name) ?>" required>
                </div>

                <div class="form-group">
                    <label for="admin_email">E-mail</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?= htmlspecialchars($admin_email) ?>" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="admin_user">Usuário de Acesso</label>
                        <input type="text" id="admin_user" name="admin_user" value="<?= htmlspecialchars($admin_user) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="admin_pass">Senha de Acesso</label>
                        <input type="password" id="admin_pass" name="admin_pass" value="<?= htmlspecialchars($admin_pass) ?>" required>
                    </div>
                </div>

                <button type="submit" name="install" class="btn">🚀 Salvar e Instalar</button>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
