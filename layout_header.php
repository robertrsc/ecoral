<?php
// layout_header.php
require_once __DIR__ . '/config.php';

$loggedUser = get_logged_user();

$headerChoirLogo = null;
$headerChoirName = null;
if ($loggedUser && !empty($loggedUser['choir_id'])) {
    try {
        $stmtHeaderChoir = $pdo->prepare("SELECT name, logo FROM choirs WHERE id = ?");
        $stmtHeaderChoir->execute([$loggedUser['choir_id']]);
        $headerChoir = $stmtHeaderChoir->fetch();
        if ($headerChoir) {
            $headerChoirName = $headerChoir['name'];
            if (!empty($headerChoir['logo'])) {
                $headerChoirLogo = $headerChoir['logo'];
            }
        }
    } catch (Exception $e) {
        // Silently skip
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eCoral - Gestão de Corais Musicais</title>
    
    <!-- Google Fonts: Inter & Outfit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS Play CDN -->
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
                            300: '#fda4af',
                            400: '#fb7185',
                            500: '#f43f5e', // Cor principal Coral
                            600: '#e11d48',
                            700: '#be123c',
                            800: '#9f1239',
                            900: '#881337',
                            950: '#4c0519',
                        },
                        darkbg: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            800: '#0f172a',
                            900: '#070a13', // Deep rich dark
                            950: '#03050a',
                        }
                    }
                }
            }
        }
    </script>
    
    <style>
        /* Estilos adicionais e micro-animações */
        body {
            font-family: 'Inter', sans-serif;
            transition: background-color 0.3s ease, color 0.3s ease;
        }
        .font-outfit {
            font-family: 'Outfit', sans-serif;
        }
        /* Glassmorphism custom classes */
        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .dark .glass-card {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: transparent;
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(244, 63, 94, 0.3);
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(244, 63, 94, 0.6);
        }
    </style>
    
    <script>
        // Script de detecção de tema imediato
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
    </script>
    <!-- Máscara de Campos Monetários -->
    <script src="/www/ecoral/assets/js/currency-mask.js" defer></script>
