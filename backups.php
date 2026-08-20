<?php
// backups.php
require_once __DIR__ . '/config.php';
require_role('superadmin');

$error = null;
$success = null;

$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}
if (!file_exists($backupDir . '/.htaccess')) {
    // Adicionar .htaccess para impedir downloads diretos sem autenticação (por segurança)
    file_put_contents($backupDir . '/.htaccess', "Deny from all");
}

// 1. Gerar Backup
if (isset($_GET['action']) && $_GET['action'] === 'generate') {
    $timestamp = date('Y-m-d_H-i-s');
    $zipFilename = "backup_ecoral_{$timestamp}.zip";
    $zipPath = $backupDir . '/' . $zipFilename;
    $sqlFilename = "dump_{$timestamp}.sql";
    $sqlPath = $backupDir . '/' . $sqlFilename;
    
    // Executar dump do banco usando mysqldump
    $cmd = "mysqldump -h " . escapeshellarg(DB_HOST) . " -u " . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " " . escapeshellarg(DB_NAME) . " > " . escapeshellarg($sqlPath) . " 2>&1";
    exec($cmd, $output, $returnVar);
    
    if ($returnVar !== 0) {
        $error = "Erro ao gerar dump do banco de dados: " . implode("\n", $output);
    } else {
        // Compactar SQL + pasta de uploads em um ZIP
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            // Adicionar arquivo SQL
            $zip->addFile($sqlPath, 'database.sql');
            
            // Adicionar arquivos de upload
            $uploadsPath = __DIR__ . '/uploads';
            if (is_dir($uploadsPath)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($uploadsPath),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = 'uploads/' . substr($filePath, strlen($uploadsPath) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            }
            $zip->close();
            
            // Remover dump temporário
            unlink($sqlPath);
            
            set_flash_message('success', "Backup '$zipFilename' gerado com sucesso!");
        } else {
            $error = "Falha ao criar arquivo ZIP de backup.";
            if (file_exists($sqlPath)) unlink($sqlPath);
        }
    }
    header("Location: backups.php");
    exit;
}

// 2. Restaurar Backup Específico
if (isset($_GET['action']) && $_GET['action'] === 'restore') {
    $file = trim($_GET['file'] ?? '');
    $zipPath = $backupDir . '/' . basename($file); // basename por segurança
    
    if (empty($file) || !file_exists($zipPath)) {
        set_flash_message('error', "Arquivo de backup não encontrado.");
    } else {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) === true) {
            // Criar diretório temporário para extração
            $tempExtractDir = $backupDir . '/temp_restore_' . time();
            mkdir($tempExtractDir, 0755, true);
            
            $zip->extractTo($tempExtractDir);
            $zip->close();
            
            $sqlExtracted = $tempExtractDir . '/database.sql';
            $uploadsExtracted = $tempExtractDir . '/uploads';
            
            $restoreSuccess = true;
            
            // 2.1 Restaurar banco de dados
            if (file_exists($sqlExtracted)) {
                $cmd = "mysql -h " . escapeshellarg(DB_HOST) . " -u " . escapeshellarg(DB_USER) . " -p" . escapeshellarg(DB_PASS) . " " . escapeshellarg(DB_NAME) . " < " . escapeshellarg($sqlExtracted) . " 2>&1";
                exec($cmd, $output, $returnVar);
                
                if ($returnVar !== 0) {
                    $restoreSuccess = false;
                    $error = "Falha ao restaurar banco de dados: " . implode("\n", $output);
                }
            } else {
                $restoreSuccess = false;
                $error = "Arquivo database.sql não encontrado no backup.";
            }
            
            // 2.2 Restaurar arquivos de upload
            if ($restoreSuccess && is_dir($uploadsExtracted)) {
                $destUploads = __DIR__ . '/uploads';
                if (!is_dir($destUploads)) {
                    mkdir($destUploads, 0755, true);
                }
                
                // Mover arquivos extraídos
                $files = array_diff(scandir($uploadsExtracted), ['.', '..']);
                foreach ($files as $f) {
                    copy($uploadsExtracted . '/' . $f, $destUploads . '/' . $f);
                }
            }
            
            // 2.3 Limpar pasta temporária
            // Função recursiva para limpar pasta
            $deleteDir = function($dir) use (&$deleteDir) {
                if (!is_dir($dir)) return;
                $files = array_diff(scandir($dir), ['.', '..']);
                foreach ($files as $file) {
                    (is_dir("$dir/$file")) ? $deleteDir("$dir/$file") : unlink("$dir/$file");
                }
                rmdir($dir);
            };
            $deleteDir($tempExtractDir);
            
            if ($restoreSuccess) {
                set_flash_message('success', "Backup restaurado com sucesso! Estrutura e arquivos atualizados.");
            }
        } else {
            $error = "Falha ao abrir arquivo ZIP de backup.";
        }
    }
    header("Location: backups.php");
    exit;
}

// 3. Restaurar via Upload Manual de ZIP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_restore') {
    if (!isset($_FILES['backup_zip']) || $_FILES['backup_zip']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Por favor, selecione um arquivo ZIP de backup válido.';
    } else {
        $fileInfo = $_FILES['backup_zip'];
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'zip') {
            $error = 'Envie apenas arquivos no formato ZIP.';
        } else {
            $tempFilename = 'manual_upload_' . time() . '.zip';
            $tempPath = $backupDir . '/' . $tempFilename;
            
            if (move_uploaded_file($fileInfo['tmp_name'], $tempPath)) {
                // Chama a restauração desse arquivo temporário
                header("Location: backups.php?action=restore&file=" . urlencode($tempFilename));
                exit;
            } else {
                $error = 'Falha ao processar upload do backup.';
            }
        }
    }
}

