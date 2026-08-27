<?php
// layout_footer.php
?>
    </main>

    <footer class="mt-auto border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-darkbg-900/60 py-6 transition-colors">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center md:flex md:items-center md:justify-between text-xs text-slate-500 dark:text-slate-400">
            <div class="flex items-center justify-center md:justify-start gap-1.5 font-black font-outfit text-coral-500 text-sm mb-2 md:mb-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3"></path>
                </svg>
                <span>e<span class="text-slate-800 dark:text-white">Coral</span></span>
                <span class="text-[10px] text-slate-400 dark:text-slate-500 font-normal ml-2">&copy; <?= date('Y') ?> eCoral SaaS</span>
            </div>
            <p class="mt-2 md:mt-0 text-[10px]">Desenvolvido para a gestão profissional de corais musicais.</p>
        </div>
    </footer>

    <!-- Scripts de Interação da UI -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Dropdown de Perfil
            const userMenuBtn = document.getElementById('user-menu-btn');
            const userMenuDropdown = document.getElementById('user-menu-dropdown');
            
            if (userMenuBtn && userMenuDropdown) {
                userMenuBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    userMenuDropdown.classList.toggle('hidden');
                });
                
                document.addEventListener('click', function() {
                    userMenuDropdown.classList.add('hidden');
                });
            }
            
            // Menu Mobile
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const mobileSidebar = document.getElementById('mobile-sidebar');
            const closeMobileMenuBtn = document.getElementById('close-mobile-menu-btn');
            const mobileSidebarBackdrop = document.getElementById('mobile-sidebar-backdrop');
            
            if (mobileMenuBtn && mobileSidebar) {
                mobileMenuBtn.addEventListener('click', function() {
                    mobileSidebar.classList.remove('hidden');
                });
                
                const closeMenu = function() {
                    mobileSidebar.classList.add('hidden');
                };
                
                if (closeMobileMenuBtn) {
                    closeMobileMenuBtn.addEventListener('click', closeMenu);
                }
                if (mobileSidebarBackdrop) {
                    mobileSidebarBackdrop.addEventListener('click', closeMenu);
                }
            }
        });
    </script>
</body>
</html>