</head>
<body class="bg-slate-50 dark:bg-darkbg-900 text-slate-800 dark:text-slate-100 min-h-screen flex flex-col">

    <!-- Script de inicialização do tema para sincronizar o botão -->
    <script>
        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
            window.dispatchEvent(new Event('theme-changed'));
        }
    </script>

    <?php if ($loggedUser): ?>
    
    <?php if (is_impersonating()): ?>
        <!-- Banner de Personificação (Impersonate) -->
        <div class="w-full bg-gradient-to-r from-coral-500 via-rose-500 to-amber-500 text-white py-2.5 px-4 shadow-md z-50 flex items-center justify-between text-xs font-medium border-b border-white/10">
            <div class="max-w-7xl w-full mx-auto flex flex-col sm:flex-row items-center justify-between gap-2.5">
                <div class="flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-white"></span>
                    </span>
                    <span>
                        Você está visualizando o sistema como <strong><?= htmlspecialchars($loggedUser['name']) ?></strong> 
                        (Perfil: <span class="capitalize font-semibold"><?= htmlspecialchars($loggedUser['role']) ?></span>).
                    </span>
                </div>
                <div>
                    <a href="impersonate.php?action=exit" 
                       class="inline-block px-3.5 py-1.5 bg-white text-coral-600 hover:text-coral-700 font-extrabold rounded-lg hover:bg-slate-100 hover:scale-105 transition-all text-[11px] shadow-sm uppercase tracking-wider">
                        ← Voltar para o Administrador
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Top Navbar para telas grandes e Header no mobile -->
    <header class="sticky top-0 z-40 w-full glass-card border-b shadow-sm">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="flex h-16 items-center justify-between">
                <div class="flex items-center">
                    <!-- Botão Hamburger Menu Mobile -->
                    <button id="mobile-menu-btn" type="button" class="inline-flex items-center justify-center rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 lg:hidden">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    
                    <a href="dashboard.php" class="flex items-center ml-4 lg:ml-0 gap-2">
                        <?php if ($headerChoirLogo): ?>
                            <div class="flex flex-col items-center leading-none select-none">
                                <img src="uploads/<?= htmlspecialchars($headerChoirLogo) ?>" class="h-8 object-contain rounded bg-white p-0.5 border border-slate-200/50" alt="Logo Coral">
                                <span class="text-[9px] font-bold text-slate-400 dark:text-slate-500 mt-0.5 tracking-wider font-outfit uppercase">eCoral</span>
                            </div>
                        <?php else: ?>
                            <span class="text-coral-500 flex items-center">
                                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                                </svg>
                            </span>
                            <span class="text-2xl font-black font-outfit text-coral-500 tracking-tight flex items-center gap-1">
                                e<span class="text-slate-800 dark:text-white">Coral</span>
                            </span>
                        <?php endif; ?>
                        <?php if ($headerChoirName): ?>
                            <span class="hidden md:inline text-xs text-slate-400 font-semibold border-l border-slate-200 dark:border-slate-700 pl-2 ml-1 max-w-[150px] truncate" title="<?= htmlspecialchars($headerChoirName) ?>">
                                <?= htmlspecialchars($headerChoirName) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    
                    <!-- Links Desktop -->
                    <nav class="hidden lg:flex items-center ml-10 space-x-4">
                        <a href="dashboard.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Dashboard</a>
                        
                        <?php if (is_superadmin()): ?>
                            <a href="choirs.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Corais</a>
                            <a href="users.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Usuários</a>
                            <a href="members.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Membros</a>
                            <a href="smtp-settings.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">SMTP</a>
                            <a href="backups.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Backups</a>
                            <a href="db-sync.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">DB Sync</a>
                            <a href="schedule.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Agenda</a>
                        <?php elseif (is_admin_user()): ?>
                            <?php if ($loggedUser['role'] === 'administrador'): ?>
                                <a href="users.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Usuários</a>
                            <?php endif; ?>
                            <a href="members.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Membros</a>
                            <a href="billing.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Cobranças</a>
                            <a href="payments.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Pagamentos</a>
                            <a href="schedule.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Agenda</a>
                            <a href="financial-reset.php" class="px-3 py-2 rounded-md text-sm font-medium text-rose-500 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 transition-colors" title="Reset Financeiro">🗑️ Reset</a>
                        <?php elseif ($loggedUser['role'] === 'colaborador'): ?>
                            <a href="payments.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Pagamentos</a>
                            <a href="schedule.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Agenda</a>
                        <?php elseif ($loggedUser['role'] === 'membro'): ?>
                            <a href="payments.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Cobranças & Comprovantes</a>
                            <a href="schedule.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Agenda</a>
                        <?php endif; ?>
                    </nav>
                </div>
                
                <div class="flex items-center gap-4">
                    <!-- Botão Toggle Tema -->
                    <button onclick="toggleTheme()" type="button" class="rounded-full p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 focus:outline-none transition-all">
                        <!-- Sun Icon (Tema Escuro -> Clica para Claro) -->
                        <svg class="h-6 w-6 hidden dark:block" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m0 13.5V21M4.22 4.22l1.77 1.77m11.96 11.96l1.77 1.77M3 12h2.25m13.5 0H21M5.97 18.03l1.77-1.77m11.96-11.96l1.77-1.77M12 7.5a4.5 4.5 0 100 9 4.5 4.5 0 000-9z" />
                        </svg>
                        <!-- Moon Icon (Tema Claro -> Clica para Escuro) -->
                        <svg class="h-6 w-6 block dark:hidden" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                        </svg>
                    </button>
                    
                    <!-- Menu Usuário -->
                    <div class="relative ml-3">
                        <button id="user-menu-btn" type="button" class="flex items-center gap-2 max-w-xs rounded-full text-sm focus:outline-none py-1.5 px-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                            <span class="w-8 h-8 rounded-full bg-coral-500 text-white font-bold flex items-center justify-center text-sm font-outfit uppercase">
                                <?= mb_substr($loggedUser['name'], 0, 2) ?>
                            </span>
                            <span class="hidden md:inline font-medium text-slate-700 dark:text-slate-200 text-xs">
                                <?= htmlspecialchars(explode(' ', $loggedUser['name'])[0]) ?> 
                                <span class="block text-[10px] text-slate-400 font-light text-left capitalize"><?= $loggedUser['role'] ?></span>
                            </span>
                        </button>
                        
                        <!-- Dropdown Menu Usuário -->
                        <div id="user-menu-dropdown" class="absolute right-0 z-50 mt-2 w-48 origin-top-right rounded-md bg-white dark:bg-slate-800 py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none hidden">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700">Meu Perfil</a>
                            <hr class="border-slate-200 dark:border-slate-700 my-1">
                            <a href="login.php?logout=1" class="block px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-slate-700">Sair</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Mobile Sidebar / Drawer (Colapsável) -->
    <div id="mobile-sidebar" class="relative z-50 lg:hidden hidden" role="dialog" aria-modal="true">
        <div id="mobile-sidebar-backdrop" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm transition-opacity"></div>
        
        <div class="fixed inset-0 flex">
            <div class="relative mr-auto flex h-full w-full max-w-xs flex-col bg-white dark:bg-slate-900 py-4 shadow-xl">
                <div class="flex items-center justify-between px-4">
                    <?php if ($headerChoirLogo): ?>
                        <div class="flex flex-col items-center leading-none select-none">
                            <img src="uploads/<?= htmlspecialchars($headerChoirLogo) ?>" class="h-7 object-contain rounded bg-white p-0.5 border border-slate-200" alt="Logo Coral">
                            <span class="text-[9px] font-bold text-slate-400 dark:text-slate-500 mt-0.5 tracking-wider font-outfit uppercase">eCoral</span>
                        </div>
                    <?php else: ?>
                        <span class="text-2xl font-black font-outfit text-coral-500 tracking-tight flex items-center gap-1">
                            e<span class="text-slate-800 dark:text-white">Coral</span>
                        </span>
                    <?php endif; ?>
                    <button id="close-mobile-menu-btn" type="button" class="-m-2.5 rounded-md p-2.5 text-slate-700 dark:text-slate-300">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                
                <div class="mt-6 flex flex-1 flex-col px-4">
                    <nav class="space-y-2">
                        <a href="dashboard.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Dashboard</a>
                        
                        <?php if (is_superadmin()): ?>
                            <a href="choirs.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Corais</a>
                            <a href="users.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Usuários</a>
                            <a href="members.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Membros</a>
                            <a href="smtp-settings.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">SMTP</a>
                            <a href="backups.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Backups</a>
                            <a href="db-sync.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">DB Sync</a>
                            <a href="schedule.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Agenda</a>
                        <?php elseif (is_admin_user()): ?>
                            <?php if ($loggedUser['role'] === 'administrador'): ?>
                                <a href="users.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Usuários</a>
                            <?php endif; ?>
                            <a href="members.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Membros</a>
                            <a href="billing.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Cobranças</a>
                            <a href="payments.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Pagamentos</a>
                            <a href="schedule.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Agenda</a>
                            <a href="financial-reset.php" class="block px-3 py-2 rounded-md text-base font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20">🗑️ Reset Financeiro</a>
                        <?php elseif ($loggedUser['role'] === 'colaborador'): ?>
                            <a href="payments.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Pagamentos</a>
                            <a href="schedule.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Agenda</a>
                        <?php elseif ($loggedUser['role'] === 'membro'): ?>
                            <a href="payments.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Cobranças & Comprovantes</a>
                            <a href="schedule.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Agenda</a>
                        <?php endif; ?>
                    </nav>
                    
                    <div class="mt-auto border-t border-slate-200 dark:border-slate-800 pt-4">
                        <a href="profile.php" class="block px-3 py-2 text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Meu Perfil</a>
                        <a href="login.php?logout=1" class="block px-3 py-2 text-base font-semibold text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-slate-800">Sair</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8">
        
        <!-- Toast Notification -->
        <?php $flash = get_flash_message(); if ($flash): ?>
            <div id="toast-notification" class="fixed top-4 right-4 z-50 flex items-center w-full max-w-xs p-4 text-slate-500 bg-white rounded-lg shadow dark:text-slate-400 dark:bg-slate-800 border-l-4 <?= $flash['type'] === 'success' ? 'border-green-500' : ($flash['type'] === 'error' ? 'border-red-500' : 'border-yellow-500') ?> animate-bounce" role="alert">
                <div class="inline-flex items-center justify-center flex-shrink-0 w-8 h-8 <?= $flash['type'] === 'success' ? 'text-green-500 bg-green-100 dark:bg-green-800 dark:text-green-200' : ($flash['type'] === 'error' ? 'text-red-500 bg-red-100 dark:bg-red-800 dark:text-red-200' : 'text-yellow-500 bg-yellow-100 dark:bg-yellow-800 dark:text-yellow-200') ?> rounded-lg">
                    <?php if ($flash['type'] === 'success'): ?>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <?php elseif ($flash['type'] === 'error'): ?>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    <?php endif; ?>
                </div>
                <div class="ml-3 text-sm font-normal"><?= htmlspecialchars($flash['message']) ?></div>
                <button type="button" onclick="document.getElementById('toast-notification').style.display='none'" class="ml-auto -mx-1.5 -my-1.5 bg-white text-slate-400 hover:text-slate-900 rounded-lg focus:ring-2 focus:ring-slate-300 p-1.5 hover:bg-slate-100 inline-flex h-8 w-8 dark:text-slate-500 dark:hover:text-white dark:bg-slate-800 dark:hover:bg-slate-700">
                    <span class="sr-only">Fechar</span>
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 14 14" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/></svg>
                </button>
            </div>
            <script>
                setTimeout(() => {
                    const toast = document.getElementById('toast-notification');
                    if (toast) {
                        toast.style.transition = 'opacity 0.5s ease';
                        toast.style.opacity = '0';
                        setTimeout(() => toast.remove(), 500);
                    }
                }, 4000);
            </script>
        <?php endif; ?>