// 4. Download de Backup
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $file = trim($_GET['file'] ?? '');
    $zipPath = $backupDir . '/' . basename($file);
    
    if (!empty($file) && file_exists($zipPath)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($zipPath) . '"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        
        // Se for arquivo temporário de upload manual, podemos apagar após download
        if (str_contains($file, 'manual_upload_')) {
            unlink($zipPath);
        }
        exit;
    } else {
        set_flash_message('error', 'Arquivo para download não encontrado.');
        header("Location: backups.php");
        exit;
    }
}

// 5. Excluir Backup
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    $file = trim($_GET['file'] ?? '');
    $zipPath = $backupDir . '/' . basename($file);
    
    if (!empty($file) && file_exists($zipPath)) {
        unlink($zipPath);
        set_flash_message('success', "Arquivo de backup removido.");
    } else {
        set_flash_message('error', "Arquivo de backup não encontrado.");
    }
    header("Location: backups.php");
    exit;
}

// Listar todos os backups ZIP salvos
$backupFiles = [];
if (is_dir($backupDir)) {
    $files = scandir($backupDir);
    foreach ($files as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'zip') {
            $path = $backupDir . '/' . $f;
            $backupFiles[] = [
                'name' => $f,
                'size' => filesize($path),
                'date' => date('d/m/Y H:i:s', filemtime($path))
            ];
        }
    }
    // Ordenar por data (mais recentes primeiro)
    usort($backupFiles, function($a, $b) {
        return filemtime($backupDir . '/' . $b['name']) - filemtime($backupDir . '/' . $a['name']);
    });
}

function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="max-w-4xl mx-auto">
    <!-- Título -->
    <div class="mb-6">
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>📦</span> Backups do Sistema
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Gere e restaure cópias completas do banco de dados e arquivos de comprovantes do eCoral.</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        
        <!-- Ações e Upload -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Gerar Backup -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-2">Novo Backup</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Clique no botão abaixo para gerar uma cópia de segurança de todos os dados agora.</p>
                <a href="backups.php?action=generate"
                   class="block text-center w-full py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow transition-colors">
                    ⚡ Gerar Backup Completo
                </a>
            </div>

            <!-- Restaurar via Upload -->
            <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6">
                <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-2">Restaurar via Arquivo</h2>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-4">Selecione um arquivo ZIP de backup do eCoral salvo no seu computador para restaurar.</p>
                
                <form action="backups.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload_restore">
                    
                    <input type="file" name="backup_zip" accept=".zip" required
                           class="w-full px-2 py-1 bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-xs dark:text-white">
                    
                    <button type="submit" onclick="return confirm('ATENÇÃO: Restaurar um backup substituirá TODOS os dados atuais do banco de dados e arquivos de comprovantes. Deseja prosseguir?')"
                            class="w-full py-2 bg-indigo-500 hover:bg-indigo-600 text-white font-semibold rounded-lg text-xs shadow transition-colors">
                        📤 Enviar e Restaurar
                    </button>
                </form>
            </div>
        </div>

        <!-- Listagem de Backups no Servidor -->
        <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
            <h2 class="text-base font-bold font-outfit text-slate-800 dark:text-white mb-4">Backups Arquivados no Servidor</h2>
            
            <?php if (empty($backupFiles)): ?>
                <div class="text-center py-12 text-slate-400 text-sm">
                    Nenhum backup arquivado na pasta do servidor.
                </div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($backupFiles as $bf): ?>
                        <div class="border border-slate-100 dark:border-slate-700/50 rounded-2xl p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-50/50 dark:bg-slate-900/10">
                            <div>
                                <p class="text-sm font-semibold text-slate-800 dark:text-white truncate max-w-xs sm:max-w-sm" title="<?= htmlspecialchars($bf['name']) ?>">
                                    <?= htmlspecialchars($bf['name']) ?>
                                </p>
                                <p class="text-xs text-slate-400">
                                    Tamanho: <?= format_bytes($bf['size']) ?> | Criado em: <?= $bf['date'] ?>
                                </p>
                            </div>
                            
                            <div class="flex items-center gap-2.5 w-full sm:w-auto text-xs">
                                <a href="backups.php?action=download&file=<?= urlencode($bf['name']) ?>"
                                   class="text-center flex-1 sm:flex-none px-3 py-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white rounded-lg font-semibold transition-colors">
                                    ⬇️ Baixar
                                </a>
                                <a href="backups.php?action=restore&file=<?= urlencode($bf['name']) ?>"
                                   onclick="return confirm('Confirma a restauração deste backup? Todos os dados atuais do banco serão apagados.')"
                                   class="text-center flex-1 sm:flex-none px-3 py-1.5 bg-indigo-500 hover:bg-indigo-600 text-white rounded-lg font-semibold transition-colors">
                                    🔄 Restaurar
                                </a>
                                <a href="backups.php?action=delete&file=<?= urlencode($bf['name']) ?>"
                                   onclick="return confirm('Deseja excluir este arquivo de backup do servidor?')"
                                   class="text-center px-2 py-1.5 text-red-500 hover:text-red-600 font-bold transition-colors">
                                    Excluir
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
