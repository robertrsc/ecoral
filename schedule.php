<?php
// schedule.php
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

// AJAX: Buscar lista de respostas de presença
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'get_event_responses') {
    header('Content-Type: application/json; charset=utf-8');
    $event_id = intval($_GET['event_id'] ?? 0);
    
    if ($event_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID do evento inválido.']);
        exit;
    }
    
    try {
        // Verificar se pertence ao coral do contexto
        $stmtCheck = $pdo->prepare("SELECT choir_id, title FROM events WHERE id = ?");
        $stmtCheck->execute([$event_id]);
        $event = $stmtCheck->fetch();
        
        if (!$event || ($event['choir_id'] != $choir_id && !is_superadmin())) {
            echo json_encode(['success' => false, 'error' => 'Acesso negado ou evento não encontrado.']);
            exit;
        }
        
        // Buscar confirmados ("Irei")
        $stmtG = $pdo->prepare("SELECT u.name, u.voice_type, er.updated_at 
                                FROM event_responses er 
                                JOIN users u ON er.member_id = u.id 
                                WHERE er.event_id = ? AND er.response = 'going' 
                                ORDER BY u.name ASC");
        $stmtG->execute([$event_id]);
        $going = $stmtG->fetchAll();
        
        // Buscar ausentes ("Não irei")
        $stmtN = $pdo->prepare("SELECT u.name, u.voice_type, er.updated_at 
                                FROM event_responses er 
                                JOIN users u ON er.member_id = u.id 
                                WHERE er.event_id = ? AND er.response = 'not_going' 
                                ORDER BY u.name ASC");
        $stmtN->execute([$event_id]);
        $not_going = $stmtN->fetchAll();
        
        // Formatar datas
        foreach ($going as &$g) {
            $g['formatted_date'] = date('d/m H:i', strtotime($g['updated_at']));
        }
        unset($g);
        
        foreach ($not_going as &$n) {
            $n['formatted_date'] = date('d/m H:i', strtotime($n['updated_at']));
        }
        unset($n);
        
        echo json_encode([
            'success' => true,
            'event_title' => $event['title'],
            'going' => $going,
            'not_going' => $not_going
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ----------------------------------------------
// PROCESSAR AÇÕES DO FORMULÁRIO (POST/GET)
// ----------------------------------------------

// 1. Membro respondendo presença
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'respond') {
    $event_id = intval($_POST['event_id'] ?? 0);
    $response_val = trim($_POST['response'] ?? '');
    
    if ($event_id > 0 && in_array($response_val, ['going', 'not_going'])) {
        try {
            // Verificar visibilidade do evento antes de aceitar resposta
            $stmtCheck = $pdo->prepare("SELECT choir_id, target_type, target_voice_type, target_member_id FROM events WHERE id = ?");
            $stmtCheck->execute([$event_id]);
            $eventObj = $stmtCheck->fetch();
            
            if ($eventObj && $eventObj['choir_id'] == $user['choir_id']) {
                $visible = false;
                if ($eventObj['target_type'] === 'all') {
                    $visible = true;
                } elseif ($eventObj['target_type'] === 'voice_type' && $eventObj['target_voice_type'] === $user['voice_type']) {
                    $visible = true;
                } elseif ($eventObj['target_type'] === 'member' && $eventObj['target_member_id'] == $user['id']) {
                    $visible = true;
                }
                
                if ($visible) {
                    $stmt = $pdo->prepare("INSERT INTO event_responses (event_id, member_id, response) VALUES (?, ?, ?)
                                           ON DUPLICATE KEY UPDATE response = ?");
                    $stmt->execute([$event_id, $user['id'], $response_val, $response_val]);
                    set_flash_message('success', 'Sua presença foi informada com sucesso!');
                } else {
                    set_flash_message('error', 'Você não tem permissão para responder a este evento.');
                }
            } else {
                set_flash_message('error', 'Evento não encontrado.');
            }
        } catch (Exception $e) {
            set_flash_message('error', 'Erro ao salvar resposta: ' . $e->getMessage());
        }
    }
    header("Location: schedule.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// Ações Administrativas
if (is_admin_user()) {
    // 2. Criar compromisso
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new') {
        $title = trim($_POST['title'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $target_type = trim($_POST['target_type'] ?? 'all');
        $target_voice_type = trim($_POST['target_voice_type'] ?? null);
        if (empty($target_voice_type)) $target_voice_type = null;
        $target_member_id = intval($_POST['target_member_id'] ?? 0);
        if ($target_member_id === 0) $target_member_id = null;
        
        if (empty($title) || empty($start_time) || empty($end_time) || $choir_id <= 0) {
            set_flash_message('error', 'Por favor, preencha todos os campos obrigatórios (*).');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO events (choir_id, title, start_time, end_time, location, notes, target_type, target_voice_type, target_member_id)
                                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$choir_id, $title, $start_time, $end_time, $location, $notes, $target_type, $target_voice_type, $target_member_id]);
                set_flash_message('success', 'Compromisso criado com sucesso!');
            } catch (Exception $e) {
                set_flash_message('error', 'Erro ao criar compromisso: ' . $e->getMessage());
            }
        }
        header("Location: schedule.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    
    // 3. Editar compromisso
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
        $event_id = intval($_POST['event_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $target_type = trim($_POST['target_type'] ?? 'all');
        $target_voice_type = trim($_POST['target_voice_type'] ?? null);
        if (empty($target_voice_type)) $target_voice_type = null;
        $target_member_id = intval($_POST['target_member_id'] ?? 0);
        if ($target_member_id === 0) $target_member_id = null;
        
        if (empty($title) || empty($start_time) || empty($end_time) || $event_id <= 0) {
            set_flash_message('error', 'Por favor, preencha todos os campos obrigatórios (*).');
        } else {
            try {
                // Verificar se pertence ao coral
                $stmtCheck = $pdo->prepare("SELECT choir_id FROM events WHERE id = ?");
                $stmtCheck->execute([$event_id]);
                if ($stmtCheck->fetchColumn() != $choir_id && !is_superadmin()) {
                    set_flash_message('error', 'Acesso negado.');
                } else {
                    $stmt = $pdo->prepare("UPDATE events SET title = ?, start_time = ?, end_time = ?, location = ?, notes = ?, target_type = ?, target_voice_type = ?, target_member_id = ? WHERE id = ?");
                    $stmt->execute([$title, $start_time, $end_time, $location, $notes, $target_type, $target_voice_type, $target_member_id, $event_id]);
                    set_flash_message('success', 'Compromisso atualizado com sucesso!');
                }
            } catch (Exception $e) {
                set_flash_message('error', 'Erro ao editar compromisso: ' . $e->getMessage());
            }
        }
        header("Location: schedule.php" . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
        exit;
    }
    
    // 4. Excluir compromisso
    if (isset($_GET['action']) && $_GET['action'] === 'delete') {
        $event_id = intval($_GET['id'] ?? 0);
        if ($event_id > 0) {
            try {
                // Verificar se pertence ao coral
                $stmtCheck = $pdo->prepare("SELECT choir_id FROM events WHERE id = ?");
                $stmtCheck->execute([$event_id]);
                if ($stmtCheck->fetchColumn() != $choir_id && !is_superadmin()) {
                    set_flash_message('error', 'Acesso negado.');
                } else {
                    $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    set_flash_message('success', 'Compromisso removido com sucesso.');
                }
            } catch (PDOException $e) {
                set_flash_message('error', 'Erro ao excluir compromisso: ' . $e->getMessage());
            }
        }
        header("Location: schedule.php");
        exit;
    }
}

// ----------------------------------------------
// CARREGAR DADOS DE COMPROMISSOS E FILTROS
// ----------------------------------------------
$tab = $_GET['tab'] ?? 'upcoming'; // 'upcoming' ou 'past'
$choirs = [];

if (is_superadmin()) {
    $choirs = $pdo->query("SELECT id, name FROM choirs ORDER BY name ASC")->fetchAll();
}

$members = [];
if ($choir_id > 0) {
    // Carregar membros ativos do coral para o seletor de atribuição
    $stmt = $pdo->prepare("SELECT id, name, voice_type FROM users WHERE choir_id = ? AND role = 'membro' AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$choir_id]);
    $members = $stmt->fetchAll();
}

// Carregar eventos com base nos filtros
$events = [];
if ($choir_id > 0) {
    $params = [];
    $today = date('Y-m-d H:i:s');
    
    if ($user['role'] === 'membro') {
        // Cantor vê apenas o que o atinge
        $sql = "SELECT e.*, er.response as member_response,
                (SELECT COUNT(*) FROM event_responses WHERE event_id = e.id AND response = 'going') as count_going,
                (SELECT COUNT(*) FROM event_responses WHERE event_id = e.id AND response = 'not_going') as count_not_going
                FROM events e
                LEFT JOIN event_responses er ON e.id = er.event_id AND er.member_id = :user_id
                WHERE e.choir_id = :choir_id
                  AND (
                      e.target_type = 'all'
                      OR (e.target_type = 'voice_type' AND e.target_voice_type = :voice_type)
                      OR (e.target_type = 'member' AND e.target_member_id = :user_id)
                  )";
        $params[':user_id'] = $user['id'];
        $params[':choir_id'] = $choir_id;
        $params[':voice_type'] = $user['voice_type'];
    } else {
        // Admin vê tudo do coral
        $sql = "SELECT e.*,
                (SELECT COUNT(*) FROM event_responses WHERE event_id = e.id AND response = 'going') as count_going,
                (SELECT COUNT(*) FROM event_responses WHERE event_id = e.id AND response = 'not_going') as count_not_going,
                u.name as target_member_name
                FROM events e
                LEFT JOIN users u ON e.target_member_id = u.id
                WHERE e.choir_id = :choir_id";
        $params[':choir_id'] = $choir_id;
    }
    
    if ($tab === 'past') {
        $sql .= " AND e.end_time < :today ORDER BY e.start_time DESC";
    } else {
        $sql .= " AND e.end_time >= :today ORDER BY e.start_time ASC";
    }
    $params[':today'] = $today;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $events = $stmt->fetchAll();
}

require_once __DIR__ . '/layout_header.php';
?>

<!-- Seletor de Coral para Superadmin -->
<?php if (is_superadmin()): ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-4 shadow-sm border border-slate-100 dark:border-slate-700/50 mb-6">
        <form action="schedule.php" method="GET" class="flex flex-col sm:flex-row items-end gap-3">
            <div class="flex-1 w-full">
                <label for="choir_id_select" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Coral para Visualizar a Agenda</label>
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
            <span>📅</span> Agenda de Compromissos
        </h1>
        <p class="text-xs text-slate-500 dark:text-slate-400">
            Acompanhe ensaios, apresentações e eventos do seu coral.
        </p>
    </div>
    
    <?php if (is_admin_user() && $choir_id > 0): ?>
        <button onclick="openNewEventModal()"
                class="px-4 py-2.5 bg-coral-500 hover:bg-coral-600 text-white font-semibold rounded-lg text-xs shadow-md transition-all">
            + Novo Evento
        </button>
    <?php endif; ?>
</div>

<?php if ($choir_id == 0): ?>
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-8 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50">
        Selecione um coral para visualizar a agenda de compromissos.
    </div>
<?php else: ?>
    <!-- Barra de Abas (Upcoming / Past) -->
    <div class="border-b border-slate-200 dark:border-slate-700 mb-6">
        <nav class="flex space-x-6" aria-label="Abas de Agenda">
            <a href="schedule.php?tab=upcoming<?= is_superadmin() ? '&choir_id='.$choir_id : '' ?>" 
               class="pb-3 px-1 border-b-2 font-bold text-sm transition-all flex items-center gap-1.5 <?= $tab === 'upcoming' ? 'border-coral-500 text-coral-500' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-300' ?>">
                <span>📅</span> Próximos Compromissos
            </a>
            <a href="schedule.php?tab=past<?= is_superadmin() ? '&choir_id='.$choir_id : '' ?>" 
               class="pb-3 px-1 border-b-2 font-bold text-sm transition-all flex items-center gap-1.5 <?= $tab === 'past' ? 'border-coral-500 text-coral-500' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 dark:text-slate-400 dark:hover:text-slate-300' ?>">
                <span>⏱️</span> Compromissos Passados
            </a>
        </nav>
    </div>

    <!-- Lista de Compromissos -->
    <?php if (empty($events)): ?>
        <div class="bg-white dark:bg-slate-800 rounded-2xl p-12 text-center text-slate-500 dark:text-slate-400 border border-slate-100 dark:border-slate-700/50 flex flex-col items-center justify-center min-h-[250px]">
            <div class="w-16 h-16 bg-slate-100 dark:bg-slate-900/60 text-slate-400 rounded-full flex items-center justify-center text-3xl mb-4">
                📅
            </div>
            <h3 class="font-bold text-slate-800 dark:text-white text-base mb-1">Nenhum evento registrado</h3>
            <p class="text-xs text-slate-400 max-w-md">
                Não há compromissos <?= $tab === 'past' ? 'passados' : 'programados para breve' ?> na agenda deste coral.
            </p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($events as $ev): 
                $start = new DateTime($ev['start_time']);
                $end = new DateTime($ev['end_time']);
                $is_past = $end < new DateTime();
                
                // Definir tag de público alvo
                $target_badge = "";
                if ($ev['target_type'] === 'all') {
                    $target_badge = "<span class='px-2 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300 font-semibold'>Público: Todos</span>";
                } elseif ($ev['target_type'] === 'voice_type') {
                    $target_badge = "<span class='px-2 py-0.5 rounded-full text-[10px] bg-coral-100 text-coral-700 dark:bg-coral-950/20 dark:text-coral-400 font-semibold'>Naipe: " . htmlspecialchars($ev['target_voice_type']) . "</span>";
                } elseif ($ev['target_type'] === 'member') {
                    $tgtName = $user['role'] === 'membro' ? 'Você' : ($ev['target_member_name'] ?? 'Membro');
                    $target_badge = "<span class='px-2 py-0.5 rounded-full text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-950/20 dark:text-indigo-400 font-semibold'>Individual: " . htmlspecialchars($tgtName) . "</span>";
                }
            ?>
                <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 shadow-sm border border-slate-100 dark:border-slate-700/50 flex flex-col justify-between hover:shadow-md transition-all">
                    <div>
                        <!-- Cabeçalho do Card -->
                        <div class="flex justify-between items-start gap-4 mb-3">
                            <div class="space-y-1">
                                <h3 class="text-base font-bold font-outfit text-slate-900 dark:text-white leading-tight">
                                    <?= htmlspecialchars($ev['title']) ?>
                                </h3>
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <?= $target_badge ?>
                                    <?php if ($is_past): ?>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] bg-slate-100 text-slate-400 dark:bg-slate-900 dark:text-slate-500">Finalizado</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (is_admin_user() && !$is_past): ?>
                                <!-- Ações de Evento (Admin) -->
                                <div class="flex items-center gap-2">
                                    <button onclick="openEditEventModal(<?= htmlspecialchars(json_encode($ev)) ?>)"
                                            class="text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors font-medium">
                                        Editar
                                    </button>
                                    <span class="text-slate-300">|</span>
                                    <a href="schedule.php?action=delete&id=<?= $ev['id'] ?>"
                                       onclick="return confirm('Deseja realmente remover este compromisso?')"
                                       class="text-xs text-red-500 hover:text-red-600 font-medium">
                                        Excluir
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Dados do Horário -->
                        <div class="space-y-2 text-xs text-slate-600 dark:text-slate-300 mb-4 bg-slate-50/50 dark:bg-slate-900/10 p-3 rounded-xl border border-slate-100/50 dark:border-slate-800/40">
                            <div class="flex items-center gap-2">
                                <span class="text-base">🕒</span>
                                <span>
                                    <strong>Início:</strong> <?= $start->format('d/m/Y \à\s H:i') ?><br>
                                    <strong>Fim:</strong> <?= $end->format('d/m/Y \à\s H:i') ?>
                                </span>
                            </div>
                            <?php if (!empty($ev['location'])): ?>
                                <div class="flex items-center gap-2 pt-1 border-t border-slate-100 dark:border-slate-800/40">
                                    <span class="text-base">📍</span>
                                    <span><strong>Local:</strong> <?= htmlspecialchars($ev['location']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Observações -->
                        <?php if (!empty($ev['notes'])): ?>
                            <p class="text-xs text-slate-500 dark:text-slate-400 mb-6 italic line-clamp-3" title="<?= htmlspecialchars($ev['notes']) ?>">
                                "<?= htmlspecialchars($ev['notes']) ?>"
                            </p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Rodapé do Card (Respostas / Interação) -->
                    <div class="pt-4 border-t border-slate-100 dark:border-slate-700/50 mt-auto">
                        <?php if ($user['role'] === 'membro'): ?>
                            <!-- Visão do Membro: Botões de Interação -->
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-[10px] text-slate-400">Sua participação:</span>
                                
                                <form action="schedule.php" method="POST" class="flex gap-2">
                                    <input type="hidden" name="action" value="respond">
                                    <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                                    
                                    <button type="submit" name="response" value="not_going"
                                            class="px-3.5 py-2 rounded-lg text-xs font-semibold border transition-all flex items-center gap-1
                                            <?= (isset($ev['member_response']) && $ev['member_response'] === 'not_going') 
                                                ? 'bg-red-500 text-white border-red-500 shadow-sm' 
                                                : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-900/50' ?>">
                                        ❌ Não vou
                                    </button>
                                    <button type="submit" name="response" value="going"
                                            class="px-3.5 py-2 rounded-lg text-xs font-semibold border transition-all flex items-center gap-1
                                            <?= (isset($ev['member_response']) && $ev['member_response'] === 'going') 
                                                ? 'bg-green-500 text-white border-green-500 shadow-sm' 
                                                : 'border-slate-200 text-slate-600 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-900/50' ?>">
                                        ✅ Irei
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <!-- Visão do Admin: Contagem de Respostas -->
                            <div class="flex items-center justify-between text-xs">
                                <div class="flex gap-3">
                                    <span class="text-green-600 dark:text-green-400 font-semibold flex items-center gap-1">
                                        <span>Irei:</span> <strong><?= $ev['count_going'] ?></strong>
                                    </span>
                                    <span class="text-red-500 font-semibold flex items-center gap-1">
                                        <span>Não vou:</span> <strong><?= $ev['count_not_going'] ?></strong>
                                    </span>
                                </div>
                                
                                <button onclick="openAttendanceModal(<?= $ev['id'] ?>)"
                                        class="text-xs text-coral-500 hover:text-coral-600 font-bold hover:underline">
                                    Ver Lista
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- ==============================================
     MODAIS ADMINISTRATIVOS (CADASTRAR / EDITAR)
     ============================================== -->
<?php if (is_admin_user() && $choir_id > 0): ?>
    
    <!-- Modal 1: Novo Evento -->
    <div id="modal-new-event" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-slate-900/80 backdrop-blur-sm" onclick="closeNewEventModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white">Novo Evento</h3>
                    <button onclick="closeNewEventModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">❌</button>
                </div>
                
                <form action="schedule.php?choir_id=<?= $choir_id ?>" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="new">
                    
                    <div>
                        <label for="new_title" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Título do Evento *</label>
                        <input type="text" name="title" id="new_title" required placeholder="Ex: Ensaio Geral, Apresentação Especial"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="new_start_time" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Início *</label>
                            <input type="datetime-local" name="start_time" id="new_start_time" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        </div>
                        <div>
                            <label for="new_end_time" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Término *</label>
                            <input type="datetime-local" name="end_time" id="new_end_time" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        </div>
                    </div>
                    
                    <div>
                        <label for="new_location" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Localização (Física ou Link)</label>
                        <input type="text" name="location" id="new_location" placeholder="Ex: Igreja Matriz, Google Meet"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    
                    <div>
                        <label for="new_notes" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Observações / Detalhes</label>
                        <textarea name="notes" id="new_notes" rows="2" placeholder="Ex: Traje preto completo. Trazer partituras da canção X."
                                  class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"></textarea>
                    </div>
                    
                    <div class="border-t border-slate-100 dark:border-slate-700/50 pt-4">
                        <label for="new_target_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Direcionar Compromisso Para:</label>
                        <select name="target_type" id="new_target_type" onchange="toggleNewTargetSelectors(this.value)"
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all mb-3">
                            <option value="all">Todos os membros do coral</option>
                            <option value="voice_type">Naipe específico</option>
                            <option value="member">Membro individual específico</option>
                        </select>
                        
                        <!-- Seletor de Naipe (Novo) -->
                        <div id="new_target_voice_container" class="hidden">
                            <label for="new_target_voice_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Naipe Alvo</label>
                            <select name="target_voice_type" id="new_target_voice_type"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="Soprano">Soprano</option>
                                <option value="Contralto">Contralto</option>
                                <option value="Tenor">Tenor</option>
                                <option value="Baixo">Baixo</option>
                            </select>
                        </div>
                        
                        <!-- Seletor de Membro (Novo) -->
                        <div id="new_target_member_container" class="hidden">
                            <label for="new_target_member_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Cantor Alvo</label>
                            <select name="target_member_id" id="new_target_member_id"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="0">Selecione...</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['voice_type'] ?? 'Sem naipe') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-2 pt-4">
                        <button type="button" onclick="closeNewEventModal()"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white transition-colors">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-coral-500 hover:bg-coral-600 text-white transition-colors">
                            Criar Evento
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal 2: Editar Evento -->
    <div id="modal-edit-event" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-slate-900/80 backdrop-blur-sm" onclick="closeEditEventModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white">Editar Evento</h3>
                    <button onclick="closeEditEventModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">❌</button>
                </div>
                
                <form action="schedule.php?choir_id=<?= $choir_id ?>" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="event_id" id="edit_event_id">
                    
                    <div>
                        <label for="edit_title" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Título do Evento *</label>
                        <input type="text" name="title" id="edit_title" required
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label for="edit_start_time" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Início *</label>
                            <input type="datetime-local" name="start_time" id="edit_start_time" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        </div>
                        <div>
                            <label for="edit_end_time" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Término *</label>
                            <input type="datetime-local" name="end_time" id="edit_end_time" required
                                   class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                        </div>
                    </div>
                    
                    <div>
                        <label for="edit_location" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Localização (Física ou Link)</label>
                        <input type="text" name="location" id="edit_location"
                               class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                    </div>
                    
                    <div>
                        <label for="edit_notes" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Observações / Detalhes</label>
                        <textarea name="notes" id="edit_notes" rows="2"
                                  class="w-full px-3.5 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all"></textarea>
                    </div>
                    
                    <div class="border-t border-slate-100 dark:border-slate-700/50 pt-4">
                        <label for="edit_target_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Direcionar Compromisso Para:</label>
                        <select name="target_type" id="edit_target_type" onchange="toggleEditTargetSelectors(this.value)"
                                class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all mb-3">
                            <option value="all">Todos os membros do coral</option>
                            <option value="voice_type">Naipe específico</option>
                            <option value="member">Membro individual específico</option>
                        </select>
                        
                        <!-- Seletor de Naipe (Editar) -->
                        <div id="edit_target_voice_container" class="hidden">
                            <label for="edit_target_voice_type" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Naipe Alvo</label>
                            <select name="target_voice_type" id="edit_target_voice_type"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="Soprano">Soprano</option>
                                <option value="Contralto">Contralto</option>
                                <option value="Tenor">Tenor</option>
                                <option value="Baixo">Baixo</option>
                            </select>
                        </div>
                        
                        <!-- Seletor de Membro (Editar) -->
                        <div id="edit_target_member_container" class="hidden">
                            <label for="edit_target_member_id" class="block text-xs font-semibold text-slate-600 dark:text-slate-300 mb-1">Selecione o Cantor Alvo</label>
                            <select name="target_member_id" id="edit_target_member_id"
                                    class="w-full px-3 py-2 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-coral-500 dark:text-white transition-all">
                                <option value="0">Selecione...</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['voice_type'] ?? 'Sem naipe') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="flex justify-end gap-2 pt-4">
                        <button type="button" onclick="closeEditEventModal()"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white transition-colors">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-xs font-semibold rounded-lg bg-coral-500 hover:bg-coral-600 text-white transition-colors">
                            Salvar Alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal 3: Lista de Presenças AJAX -->
    <div id="modal-responses-list" class="fixed inset-0 z-50 overflow-y-auto hidden">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 transition-opacity bg-slate-900/80 backdrop-blur-sm" onclick="closeAttendanceModal()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
            
            <div class="inline-block align-bottom bg-white dark:bg-slate-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-xl sm:w-full border border-slate-100 dark:border-slate-700/50 p-6 md:p-8 relative">
                <!-- Botão Fechar -->
                <button onclick="closeAttendanceModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200">❌</button>
                
                <div class="mb-4">
                    <h3 class="text-lg font-bold font-outfit text-slate-900 dark:text-white" id="modal_event_title">...</h3>
                    <p class="text-xs text-slate-400">Lista detalhada de presenças confirmadas e recusas.</p>
                </div>
                
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 min-h-[150px]">
                    <!-- Irei -->
                    <div class="space-y-3">
                        <h4 class="text-xs font-bold text-green-600 dark:text-green-400 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800 pb-2">
                            Confirmados (Irei) <span id="modal_going_count" class="ml-1 px-1.5 py-0.5 rounded bg-green-50 text-[10px] text-green-700 dark:bg-green-950/20 dark:text-green-400 font-bold">0</span>
                        </h4>
                        <ul id="modal_going_list" class="space-y-2 max-h-56 overflow-y-auto text-xs text-slate-600 dark:text-slate-300">
                            <!-- JS inserido -->
                        </ul>
                    </div>
                    
                    <!-- Não irei -->
                    <div class="space-y-3">
                        <h4 class="text-xs font-bold text-red-500 uppercase tracking-wider border-b border-slate-100 dark:border-slate-800 pb-2">
                            Ausentes (Não irei) <span id="modal_not_going_count" class="ml-1 px-1.5 py-0.5 rounded bg-red-50 text-[10px] text-red-700 dark:bg-red-950/20 dark:text-red-400 font-bold">0</span>
                        </h4>
                        <ul id="modal_not_going_list" class="space-y-2 max-h-56 overflow-y-auto text-xs text-slate-600 dark:text-slate-300">
                            <!-- JS inserido -->
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
// ----------------------------------------------
// FUNÇÕES AUXILIARES DOS MODAIS DE ADMIN
// ----------------------------------------------
function openNewEventModal() {
    document.getElementById('modal-new-event').classList.remove('hidden');
}

function closeNewEventModal() {
    document.getElementById('modal-new-event').classList.add('hidden');
}

function openEditEventModal(eventObj) {
    document.getElementById('edit_event_id').value = eventObj.id;
    document.getElementById('edit_title').value = eventObj.title;
    
    // Converter datas "YYYY-MM-DD HH:MM:SS" para "YYYY-MM-DDTHH:MM" que o datetime-local espera
    const formatDateTime = (dtStr) => {
        if (!dtStr) return '';
        return dtStr.substring(0, 10) + 'T' + dtStr.substring(11, 16);
    };
    
    document.getElementById('edit_start_time').value = formatDateTime(eventObj.start_time);
    document.getElementById('edit_end_time').value = formatDateTime(eventObj.end_time);
    document.getElementById('edit_location').value = eventObj.location || '';
    document.getElementById('edit_notes').value = eventObj.notes || '';
    
    // Alvo
    document.getElementById('edit_target_type').value = eventObj.target_type;
    toggleEditTargetSelectors(eventObj.target_type);
    
    if (eventObj.target_type === 'voice_type') {
        document.getElementById('edit_target_voice_type').value = eventObj.target_voice_type;
    } else if (eventObj.target_type === 'member') {
        document.getElementById('edit_target_member_id').value = eventObj.target_member_id;
    }
    
    document.getElementById('modal-edit-event').classList.remove('hidden');
}

function closeEditEventModal() {
    document.getElementById('modal-edit-event').classList.add('hidden');
}

function toggleNewTargetSelectors(type) {
    const voiceCont = document.getElementById('new_target_voice_container');
    const memberCont = document.getElementById('new_target_member_container');
    
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

function toggleEditTargetSelectors(type) {
    const voiceCont = document.getElementById('edit_target_voice_container');
    const memberCont = document.getElementById('edit_target_member_container');
    
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

// ----------------------------------------------
// FUNÇÕES DE PRESENÇA (AJAX)
// ----------------------------------------------
function openAttendanceModal(eventId) {
    const titleEl = document.getElementById('modal_event_title');
    const goingList = document.getElementById('modal_going_list');
    const notGoingList = document.getElementById('modal_not_going_list');
    const goingCount = document.getElementById('modal_going_count');
    const notGoingCount = document.getElementById('modal_not_going_count');
    
    titleEl.textContent = 'Carregando...';
    goingList.innerHTML = '';
    notGoingList.innerHTML = '';
    goingCount.textContent = '0';
    notGoingCount.textContent = '0';
    
    document.getElementById('modal-responses-list').classList.remove('hidden');
    
    fetch(`schedule.php?ajax_action=get_event_responses&event_id=${eventId}${isSuperadminQuery()}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                titleEl.textContent = data.event_title;
                goingCount.textContent = data.going.length;
                notGoingCount.textContent = data.not_going.length;
                
                if (data.going.length === 0) {
                    goingList.innerHTML = '<li class="text-slate-400 italic">Nenhum membro confirmado</li>';
                } else {
                    data.going.forEach(item => {
                        goingList.innerHTML += `
                            <li class="p-2 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                                <div>
                                    <span class="font-semibold text-slate-800 dark:text-white">${escapeHtml(item.name)}</span>
                                    <span class="block text-[10px] text-slate-400 capitalize">${escapeHtml(item.voice_type || 'Sem naipe')}</span>
                                </div>
                                <span class="text-[9px] text-slate-400" title="Data da resposta">${item.formatted_date}</span>
                            </li>
                        `;
                    });
                }
                
                if (data.not_going.length === 0) {
                    notGoingList.innerHTML = '<li class="text-slate-400 italic">Nenhuma recusa</li>';
                } else {
                    data.not_going.forEach(item => {
                        notGoingList.innerHTML += `
                            <li class="p-2 border-b border-slate-100 dark:border-slate-800 flex justify-between items-center">
                                <div>
                                    <span class="font-semibold text-slate-800 dark:text-white">${escapeHtml(item.name)}</span>
                                    <span class="block text-[10px] text-slate-400 capitalize">${escapeHtml(item.voice_type || 'Sem naipe')}</span>
                                </div>
                                <span class="text-[9px] text-slate-400">${item.formatted_date}</span>
                            </li>
                        `;
                    });
                }
            } else {
                titleEl.textContent = 'Erro ao carregar dados';
                goingList.innerHTML = `<li class="text-red-500">${escapeHtml(data.error)}</li>`;
            }
        })
        .catch(err => {
            titleEl.textContent = 'Falha na comunicação';
            goingList.innerHTML = '<li class="text-red-500">Erro de rede ao buscar presenças.</li>';
        });
}

function closeAttendanceModal() {
    document.getElementById('modal-responses-list').classList.add('hidden');
}

function isSuperadminQuery() {
    const urlParams = new URLSearchParams(window.location.search);
    const choirId = urlParams.get('choir_id');
    return choirId ? `&choir_id=${choirId}` : '';
}

function escapeHtml(text) {
    if (!text) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, function(m) { return map[m]; });
}
</script>

<?php
require_once __DIR__ . '/layout_footer.php';
?>
