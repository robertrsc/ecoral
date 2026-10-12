<?php
// help.php - Central de Ajuda / Dúvidas Frequentes
require_once __DIR__ . '/config.php';
require_login();

$faqs = [
    ['icon' => '💳', 'q' => 'Como faço para pagar uma cobrança?',
     'a' => 'Faça o pagamento (por exemplo, via PIX) da forma combinada com o coral. Depois, toque no botão <strong>Pagar</strong> na tela inicial, informe o valor pago, anexe a foto ou o PDF do comprovante, marque as cobranças que está pagando e toque em <strong>Enviar Comprovante</strong>.'],
    ['icon' => '📸', 'q' => 'Como envio a foto do comprovante?',
     'a' => 'No campo <strong>Arquivo Comprovante</strong>, toque em "Escolher arquivo". O celular vai oferecer a opção de tirar uma foto ou escolher uma imagem da galeria. Também é possível enviar arquivos PDF.'],
    ['icon' => '⏳', 'q' => 'Enviei o comprovante. E agora?',
     'a' => 'O comprovante fica <strong>"Em análise"</strong> até que um administrador do coral confira o pagamento. Assim que for aprovado, a cobrança aparece como paga e você recebe um e-mail de confirmação.'],
    ['icon' => '💰', 'q' => 'O que é o meu "Saldo"?',
     'a' => 'Se você pagar um valor maior do que as cobranças selecionadas, a diferença vira crédito (saldo) para você. Esse saldo pode ser usado para pagar próximas cobranças pelo botão <strong>Pagar com saldo</strong>. Para ver o valor, toque no ícone de olho 👁️ no topo da tela.'],
    ['icon' => '📊', 'q' => 'Onde vejo todas as minhas cobranças?',
     'a' => 'Toque no botão <strong>Financeiro</strong> na tela inicial. Lá você encontra seus comprovantes enviados e o histórico de pagamentos realizados.'],
    ['icon' => '📅', 'q' => 'Como vejo os ensaios e apresentações?',
     'a' => 'Toque em <strong>Agenda</strong> na tela inicial para ver os próximos eventos do coral e confirmar sua presença.'],
    ['icon' => '📂', 'q' => 'Como baixo partituras e áudios?',
     'a' => 'Toque em <strong>Arquivos</strong>. Use a caixa de pesquisa para encontrar o arquivo pelo nome. Toque no nome para <strong>abrir/ouvir</strong> ou no botão <strong>Baixar</strong> para salvar no celular.'],
    ['icon' => '👤', 'q' => 'Como altero meus dados ou minha senha?',
     'a' => 'Toque em <strong>Perfil</strong> na tela inicial. Lá você pode atualizar telefone, endereço e trocar a sua senha.'],
    ['icon' => '🔑', 'q' => 'Esqueci minha senha. O que faço?',
     'a' => 'Na tela de entrada (login), toque em <strong>"Esqueci minha senha"</strong> e informe seu e-mail. Você receberá um link para criar uma nova senha.'],
    ['icon' => '🏠', 'q' => 'Como volto para a tela inicial?',
     'a' => 'Em qualquer tela, toque em <strong>"‹ Voltar ao início"</strong>, no alto da página, ou toque no logotipo do coral.'],
];

require_once __DIR__ . '/layout_header.php';
?>

<div class="mb-5">
    <h1 class="text-2xl font-bold font-outfit text-slate-900 dark:text-white flex items-center gap-2">
        <span>❓</span> Ajuda e Dúvidas Frequentes
    </h1>
    <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Toque em uma pergunta para ver a resposta.</p>
</div>

<div class="space-y-3 max-w-3xl">
    <?php foreach ($faqs as $i => $f): ?>
        <details id="faq-<?= $i ?>" class="group bg-white dark:bg-slate-800 rounded-2xl border border-slate-100 dark:border-slate-700/50 shadow-sm overflow-hidden">
            <summary class="flex items-center gap-3 p-4 min-h-[60px] cursor-pointer list-none select-none hover:bg-slate-50 dark:hover:bg-slate-700/30">
                <span class="text-2xl shrink-0" aria-hidden="true"><?= $f['icon'] ?></span>
                <span class="flex-1 text-base font-semibold text-slate-800 dark:text-white"><?= $f['q'] ?></span>
                <svg class="w-5 h-5 text-slate-400 shrink-0 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/></svg>
            </summary>
            <div class="px-4 pb-4 pl-14 text-[15px] leading-relaxed text-slate-600 dark:text-slate-300">
                <?= $f['a'] ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>

<div class="mt-6 max-w-3xl p-4 rounded-2xl bg-coral-50 dark:bg-coral-950/20 border border-coral-100 dark:border-coral-900/40 text-sm text-coral-800 dark:text-coral-300">
    Ainda com dúvidas? Fale com a coordenação ou a tesouraria do seu coral. 😊
</div>

<?php require_once __DIR__ . '/layout_footer.php'; ?>
