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
            return ['icon' => '📄', 'color' => 'bg-red-50 text-red-700 dark:bg-red-950/20 dark:text-red-400', 'label' => 'PDF Documento', 'is_media' => false, 'media_type' => null];
        case 'txt':
            return ['icon' => '📝', 'color' => 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300', 'label' => 'Texto TXT', 'is_media' => false, 'media_type' => null];
        case 'jpg':
        case 'jpeg':
        case 'png':
        case 'bmp':
            return ['icon' => '🖼️', 'color' => 'bg-teal-50 text-teal-700 dark:bg-teal-950/20 dark:text-teal-400', 'label' => 'Imagem', 'is_media' => false, 'media_type' => null];
        case 'mp3':
        case 'ogg':
        case 'wav':
        case 'm4a':
            return ['icon' => '🎵', 'color' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-950/20 dark:text-indigo-400', 'label' => 'Áudio', 'is_media' => true, 'media_type' => 'audio'];
        case 'mp4':
        case 'webm':
            return ['icon' => '🎬', 'color' => 'bg-purple-50 text-purple-700 dark:bg-purple-950/20 dark:text-purple-400', 'label' => 'Vídeo MP4', 'is_media' => true, 'media_type' => 'video'];
        default:
            return ['icon' => '📁', 'color' => 'bg-slate-100 text-slate-600 dark:bg-slate-900 dark:text-slate-400', 'label' => 'Arquivo', 'is_media' => false, 'media_type' => null];
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
    } elseif (has_permission('manage_files') && $file['choir_id'] == $user['choir_id']) {
        $allowed = true;
    } elseif ($file['choir_id'] == $user['choir_id']) {
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
        'webm' => 'video/webm',
        'mp3'  => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
        'wav'  => 'audio/wav',
        'm4a'  => 'audio/mp4'
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
    // Modo de exibição: 'view' abre no navegador/player nativo, padrão 'download' baixa
    $mode = $_GET['mode'] ?? 'download';
    $disposition = ($mode === 'view') ? 'inline' : 'attachment';

    // Limpar buffers para evitar corrupção de binários
    if (ob_get_level()) {
        ob_end_clean();
    }

    $fileSize = filesize($filePath);
    $start = 0;
    $end = $fileSize - 1;

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Content-Disposition: ' . $disposition . '; filename="' . rawurlencode(basename($file['original_name'])) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');

    // Suporte a HTTP Range (essencial para streaming de áudio e vídeo nos navegadores)
    if (isset($_SERVER['HTTP_RANGE'])) {
        $c_start = $start;
        $c_end = $end;

        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }
        if ($range === '-') {
            $c_start = $fileSize - substr($range, 1);
        } else {
            $range = explode('-', $range);
            $c_start = (int)$range[0];
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? (int)$range[1] : $fileSize - 1;
        }
        $c_end = ($c_end > $end) ? $end : $c_end;
        if ($c_start > $c_end || $c_start > $fileSize - 1 || $c_end >= $fileSize) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$fileSize");
            exit;
        }
        $start = $c_start;
        $end = $c_end;
        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $start-$end/$fileSize");
        header("Content-Length: " . $length);
    } else {
        header("Content-Length: " . $fileSize);
    }

    $fp = fopen($filePath, 'rb');
    fseek($fp, $start);
    $buffer = 1024 * 64;
    while (!feof($fp) && ($pos = ftell($fp)) <= $end) {
        if ($pos + $buffer > $end) {
            $buffer = $end - $pos + 1;
        }
        echo fread($fp, $buffer);
        flush();
    }
    fclose($fp);
    exit;
}

