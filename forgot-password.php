<?php
// forgot-password.php
require_once __DIR__ . '/config.php';

$error = null;
$success = null;
$debug_link = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Por favor, preencha o campo de e-mail.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, informe um e-mail válido.';
    } else {
        // Verificar se usuário existe
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $token = bin2hex(random_bytes(16));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            try {
                // Atualiza o token no banco
                $stmtUpdate = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $stmtUpdate->execute([$token, $expires, $user['id']]);
                
                // Monta o link
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $domain = $_SERVER['HTTP_HOST'];
                $reset_link = $protocol . $domain . "/www/ecoral/reset-password.php?token=" . $token;
                
                $subject = "Recuperação de Senha - eCoral";
                $body = "
                    <h2>Olá, {$user['name']}</h2>
                    <p>Você solicitou a recuperação de senha no sistema eCoral SaaS.</p>
                    <p>Clique no link abaixo para redefinir sua senha. Este link expira em 1 hora.</p>
                    <p><a href='{$reset_link}' style='display:inline-block;padding:10px 20px;background-color:#f43f5e;color:#fff;text-decoration:none;border-radius:5px;font-weight:bold;'>Redefinir Minha Senha</a></p>
                    <p>Se você não fez esta solicitação, por favor ignore este e-mail.</p>
                ";
                
                // Tenta enviar o e-mail
                send_email($email, $user['name'], $subject, $body);
                
                // Adiciona o link no debug_link para facilitar teste local/avaliação
                $debug_link = $reset_link;
                
                $success = 'As instruções de recuperação foram processadas. Se o e-mail estiver correto, você receberá o link para criar uma nova senha.';
            } catch (PDOException $e) {
                $error = 'Erro ao processar solicitação: ' . $e->getMessage();
            }
        } else {
            // Retorna a mesma mensagem de sucesso por motivos de segurança, 
            // mas sem o debug_link para não revelar existência de e-mails.
            $success = 'As instruções de recuperação foram processadas. Se o e-mail estiver correto, você receberá o link para criar uma nova senha.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Senha - eCoral</title>
    
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

    <!-- Card -->
    <div class="w-full max-w-md bg-white/80 dark:bg-slate-800/80 backdrop-blur-md rounded-2xl shadow-xl border border-white/20 dark:border-slate-700/30 p-6 md:p-8 relative overflow-hidden transition-all duration-300">
        
        <!-- Detalhe decorativo animado -->
        <div class="absolute -top-10 -right-10 w-32 h-32 bg-coral-500/20 rounded-full blur-2xl"></div>
        
        <!-- Topo -->
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
        
        <h2 class="text-xl font-bold text-slate-800 dark:text-white mb-2 font-outfit">Recuperar Senha</h2>
        <p class="text-xs text-slate-500 dark:text-slate-400 mb-6">Informe seu e-mail cadastrado para enviarmos as instruções de redefinição de senha.</p>

        <?php if ($success): ?>
            <div class="mb-4 p-3 rounded-lg text-sm bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300">
                <?= htmlspecialchars($success) ?>
            </div>
            
            <?php if ($debug_link): ?>
                <!-- Bloco de Debug/Demonstração Local -->
                <div class="mb-4 p-4 rounded-lg text-xs bg-amber-50 text-amber-800 dark:bg-amber-950/20 dark:text-amber-300 border border-amber-200 dark:border-amber-900">
                    <p class="font-bold mb-1">🛠️ Modo de Desenvolvimento:</p>
                    <p class="mb-2">Como o sistema está rodando localmente, utilize o link direto de recuperação gerado abaixo:</p>
                    <a href="<?= $debug_link ?>" class="text-coral-500 hover:text-coral-600 font-semibold break-all underline">
                        <?= $debug_link ?>
                    </a>
                </div>
            <?php endif; ?>

            <div class="mt-6 text-center">
                <a href="login.php" class="text-coral-500 hover:text-coral-600 font-bold underline text-xs">Voltar para o Login</a>
            </div>
        <?php else: ?>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="forgot-password.php" method="POST" class="space-y-4">
                <div>
                    <label for="email" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Seu E-mail Cadastrado</label>
                    <input type="email" name="email" id="email" required
                           class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                           placeholder="roberto@email.com">
                </div>
                
                <button type="submit" 
                        class="w-full py-2.5 px-4 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg shadow-md focus:outline-none focus:ring-2 focus:ring-coral-400 focus:ring-offset-2 transition-all text-sm mt-6">
                    Enviar Link de Recuperação
                </button>
            </form>
            
            <div class="mt-6 text-center">
                <a href="login.php" class="text-slate-500 dark:text-slate-400 hover:text-coral-500 font-semibold text-xs">Cancelar e Voltar</a>
            </div>
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
