<?php
// db-sync.php
require_once __DIR__ . '/config.php';
require_role('superadmin');

require_once __DIR__ . '/db_schema.php';

$syncResults = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync') {
    // Executa a sincronização contida em db_schema.php
    $syncResults = sync_database($pdo);
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="max-w-3xl mx-auto">
    <!-- Título -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>🔄</span> Sincronização do Banco de Dados
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Sincronize a estrutura física de tabelas e sementes do MySQL local com a versão mais recente declarada no código-fonte.</p>
    </div>

    <div class="grid grid-cols-1 gap-6">
        
        <!-- Bloco de Explicação e Ação -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-2">Executar Sincronização</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-6">
                Este processo executa as instruções <code>CREATE TABLE IF NOT EXISTS</code> e insere dados essenciais ausentes. Novas tabelas ou alterações estruturais declaradas em <code>db_schema.php</code> serão propagadas com segurança.
            </p>
            
            <form action="db-sync.php" method="POST">
                <input type="hidden" name="action" value="sync">
                <button type="submit"
                        class="px-5 py-3 rounded-xl bg-coral-500 hover:bg-coral-600 text-white font-bold text-sm shadow transition-colors flex items-center gap-2 mx-auto">
                    <span>🔄</span> Sincronizar Estruturas Agora
                </button>
            </form>
        </div>

        <!-- Bloco de Resultados -->
        <?php if ($syncResults !== null): ?>
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Resultados do Processamento</h2>
                
                <div class="space-y-3">
                    <?php foreach ($syncResults as $component => $res): ?>
                        <div class="border border-slate-100 dark:border-slate-700/50 rounded-2xl p-4 flex justify-between items-center gap-4 bg-slate-50/50 dark:bg-slate-900/10">
                            <div>
                                <p class="text-sm font-semibold text-slate-800 dark:text-white uppercase">
                                    <?= htmlspecialchars($component) ?>
                                </p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                                    <?= htmlspecialchars($res['message']) ?>
                                </p>
                            </div>
                            
                            <div>
                                <?php if ($res['success']): ?>
                                    <span class="w-8 h-8 rounded-full bg-green-100 dark:bg-green-950/20 text-green-600 dark:text-green-400 flex items-center justify-center font-bold">
                                        ✓
                                    </span>
                                <?php else: ?>
                                    <span class="w-8 h-8 rounded-full bg-red-100 dark:bg-red-950/20 text-red-600 dark:text-red-400 flex items-center justify-center font-bold">
                                        ✗
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
