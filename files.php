<?php
// files.php
require_once __DIR__ . '/config.php';
require_login();

$user = get_logged_user();
$error = null;
$success = null;

// Identificar o coral_id do contexto
if (is_superadmin()) {
    $choir_id = intval($_GET['choir_id'] ?? $_SESSION['admin_choir_id'] ?? 0);
    if ($choir_id > 0) {
        $_SESSION['admin_choir_id'] = $choir_id;
    }
} else {
    $choir_id = $user['choir_id'];
}

// Garantir segurança da raiz de armazenamento local de arquivos (.htaccess)
$storageRoot = __DIR__ . '/storage';
if (!is_dir($storageRoot)) {
    mkdir($storageRoot, 0755, true);
}
if (!file_exists($storageRoot . '/.htaccess')) {
    file_put_contents($storageRoot . '/.htaccess', "Deny from all");
}

// Helper: formatar tamanho de arquivo
function format_file_size($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', '') . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '') . ' KB';
    }
    return $bytes . ' B';
}

// Helper: obter ícone e cor por tipo
function get_file_type_meta($ext) {
    $ext = strtolower($ext);
    switch ($ext) {
        case 'pdf':
            return ['icon' => '📄', 'color' => 'bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-400', 'label' => 'PDF Documento'];
        case 'txt':
            return ['icon' => '📝', 'color' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', 'label' => 'Texto TXT'];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'bmp':
            return ['icon' => '🖼️', 'color' => 'bg-teal-50 text-teal-700 dark:bg-teal-950/20 dark:text-teal-400', 'label' => 'Imagem'];
        case 'ogg':
            return ['icon' => '🎵', 'color' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/20 dark:text-indigo-400', 'label' => 'Música/Áudio'];
        case 'mp4':
            // MP4 pode ser áudio ou vídeo. Vamos rotular amigavelmente.
            return ['icon' => '🎥', 'color' => 'bg-purple-50 text-purple-700 dark:bg-purple-950/20 dark:text-purple-400', 'label' => 'Vídeo/Mídia'];
        default:
            return ['icon' => '📁', 'color' => 'bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-400', 'label' => 'Arquivo'];
    }
}

// ----------------------------------------------
// CONTROLADOR DE AÇÕES (UPLOAD, DOWNLOAD, EXCLUSÃO)
// ----------------------------------------------

$action = $_GET['action'] ?? 'list';

// 1. Ação: DOWNLOAD SEGURO
if ($action === 'download') {
    $file_id = intval($_GET['id'] ?? 0);
    
    if ($file_id <= 0) {
        die("ID de arquivo inválido.");
    }
    
    // Obter metadados
    $stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        die("Arquivo não encontrado.");
    }
    
    // Verificar visibilidade / permissões de acesso ao download
    $allowed = false;
    if (is_superadmin()) {
        $allowed = true;
    } elseif (is_admin_user() && $file['choir_id'] == $user['choir_id']) {
        $allowed = true;
    } elseif ($user['role'] === 'membro' && $file['choir_id'] == $user['choir_id']) {
        // Cantor: verificar se faz parte do target
        if ($file['target_type'] === 'all') {
            $allowed = true;
        } elseif ($file['target_type'] === 'voice_type' && $file['target_voice_type'] === $user['voice_type']) {
            $allowed = true;
        } elseif ($file['target_type'] === 'member' && $file['target_member_id'] == $user['id']) {
            $allowed = true;
        }
    }
    
    if (!$allowed) {
        die("Acesso negado. Você não tem permissão para baixar este arquivo.");
    }
    
    $filePath = __DIR__ . '/storage/' . $file['choir_id'] . '/' . $file['filename'];
    
    if (!file_exists($filePath)) {
        die("O arquivo físico não foi encontrado no servidor de armazenamento.");
    }
    
    // Detectar Mime-Type correto
    $ext = strtolower($file['file_type']);
    $mimeTypes = [
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'bmp'  => 'image/bmp',
        'mp4'  => 'video/mp4',
        'ogg'  => 'audio/ogg'
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
    
    // Limpar buffers para evitar corrupção de binários
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . basename($file['original_name']) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
}

// 2. Ação: EXCLUIR ARQUIVO (Admin apenas)
if ($action === 'delete' && is_admin_user()) {
    $file_id = intval($_GET['id'] ?? 0);
    
    if ($file_id > 0) {
        try {
            // Obter arquivo e validar coral do contexto
            $stmt = $pdo->prepare("SELECT * FROM shared_files WHERE id = ?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetch();
            
            if ($file) {
                if ($file['choir_id'] != $choir_id && !is_superadmin()) {
                    set_flash_message('error', 'Acesso negado.');
                } else {
                    $pdo->beginTransaction();
                    
                    // Remover do banco
                    $stmtDel = $pdo->prepare("DELETE FROM shared_files WHERE id = ?");
                    $stmtDel->execute([$file_id]);
                    
                    // Remover do disco físico
                    $filePath = __DIR__ . '/storage/' . $file['choir_id'] . '/' . $file['filename'];
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    
                    $pdo->commit();
                    set_flash_message('success', 'Arquivo compartilhado removido com sucesso!');
                }
            } else {
                set_flash_message('error', 'Arquivo não encontrado.');
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash_message('error', 'Erro ao remover arquivo: ' . $e->getMessage());
        }
    }
    header("Location: files.php" . (is_superadmin() ? '?choir_id='.$choir_id : ''));
    exit;
}

// 3. Ação: UPLOAD DE ARQUIVO (Admin apenas)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && is_admin_user()) {
    $title = trim($_POST['title'] ?? '');
    $target_type = trim($_POST['target_type'] ?? 'all');
    $target_voice_type = trim($_POST['target_voice_type'] ?? null);
    if (empty($target_voice_type)) $target_voice_type = null;
    $target_member_id = intval($_POST['target_member_id'] ?? 0);
    if ($target_member_id === 0) $target_member_id = null;
    
    if (empty($title) || $choir_id <= 0) {
        $error = 'Por favor, preencha o título descritivo do arquivo.';
    } elseif (!isset($_FILES['shared_file']) || $_FILES['shared_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Por favor, selecione um arquivo válido para upload.';
    } else {
        $fileInfo = $_FILES['shared_file'];
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'bmp', 'mp4', 'ogg'];
        
        if (!in_array($ext, $allowedExtensions)) {
            $error = 'Extensão de arquivo não permitida. Envie apenas: PDF, TXT, JPG, JPEG, PNG, BMP, MP4 ou OGG.';
        } else {
            // Garantir que a pasta do coral exista
            $choirStorageDir = $storageRoot . '/' . $choir_id;
            if (!is_dir($choirStorageDir)) {
                mkdir($choirStorageDir, 0755, true);
            }
            
            // Gerar nome único físico
            $physicalName = uniqid('file_', true) . '.' . $ext;
            $destination = $choirStorageDir . '/' . $physicalName;
            
            if (move_uploaded_file($fileInfo['tmp_name'], $destination)) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO shared_files (choir_id, title, filename, original_name, file_type, file_size, target_type, target_voice_type, target_member_id, uploaded_by)
                                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $choir_id,
                        $title,
                        $physicalName,
                        $fileInfo['name'],
                        $ext,
                        $fileInfo['size'],
                        $target_type,
                        $target_voice_type,
                        $target_member_id,
                        $user['id']
                    ]);
                    
                    set_flash_message('success', 'Arquivo compartilhado com sucesso!');
                    header("Location: files.php" . (is_superadmin() ? '?choir_id='.$choir_id : ''));
                    exit;
                } catch (Exception $e) {
                    // Remover o arquivo físico em caso de erro na query
                    if (file_exists($destination)) {
                        unlink($destination);
                    }
                    $error = 'Erro ao salvar no banco de dados: ' . $e->getMessage();
                }
            } else {
                $error = 'Falha ao salvar o arquivo físico no servidor.';
            }
        }
    }
}

