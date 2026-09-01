<?php
// boarding-pdf.php — Gerador de Lista de Embarque em PDF (eCoral)
ob_start();

require_once __DIR__ . '/config.php';
require_login();

$loggedUser = get_logged_user();
if (!is_admin_user() && ($loggedUser['role'] ?? '') !== 'colaborador') {
    set_flash_message('error', 'Você não tem permissão para emitir a lista de embarque.');
    header("Location: members.php");
    exit;
}

// Obter IDs dos membros selecionados
$raw_ids = $_POST['member_ids'] ?? $_GET['ids'] ?? [];
if (is_string($raw_ids)) {
    $raw_ids = array_filter(explode(',', $raw_ids));
}
$member_ids = array_map('intval', array_filter((array)$raw_ids));

if (empty($member_ids)) {
    set_flash_message('warning', 'Por favor, assinale ao menos um membro para gerar a Lista de Embarque.');
    header("Location: members.php");
    exit;
}

// Buscar dados dos membros selecionados no banco
$placeholders = implode(',', array_fill(0, count($member_ids), '?'));
$sql = "SELECT u.name, u.cpf, u.rg, u.phone, u.voice_type, u.member_code, c.name as choir_name, c.logo as choir_logo
        FROM users u
        LEFT JOIN choirs c ON u.choir_id = c.id
        WHERE u.id IN ($placeholders)";

$params = $member_ids;
if (!is_superadmin() && !empty($loggedUser['choir_id'])) {
    $sql .= " AND u.choir_id = ?";
    $params[] = intval($loggedUser['choir_id']);
}
$sql .= " ORDER BY u.name ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll();
} catch (Exception $e) {
    set_flash_message('error', 'Erro ao carregar dados para a lista de embarque: ' . $e->getMessage());
    header("Location: members.php");
    exit;
}

if (empty($members)) {
    set_flash_message('error', 'Nenhum registro de membro válido encontrado para os IDs selecionados.');
    header("Location: members.php");
    exit;
}

$choirName = $members[0]['choir_name'] ?? 'eCoral';
$choirLogo = $members[0]['choir_logo'] ?? null;

// Incluir a biblioteca FPDF
require_once __DIR__ . '/vendor/fpdf/fpdf.php';

// Classe estendida FPDF para layout personalizado da Lista de Embarque
class BoardingPDF extends FPDF {
    public $choirName = '';
    public $choirLogo = '';
    public $loggedUserName = '';

    function Header() {
        $hasLogo = false;
        if (!empty($this->choirLogo)) {
            $logoPath = __DIR__ . '/uploads/' . $this->choirLogo;
            if (file_exists($logoPath)) {
                $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png'])) {
                    try {
                        @$this->Image($logoPath, 12, 10, 22);
                        $hasLogo = true;
                    } catch (Throwable $imgErr) {
                        $hasLogo = false;
                    }
                }
            }
        }

        $leftMargin = $hasLogo ? 38 : 12;
        
        // Título Principal
        $this->SetFont('Helvetica', 'B', 16);
        $this->SetTextColor(244, 63, 94); // Coral primary #f43f5e
        $this->SetXY($leftMargin, 10);
        $this->Cell(0, 7, $this->pdfStr('LISTA DE EMBARQUE DE PASSAGEIROS'), 0, 1, 'L');
        
        // Subtítulo e Coral
        $this->SetFont('Helvetica', 'B', 11);
        $this->SetTextColor(30, 41, 59); // Slate-800
        $this->SetX($leftMargin);
        $this->Cell(0, 5, $this->pdfStr('Coral: ' . $this->choirName), 0, 1, 'L');
        
        // Metadados
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(100, 116, 139); // Slate-500
        $this->SetX($leftMargin);
        $emissionInfo = 'Emissão: ' . date('d/m/Y H:i') . ' | Emitido por: ' . $this->loggedUserName;
        $this->Cell(0, 4, $this->pdfStr($emissionInfo), 0, 1, 'L');
        
        $this->Ln(4);
        
