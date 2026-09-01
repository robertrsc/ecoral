/**
 * eCoral — Máscara de Campos Monetários (BRL)
 * 
 * Uso: adicione data-currency-mask a qualquer <input> para ativar a máscara.
 * Um <input type="hidden"> com o mesmo name é criado automaticamente para
 * enviar o valor numérico puro (ex: 1234.56) ao servidor PHP.
 * 
 * Atributos suportados:
 *   data-currency-mask         — ativa a máscara com pontuação automática (1.234,56)
 *   data-allow-zero="true"     — permite valor zero (padrão: false = mín 0.01)
 */
(function () {
    'use strict';

    /**
     * Converte string formatada "1.234,56" → float 1234.56
     */
    function parseFormatted(str) {
        if (!str) return 0;
        return parseFloat(str.replace(/\./g, '').replace(',', '.')) || 0;
    }

    /**
     * Formata float 1234.56 → "1.234,56"
     */
    function formatBRL(value) {
        return value.toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    /**
     * Aplica máscara em um <input type="text"> e cria/atualiza o hidden correspondente.
     */
    function applyMask(input) {
        // Evitar dupla inicialização
        if (input.dataset.currencyInitialized) return;
        input.dataset.currencyInitialized = 'true';

        const originalName = input.name;
        const allowZero = input.dataset.allowZero === 'true';

        // Criar o hidden que vai ao servidor
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = originalName;
        input.name = originalName + '_display'; // renomeia o visível para não colidir
        input.after(hidden);

        // Pré-preencher se o input já tem valor inicial
        let initialVal = NaN;
        if (input.dataset.initialValue !== undefined && input.dataset.initialValue !== '') {
            initialVal = parseFloat(input.dataset.initialValue);
        } else if (input.value !== '') {
            initialVal = parseFormatted(input.value);
        }

        if (!isNaN(initialVal) && (initialVal > 0 || (allowZero && initialVal >= 0))) {
            input.value = formatBRL(initialVal);
            hidden.value = initialVal.toFixed(2);
        } else {
            hidden.value = '';
        }

        function updateFromInput() {
            // Extrai dígitos apenas
            const raw = input.value.replace(/\D/g, '');
            if (raw === '') {
                input.value = '';
                hidden.value = '';
                return;
            }
            if (raw === '0' && !allowZero) {
                input.value = '';
                hidden.value = '';
                return;
            }
            // Últimos 2 dígitos são centavos
            const cents = parseInt(raw, 10);
            const reais = cents / 100;
            input.value = formatBRL(reais);
            hidden.value = reais.toFixed(2);
        }

        input.addEventListener('input', function (e) {
            updateFromInput();
            const len = input.value.length;
            input.setSelectionRange(len, len);
        });

        input.addEventListener('keydown', function (e) {
            // Permite: Backspace, Delete, Tab, Escape, Enter, setas, Home, End
            const allowed = [
                'Backspace', 'Delete', 'Tab', 'Escape', 'Enter',
                'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown',
                'Home', 'End'
            ];
            if (allowed.includes(e.key)) return;
            // Bloqueia qualquer tecla não numérica
            if (!/^\d$/.test(e.key)) {
                e.preventDefault();
            }
        });

        input.addEventListener('paste', function (e) {
            e.preventDefault();
            const pasted = (e.clipboardData || window.clipboardData).getData('text');
            const digits = pasted.replace(/\D/g, '');
            const cur = input.value.replace(/\D/g, '');
            input.value = cur + digits;
            updateFromInput();
        });

        input.addEventListener('focus', function () {
            if (!input.value) return;
            setTimeout(() => {
                const len = input.value.length;
                input.setSelectionRange(len, len);
            }, 0);
        });

        input.addEventListener('blur', function () {
            const val = parseFormatted(input.value);
            if (!allowZero && val < 0.01) {
                input.value = '';
                hidden.value = '';
            }
        });
    }

    /**
     * Inicializa todos os inputs com [data-currency-mask] presentes no DOM ou
     * adicionados dinamicamente via MutationObserver.
     */
    function initAll() {
        document.querySelectorAll('input[data-currency-mask]').forEach(applyMask);
    }

    // Inicialização quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Observar elementos adicionados dinamicamente (modais, etc.)
    const observer = new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType !== 1) return;
                if (node.matches && node.matches('input[data-currency-mask]')) {
                    applyMask(node);
                }
                if (node.querySelectorAll) {
                    node.querySelectorAll('input[data-currency-mask]').forEach(applyMask);
                }
            });
        });
    });
    if (document.body || document.documentElement) {
        observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
    }

    // Expor globalmente para uso em modais e scripts JS
    window.ecoralCurrencyMask = {
        apply: applyMask,
        init: initAll,
        format: formatBRL,
        parse: parseFormatted,
        setValue: function (inputEl, value) {
            if (typeof inputEl === 'string') inputEl = document.querySelector(inputEl);
            if (!inputEl) return;
            const num = parseFloat(value);
            const allowZero = inputEl.dataset.allowZero === 'true';
            const hiddenName = inputEl.name.replace('_display', '');
            const hiddenEl = inputEl.parentElement ? inputEl.parentElement.querySelector(`input[type="hidden"][name="${hiddenName}"]`) : null;
            if (!isNaN(num) && (num > 0 || (allowZero && num >= 0))) {
                inputEl.value = formatBRL(num);
                if (hiddenEl) hiddenEl.value = num.toFixed(2);
            } else {
                inputEl.value = '';
                if (hiddenEl) hiddenEl.value = '';
            }
        }
    };
})();