// 2. Ação: EXCLUIR ARQUIVO (Quem gerencia arquivos)
if ($action === 'delete' && has_permission('manage_files')) {
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

// 3. Ação: UPLOAD DE ARQUIVO (Quem gerencia arquivos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload' && has_permission('manage_files')) {
    $title = trim($_POST['title'] ?? '');
    $target_type = trim($_POST['target_type'] ?? 'all');
    $target_voice_type = trim($_POST['target_voice_type'] ?? null);
    if (empty($target_voice_type)) $target_voice_type = null;
    $target_member_id = intval($_POST['target_member_id'] ?? 0);
    if ($target_member_id === 0) $target_member_id = null;
    
    if (empty($title) || $choir_id <= 0) {
        $error = 'Por favor, preencha o título descritivo do arquivo.';
    } elseif (!isset($_FILES['shared_file']) || $_FILES['shared_file']['error'] !== UPLOAD_ERR_OK) {
        if (isset($_FILES['shared_file']['error']) && ($_FILES['shared_file']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['shared_file']['error'] === UPLOAD_ERR_FORM_SIZE)) {
            $error = 'O arquivo selecionado excede o limite permitido de 20MB.';
        } else {
            $error = 'Por favor, selecione um arquivo válido para upload.';
        }
    } else {
        $fileInfo = $_FILES['shared_file'];
        $maxSizeBytes = 20 * 1024 * 1024; // 20 MB

        if ($fileInfo['size'] > $maxSizeBytes) {
            $error = 'O arquivo selecionado excede o tamanho máximo permitido de 20MB.';
        } else {
            $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
            $allowedExtensions = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'bmp', 'mp4', 'webm', 'mp3', 'ogg', 'wav', 'm4a'];
            
            if (!in_array($ext, $allowedExtensions)) {
                $error = 'Extensão de arquivo não permitida. Envie apenas: PDF, TXT, Imagens (JPG, PNG, BMP), Áudios (MP3, OGG, WAV) ou Vídeos (MP4, WEBM).';
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
    if (!has_permission('manage_files')) {
        // Usuário regular: Filtrar arquivos destinados a ele
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
        // Quem gerencia arquivos: Visualizar todos os arquivos do coral
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
    
    <?php if (has_permission('manage_files') && $choir_id > 0): ?>
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
    
    <!-- Barra de Pesquisa e Filtros -->
    <div class="mb-4 flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
        <div class="relative flex-1">
            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                🔍
            </span>
            <input type="text" id="file_search_input" oninput="filterFilesList()"
                   placeholder="Pesquisar por título, nome do arquivo ou naipe..."
                   class="w-full pl-10 pr-10 py-2.5 text-sm bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white shadow-sm transition-all">
            <button id="file_search_clear" onclick="clearFileSearch()" type="button"
                    class="hidden absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-xs">
                ✕
            </button>
        </div>
        <div id="file_count_badge" class="text-xs text-slate-500 dark:text-slate-400 flex items-center gap-1.5 self-end sm:self-center px-1">
            <span>Total:</span>
            <span class="font-bold text-slate-700 dark:text-slate-200" id="file_count_num"><?= count($files) ?></span> arquivo(s)
        </div>
    </div>

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
        
        <!-- Estado Vazio da Busca (JS) -->
        <div id="files_search_empty" class="hidden bg-white dark:bg-slate-800 rounded-2xl p-8 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50 mb-4">
            <span class="text-3xl block mb-2">🔎</span>
            <p class="font-semibold text-slate-700 dark:text-slate-200 text-sm">Nenhum arquivo encontrado</p>
            <p class="text-xs text-slate-400 mt-1">Tente pesquisar por outro termo ou limpe o campo de busca.</p>
        </div>

        <!-- 1. MODO MOBILE: Lista em Cards Compactos (sem rolagem horizontal) -->
        <div class="block md:hidden space-y-3" id="files_mobile_container">
            <?php foreach ($files as $f): 
                $meta = get_file_type_meta($f['file_type']);
                
                // Formatação do público alvo
                $target_badge = "";
                $target_text_search = "todos";
                if ($f['target_type'] === 'all') {
                    $target_badge = "<span class='px-2 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-semibold'>Todos</span>";
                    $target_text_search = "todos todos os membros";
                } elseif ($f['target_type'] === 'voice_type') {
                    $target_badge = "<span class='px-2 py-0.5 rounded-full text-[10px] bg-coral-100 text-coral-700 dark:bg-coral-950/20 dark:text-coral-400 font-semibold'>Naipe: " . htmlspecialchars($f['target_voice_type']) . "</span>";
                    $target_text_search = "naipe " . mb_strtolower($f['target_voice_type']);
                } elseif ($f['target_type'] === 'member') {
                    $nameToShow = $user['role'] === 'membro' ? 'Você' : ($f['member_name'] ?? 'Membro');
                    $target_badge = "<span class='px-2 py-0.5 rounded-full text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-950/20 dark:text-indigo-400 font-semibold'>Membro: " . htmlspecialchars($nameToShow) . "</span>";
                    $target_text_search = "membro " . mb_strtolower($nameToShow);
                }
                
                $searchContent = mb_strtolower($f['title'] . ' ' . $f['original_name'] . ' ' . $f['file_type'] . ' ' . $target_text_search . ' ' . $f['uploader_name']);
            ?>
                <div class="file-item-card bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/60 transition-all hover:shadow-md"
                             <!-- Topo do Card: Ícone + Título Clicável (Abre/Baixa/Play) -->
                    <div class="flex items-start gap-3">
                        <?php if ($meta['is_media']): ?>
                            <button type="button"
                                    onclick="openMediaPlayer(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, '<?= $meta['media_type'] ?>', '<?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>')"
                                    class="w-11 h-11 shrink-0 rounded-xl flex items-center justify-center text-xl bg-coral-50 text-coral-600 dark:bg-coral-950/30 dark:text-coral-400 border border-coral-200/60 dark:border-coral-800/60 active:scale-95 transition-transform cursor-pointer"
                                    title="Reproduzir">
                                ▶️
                            </button>
                            <div class="flex-1 min-w-0">
                                <button type="button"
                                        onclick="openMediaPlayer(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, '<?= $meta['media_type'] ?>', '<?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>')"
                                        class="block text-left font-bold text-slate-900 dark:text-white text-sm hover:text-coral-500 dark:hover:text-coral-400 leading-snug line-clamp-2 cursor-pointer">
                                    <?= htmlspecialchars($f['title']) ?>
                                </button>
                                <div class="text-[11px] text-slate-400 dark:text-slate-400 truncate mt-0.5" title="<?= htmlspecialchars($f['original_name']) ?>">
                                    📎 <?= htmlspecialchars($f['original_name']) ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="files.php?action=download&mode=view&id=<?= $f['id'] ?>" target="_blank"
                               class="w-11 h-11 shrink-0 rounded-xl flex items-center justify-center text-xl bg-slate-100 dark:bg-slate-900 border border-slate-200/60 dark:border-slate-700 active:scale-95 transition-transform"
                               title="Visualizar">
                                <?= $meta['icon'] ?>
                            </a>
                            <div class="flex-1 min-w-0">
                                <a href="files.php?action=download&mode=view&id=<?= $f['id'] ?>" target="_blank"
                                   class="block font-bold text-slate-900 dark:text-white text-sm hover:text-coral-500 dark:hover:text-coral-400 leading-snug line-clamp-2">
                                    <?= htmlspecialchars($f['title']) ?>
                                </a>
                                <div class="text-[11px] text-slate-400 dark:text-slate-400 truncate mt-0.5" title="<?= htmlspecialchars($f['original_name']) ?>">
                                    📎 <?= htmlspecialchars($f['original_name']) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Informações secundárias / Metadados -->
                    <div class="flex flex-wrap items-center gap-2 mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-700/50 text-xs">
                        <span class="px-2 py-0.5 rounded-md font-bold uppercase text-[10px] <?= $meta['color'] ?>">
                            <?= htmlspecialchars($f['file_type']) ?>
                        </span>
                        <span class="text-[11px] text-slate-500 dark:text-slate-400 font-medium">
                            <?= format_file_size($f['file_size']) ?>
                        </span>
                        <div class="ml-auto">
                            <?= $target_badge ?>
                        </div>
                    </div>

                    <!-- Rodapé com Ações Rápidas (Acessíveis pelo toque) -->
                    <div class="flex items-center justify-between gap-2 mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-700/50">
                        <div class="text-[10px] text-slate-400 truncate max-w-[130px]">
                            Por: <strong class="text-slate-600 dark:text-slate-300"><?= htmlspecialchars($f['uploader_name']) ?></strong>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <!-- Abrir / Play -->
                            <?php if ($meta['is_media']): ?>
                                <button type="button"
                                        onclick="openMediaPlayer(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, '<?= $meta['media_type'] ?>', '<?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>')"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-sm transition-all active:scale-95 cursor-pointer"
                                        title="Reproduzir">
                                    <span>▶️</span> Play
                                </button>
                            <?php else: ?>
                                <a href="files.php?action=download&mode=view&id=<?= $f['id'] ?>" target="_blank"
                                   class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 font-semibold text-xs transition-colors">
                                    <span>👁️</span> Abrir
                                </a>
                            <?php endif; ?>

                            <!-- Baixar diretamente -->
                            <a href="files.php?action=download&mode=download&id=<?= $f['id'] ?>"
                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-coral-50 hover:bg-coral-100 text-coral-600 dark:bg-coral-950/30 dark:text-coral-400 font-semibold text-xs transition-colors">
                                <span>📥</span> Baixar
                            </a>

                            <?php if (has_permission('manage_files')): ?>
                                <a href="files.php?action=delete&id=<?= $f['id'] ?><?= is_superadmin() ? '&choir_id='.$choir_id : '' ?>"
                                   onclick="return confirm('Deseja realmente excluir este arquivo?')"
                                   class="p-1.5 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20 transition-colors"
                                   title="Excluir arquivo">
                                    🗑️
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>

        <!-- 2. MODO DESKTOP: Tabela Tradicional -->
        <div class="hidden md:block bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden" id="files_desktop_container">
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
                            
                            $target_label = "";
                            $target_text_search = "todos";
                            if ($f['target_type'] === 'all') {
                                $target_label = "<span class='px-2.5 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-semibold'>Todos</span>";
                                $target_text_search = "todos todos os membros";
                            } elseif ($f['target_type'] === 'voice_type') {
                                $target_label = "<span class='px-2.5 py-0.5 rounded-full text-[10px] bg-coral-100 text-coral-700 dark:bg-coral-950/20 dark:text-coral-400 font-semibold'>Naipe: " . htmlspecialchars($f['target_voice_type']) . "</span>";
                                $target_text_search = "naipe " . mb_strtolower($f['target_voice_type']);
                            } elseif ($f['target_type'] === 'member') {
                                $nameToShow = $user['role'] === 'membro' ? 'Você' : ($f['member_name'] ?? 'Membro');
                                $target_label = "<span class='px-2.5 py-0.5 rounded-full text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-950/20 dark:text-indigo-400 font-semibold'>Membro: " . htmlspecialchars($nameToShow) . "</span>";
                                $target_text_search = "membro " . mb_strtolower($nameToShow);
                            }
                            
                            $searchContent = mb_strtolower($f['title'] . ' ' . $f['original_name'] . ' ' . $f['file_type'] . ' ' . $target_text_search . ' ' . $f['uploader_name']);
                        ?>
                            <tr class="file-item-row hover:bg-slate-50 dark:hover:bg-slate-800/50" data-search="<?= htmlspecialchars($searchContent) ?>">
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="flex items-center gap-3">
                                        <?php if ($meta['is_media']): ?>
                                            <button type="button"
                                                    onclick="openMediaPlayer(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, '<?= $meta['media_type'] ?>', '<?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>')"
                                                    class="text-2xl p-1 bg-coral-50 text-coral-600 dark:bg-coral-950/30 dark:text-coral-400 rounded-xl hover:scale-110 transition-transform cursor-pointer" title="Reproduzir">
                                                <?= $meta['icon'] ?>
                                            </button>
                                            <div>
                                                <button type="button"
                                                        onclick="openMediaPlayer(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, '<?= $meta['media_type'] ?>', '<?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>')"
                                                        class="text-left font-bold text-slate-900 dark:text-white hover:text-coral-500 dark:hover:text-coral-400 transition-colors cursor-pointer">
                                                    <?= htmlspecialchars($f['title']) ?>
                                                </button>
                                                <div class="text-[10px] text-slate-400 truncate max-w-xs" title="<?= htmlspecialchars($f['original_name']) ?>">
                                                    <?= htmlspecialchars($f['original_name']) ?>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <a href="files.php?action=download&mode=view&id=<?= $f['id'] ?>" target="_blank"
                                               class="text-2xl p-1 bg-slate-50 dark:bg-slate-900 rounded-xl hover:scale-110 transition-transform" title="Abrir / Visualizar">
                                                <?= $meta['icon'] ?>
                                            </a>
                                            <div>
                                                <a href="files.php?action=download&mode=view&id=<?= $f['id'] ?>" target="_blank"
                                                   class="font-bold text-slate-900 dark:text-white hover:text-coral-500 dark:hover:text-coral-400 transition-colors">
                                                    <?= htmlspecialchars($f['title']) ?>
                                                </a>
                                                <div class="text-[10px] text-slate-400 truncate max-w-xs" title="<?= htmlspecialchars($f['original_name']) ?>">
                                                    <?= htmlspecialchars($f['original_name']) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
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
                                <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-semibold space-x-1.5">
                                    <?php if ($meta['is_media']): ?>
                                        <button type="button"
                                                onclick="openMediaPlayer(<?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['title']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($f['original_name']), ENT_QUOTES) ?>, '<?= $meta['media_type'] ?>', '<?= htmlspecialchars($meta['label'], ENT_QUOTES) ?>')"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-sm transition-all active:scale-95 cursor-pointer"
                                                title="Reproduzir">
                                            <span>▶️</span> Play
                                        </button>
                                    <?php else: ?>
                                        <a href="files.php?action=download&mode=view&id=<?= $f['id'] ?>" target="_blank"
                                           class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 dark:bg-slate-700 dark:hover:bg-slate-600 dark:text-slate-300 transition-colors"
                                           title="Abrir no navegador">
                                            👁️ Abrir
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="files.php?action=download&mode=download&id=<?= $f['id'] ?>"
                                       class="px-2.5 py-1.5 rounded-lg bg-coral-50 hover:bg-coral-100 text-coral-600 dark:bg-coral-950/20 dark:text-coral-400 transition-colors"
                                       title="Baixar arquivo">
                                        📥 Baixar
                                    </a>
                                    
                                    <?php if (has_permission('manage_files')): ?>
                                        <a href="files.php?action=delete&id=<?= $f['id'] ?><?= is_superadmin() ? '&choir_id='.$choir_id : '' ?>"
                                           onclick="return confirm('Deseja realmente excluir este arquivo compartilhado? Ele será removido permanentemente de nossos servidores.')"
                                           class="px-2.5 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 dark:bg-red-950/20 dark:text-red-400 transition-colors"
                                           title="Excluir">
                                            🗑️
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
<?php if (has_permission('manage_files') && $choir_id > 0): ?>
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
                        <label for="shared_file" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Arquivo * (Máx. 20 MB)</label>
                        <input type="file" name="shared_file" id="shared_file" required accept=".pdf,.txt,.jpg,.jpeg,.png,.bmp,.mp4,.webm,.mp3,.ogg,.wav,.m4a"
                               onchange="validateFileSize(this)"
                               class="w-full px-2 py-1.5 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg dark:text-white file:mr-2 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-coral-100 file:text-coral-700 hover:file:bg-coral-200">
                        <p class="text-[10px] text-slate-400 mt-1">Formatos aceitos: Documentos (PDF, TXT), Imagens (JPG, PNG, BMP), Áudios (MP3, OGG, WAV) e Vídeos (MP4, WEBM). Tamanho máximo: <strong>20 MB</strong>.</p>
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

    function validateFileSize(input) {
        const maxBytes = 20 * 1024 * 1024; // 20 MB
        if (input.files && input.files[0]) {
            if (input.files[0].size > maxBytes) {
                alert('Atenção: O arquivo selecionado possui ' + (input.files[0].size / (1024 * 1024)).toFixed(2) + ' MB, o que ultrapassa o limite permitido de 20 MB.');
                input.value = '';
            }
        }
    }
    </script>
<?php endif; ?>

<!-- ==============================================
     MODAL MINI PLAYER DE ÁUDIO E VÍDEO
     ============================================== -->
<div id="modal-media-player" class="fixed inset-0 z-50 overflow-y-auto hidden" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
        <!-- Backdrop com blur -->
        <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm transition-opacity" onclick="closeMediaPlayer()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>

        <!-- Card do Mini Player -->
        <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-3xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle w-full max-w-xl border border-slate-100 dark:border-slate-700/60 p-5 sm:p-6 relative z-10">
            <!-- Topo: Ícone, Título, Badge e Botão Fechar -->
            <div class="flex items-start justify-between gap-3 mb-4">
                <div class="flex items-center gap-3 min-w-0">
                    <span id="player-icon" class="w-10 h-10 rounded-2xl bg-coral-50 dark:bg-coral-950/40 text-coral-600 dark:text-coral-400 flex items-center justify-center text-xl shrink-0">
                        🎵
                    </span>
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 id="player-title" class="font-bold font-outfit text-slate-900 dark:text-white text-base truncate">
                                Nome do Arquivo
                            </h3>
                            <span id="player-badge" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 dark:bg-indigo-950/40 dark:text-indigo-400 uppercase tracking-wider shrink-0">
                                ÁUDIO
                            </span>
                        </div>
                        <p id="player-filename" class="text-xs text-slate-400 truncate mt-0.5">
                            arquivo.mp3
                        </p>
                    </div>
                </div>
                <button type="button" onclick="closeMediaPlayer()" 
                        class="w-8 h-8 rounded-full flex items-center justify-center text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700/50 transition-colors shrink-0"
                        title="Fechar player (Esc)">
                    ✕
                </button>
            </div>

            <!-- Container do Player de Vídeo -->
            <div id="player-video-container" class="hidden mb-4">
                <div class="bg-black rounded-2xl overflow-hidden shadow-inner flex items-center justify-center aspect-video max-h-[60vh]">
                    <video id="player-video-element" controls playsinline class="w-full h-full object-contain">
                        Seu navegador não suporta a tag de vídeo.
                    </video>
                </div>
            </div>

            <!-- Container do Player de Áudio -->
            <div id="player-audio-container" class="hidden mb-4">
                <div class="p-6 rounded-2xl bg-gradient-to-br from-indigo-50/70 to-slate-50 dark:from-slate-900/60 dark:to-indigo-950/20 border border-indigo-100/60 dark:border-indigo-900/40 flex flex-col items-center justify-center text-center">
                    <div class="w-16 h-16 rounded-full bg-gradient-to-tr from-coral-500 to-rose-500 text-white flex items-center justify-center text-2xl shadow-lg shadow-coral-500/25 mb-4 animate-pulse">
                        🎵
                    </div>
                    <audio id="player-audio-element" controls class="w-full">
                        Seu navegador não suporta o elemento de áudio.
                    </audio>
                </div>
            </div>

            <!-- Rodapé: Ações (Baixar, Concluído) -->
            <div class="flex items-center justify-between gap-3 pt-3 border-t border-slate-100 dark:border-slate-700/50">
                <a id="player-download-btn" href="#"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-xs font-semibold text-slate-700 dark:text-slate-200 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 transition-colors">
                    <span>📥</span> Baixar arquivo
                </a>
                <button type="button" onclick="closeMediaPlayer()"
                        class="px-4 py-2 rounded-xl text-xs font-semibold text-white bg-slate-800 hover:bg-slate-900 dark:bg-slate-700 dark:hover:bg-slate-600 transition-colors">
                    Concluído
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openMediaPlayer(id, title, originalName, mediaType, label) {
    const modal = document.getElementById('modal-media-player');
    const titleEl = document.getElementById('player-title');
    const filenameEl = document.getElementById('player-filename');
    const badgeEl = document.getElementById('player-badge');
    const iconEl = document.getElementById('player-icon');
    const downloadBtn = document.getElementById('player-download-btn');
    
    const videoContainer = document.getElementById('player-video-container');
    const audioContainer = document.getElementById('player-audio-container');
    const videoEl = document.getElementById('player-video-element');
    const audioEl = document.getElementById('player-audio-element');

    if (!modal) return;

    titleEl.textContent = title;
    filenameEl.textContent = '📎 ' + originalName;
    badgeEl.textContent = label || (mediaType === 'video' ? 'Vídeo' : 'Áudio');
    iconEl.textContent = mediaType === 'video' ? '🎬' : '🎵';
    downloadBtn.href = 'files.php?action=download&mode=download&id=' + id;

    const streamUrl = 'files.php?action=download&mode=view&id=' + id;

    if (mediaType === 'video') {
        audioEl.pause();
        audioEl.src = '';
        audioContainer.classList.add('hidden');

        videoContainer.classList.remove('hidden');
        videoEl.src = streamUrl;
        videoEl.load();
        videoEl.play().catch(e => console.log('Autoplay prevenido:', e));
    } else {
        videoEl.pause();
        videoEl.src = '';
        videoContainer.classList.add('hidden');

        audioContainer.classList.remove('hidden');
        audioEl.src = streamUrl;
        audioEl.load();
        audioEl.play().catch(e => console.log('Autoplay prevenido:', e));
    }

    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
}

function closeMediaPlayer() {
    const modal = document.getElementById('modal-media-player');
    const videoEl = document.getElementById('player-video-element');
    const audioEl = document.getElementById('player-audio-element');

    if (videoEl) {
        videoEl.pause();
        videoEl.src = '';
    }
    if (audioEl) {
        audioEl.pause();
        audioEl.src = '';
    }
    if (modal) {
        modal.classList.add('hidden');
    }
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('modal-media-player');
        if (modal && !modal.classList.contains('hidden')) {
            closeMediaPlayer();
        }
    }
});
    function filterFilesList() {
        const input = document.getElementById('file_search_input');
        const clearBtn = document.getElementById('file_search_clear');
        const emptyState = document.getElementById('files_search_empty');
        const countNum = document.getElementById('file_count_num');
        
        if (!input) return;
        const query = input.value.trim().toLowerCase();
        
        if (clearBtn) {
            if (query.length > 0) {
                clearBtn.classList.remove('hidden');
            } else {
                clearBtn.classList.add('hidden');
            }
        }
        
        let visibleCount = 0;
        
        // Filtrar cards mobile
        const cards = document.querySelectorAll('.file-item-card');
        cards.forEach(card => {
            const text = card.getAttribute('data-search') || '';
            if (!query || text.includes(query)) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        
        // Filtrar linhas desktop
        const rows = document.querySelectorAll('.file-item-row');
        rows.forEach(row => {
            const text = row.getAttribute('data-search') || '';
            if (!query || text.includes(query)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
        
        if (countNum) {
            countNum.textContent = visibleCount;
        }
        
        if (emptyState) {
            if (visibleCount === 0 && (cards.length > 0 || rows.length > 0)) {
                emptyState.classList.remove('hidden');
            } else {
                emptyState.classList.add('hidden');
            }
        }
    }

    function clearFileSearch() {
        const input = document.getElementById('file_search_input');
        if (input) {
            input.value = '';
            filterFilesList();
            input.focus();
        }
    }
    </script>
<?php
require_once __DIR__ . '/layout_footer.php';
?>
