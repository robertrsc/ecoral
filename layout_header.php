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

$isMemberApp = $loggedUser && ($loggedUser['role'] ?? '') === 'membro';
$currentPage = basename($_SERVER['PHP_SELF']);
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
        /* Títulos de página menores no mobile */
        @media (max-width: 767px) {
            main h1 { font-size: 1.25rem !important; line-height: 1.75rem !important; }
        }
        /* Tiles estilo Metro */
        .metro-tile { transition: transform .15s ease, box-shadow .15s ease, filter .15s ease; }
        .metro-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 24px -8px rgba(0,0,0,.35); }
        .metro-tile:active { transform: scale(.97); filter: brightness(.95); }
    </style>
    
    <script>
        // Script de detecção de tema imediato
        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        // Garantir que as páginas sempre abram no topo ao carregar ou navegar
        if ('scrollRestoration' in history) {
            history.scrollRestoration = 'manual';
        }
        window.scrollTo(0, 0);
        window.addEventListener('pageshow', function() {
            window.scrollTo(0, 0);
        });
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
                    <?php if (!$isMemberApp): ?>
                    <button id="mobile-menu-btn" type="button" class="inline-flex items-center justify-center rounded-md p-2 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-200 lg:hidden">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <?php endif; ?>
                    
                    <a href="dashboard.php" class="flex items-center <?= $isMemberApp ? '' : 'ml-4' ?> lg:ml-0 gap-2">
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
                            <a href="files.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Arquivos</a>
                        <?php else: ?>
                            <?php if (has_permission('manage_users') || $loggedUser['role'] === 'administrador'): ?>
                                <a href="users.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Usuários</a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('manage_users')): ?>
                                <a href="members.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Membros</a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('manage_financial')): ?>
                                <a href="billing.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Cobranças</a>
                            <?php endif; ?>
                            
                            <!-- Pagamentos / Comprovantes -->
                            <?php if ($loggedUser['role'] === 'membro'): ?>
                                <a href="payments.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Cobranças & Comprovantes</a>
                            <?php else: ?>
                                <a href="payments.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Pagamentos</a>
                            <?php endif; ?>
                            
                            <!-- Agenda e Arquivos para todos os membros e equipe -->
                            <a href="schedule.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Agenda</a>
                            <a href="files.php" class="px-3 py-2 rounded-md text-sm font-medium text-slate-700 hover:text-coral-500 dark:text-slate-300 dark:hover:text-coral-400 transition-colors">Arquivos</a>
                            
                            <?php if (has_permission('manage_financial')): ?>
                                <a href="financial-reset.php" class="px-3 py-2 rounded-md text-sm font-medium text-rose-500 hover:text-rose-700 dark:text-rose-400 dark:hover:text-rose-300 transition-colors" title="Reset Financeiro">🗑️ Reset</a>
                            <?php endif; ?>
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
                    <div class="relative ml-3 flex flex-col items-end">
                        <button id="user-menu-btn" type="button" class="flex items-center gap-2 max-w-xs rounded-full text-sm focus:outline-none py-1.5 px-3 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                            <span class="w-8 h-8 rounded-full bg-coral-500 text-white font-bold flex items-center justify-center text-sm font-outfit uppercase">
                                <?= mb_substr($loggedUser['name'], 0, 2) ?>
                            </span>
                            <span class="hidden md:inline font-medium text-slate-700 dark:text-slate-200 text-xs">
                                <?= htmlspecialchars(explode(' ', $loggedUser['name'])[0]) ?> 
                                <span class="block text-[10px] text-slate-400 font-light text-left capitalize"><?= $loggedUser['role'] ?></span>
                            </span>
                        </button>
                        <?php if ($isMemberApp): ?>
                            <!-- Saldo estilo app bancário -->
                            <button type="button" onclick="toggleBalanceVisibility()" id="header-balance-btn"
                                    class="mt-0.5 flex items-center gap-1 text-[11px] leading-none text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200"
                                    aria-label="Mostrar ou ocultar saldo">
                                <span>Saldo</span>
                                <strong class="text-emerald-600 dark:text-emerald-400" data-balance-value="<?= htmlspecialchars(format_currency($loggedUser['balance'] ?? 0)) ?>">R$ ••••</strong>
                                <svg class="w-3.5 h-3.5 eye-open hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                <svg class="w-3.5 h-3.5 eye-closed" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/></svg>
                            </button>
                            <script>
                                function applyBalanceVisibility() {
                                    const show = localStorage.getItem('showBalance') === '1';
                                    document.querySelectorAll('[data-balance-value]').forEach(el => {
                                        el.textContent = show ? el.dataset.balanceValue : 'R$ ••••';
                                    });
                                    document.querySelectorAll('.eye-open').forEach(el => el.classList.toggle('hidden', !show));
                                    document.querySelectorAll('.eye-closed').forEach(el => el.classList.toggle('hidden', show));
                                }
                                function toggleBalanceVisibility() {
                                    localStorage.setItem('showBalance', localStorage.getItem('showBalance') === '1' ? '0' : '1');
                                    applyBalanceVisibility();
                                }
                                document.addEventListener('DOMContentLoaded', applyBalanceVisibility);
                            </script>
                        <?php endif; ?>
                        
                        <!-- Dropdown Menu Usuário -->
                        <div id="user-menu-dropdown" class="absolute right-0 top-full z-50 mt-2 w-48 origin-top-right rounded-md bg-white dark:bg-slate-800 py-1 shadow-lg ring-1 ring-black ring-opacity-5 focus:outline-none hidden">
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
    <?php if (!$isMemberApp): ?>
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
                            <a href="files.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Arquivos</a>
                        <?php else: ?>
                            <?php if (has_permission('manage_users') || $loggedUser['role'] === 'administrador'): ?>
                                <a href="users.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Usuários</a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('manage_users')): ?>
                                <a href="members.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Membros</a>
                            <?php endif; ?>
                            
                            <?php if (has_permission('manage_financial')): ?>
                                <a href="billing.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Cobranças</a>
                            <?php endif; ?>
                            
                            <?php if ($loggedUser['role'] === 'membro'): ?>
                                <a href="payments.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Cobranças & Comprovantes</a>
                            <?php else: ?>
                                <a href="payments.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Pagamentos</a>
                            <?php endif; ?>
                            
                            <a href="schedule.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Agenda</a>
                            <a href="files.php" class="block px-3 py-2 rounded-md text-base font-semibold text-slate-900 dark:text-white hover:bg-slate-100 dark:hover:bg-slate-800">Arquivos</a>
                            
                            <?php if (has_permission('manage_financial')): ?>
                                <a href="financial-reset.php" class="block px-3 py-2 rounded-md text-base font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-900/20">🗑️ Reset Financeiro</a>
                            <?php endif; ?>
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
    <?php endif; ?>

    <!-- Main Container -->
    <main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8">
        <?php if ($loggedUser && $currentPage !== 'dashboard.php'): ?>
            <!-- Botão Voltar ao início -->
            <a href="dashboard.php" id="back-to-dashboard"
               class="inline-flex items-center gap-2 mb-3 -ml-1 px-3 py-2 min-h-[44px] rounded-xl text-sm font-semibold text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"/></svg>
                Voltar ao início
            </a>
        <?php endif; ?>
        
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