        // Linha divisória
        $this->SetDrawColor(226, 232, 240);
        $this->SetLineWidth(0.5);
        $this->Line(12, $this->GetY(), 198, $this->GetY());
        $this->Ln(4);
    }

    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Helvetica', 'I', 8);
        $this->SetTextColor(148, 163, 184);
        $this->Cell(0, 10, $this->pdfStr('eCoral — Gestão de Corais Musicais | Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }

    function pdfStr($str) {
        if ($str === null) return '';
        return mb_convert_encoding((string)$str, 'ISO-8859-1', 'UTF-8');
    }
}

// Instanciar o PDF
try {
    $pdf = new BoardingPDF('P', 'mm', 'A4');
    $pdf->AliasNbPages();
    $pdf->choirName = $choirName;
    $pdf->choirLogo = $choirLogo;
    $pdf->loggedUserName = $loggedUser['name'] ?? 'Administrador';

    $pdf->SetMargins(12, 10, 12);
    $pdf->SetAutoPageBreak(true, 25);
    $pdf->AddPage();

    // Resumo / Totalizador
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->SetTextColor(15, 23, 42);
    $totalPassageiros = count($members);
    $pdf->Cell(0, 8, $pdf->pdfStr(" TOTAL DE PASSAGEIROS EMBARCADOS: $totalPassageiros "), 1, 1, 'L', true);
    $pdf->Ln(3);

    // Tabela de 4 Colunas conforme solicitado:
    // 1. Nome do Membro (68mm)
    // 2. CPF (38mm)
    // 3. Identidade/RG (38mm)
    // 4. Telefone (42mm)
    $wName  = 68;
    $wCpf   = 38;
    $wRg    = 38;
    $wPhone = 42;

    // Cabecalho da Tabela
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(244, 63, 94); // Coral
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetDrawColor(225, 29, 72);

    $pdf->Cell($wName,  8, $pdf->pdfStr('Nome do Membro'), 1, 0, 'L', true);
    $pdf->Cell($wCpf,   8, $pdf->pdfStr('CPF'),            1, 0, 'C', true);
    $pdf->Cell($wRg,    8, $pdf->pdfStr('Identidade (RG)'), 1, 0, 'C', true);
    $pdf->Cell($wPhone, 8, $pdf->pdfStr('Telefone'),       1, 1, 'C', true);

    // Linhas da Tabela
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->SetDrawColor(226, 232, 240);

    $fill = false;
    foreach ($members as $m) {
        if ($fill) {
            $pdf->SetFillColor(248, 250, 252);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        $nameDisplay = trim($m['name']);
        if (!empty($m['voice_type'])) {
            $nameDisplay .= ' (' . $m['voice_type'] . ')';
        }

        $cpfDisplay = !empty($m['cpf']) ? $m['cpf'] : 'Não informado';
        $rgDisplay  = !empty($m['rg'])  ? $m['rg']  : 'Não informado';
        $phoneDisplay = !empty($m['phone']) ? $m['phone'] : 'Não informado';

        $pdf->Cell($wName,  7, $pdf->pdfStr(' ' . $nameDisplay),  1, 0, 'L', true);
        $pdf->Cell($wCpf,   7, $pdf->pdfStr($cpfDisplay),         1, 0, 'C', true);
        $pdf->Cell($wRg,    7, $pdf->pdfStr($rgDisplay),          1, 0, 'C', true);
        $pdf->Cell($wPhone, 7, $pdf->pdfStr($phoneDisplay),       1, 1, 'C', true);

        $fill = !$fill;
    }

    // Bloco de Assinatura e Conferência no final
    $pdf->Ln(10);
    if ($pdf->GetY() > 230) {
        $pdf->AddPage();
    }

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->Cell(0, 6, $pdf->pdfStr('TERMO DE RESPONSABILIDADE E CONFERÊNCIA DE EMBARQUE'), 0, 1, 'L');

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->MultiCell(0, 4, $pdf->pdfStr('Atesto para os devidos fins que os passageiros acima relacionados foram devidamente conferidos e identificados no momento do embarque.'));

    $pdf->Ln(12);

    // Linha para assinatura
    $pdf->SetDrawColor(148, 163, 184);
    $pdf->SetLineWidth(0.3);
    $pdf->Line(12, $pdf->GetY(), 110, $pdf->GetY());

    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(51, 65, 85);
    $pdf->Text(12, $pdf->GetY() + 4, $pdf->pdfStr('Assinatura do Responsável pelo Embarque'));

    $pdf->SetXY(120, $pdf->GetY() - 4);
    $pdf->Cell(0, 5, $pdf->pdfStr('Data: _____ / _____ / 20___    Horário: ____ : ____'), 0, 1, 'R');

    // Limpar qualquer buffer antes de enviar o PDF binário
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Enviar o PDF no navegador (inline)
    $pdf->Output('I', 'lista_embarque_' . date('Ymd_His') . '.pdf');
    exit;

} catch (Throwable $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log("Erro ao gerar PDF da Lista de Embarque: " . $e->getMessage());
    echo "<div style='font-family: sans-serif; padding: 20px; color: #721c24; background: #f8d7da; border-radius: 8px;'>";
    echo "<h2>Erro ao gerar o PDF da Lista de Embarque</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><a href='members.php'>Voltar para a Lista de Membros</a></p>";
    echo "</div>";
    exit;
}