// ----------------------------------------------
// CARREGAR DADOS E FILTROS DE LISTAGEM
// ----------------------------------------------
$members = [];
if ($choir_id > 0) {
    // Membros para o seletor admin
    $stmt = $pdo->prepare("SELECT id, name, voice_type FROM users WHERE choir_id = ? AND role = 'membro' AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$choir_id]);
    $members = $stmt->fetchAll();
}

$files = [];
if ($choir_id > 0) {
    if ($user['role'] === 'membro') {
        // Membro: Filtrar arquivos destinados a ele
        $sql = "SELECT sf.*, u.name as uploader_name 
                FROM shared_files sf
                JOIN users u ON sf.uploaded_by = u.id
                WHERE sf.choir_id = :choir_id
                  AND (
                      sf.target_type = 'all'
                      OR (sf.target_type = 'voice_type' AND sf.target_voice_type = :voice_type)
                      OR (sf.target_type = 'member' AND sf.target_member_id = :user_id)
                  )
                ORDER BY sf.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':choir_id' => $choir_id,
            ':voice_type' => $user['voice_type'],
            ':user_id' => $user['id']
        ]);
        $files = $stmt->fetchAll();
    } else {
        // Admin: Visualizar todos os arquivos do coral
        $sql = "SELECT sf.*, u.name as uploader_name, m.name as member_name
                FROM shared_files sf
                JOIN users u ON sf.uploaded_by = u.id
                LEFT JOIN users m ON sf.target_member_id = m.id
                WHERE sf.choir_id = :choir_id
                ORDER BY sf.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':choir_id' => $choir_id]);
        $files = $stmt->fetchAll();
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<!-- Seletor de Coral para Superadmin -->
<?php if (is_superadmin()): ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50 mb-6">
        <form action="files.php" method="GET" class="flex flex-col sm:flex-row items-end gap-3">
            <div class="flex-1 w-full">
                <label for="choir_id_select" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Coral para Visualizar os Arquivos</label>
                <select name="choir_id" id="choir_id_select" onchange="this.form.submit()"
                        class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    <option value="">Selecione um coral...</option>
                    <?php foreach ($choirs as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $choir_id == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>📂</span> Compartilhamento de Arquivos
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Acesse e baixe partituras, áudios de ensaio, regulamentos e vídeos do coral.
        </p>
    </div>
    
    <?php if (is_admin_user() && $choir_id > 0): ?>
        <button onclick="openUploadModal()"
                class="px-4 py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
            + Compartilhar Arquivo
        </button>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($choir_id == 0): ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-8 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50">
        Selecione um coral para visualizar a lista de arquivos.
    </div>
<?php else: ?>
    
    <!-- Grid/Lista de Arquivos Compartilhados -->
    <?php if (empty($files)): ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50 flex flex-col items-center justify-center min-h-[250px]">
            <div class="w-16 h-16 bg-slate-100 dark:bg-slate-900/60 text-slate-400 rounded-full flex items-center justify-center text-3xl mb-4">
                📂
            </div>
            <h3 class="font-bold text-slate-800 dark:text-white text-base mb-1">Nenhum arquivo compartilhado</h3>
            <p class="text-xs text-slate-400 max-w-md">
                Ainda não há arquivos ou materiais carregados para a sua visualização nesta pasta.
            </p>
        </div>
    <?php else: ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                    <thead class="bg-slate-50 dark:bg-slate-900/60">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Arquivo</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Tipo / Formato</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Tamanho</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Destinatários</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Enviado por</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                        <?php foreach ($files as $f): 
                            $meta = get_file_type_meta($f['file_type']);
                            
                            // Formatação do público alvo
                            $target_label = "";
                            if ($f['target_type'] === 'all') {
                                $target_label = "<span class='px-2.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-semibold'>Todos</span>";
                            } elseif ($f['target_type'] === 'voice_type') {
                                $target_label = "<span class='px-2.5 py-0.5 rounded-full text-[10px] bg-coral-100 text-coral-700 dark:bg-coral-950/20 dark:text-coral-400 font-semibold'>Naipe: " . htmlspecialchars($f['target_voice_type']) . "</span>";
                            } elseif ($f['target_type'] === 'member') {
                                $nameToShow = $user['role'] === 'membro' ? 'Você' : ($f['member_name'] ?? 'Membro');
                                $target_label = "<span class='px-2.5 py-0.5 rounded-full text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-950/20 dark:text-indigo-400 font-semibold'>Membro: " . htmlspecialchars($nameToShow) . "</span>";
                            }
                        ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-3">
                                        <span class="text-2xl p-1 bg-slate-50 dark:bg-slate-900 rounded-xl" title="<?= htmlspecialchars($meta['label']) ?>"><?= $meta['icon'] ?></span>
                                        <div>
                                            <div class="font-bold text-slate-900 dark:text-white"><?= htmlspecialchars($f['title']) ?></div>
                                            <div class="text-[10px] text-slate-400 truncate max-w-xs" title="<?= htmlspecialchars($f['original_name']) ?>">
                                                <?= htmlspecialchars($f['original_name']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs">
                                    <span class="px-2.5 py-1 rounded-lg font-semibold uppercase <?= $meta['color'] ?>">
                                        <?= htmlspecialchars($f['file_type']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-xs font-semibold text-slate-800 dark:text-slate-300">
                                    <?= format_file_size($f['file_size']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?= $target_label ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-xs text-slate-500">
                                    <strong><?= htmlspecialchars($f['uploader_name']) ?></strong>
                                    <span class="block text-[10px] text-slate-400 mt-0.5"><?= date('d/m/Y H:i', strtotime($f['created_at'])) ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-semibold space-x-2">
                                    <a href="files.php?action=download&id=<?= $f['id'] ?>"
                                       class="px-3 py-1.5 rounded-lg bg-coral-50 hover:bg-coral-100 text-coral-600 dark:bg-coral-950/20 dark:text-coral-400 transition-colors">
                                        📥 Baixar
                                    </a>
                                    
                                    <?php if (is_admin_user()): ?>
                                        <a href="files.php?action=delete&id=<?= $f['id'] ?><?= is_superadmin() ? '&choir_id='.$choir_id : '' ?>"
                                           onclick="return confirm('Deseja realmente excluir este arquivo compartilhado? Ele será removido permanentemente de nossos servidores.')"
                                           class="px-3 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 dark:bg-red-950/20 dark:text-red-400 transition-colors">
                                            Excluir
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ==============================================
     MODAL DE UPLOAD (ADMINS APENAS)
     ============================================== -->
<?php if (is_admin_user() && $choir_id > 0): ?>
    <div id="modal-upload-file" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-slate-900/80 backdrop-blur-sm" onclick="closeUploadModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white">Compartilhar Novo Arquivo</h3>
                    <button onclick="closeUploadModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">❌</button>
                </div>
                
                <form action="files.php?choir_id=<?= $choir_id ?>" method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload">
                    
                    <div>
                        <label for="upload_title" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Título Descritivo *</label>
                        <input type="text" name="title" id="upload_title" required placeholder="Ex: Partitura - Hallelujah, Guia de Voz Soprano"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    
                    <div>
                        <label for="shared_file" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Arquivo *</label>
                        <input type="file" name="shared_file" id="shared_file" required accept=".pdf,.txt,.jpg,.jpeg,.png,.bmp,.mp4,.ogg"
                               class="w-full px-2 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg dark:text-white file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-coral-100 file:text-coral-700 hover:file:bg-coral-200">
                        <p class="text-[10px] text-slate-400 mt-1">Formatos aceitos: Documentos (PDF, TXT), Imagens (JPG, JPEG, PNG, BMP), Músicas (MP4, OGG) e Vídeos (MP4).</p>
                    </div>
                    
                    <div class="border-t border-slate-100 dark:border-slate-700/50 pt-4">
                        <label for="upload_target_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Direcionar Arquivo Para:</label>
                        <select name="target_type" id="upload_target_type" onchange="toggleTargetSelectors(this.value)"
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all mb-3">
                            <option value="all">Todos os membros do coral</option>
                            <option value="voice_type">Naipe específico</option>
                            <option value="member">Membro cantor específico</option>
                        </select>
                        
                        <!-- Seletor de Naipe -->
                        <div id="target_voice_container" class="hidden">
                            <label for="upload_target_voice_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Naipe Alvo</label>
                            <select name="target_voice_type" id="upload_target_voice_type"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="Soprano">Soprano</option>
                                <option value="Contralto">Contralto</option>
                                <option value="Tenor">Tenor</option>
                                <option value="Baixo">Baixo</option>
                            </select>
                        </div>
                        
                        <!-- Seletor de Membro -->
                        <div id="target_member_container" class="hidden">
                            <label for="upload_target_member_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Cantor Alvo</label>
                            <select name="target_member_id" id="upload_target_member_id"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="0">Selecione...</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['voice_type'] ?? 'Sem naipe') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-2 pt-4">
                        <button type="button" onclick="closeUploadModal()"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white transition-colors">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-coral-500 hover:bg-coral-600 text-white transition-colors">
                            Compartilhar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function openUploadModal() {
        document.getElementById('modal-upload-file').classList.remove('hidden');
    }

    function closeUploadModal() {
        document.getElementById('modal-upload-file').classList.add('hidden');
    }

    function toggleTargetSelectors(type) {
        const voiceCont = document.getElementById('target_voice_container');
        const memberCont = document.getElementById('target_member_container');
        
        if (type === 'all') {
            voiceCont.classList.add('hidden');
            memberCont.classList.add('hidden');
        } else if (type === 'voice_type') {
            voiceCont.classList.remove('hidden');
            memberCont.classList.add('hidden');
        } else if (type === 'member') {
            voiceCont.classList.add('hidden');
            memberCont.classList.remove('hidden');
        }
    }
    </script>
<?php endif; ?>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
