<?php
// login.php
require_once __DIR__ . '/config.php';

// Tratar Logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    session_start(); // inicia nova sessão para a flash message
    set_flash_message('success', 'Você saiu da sua conta com sucesso.');
    header("Location: login.php");
    exit;
}

// Se já estiver logado, vai pro dashboard
if (is_logged_in()) {
    header("Location: dashboard.php");
    exit;
}

$error = null;
$username_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_input = trim($_POST['login_input'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login_input) || empty($password)) {
        $error = 'Por favor, preencha todos os campos.';
    } else {
        // Tenta achar por email ou username
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            if ($user['status'] !== 'active') {
                $error = 'Sua conta está pendente de aprovação ou inativa. Entre em contato com o administrador.';
            } else {
                $_SESSION['user_id'] = $user['id'];
                set_flash_message('success', 'Bem-vindo de volta, ' . $user['name'] . '!');
                header("Location: dashboard.php");
                exit;
            }
        } else {
            $error = 'Usuário ou senha incorretos.';
        }
    }
    $username_val = htmlspecialchars($login_input);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar - eCoral</title>
    
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
                            700: '#be123c',
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

    <!-- Card de Login -->
    <div class="w-full max-w-md bg-white/80 dark:bg-slate-800/80 backdrop-blur-md rounded-2xl shadow-xl border border-white/20 dark:border-slate-700/30 p-6 md:p-8 relative overflow-hidden transition-all duration-300">
        
        <!-- Detalhe decorativo animado -->
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-coral-500/20 rounded-full blur-2xl"></div>
        
        <!-- Toggle Tema no topo do Card -->
        <div class="flex justify-between items-center mb-6">
            <span class="text-2xl font-black font-outfit text-coral-500 tracking-tight flex items-center gap-1.5">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                </svg>
                eCoral
            </span>
            <button onclick="toggleTheme()" type="button" class="rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                <svg class="h-5 w-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v2.25m0 13.5V21M4.22 4.22l1.77 1.77m11.96 11.96l1.77 1.77M3 12h2.25m13.5 0H21M5.97 18.03l1.77-1.77m11.96-11.96l1.77-1.77M12 7.5a4.5 4.5 0 100 9 4.5 4.5 0 000-9z" />
                </svg>
                <svg class="h-5 w-5 block dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
            </button>
        </div>
        
        <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2 font-outfit">Seja bem-vindo</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-6">Acesse seu painel para gerenciar suas atividades do coral.</p>

        <!-- Flash messages -->
        <?php $flash = get_flash_message(); if ($flash): ?>
            <div class="mb-4 p-3 rounded-lg text-sm <?= $flash['type'] === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300' ?>">
                <?= htmlspecialchars($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="login.php" method="POST" class="space-y-4">
            <div>
                <label for="login_input" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Usuário ou E-mail</label>
                <input type="text" name="login_input" id="login_input" value="<?= $username_val ?>" required
                       class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                       placeholder="Ex: sadmin ou email@exemplo.com">
            </div>
            
            <div>
                <div class="flex justify-between items-center mb-1">
                    <label for="password" class="block text-xs font-semibold text-slate-600 dark:text-slate-300">Senha</label>
                    <a href="forgot-password.php" class="text-xs text-coral-500 hover:text-coral-600 font-medium">Esqueceu a senha?</a>
                </div>
                <input type="password" name="password" id="password" required
                       class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                       placeholder="Sua senha">
            </div>
            
            <button type="submit" 
                    class="w-full py-2.5 px-4 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-coral-400 focus:ring-offset-2 transition-all text-sm mt-6">
                Entrar no Sistema
            </button>
        </form>
        
        <div class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
            É cantor de algum coral? 
            <a href="register.php" class="text-coral-500 hover:text-coral-600 font-semibold underline">Cadastre-se</a>
        </div>
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
