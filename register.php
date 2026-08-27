<?php
// register.php
require_once __DIR__ . '/config.php';

// Se já estiver logado, vai pro dashboard
if (is_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

$error = null;
$success = null;

// Carregar código do coral pré-selecionado
$choir_code = trim($_GET['code'] ?? '');
$registerChoirLogo = null;
$registerChoirName = null;
$preselected_choir_id = 0;

if (!empty($choir_code)) {
    try {
        $stmtChoir = $pdo->prepare("SELECT id, name, logo FROM choirs WHERE code = ? LIMIT 1");
        $stmtChoir->execute([$choir_code]);
        $choirObj = $stmtChoir->fetch();
        if ($choirObj) {
            $preselected_choir_id = intval($choirObj['id']);
            $registerChoirName = $choirObj['name'];
            if (!empty($choirObj['logo'])) {
                $registerChoirLogo = $choirObj['logo'];
            }
        }
    } catch (Exception $e) {
        // Ignorar
    }
}

// Carregar corais cadastrados
try {
    $stmt = $pdo->query("SELECT id, name FROM choirs ORDER BY name ASC");
    $choirs = $stmt->fetchAll();
} catch (PDOException $e) {
    $choirs = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $choir_id = $preselected_choir_id > 0 ? $preselected_choir_id : intval($_POST['choir_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $voice_type = trim($_POST['voice_type'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($choir_id) || empty($name) || empty($email) || empty($username) || empty($password)) {
        $error = 'Por favor, preencha todos os campos obrigatórios (*).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, informe um e-mail válido.';
    } else {
        // Verificar se usuário ou e-mail já existem
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmt->execute([$email, $username]);
        if ($stmt->fetch()) {
            $error = 'O e-mail ou nome de usuário já está cadastrado no sistema.';
        } else {
            // Cadastrar membro
            try {
                $member_code = get_or_generate_member_code($pdo, $voice_type, $choir_id);
                $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
                $stmtInsert = $pdo->prepare("INSERT INTO users (choir_id, name, email, phone, voice_type, member_code, username, password, role, status) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'membro', 'pending')");
                $stmtInsert->execute([$choir_id, $name, $email, $phone, $voice_type, $member_code, $username, $hashedPassword]);
                
                $success = 'Seu cadastro foi realizado com sucesso! Aguarde a aprovação do administrador do seu coral para acessar o sistema.';
                // Limpar campos
                $_POST = [];
            } catch (PDOException $e) {
                $error = 'Erro ao realizar cadastro: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastre-se - eCoral</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        outfit: ['Outfit', 'sans-serif'],
                    },
                    colors: {
                        coral: {
                            50: '#fff1f2',
                            100: '#ffe4e6',
                            500: '#f43f5e',
                            600: '#e11d48',
                        },
                        darkbg: {
                            900: '#070a13',
                            800: '#0f172a',
                        }
                    }
                }
            }
        }
    </script>
    
    <script>
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
</head>
<body class="bg-gradient-to-tr from-slate-100 to-slate-200 dark:from-darkbg-900 dark:to-slate-900 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col justify-center items-center p-4">

    <!-- Card de Cadastro -->
    <div class="w-full max-w-lg bg-white/80 dark:bg-slate-800/80 backdrop-blur-md rounded-2xl shadow-xl border border-white/20 dark:border-slate-700/30 p-6 md:p-8 relative overflow-hidden transition-all duration-300">
        
        <!-- Detalhe decorativo animado -->
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-coral-500/20 rounded-full blur-2xl"></div>
        
        <div class="flex justify-between items-center mb-6">
            <?php if ($registerChoirLogo): ?>
                <div class="flex flex-col items-start leading-none select-none">
                    <img src="uploads/<?= htmlspecialchars($registerChoirLogo) ?>" class="h-10 object-contain rounded bg-white p-0.5 border border-slate-200/50" alt="Logo Coral">
                    <span class="text-[9px] font-bold text-slate-400 dark:text-slate-500 mt-0.5 tracking-wider font-outfit uppercase">eCoral</span>
                    <?php if ($registerChoirName): ?>
                        <span class="text-[10px] text-slate-500 font-semibold mt-1 block truncate max-w-[200px]" title="<?= htmlspecialchars($registerChoirName) ?>">
                            <?= htmlspecialchars($registerChoirName) ?>
                        </span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <span class="text-2xl font-black font-outfit text-coral-500 tracking-tight flex items-center gap-1.5">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                    </svg>
                    eCoral
                </span>
            <?php endif; ?>
            <button onclick="toggleTheme()" type="button" class="rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                <svg class="h-5 w-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v2.25m0 13.5V21M4.22 4.22l1.77 1.77m11.96 11.96l1.77 1.77M3 12h2.25m13.5 0H21M5.97 18.03l1.77-1.77m11.96-11.96l1.77-1.77M12 7.5a4.5 4.5 0 100 9 4.5 4.5 0 000-9z" />
                </svg>
                <svg class="h-5 w-5 block dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
            </button>
        </div>
        
        <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2 font-outfit">Cadastro de Cantor</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-6">Preencha seus dados para solicitar acesso ao seu coral.</p>

        <?php if ($success): ?>
            <div class="mb-6 p-4 rounded-lg text-sm bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300 border-l-4 border-green-500">
                <?= htmlspecialchars($success) ?>
                <div class="mt-3">
                    <a href="login.php<?= !empty($choir_code) ? '?' . htmlspecialchars($choir_code) : '' ?>" class="text-coral-500 hover:text-coral-600 font-bold underline">Voltar para o Login</a>
                </div>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if (empty($choirs)): ?>
                <div class="mb-4 p-4 text-center rounded-lg text-sm bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300">
                    Não há corais cadastrados no momento. Por favor, solicite ao Super Administrador para cadastrar seu coral primeiro.
                </div>
                <div class="mt-4 text-center">
                    <a href="login.php<?= !empty($choir_code) ? '?' . htmlspecialchars($choir_code) : '' ?>" class="text-coral-500 hover:text-coral-600 font-bold underline text-xs">Voltar para o Login</a>
                </div>
            <?php else: ?>

                <form action="register.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" method="POST" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <?php if ($preselected_choir_id > 0): ?>
                                <label class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Seu Coral</label>
                                <div class="w-full px-3.5 py-2 text-sm bg-slate-100 dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg text-slate-500 font-semibold select-none">
                                    <?= htmlspecialchars($registerChoirName) ?>
                                </div>
                            <?php else: ?>
                                <label for="choir_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione seu Coral *</label>
                                <select name="choir_id" id="choir_id" required
                                        class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                    <option value="">Selecione...</option>
                                    <?php foreach ($choirs as $choir): ?>
                                        <option value="<?= $choir['id'] ?>" <?= isset($_POST['choir_id']) && $_POST['choir_id'] == $choir['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($choir['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <label for="voice_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Naipe / Tipo de Voz</label>
                            <select name="voice_type" id="voice_type"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="">Não sei / Indefinido</option>
                                <option value="Soprano" <?= isset($_POST['voice_type']) && $_POST['voice_type'] === 'Soprano' ? 'selected' : '' ?>>Soprano</option>
                                <option value="Contralto" <?= isset($_POST['voice_type']) && $_POST['voice_type'] === 'Contralto' ? 'selected' : '' ?>>Contralto</option>
                                <option value="Tenor" <?= isset($_POST['voice_type']) && $_POST['voice_type'] === 'Tenor' ? 'selected' : '' ?>>Tenor</option>
                                <option value="Baixo" <?= isset($_POST['voice_type']) && $_POST['voice_type'] === 'Baixo' ? 'selected' : '' ?>>Baixo</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome Completo *</label>
                        <input type="text" name="name" id="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                               placeholder="Ex: Roberto Silva">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="email" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">E-mail *</label>
                            <input type="email" name="email" id="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                                   placeholder="roberto@email.com">
                        </div>

                        <div>
                            <label for="phone" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Telefone / WhatsApp</label>
                            <input type="text" name="phone" id="phone" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                                   placeholder="(11) 99999-9999">
                        </div>
                    </div>

                    <hr class="border-slate-200 dark:border-slate-700 my-4">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="username" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome de Usuário *</label>
                            <input type="text" name="username" id="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                                   placeholder="robertosilva">
                        </div>

                        <div>
                            <label for="password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Senha de Acesso *</label>
                            <input type="password" name="password" id="password" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                                   placeholder="Senha segura">
                        </div>
                    </div>
                    
                    <button type="submit" 
                            class="w-full py-2.5 px-4 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-coral-400 focus:ring-offset-2 transition-all text-sm mt-6">
                        Solicitar Cadastro
                    </button>
                </form>

                <div class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
                    Já tem uma conta? 
                    <a href="login.php<?= !empty($choir_code) ? '?' . htmlspecialchars($choir_code) : '' ?>" class="text-coral-500 hover:text-coral-600 font-semibold underline">Fazer Login</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        }
    </script>
</body>
</html>
