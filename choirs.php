<?php
// choirs.php
require_once __DIR__ . '/config.php';
require_role('superadmin'); // Apenas superadmin cadastrar novos corais

$error = null;
$success = null;
$action = $_GET['action'] ?? 'list';
$edit_id = intval($_GET['id'] ?? 0);

// Inserir / Atualizar
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    $logo_filename = null;
    if ($action === 'edit' && $edit_id > 0) {
        $stmtLogo = $pdo->prepare("SELECT logo FROM choirs WHERE id = ?");
        $stmtLogo->execute([$edit_id]);
        $logo_filename = $stmtLogo->fetchColumn();
    }
    
    // Processar upload de logo
    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $fileInfo = $_FILES['logo_file'];
        $ext = strtolower(pathinfo($fileInfo['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'svg'])) {
            $newFilename = uniqid('logo_', true) . '.' . $ext;
            $uploadDir = __DIR__ . '/uploads';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $destination = $uploadDir . '/' . $newFilename;
            if (move_uploaded_file($fileInfo['tmp_name'], $destination)) {
                // Remover logo antiga
                if ($logo_filename && file_exists($uploadDir . '/' . $logo_filename)) {
                    @unlink($uploadDir . '/' . $logo_filename);
                }
                $logo_filename = $newFilename;
            } else {
                $error = 'Falha ao salvar a logomarca enviada.';
            }
        } else {
            $error = 'Formato de logomarca inválido. Envie JPG, JPEG, PNG, GIF ou SVG.';
        }
    }
    
    $code = strtoupper(trim($_POST['code'] ?? ''));
    if (empty($name)) {
        $error = 'O nome do coral é obrigatório.';
    } else {
        // Validar/Gerar código do coral
        if (empty($code)) {
            $chars = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
            do {
                $code = '';
                for ($i = 0; $i < 8; $i++) {
                    $code .= $chars[rand(0, strlen($chars) - 1)];
                }
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM choirs WHERE code = ?");
                $stmtCheck->execute([$code]);
                $exists = $stmtCheck->fetchColumn() > 0;
            } while ($exists);
        } else {
            // Se foi digitado, validar formato e unicidade
            if (!preg_match('/^[A-Z0-9_-]{3,20}$/', $code)) {
                $error = 'O código do coral deve ter entre 3 e 20 caracteres (apenas letras maiúsculas, números, - e _).';
            } else {
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM choirs WHERE code = ? AND id != ?");
                $stmtCheck->execute([$code, $edit_id]);
                if ($stmtCheck->fetchColumn() > 0) {
                    $error = 'Este código de coral já está em uso por outro cadastro.';
                }
            }
        }
    }
    
    if (!$error) {
        try {
            if ($action === 'new') {
                $stmt = $pdo->prepare("INSERT INTO choirs (name, code, description, logo) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $code, $description, $logo_filename]);
                set_flash_message('success', "Coral '$name' cadastrado com sucesso.");
                header("Location: choirs.php");
                exit;
            } elseif ($action === 'edit' && $edit_id > 0) {
                $stmt = $pdo->prepare("UPDATE choirs SET name = ?, code = ?, description = ?, logo = ? WHERE id = ?");
                $stmt->execute([$name, $code, $description, $logo_filename, $edit_id]);
                set_flash_message('success', "Coral '$name' atualizado com sucesso.");
                header("Location: choirs.php");
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Erro no banco de dados: ' . $e->getMessage();
        }
    }
}

// Excluir
if ($action === 'delete' && $edit_id > 0) {
    try {
        // Desvincular usuários primeiro (feito por ON DELETE SET NULL na tabela users)
        $stmt = $pdo->prepare("DELETE FROM choirs WHERE id = ?");
        $stmt->execute([$edit_id]);
        set_flash_message('success', "Coral excluído com sucesso.");
    } catch (PDOException $e) {
        set_flash_message('error', "Erro ao excluir coral: " . $e->getMessage());
    }
    header("Location: choirs.php");
    exit;
}

// Resetar Coral
if ($action === 'reset' && $edit_id > 0) {
    try {
        reset_choir_registry($pdo, $edit_id);
        set_flash_message('success', "Cadastro do coral reiniciado com sucesso! Todos os faturamentos, arquivos, comprovantes, cantores e colaboradores foram removidos, restando apenas os administradores.");
    } catch (Exception $e) {
        set_flash_message('error', "Erro ao reiniciar cadastro do coral: " . $e->getMessage());
    }
    header("Location: choirs.php");
    exit;
}

// Buscar dados se for edição
$choir_data = null;
if ($action === 'edit' && $edit_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM choirs WHERE id = ?");
    $stmt->execute([$edit_id]);
    $choir_data = $stmt->fetch();
    if (!$choir_data) {
        set_flash_message('error', 'Coral não encontrado.');
        header("Location: choirs.php");
        exit;
    }
}

// Listar todos os corais
$choirs = [];
if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT c.*, 
            (SELECT COUNT(*) FROM users u WHERE u.choir_id = c.id AND u.role != 'membro') as staff_count,
            (SELECT COUNT(*) FROM users u WHERE u.choir_id = c.id AND u.role = 'membro') as singer_count
            FROM choirs c ORDER BY c.id DESC");
        $choirs = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = 'Erro ao listar corais: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/layout_header.php';
?>

<div class="flex justify-between items-center mb-6">
    <div>
        <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
            <span>🎼</span> Corais Musicais
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">Gerenciamento dos corais atendidos pela plataforma SaaS.</p>
    </div>
    
    <?php if ($action === 'list'): ?>
        <a href="choirs.php?action=new" 
           class="px-3.5 py-2 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
            + Novo Coral
        </a>
    <?php else: ?>
        <a href="choirs.php" 
           class="px-3.5 py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-white font-semibold rounded-lg text-xs transition-all">
            Voltar para a Lista
        </a>
    <?php endif; ?>
</div>

<?php if ($error): ?>
    <div class="mb-4 p-3 rounded-lg text-sm bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<!-- ==============================================
     FORMULÁRIO: CADASTRAR / EDITAR
     ============================================== -->
<?php if ($action === 'new' || $action === 'edit'): ?>
    <div class="max-w-xl mx-auto bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
        <h2 class="text-lg font-bold font-outfit text-slate-800 dark:text-white mb-4">
            <?= $action === 'edit' ? 'Editar Coral' : 'Cadastrar Novo Coral' ?>
        </h2>
        
        <form action="choirs.php?action=<?= $action ?><?= $action === 'edit' ? '&id=' . $edit_id : '' ?>" method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label for="name" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Nome do Coral *</label>
                <input type="text" name="name" id="name" required value="<?= htmlspecialchars($choir_data['name'] ?? '') ?>"
                       class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                       placeholder="Ex: Coral Sol Maior">
            </div>
            
            <div>
                <label for="code" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Código Único do Coral (para Links)</label>
                <input type="text" name="code" id="code" value="<?= htmlspecialchars($choir_data['code'] ?? '') ?>"
                       class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all uppercase"
                       placeholder="Deixe em branco para auto-gerar.">
                <span class="text-[10px] text-slate-400 block mt-1">Este código é utilizado para gerar links personalizados de Login e Auto-Cadastro.</span>
            </div>
            
            <div>
                <label for="description" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Descrição</label>
                <textarea name="description" id="description" rows="4"
                          class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"
                          placeholder="Fale brevemente sobre o coral, repertório principal, ensaios, etc."><?= htmlspecialchars($choir_data['description'] ?? '') ?></textarea>
            </div>
            
            <div>
                <label for="logo_file" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Logomarca do Coral</label>
                <?php if ($action === 'edit' && !empty($choir_data['logo'])): ?>
                    <div class="mb-2 flex items-center gap-3 bg-slate-50 dark:bg-slate-900/60 p-2.5 rounded-lg border border-slate-100 dark:border-slate-800">
                        <img src="uploads/<?= htmlspecialchars($choir_data['logo']) ?>" class="h-10 w-10 object-contain rounded bg-white p-1 border border-slate-200" alt="Logo atual">
                        <span class="text-[10px] text-slate-500">Logo atual. Para alterar, envie um novo arquivo abaixo.</span>
                    </div>
                <?php endif; ?>
                <input type="file" name="logo_file" id="logo_file" accept=".jpg,.jpeg,.png,.gif,.svg"
                       class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-coral-50 file:text-coral-700 hover:file:bg-coral-100 dark:file:bg-slate-900 dark:file:text-slate-300 dark:hover:file:bg-slate-800 transition-all">
            </div>
            
            <div class="flex justify-end gap-2 pt-4">
                <a href="choirs.php"
                   class="px-4 py-2.5 rounded-lg border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 font-semibold text-xs hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                    Cancelar
                </a>
                <button type="submit"
                        class="px-4 py-2.5 rounded-lg bg-coral-500 hover:bg-coral-600 text-white font-semibold text-xs shadow-md transition-colors">
                    <?= $action === 'edit' ? 'Salvar Alterações' : 'Cadastrar Coral' ?>
                </button>
            </div>
        </form>
    </div>

<!-- ==============================================
     LISTA DE CORAIS
     ============================================== -->
<?php else: ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-sm border border-slate-100 dark:border-slate-700/50 overflow-hidden">
        <?php if (empty($choirs)): ?>
            <div class="text-center py-12 text-slate-400">
                Nenhum coral cadastrado. Clique em "+ Novo Coral" para começar.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 dark:divide-slate-700/50">
                    <thead class="bg-slate-50 dark:bg-slate-900/60">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Nome</th>
                            <th class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Descrição</th>
                            <th class="px-6 py-3 text-center text-xs font-semibold text-slate-500 uppercase tracking-wider">Staff / Cantores</th>
                            <th class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700/30">
                        <?php foreach ($choirs as $c): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500">#<?= $c['id'] ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-800 dark:text-white flex items-center gap-3">
                                    <?php if (!empty($c['logo'])): ?>
                                        <img src="uploads/<?= htmlspecialchars($c['logo']) ?>" class="h-8 w-8 object-contain rounded bg-white p-0.5 border border-slate-200" alt="Logo">
                                    <?php else: ?>
                                        <span class="h-8 w-8 rounded-full bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-300 flex items-center justify-center text-xs font-bold font-outfit uppercase">
                                            <?= mb_substr($c['name'], 0, 2) ?>
                                        </span>
                                    <?php endif; ?>
                                    <div class="flex flex-col">
                                        <div class="flex items-center gap-2">
                                            <span><?= htmlspecialchars($c['name']) ?></span>
                                            <?php if (!empty($c['code'])): ?>
                                                <span class="px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 text-[10px] font-mono font-bold uppercase tracking-wider">
                                                    <?= htmlspecialchars($c['code']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($c['code'])): ?>
                                            <div class="flex gap-2 mt-1 text-[10px] text-slate-400 font-normal">
                                                <button onclick="copyToClipboard('<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/www/ecoral/login.php?' . $c['code'] ?>', this)" 
                                                        class="hover:text-coral-500 font-semibold underline focus:outline-none">
                                                    Copiar Link Login
                                                </button>
                                                <span>•</span>
                                                <button onclick="copyToClipboard('<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/www/ecoral/register.php?code=' . $c['code'] ?>', this)" 
                                                        class="hover:text-coral-500 font-semibold underline focus:outline-none">
                                                    Copiar Link Cadastro
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate"><?= htmlspecialchars($c['description'] ?? 'Sem descrição.') ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-slate-500">
                                    <span class="px-2 py-1 bg-indigo-50 dark:bg-indigo-950/20 text-indigo-600 dark:text-indigo-400 font-semibold rounded text-xs"><?= $c['staff_count'] ?> admins</span>
                                    <span class="px-2 py-1 bg-teal-50 dark:bg-teal-950/20 text-teal-600 dark:text-teal-400 font-semibold rounded text-xs"><?= $c['singer_count'] ?> cantores</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-xs font-medium space-x-2">
                                    <a href="choirs.php?action=edit&id=<?= $c['id'] ?>" class="text-coral-500 hover:text-coral-600 font-bold">Editar</a>
                                    <a href="choirs.php?action=reset&id=<?= $c['id'] ?>" onclick="return confirm('ATENÇÃO: Esta ação é irreversível! Todos os dados de cantores, equipe, comprovantes, faturamento e arquivos deste coral serão excluídos permanentemente, mantendo apenas os administradores. Deseja prosseguir?')" class="text-amber-500 hover:text-amber-600 font-bold">Resetar</a>
                                    <a href="choirs.php?action=delete&id=<?= $c['id'] ?>" onclick="return confirm('Deseja realmente excluir este coral? Todos os dados financeiros e cobranças vinculadas serão excluídos permanentemente. Usuários serão desvinculados.')" class="text-red-500 hover:text-red-600 font-bold">Excluir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
function copyToClipboard(text, btnElement) {
    const originalText = btnElement.innerText;
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showFeedback();
        }, function() {
            fallbackCopy();
        });
    } else {
        fallbackCopy();
    }
    
    function fallbackCopy() {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
            showFeedback();
        } catch (err) {
            console.error('Fallback copy failed', err);
        }
        document.body.removeChild(textArea);
    }
    
    function showFeedback() {
        btnElement.innerText = "Copiado!";
        btnElement.classList.add("text-emerald-500");
        setTimeout(function() {
            btnElement.innerText = originalText;
            btnElement.classList.remove("text-emerald-500");
        }, 1500);
    }
}
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
