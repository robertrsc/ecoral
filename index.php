<?php
// index.php
require_once __DIR__ . '/config.php';

// Se já estiver logado, redireciona direto para o painel
if (is_logged_in()) {
    header("Location: dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eCoral - Gestão Premium de Corais</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    
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
                            200: '#fecdd3',
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
<body class="bg-slate-50 dark:bg-darkbg-900 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col transition-colors">

    <!-- Top Navigation Bar -->
    <header class="w-full max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
        <a href="index.php" class="flex items-center gap-2">
            <span class="text-2xl font-black font-outfit text-coral-500 tracking-tight flex items-center gap-1.5">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                </svg>
                eCoral
            </span>
        </a>
        
        <div class="flex items-center gap-4">
            <!-- Toggle Tema -->
            <button onclick="toggleTheme()" type="button" class="rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200">
                <svg class="h-5 w-5 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 3v2.25m0 13.5V21M4.22 4.22l1.77 1.77m11.96 11.96l1.77 1.77M3 12h2.25m13.5 0H21M5.97 18.03l1.77-1.77m11.96-11.96l1.77-1.77M12 7.5a4.5 4.5 0 100 9 4.5 4.5 0 000-9z" />
                </svg>
                <svg class="h-5 w-5 block dark:hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                </svg>
            </button>
            
            <a href="login.php" class="px-4 py-2 rounded-xl bg-coral-500 hover:bg-coral-600 text-white font-bold text-xs shadow-md transition-all">
                Entrar no Painel
            </a>
        </div>
    </header>

    <!-- Hero Section -->
    <main class="flex-1 flex flex-col justify-center max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-24 text-center">
        <h1 class="text-4xl sm:text-6xl font-black font-outfit tracking-tight text-slate-900 dark:text-white leading-none">
            A gestão perfeita para seu <br>
            <span class="text-coral-500 bg-clip-text">coral musical</span>
        </h1>
        
        <p class="mt-6 text-sm sm:text-lg text-slate-500 dark:text-slate-400 max-w-2xl mx-auto leading-relaxed">
            Controle mensalidades, gerencie cantores e seus naipes de voz, registre comprovantes de pagamentos e tenha relatórios detalhados na palma da mão. Simples, moderno e mobile-first.
        </p>

        <div class="mt-10 flex flex-col sm:flex-row justify-center items-center gap-4">
            <a href="login.php" class="w-full sm:w-auto px-8 py-4 bg-coral-500 hover:bg-coral-600 text-white font-bold rounded-2xl shadow-lg transition-all text-sm">
                Entrar como Admin / Cantor
            </a>
            <a href="register.php" class="w-full sm:w-auto px-8 py-4 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-700 dark:text-white font-bold rounded-2xl transition-all text-sm">
                Cadastrar-se em um Coral
            </a>
        </div>

        <!-- Recursos Destaques -->
        <div class="mt-20 grid grid-cols-1 sm:grid-cols-3 gap-6 text-left">
            <div class="bg-white dark:bg-slate-800/50 p-6 rounded-3xl border border-slate-100 dark:border-slate-800 shadow-sm">
                <span class="text-2xl">🎤</span>
                <h3 class="text-sm font-bold font-outfit text-slate-800 dark:text-white mt-3 mb-1">Cantores & Naipes</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Organização rápida por voz (Soprano, Contralto, Tenor, Baixo) e auto-cadastro prático.</p>
            </div>
            <div class="bg-white dark:bg-slate-800/50 p-6 rounded-3xl border border-slate-100 dark:border-slate-800 shadow-sm">
                <span class="text-2xl">💳</span>
                <h3 class="text-sm font-bold font-outfit text-slate-800 dark:text-white mt-3 mb-1">Mensalidades & Custos</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Crie cobranças únicas ou recorrentes com datas limite personalizadas para cada coral.</p>
            </div>
            <div class="bg-white dark:bg-slate-800/50 p-6 rounded-3xl border border-slate-100 dark:border-slate-800 shadow-sm">
                <span class="text-2xl">🧾</span>
                <h3 class="text-sm font-bold font-outfit text-slate-800 dark:text-white mt-3 mb-1">Controle de Comprovantes</h3>
                <p class="text-xs text-slate-400 leading-relaxed">Upload rápido de comprovantes pelos membros, saldo pessoal e baixa automática ao aprovar.</p>
            </div>
        </div>
    </main>

    <footer class="border-t border-slate-200 dark:border-slate-800 py-6">
        <div class="max-w-7xl mx-auto px-4 text-center text-xs text-slate-400">
            &copy; <?= date('Y') ?> eCoral SaaS. Plataforma de Gestão Musical.
        </div>
    </footer>

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
