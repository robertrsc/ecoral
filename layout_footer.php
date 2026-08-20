<?php
// layout_footer.php
?>
    </main>

    <footer class="mt-auto border-t border-slate-200 dark:border-slate-800 bg-white dark:bg-darkbg-900/60 py-6 transition-colors">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center md:flex md:items-center md:justify-between text-xs text-slate-500 dark:text-slate-400">
            <p>&copy; <?= date('Y') ?> eCoral SaaS. Desenvolvido com carinho para a gestão de corais musicais.</p>
            <p class="mt-2 md:mt-0 font-outfit font-semibold text-coral-500">Tema Claro e Escuro Ativo</p>
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
