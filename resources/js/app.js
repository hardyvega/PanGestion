import './bootstrap';
import './private-page';
import './login-form';
import './role-permissions';
import './article-form';
import './adjustment-article-picker';
import './movement-article-picker';
import './recent-sales';

document.addEventListener('DOMContentLoaded', () => {
    const toggle = document.querySelector('[data-password-toggle]');

    if (toggle) {
        const inputId = toggle.getAttribute('aria-controls');
        const input = inputId ? document.getElementById(inputId) : null;
        const label = toggle.querySelector('[data-toggle-label]');

        if (input && label) {
            toggle.addEventListener('click', () => {
                const shouldShow = input.type === 'password';

                input.type = shouldShow ? 'text' : 'password';
                label.textContent = shouldShow ? 'Ocultar' : 'Mostrar';
                toggle.setAttribute('aria-label', `${shouldShow ? 'Ocultar' : 'Mostrar'} contraseña`);
                toggle.setAttribute('aria-pressed', shouldShow ? 'true' : 'false');
            });
        }
    }

    const navToggle = document.querySelector('[data-nav-toggle]');
    const navClose = document.querySelector('[data-nav-close]');
    const navScrim = document.querySelector('[data-nav-scrim]');
    const sidebar = document.getElementById('app-sidebar');
    const mainColumn = document.querySelector('.app-main-column');

    if (navToggle && navClose && navScrim && sidebar && mainColumn) {
        const mobileNavigation = window.matchMedia('(max-width: 760px)');
        const setNavigationOpen = (isOpen) => {
            isOpen = isOpen && mobileNavigation.matches;
            document.body.classList.toggle('nav-is-open', isOpen);
            navToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            const srOnly = navToggle.querySelector('.sr-only');
            if (srOnly) {
                srOnly.textContent = isOpen ? 'Cerrar navegación' : 'Abrir navegación';
            }
            navScrim.hidden = !isOpen;
            sidebar.inert = mobileNavigation.matches && !isOpen;
            mainColumn.inert = isOpen;
            if (isOpen) {
                navClose.focus();
            } else if (mobileNavigation.matches && sidebar.contains(document.activeElement)) {
                navToggle.focus();
            }
        };

        setNavigationOpen(false);
        mobileNavigation.addEventListener('change', () => setNavigationOpen(false));

        navToggle.addEventListener('click', () => {
            setNavigationOpen(!document.body.classList.contains('nav-is-open'));
        });

        navScrim.addEventListener('click', () => setNavigationOpen(false));
        navClose.addEventListener('click', () => setNavigationOpen(false));

        sidebar.querySelectorAll('a.nav-link').forEach((link) => {
            link.addEventListener('click', () => setNavigationOpen(false));
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setNavigationOpen(false);
            }
            if (event.key === 'Tab' && document.body.classList.contains('nav-is-open')) {
                const focusable = [...sidebar.querySelectorAll('a[href], button:not([disabled])')];
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            }
        });
    }

    initializeSalesPoint();
    initializeOrderCreate();
    initializeOrderDetail();
    initializeCashForms();
});

function initializeCashForms() {
    document.querySelectorAll('[data-cash-close]').forEach(initializeCashClosing);
    document.querySelectorAll('[data-cash-manual-movement]').forEach(initializeCashManualMovement);
    document.querySelectorAll('form[data-submit-once]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
                button.textContent = 'Procesando…';
            });
        });
    });
}

function initializeCashManualMovement(form) {
    const type = form.querySelector('[data-cash-movement-type]');
    const help = form.querySelector('[data-cash-movement-help]');

    if (!type || !help) return;

    const update = () => {
        help.textContent = type.selectedOptions[0]?.dataset.description ?? '';
    };

    type.addEventListener('change', update);
    update();
}

function initializeCashClosing(form) {
    const counted = form.querySelector('[data-close-counted]');
    const fund = form.querySelector('[data-close-fund]');
    const difference = form.querySelector('[data-close-difference]');
    const withdrawal = form.querySelector('[data-close-withdrawal]');
    const observation = form.querySelector('[data-close-observation]');
    const signedCents = (value) => String(value).startsWith('-') ? -scaled(String(value).slice(1),2) : scaled(value,2);
    const display = (cents) => `${cents < 0n ? '-' : ''}${formatMoney(formatScaled(cents < 0n ? -cents : cents,2))}`;
    const update = () => {
        try {
            const actual = scaled(counted.value,2);
            const delta = actual - signedCents(form.dataset.expected);
            difference.textContent = `${delta < 0n ? 'FALTANTE DE CAJA' : delta > 0n ? 'SOBRANTE DE CAJA' : 'CAJA CUADRADA'}: ${display(delta)}`;
            difference.className = delta === 0n ? 'form-success' : 'form-alert';
            observation.required = delta !== 0n;
            const next = scaled(fund.value,2);
            if (next > actual) { fund.setCustomValidity('El fondo no puede superar el efectivo contado.'); withdrawal.textContent='Fondo superior al disponible'; }
            else { fund.setCustomValidity(''); withdrawal.textContent=`Retiro final a realizar: ${display(actual-next)}`; }
        } catch {
            difference.textContent='Completa los importes para revisar la cuadratura.';
            withdrawal.textContent='Retiro final pendiente';
            fund.setCustomValidity('Revisa los importes del cierre.');
        }
    };
    counted.addEventListener('input',update);
    fund.addEventListener('input',update);
    update();
}

function initializeSalesPoint() {
    const root = document.querySelector('[data-search-form]');
    const pos = document.getElementById('sales-pos');

    if (!root || !pos) {
        return;
    }

    const searchInput = pos.querySelector('[data-search-input]');
    const searchResults = pos.querySelector('[data-product-results]');
    const searchFeedback = pos.querySelector('[data-search-feedback]');
    const cartLines = pos.querySelector('[data-cart-lines]');
    const cartEmpty = pos.querySelector('[data-cart-empty]');
    const cartTotal = pos.querySelector('[data-cart-total]');
    const cartCount = pos.querySelector('[data-cart-count]');
    const paymentLines = pos.querySelector('[data-payment-lines]');
    const paymentTotal = pos.querySelector('[data-payment-total]');
    const paidTotal = pos.querySelector('[data-paid-total]');
    const paymentBalance = pos.querySelector('[data-payment-balance]');
    const lineInputs = pos.querySelector('[data-line-inputs]');
    const paymentInputs = pos.querySelector('[data-payment-inputs]');
    const checkoutForm = pos.querySelector('[data-checkout-form]');
    const checkoutButton = pos.querySelector('[data-checkout-button]');
    const addPaymentButton = pos.querySelector('[data-add-payment]');
    const checkoutHelp = pos.querySelector('[data-checkout-help]');
    const paymentMode = pos.querySelector('[data-payment-mode]');
    const mobileTotal = pos.querySelector('[data-mobile-total]');
    const cartPanel = pos.querySelector('.cart-panel');
    const methods = Array.isArray(window.panGestionPaymentMethods) ? window.panGestionPaymentMethods : [];
    const products = new Map();
    const cart = [];
    const paymentRows = [];
    let searching = false;
    let submitting = false;

    const initialCart = parseJson(pos.dataset.initialCart, {});
    const initialLines = parseJson(pos.dataset.initialLines, []);
    const initialPayments = parseJson(pos.dataset.initialPayments, []);

    Object.values(initialCart).forEach((product) => {
        if (product && product.id) {
            products.set(String(product.id), product);
        }
    });

    initialLines.forEach((line) => {
        const product = products.get(String(line.article_id));
        if (product && product.precio !== null) {
            try {
                const quantity = normalizeQuantity(line.quantity);
                if (compareQuantity(quantity, '0.000') > 0) cart.push({ product, quantity });
            } catch { /* Invalid submitted quantities must not break the checkout screen. */ }
        }
    });

    initialPayments.forEach((payment) => {
        paymentRows.push({
            methodId: String(payment.payment_method_id || methods[0]?.id || ''),
            amount: String(payment.amount ?? ''),
            received: String(payment.received_amount ?? ''),
            uuid: payment.payment_uuid || makeUuid(),
        });
    });

    if (cart.length > 0 && paymentRows.length === 0) {
        paymentRows.push(newPaymentRow(totalForCart()));
    }

    const money = (value) => formatPosMoney(value);

    function setFeedback(message = '', tone = 'neutral') {
        searchFeedback.textContent = message;
        searchFeedback.className = `sales-feedback${message ? ` is-${tone}` : ''}`;
    }

    function searchProducts(event) {
        event.preventDefault();
        if (searching || submitting || searchInput.disabled) return;
        const term = searchInput.value.trim();

        if (term === '') {
            setFeedback('Escribe un nombre, SKU, ID o código de barras.', 'error');
            return;
        }

        searching = true;
        searchResults.innerHTML = '';
        searchResults.setAttribute('aria-busy', 'true');
        setFeedback('Buscando…', 'neutral');
        fetch(`${pos.dataset.searchUrl}?term=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('search');
                }
                return response.json();
            })
            .then((payload) => {
                const results = Array.isArray(payload.data) ? payload.data : [];
                results.forEach((product) => products.set(String(product.id), product));

                if (payload.exact_match && results.length === 1) {
                    if (addProduct(results[0])) {
                        searchInput.value = '';
                        setFeedback(`${results[0].nombre} agregado al carrito.`, 'success');
                        focusSearch();
                    }
                    return;
                }

                renderResults(results);
                setFeedback(results.length === 0 ? 'No encontramos productos vendibles.' : 'Selecciona un producto para agregarlo.', 'neutral');
            })
            .catch(() => setFeedback('No se pudo consultar el catálogo. Inténtalo nuevamente.', 'error'))
            .finally(() => {
                searching = false;
                searchResults.setAttribute('aria-busy', 'false');
            });
    }

    function renderResults(results) {
        if (results.length === 0) {
            searchResults.innerHTML = '';
            return;
        }

        searchResults.innerHTML = results.map((product) => `
            <button type="button" class="product-result" data-product-id="${escapeHtml(product.id)}">
                <span class="product-result-main">
                    <strong>${escapeHtml(product.nombre)}</strong>
                    <small>${escapeHtml(product.sku || 'Sin SKU')} · ${escapeHtml(product.unidad_simbolo || product.unidad_codigo || 'unidad')}</small>
                </span>
                <span class="product-result-meta">
                    <strong>${product.precio === null ? 'Sin precio' : money(product.precio)}</strong>
                    <small>Stock ${escapeHtml(product.stock_label ?? product.stock)}</small>
                </span>
                <span class="product-add" aria-hidden="true">+ Agregar</span>
            </button>
        `).join('');
    }

    function addProduct(product) {
        if (submitting || !product || product.precio === null) {
            setFeedback('El producto no tiene un precio de venta vigente.', 'error');
            return false;
        }

        const existing = cart.find((line) => String(line.product.id) === String(product.id));
        const next = existing ? addQuantity(existing.quantity, '1.000')
            : (compareQuantity(product.stock, '1.000') < 0 ? normalizeQuantity(product.stock) : '1.000');
        if (compareQuantity(next, '0.000') <= 0 || compareQuantity(next, product.stock) > 0) {
            setFeedback('No hay stock suficiente para agregar otra unidad.', 'error');
            return false;
        }
        if (existing) {
            existing.quantity = next;
        } else {
            cart.push({ product, quantity: next });
        }

        if (paymentRows.length === 0) {
            paymentRows.push(newPaymentRow(totalForCart()));
        }

        markIntentChanged();
        renderCart();
        return true;
    }

    function renderCart() {
        const focusedLine = document.activeElement?.closest('[data-cart-line]');
        const focusedControl = document.activeElement?.matches('[data-quantity-action]')
            ? `[data-quantity-action="${document.activeElement.dataset.quantityAction}"]` : '[data-quantity-input]';
        cartEmpty.hidden = cart.length > 0;
        cartCount.textContent = `${cart.length} ${cart.length === 1 ? 'producto' : 'productos'}`;
        cartTotal.textContent = money(totalForCart());
        mobileTotal.textContent = money(totalForCart());

        if (cart.length === 0) paymentRows.length = 0;
        if (paymentRows.length === 1) paymentRows[0].amount = totalForCart();

        cartLines.querySelectorAll('[data-cart-line]').forEach((line) => line.remove());
        cart.forEach((line, index) => {
            const unit = escapeHtml(line.product.unidad_simbolo || line.product.unidad_codigo || 'unidad');
            const row = document.createElement('div');
            row.className = 'cart-line';
            row.dataset.cartLine = String(index);
            row.innerHTML = `
                <div class="cart-line-info">
                    <strong>${escapeHtml(line.product.nombre)}</strong>
                    <small>${escapeHtml(line.product.sku || 'Sin SKU')} · ${money(line.product.precio)} por ${unit}</small>
                    <small class="stock-note">Stock disponible: ${escapeHtml(line.product.stock_label ?? line.product.stock)}</small>
                </div>
                <div class="cart-line-controls">
                    <button type="button" class="quantity-button" data-quantity-action="decrease" title="Disminuir cantidad" aria-label="Disminuir ${escapeHtml(line.product.nombre)}">−</button>
                    <input class="quantity-input" inputmode="decimal" aria-label="Cantidad de ${escapeHtml(line.product.nombre)} en ${unit}" data-quantity-input value="${quantityForInput(line.quantity)}">
                    <button type="button" class="quantity-button" data-quantity-action="increase" title="Aumentar cantidad" aria-label="Aumentar ${escapeHtml(line.product.nombre)}">+</button>
                    <span class="quantity-unit" aria-hidden="true">${unit}</span>
                </div>
                <strong class="cart-line-subtotal">${money(subtotalFor(line))}</strong>
                <button type="button" class="remove-line" data-remove-line title="Quitar producto" aria-label="Eliminar ${escapeHtml(line.product.nombre)}">×</button>
            `;
            cartLines.appendChild(row);
        });

        renderPayments();
        syncHiddenInputs();
        if (focusedLine && cart.length > 0) {
            const index = Math.min(Number(focusedLine.dataset.cartLine), cart.length - 1);
            cartLines.querySelector(`[data-cart-line="${index}"]`)?.querySelector(focusedControl)?.focus();
        }
    }

    function quantityForInput(quantity) {
        const [integer, fraction] = quantity.split('.');
        const digits = fraction.replace(/0+$/, '');
        return digits === '' ? integer : `${integer},${digits}`;
    }

    function renderPayments() {
        if (paymentRows.length === 0) {
            paymentLines.innerHTML = '<div class="sales-empty sales-empty-small">Agrega el total de la venta a uno o más medios.</div>';
        } else {
            paymentLines.innerHTML = paymentRows.map((payment, index) => {
                const method = methodFor(payment.methodId);
                const isCash = Boolean(method?.afecta_efectivo);

                return `
                    <div class="payment-line" data-payment-line="${index}">
                        <div class="field-group payment-method-field">
                            <label for="payment-method-${index}">Método</label>
                            <select id="payment-method-${index}" class="form-input payment-method-input" data-payment-method>
                                ${methods.map((option) => `<option value="${option.id}"${String(option.id) === String(payment.methodId) ? ' selected' : ''}>${escapeHtml(option.nombre)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="field-group payment-amount-field">
                            <label for="payment-amount-${index}">${paymentRows.length === 1 ? 'Total de venta' : 'Monto de este pago'}</label>
                            <input id="payment-amount-${index}" class="form-input payment-amount-input" type="text" inputmode="decimal" data-payment-amount value="${escapeHtml(payment.amount)}" ${paymentRows.length === 1 ? 'readonly' : ''}>
                        </div>
                        <div class="field-group payment-received-field${isCash ? '' : ' is-hidden'}">
                            <label for="payment-received-${index}">Monto recibido del cliente</label>
                            <input id="payment-received-${index}" class="form-input payment-received-input" type="text" inputmode="decimal" autocomplete="off" placeholder="0" aria-describedby="payment-change-${index}" data-payment-received value="${escapeHtml(payment.received)}" ${isCash ? 'required' : 'disabled'}>
                            <small data-received-preview></small>
                            <output id="payment-change-${index}" class="payment-change" data-payment-change role="status" aria-live="polite" aria-atomic="true"></output>
                        </div>
                        <button type="button" class="remove-payment" data-remove-payment title="Quitar medio de pago" aria-label="Eliminar método de pago">×</button>
                    </div>
                `;
            }).join('');
        }

        updatePaymentSummary();
    }

    function updatePaymentSummary() {
        paymentRows.forEach((payment, index) => {
            const row = paymentLines.querySelector(`[data-payment-line="${index}"]`);
            const state = posPaymentState(payment, methodFor(payment.methodId));
            const output = row?.querySelector('[data-payment-change]');
            if (output) {
                output.textContent = state.message;
                output.classList.toggle('is-error', !state.valid);
                row.querySelector('[data-payment-received]')?.setAttribute('aria-invalid', String(state.field === 'received'));
                row.querySelector('[data-payment-amount]')?.setAttribute('aria-invalid', String(state.field === 'amount'));
                row.querySelector('[data-payment-method]')?.setAttribute('aria-invalid', String(state.field === 'method'));
                row.querySelector('[data-received-preview]').textContent = state.receivedLabel;
            }
        });
        paymentMode.textContent = paymentRows.length > 1 ? 'Mixto' : (methodFor(paymentRows[0]?.methodId)?.nombre || '');
        pos.querySelector('.payment-totals').hidden = paymentRows.length < 2;
        const total = totalForCart();
        const paid = paidForPayments();
        const balance = subtractMoney(total, paid);
        paymentTotal.textContent = money(total);
        paidTotal.textContent = money(paid);
        paymentBalance.textContent = money(balance);
        paymentBalance.classList.toggle('is-settled', balance === '0.00');
        paymentBalance.classList.toggle('is-over', compareMoney(paid, total) > 0);
        const state = checkoutState();
        checkoutButton.disabled = submitting || !state.allowed;
        checkoutHelp.textContent = state.message;
        updateStickyCart();
    }

    function syncHiddenInputs() {
        lineInputs.innerHTML = cart.map((line, index) => `
            <input type="hidden" name="lines[${index}][article_id]" value="${escapeHtml(line.product.id)}">
            <input type="hidden" name="lines[${index}][quantity]" value="${escapeHtml(line.quantity)}">
        `).join('');
        const canonicalMoney = (value) => {
            try { return normalizeMoney(value); } catch { return ''; }
        };
        paymentInputs.innerHTML = paymentRows.map((payment, index) => `
            <input type="hidden" name="payments[${index}][payment_method_id]" value="${escapeHtml(payment.methodId)}">
            <input type="hidden" name="payments[${index}][amount]" value="${canonicalMoney(payment.amount)}">
            <input type="hidden" name="payments[${index}][received_amount]" value="${methodFor(payment.methodId)?.afecta_efectivo ? canonicalMoney(payment.received) : ''}">
            <input type="hidden" name="payments[${index}][payment_uuid]" value="${escapeHtml(payment.uuid)}">
        `).join('');
    }

    function newPaymentRow(amount = '') {
        return {
            methodId: String(methods[0]?.id || ''),
            amount,
            received: '',
            uuid: makeUuid(),
        };
    }

    function totalForCart() {
        return cart.reduce((total, line) => addMoney(total, subtotalFor(line)), '0.00');
    }

    function subtotalFor(line) {
        return multiplyMoney(line.quantity, line.product.precio);
    }

    function paidForPayments() {
        return paymentRows.reduce((total, payment) => {
            try {
                return addMoney(total, normalizeMoney(payment.amount));
            } catch {
                return total;
            }
        }, '0.00');
    }

    function checkoutState() {
        if (pos.querySelector('[data-cash-session-input]').value === '') return { allowed: false, message: pos.dataset.cashSessionNotice || 'Debes abrir una sesión de caja vigente antes de cobrar.' };
        if (cart.length === 0) return { allowed: false, message: 'Agrega un producto para comenzar.' };
        if (cart.some((line) => compareQuantity(line.quantity, line.product.stock) > 0)) return { allowed: false, message: 'Revisa las cantidades: no hay stock suficiente.' };
        if (paymentRows.length === 0) return { allowed: false, message: 'Agrega un medio de pago.' };
        for (const payment of paymentRows) {
            const state = posPaymentState(payment, methodFor(payment.methodId));
            if (!state.valid) return { allowed: false, message: state.message };
        }
        if (compareMoney(totalForCart(), paidForPayments()) !== 0) return { allowed: false, message: 'Distribuye exactamente el total entre los medios de pago.' };
        return { allowed: true, message: 'Todo listo para completar la venta.' };
    }

    function updateStickyCart() {
        cartPanel.classList.toggle('can-stick', cartPanel.getBoundingClientRect().height <= window.innerHeight - 88);
    }

    function methodFor(id) {
        return methods.find((method) => String(method.id) === String(id));
    }

    function markIntentChanged() {
        const input = pos.querySelector('[data-command-uuid-input]');
        if (input) {
            input.value = makeUuid();
        }
        paymentRows.forEach((payment) => { payment.uuid = makeUuid(); });
    }

    function focusSearch() {
        window.requestAnimationFrame(() => searchInput?.focus());
    }

    root.addEventListener('submit', searchProducts);

    searchResults.addEventListener('click', (event) => {
        const button = event.target.closest('[data-product-id]');
        if (!button) {
            return;
        }
        const product = products.get(String(button.dataset.productId));
        if (addProduct(product)) {
            searchInput.value = '';
            searchResults.innerHTML = '';
            setFeedback(`${product.nombre} agregado al carrito.`, 'success');
            focusSearch();
        }
    });

    cartLines.addEventListener('click', (event) => {
        if (submitting) return;
        const lineElement = event.target.closest('[data-cart-line]');
        if (!lineElement) {
            return;
        }
        const index = Number(lineElement.dataset.cartLine);
        const line = cart[index];
        if (!line) {
            return;
        }

        if (event.target.closest('[data-remove-line]')) {
            cart.splice(index, 1);
            markIntentChanged();
            renderCart();
            return;
        }

        const action = event.target.closest('[data-quantity-action]')?.dataset.quantityAction;
        if (action === 'increase') {
            const next = addQuantity(line.quantity, '1.000');
            if (compareQuantity(next, line.product.stock) > 0) {
                setFeedback('No hay stock suficiente para aumentar la cantidad.', 'error');
                return;
            }
            line.quantity = next;
        } else if (action === 'decrease') {
            const next = subtractQuantity(line.quantity, '1.000');
            if (compareQuantity(next, '0.000') <= 0) {
                cart.splice(index, 1);
            } else {
                line.quantity = next;
            }
        }

        if (action) {
            markIntentChanged();
            renderCart();
        }
    });

    cartLines.addEventListener('change', (event) => {
        if (submitting) return;
        if (!event.target.matches('[data-quantity-input]')) {
            return;
        }
        const lineElement = event.target.closest('[data-cart-line]');
        const line = cart[Number(lineElement?.dataset.cartLine)];
        try {
            const quantity = normalizeQuantity(event.target.value.replace(',', '.'));
            if (compareQuantity(quantity, '0.000') <= 0) {
                throw new Error('quantity');
            }
            if (compareQuantity(quantity, line.product.stock) > 0) {
                event.target.value = quantityForInput(line.quantity);
                setFeedback('La cantidad supera el stock disponible.', 'error');
                return;
            }
            line.quantity = quantity;
        } catch {
            event.target.value = quantityForInput(line.quantity);
            setFeedback('La cantidad debe ser mayor que cero y tener hasta tres decimales.', 'error');
            return;
        }
        markIntentChanged();
        renderCart();
    });

    function updatePayment(event) {
        if (submitting) return;
        const lineElement = event.target.closest('[data-payment-line]');
        const payment = paymentRows[Number(lineElement?.dataset.paymentLine)];
        if (!payment) {
            return;
        }

        if (event.target.matches('[data-payment-method]')) {
            payment.methodId = event.target.value;
            payment.received = '';
        }
        if (event.target.matches('[data-payment-amount]')) {
            payment.amount = event.target.value.trim();
        }
        if (event.target.matches('[data-payment-received]')) {
            payment.received = event.target.value.trim();
        }
        markIntentChanged();
        if (event.target.matches('[data-payment-method]')) {
            const index = Number(lineElement.dataset.paymentLine);
            renderPayments();
            paymentLines.querySelector(`#payment-method-${index}`)?.focus();
        } else {
            updatePaymentSummary();
        }
        syncHiddenInputs();
    }
    paymentLines.addEventListener('input', (event) => {
        if (!event.target.matches('[data-payment-method]')) updatePayment(event);
    });
    paymentLines.addEventListener('change', (event) => {
        if (event.target.matches('[data-payment-method]')) updatePayment(event);
    });

    paymentLines.addEventListener('click', (event) => {
        if (submitting) return;
        if (!event.target.closest('[data-remove-payment]')) {
            return;
        }
        const lineElement = event.target.closest('[data-payment-line]');
        paymentRows.splice(Number(lineElement?.dataset.paymentLine), 1);
        if (paymentRows.length === 1) paymentRows[0].amount = totalForCart();
        markIntentChanged();
        renderPayments();
        syncHiddenInputs();
    });

    addPaymentButton?.addEventListener('click', () => {
        if (submitting || cart.length === 0) return;
        paymentRows.push(newPaymentRow());
        markIntentChanged();
        renderPayments();
        syncHiddenInputs();
        paymentLines.querySelector(`#payment-method-${paymentRows.length - 1}`)?.focus();
    });

    checkoutForm.addEventListener('submit', (event) => {
        if (submitting) { event.preventDefault(); return; }
        syncHiddenInputs();
        const state = checkoutState();
        if (!state.allowed) {
            event.preventDefault();
            checkoutHelp.textContent = state.message;
            if (cart.length === 0) focusSearch();
            else paymentLines.querySelector('[aria-invalid="true"]')?.focus();
            return;
        }

        submitting = true;
        checkoutButton.disabled = true;
        checkoutButton.setAttribute('aria-busy', 'true');
        checkoutButton.textContent = 'Procesando…';
    });

    renderCart();
    if (!searchInput.disabled) focusSearch();
    window.addEventListener('resize', updateStickyCart);
    if (typeof ResizeObserver !== 'undefined') new ResizeObserver(updateStickyCart).observe(cartPanel);
    pos.querySelector('[data-cart-shortcut]')?.addEventListener('click', (event) => {
        event.preventDefault();
        cartPanel.scrollIntoView({ block: 'start' });
        pos.querySelector('#cart-title').focus({ preventScroll: true });
    });

    function parseJson(value, fallback) {
        try {
            return value ? JSON.parse(value) : fallback;
        } catch {
            return fallback;
        }
    }
}

function formatPosMoney(value) {
    const raw = String(value);
    const negative = raw.startsWith('-');
    const [integer, fraction] = normalizeMoney(negative ? raw.slice(1) : raw).split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${negative ? '-$' : '$'}${grouped}${fraction !== '00' ? `,${fraction}` : ''}`;
}

function posPaymentState(payment, method) {
    const invalid = (message, field = 'received') => ({ valid: false, message, receivedLabel: '', field });
    if (!method) return invalid('Selecciona un medio de pago disponible.', 'method');
    let amount;
    try {
        amount = normalizeMoney(payment.amount);
        if (compareMoney(amount, '0.00') <= 0) return invalid('El importe aplicado debe ser mayor que cero.', 'amount');
    } catch { return invalid('Indica un importe válido, sin negativos y con hasta dos decimales.', 'amount'); }
    if (!method.afecta_efectivo) return { valid: true, message: '', receivedLabel: '' };
    if (payment.received.trim() === '') return invalid('Indica el monto recibido en efectivo.');
    let received;
    try { received = normalizeMoney(payment.received); }
    catch { return invalid('Indica un monto recibido válido, sin negativos y con hasta dos decimales.'); }
    if (compareMoney(received, amount) < 0) return invalid(`Faltan ${formatPosMoney(subtractMoney(amount, received))}`);
    return {
        valid: true,
        message: `Vuelto a entregar: ${formatPosMoney(subtractMoney(received, amount))}`,
        receivedLabel: `Recibido: ${formatPosMoney(received)}`,
    };
}

function normalizeQuantity(value) {
    return normalizeDecimal(value, 3);
}

function normalizeMoney(value) {
    return normalizeDecimal(value, 2);
}

function normalizeDecimal(value, scale) {
    const stringValue = String(value ?? '').trim();
    if (!new RegExp(`^[0-9]+(?:\\.[0-9]{1,${scale}})?$`).test(stringValue)) {
        throw new Error('decimal');
    }
    const [integer, fraction = ''] = stringValue.split('.');
    return `${BigInt(integer).toString()}.${fraction.padEnd(scale, '0')}`;
}

function scaled(value, scale) {
    const normalized = normalizeDecimal(value, scale);
    const [integer, fraction] = normalized.split('.');
    return BigInt(integer) * (10n ** BigInt(scale)) + BigInt(fraction);
}

function formatScaled(value, scale) {
    const base = 10n ** BigInt(scale);
    const integer = value / base;
    const fraction = String(value % base).padStart(scale, '0');
    return `${integer}.${fraction}`;
}

function addMoney(left, right) {
    return formatScaled(scaled(left, 2) + scaled(right, 2), 2);
}

function subtractMoney(left, right) {
    const result = scaled(left, 2) - scaled(right, 2);
    const negative = result < 0n;
    return `${negative ? '-' : ''}${formatScaled(negative ? -result : result, 2)}`;
}

function compareMoney(left, right) {
    const leftScaled = scaled(left, 2);
    const rightScaled = scaled(right, 2);
    return leftScaled === rightScaled ? 0 : (leftScaled > rightScaled ? 1 : -1);
}

function multiplyMoney(quantity, price) {
    const product = scaled(quantity, 3) * scaled(price, 2);
    const cents = product / 1000n;
    const remainder = product % 1000n;
    return formatScaled(cents + (remainder >= 500n ? 1n : 0n), 2);
}

function addQuantity(left, right) {
    return formatScaled(scaled(left, 3) + scaled(right, 3), 3);
}

function subtractQuantity(left, right) {
    const result = scaled(left, 3) - scaled(right, 3);
    return formatScaled(result < 0n ? 0n : result, 3);
}

function compareQuantity(left, right) {
    const leftScaled = scaled(left, 3);
    const rightScaled = scaled(right, 3);
    return leftScaled === rightScaled ? 0 : (leftScaled > rightScaled ? 1 : -1);
}

function formatMoney(value) {
    const normalized = normalizeMoney(value);
    const negative = normalized.startsWith('-');
    const unsigned = negative ? normalized.slice(1) : normalized;
    const integer = unsigned.split('.')[0];
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return `${negative ? '-$' : '$'}${grouped}`;
}

function makeUuid() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }
    return '00000000-0000-4000-8000-000000000000';
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function parseAgreedOrderTotal(value) {
    const input = value.trim();
    if (input.length > 32 || !/^\$?[ \t]*(?:[0-9]{1,12}|[1-9][0-9]{0,2}(?:\.[0-9]{3}){1,3})$/.test(input)) {
        return null;
    }
    const digits = input.replace(/[$. \t]/g, '').replace(/^0+/, '');
    return digits ? digits + '.00' : null;
}

function initializeOrderCreate() {
    const root = document.querySelector('[data-order-create]');
    if (!root) return;

    const form = root.querySelector('[data-order-form]');
    const priceInput = root.querySelector('[data-order-agreed-total]');
    const paymentLines = root.querySelector('[data-order-payment-lines]');
    const sessionInput = root.querySelector('[data-order-cash-session]');
    const addPayment = root.querySelector('[data-add-order-payment]');
    const totalElement = root.querySelector('[data-order-total]');
    const paidElement = root.querySelector('[data-order-paid]');
    const balanceElement = root.querySelector('[data-order-balance]');
    const error = root.querySelector('[data-order-form-error]');
    const submit = root.querySelector('[data-order-submit]');
    const methods = Array.isArray(window.panGestionOrderPaymentMethods) ? window.panGestionOrderPaymentMethods : [];
    const methodFor = (id) => methods.find((method) => String(method.id) === String(id));
    const oldValue = (value) => typeof value === 'string' || typeof value === 'number' ? String(value) : '';
    const oldPayments = paymentLines && Array.isArray(window.panGestionOrderPayments) ? window.panGestionOrderPayments : [];
    const payments = oldPayments.filter((payment) => payment && typeof payment === 'object').map((payment) => ({
        methodId: oldValue(payment.payment_method_id),
        amount: oldValue(payment.amount),
        received: oldValue(payment.received_amount),
        uuid: /^[a-f0-9-]{36}$/i.test(oldValue(payment.payment_uuid)) ? payment.payment_uuid : orderPaymentUuid(),
    }));

    function orderPaymentUuid() {
        if (window.crypto.randomUUID) return window.crypto.randomUUID();

        const bytes = window.crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function displayAmount(amount) {
        const cents = amount.split('.')[1];
        return formatMoney(amount) + (cents === '00' ? '' : ',' + cents);
    }

    function draftPaid() {
        let total = '0.00';
        for (const payment of payments) {
            if (!/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/.test(payment.amount)) return null;
            total = addMoney(total, payment.amount);
        }
        return total;
    }

    function renderSummary() {
        const total = parseAgreedOrderTotal(priceInput.value);
        const paid = draftPaid();
        totalElement.textContent = total ? displayAmount(total) : '—';
        paidElement.textContent = paid ? displayAmount(paid) : '—';
        balanceElement.textContent = total && paid && compareMoney(paid, total) <= 0
            ? displayAmount(subtractMoney(total, paid))
            : '—';
    }

    function renderPayments() {
        if (!paymentLines) return;
        paymentLines.innerHTML = payments.map((payment, index) => {
            const cash = Boolean(methodFor(payment.methodId)?.afecta_efectivo);
            const field = `payments[${index}]`;
            const options = methods.map((method) =>
                `<option value="${escapeHtml(method.id)}"${String(method.id) === payment.methodId ? ' selected' : ''}>${escapeHtml(method.nombre)}</option>`
            ).join('');
            return `<div class="order-payment-line" data-order-payment-line="${index}">
                <label class="field-group"><span>Medio de pago</span><select class="form-input" name="${field}[payment_method_id]" data-order-payment-method required><option value="">Seleccionar</option>${options}</select></label>
                <label class="field-group"><span>Monto</span><input class="form-input" inputmode="decimal" name="${field}[amount]" data-order-payment-amount value="${escapeHtml(payment.amount)}" required></label>
                <label class="field-group${cash ? '' : ' is-hidden'}" data-order-payment-cash><span>Efectivo recibido</span><input class="form-input" inputmode="decimal" name="${field}[received_amount]" data-order-payment-received value="${escapeHtml(payment.received)}" ${cash ? 'required' : 'disabled'}></label>
                <input type="hidden" name="${field}[payment_uuid]" value="${escapeHtml(payment.uuid)}">
                <button class="remove-payment" type="button" data-order-remove-payment aria-label="Quitar abono">×</button>
            </div>`;
        }).join('');
        renderSummary();
    }

    function showError(message) {
        error.hidden = !message;
        error.textContent = message;
    }

    priceInput.addEventListener('input', () => {
        priceInput.setCustomValidity('');
        showError('');
        renderSummary();
    });
    paymentLines?.addEventListener('input', (event) => {
        const row = event.target.closest('[data-order-payment-line]');
        const payment = payments[Number(row?.dataset.orderPaymentLine)];
        if (!payment) return;
        if (event.target.matches('[data-order-payment-amount]')) payment.amount = event.target.value.trim();
        if (event.target.matches('[data-order-payment-received]')) payment.received = event.target.value.trim();
        showError('');
        renderSummary();
    });
    paymentLines?.addEventListener('change', (event) => {
        if (!event.target.matches('[data-order-payment-method]')) return;
        const row = event.target.closest('[data-order-payment-line]');
        const payment = payments[Number(row.dataset.orderPaymentLine)];
        payment.methodId = event.target.value;
        const cash = Boolean(methodFor(payment.methodId)?.afecta_efectivo);
        const received = row.querySelector('[data-order-payment-received]');
        row.querySelector('[data-order-payment-cash]').classList.toggle('is-hidden', !cash);
        received.disabled = !cash;
        received.required = cash;
        if (!cash) {
            payment.received = '';
            received.value = '';
        }
    });
    paymentLines?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-order-payment-line]');
        if (!row || !event.target.closest('[data-order-remove-payment]')) return;
        payments.splice(Number(row.dataset.orderPaymentLine), 1);
        showError('');
        renderPayments();
    });
    sessionInput?.addEventListener('change', () => showError(''));
    addPayment?.addEventListener('click', () => {
        const total = parseAgreedOrderTotal(priceInput.value);
        const paid = draftPaid();
        const balance = total && paid && compareMoney(paid, total) < 0 ? subtractMoney(total, paid) : '';
        payments.push({ methodId: String(methods[0]?.id || ''), amount: balance, received: '', uuid: orderPaymentUuid() });
        renderPayments();
    });
    form.addEventListener('submit', (event) => {
        if (submit.disabled) {
            event.preventDefault();
            return;
        }
        const total = parseAgreedOrderTotal(priceInput.value);
        if (!total) {
            event.preventDefault();
            priceInput.setCustomValidity('Ingresa un precio mayor que cero en pesos enteros.');
            priceInput.reportValidity();
            return;
        }
        const paid = draftPaid();
        if (payments.length > 0 && !sessionInput.value) {
            event.preventDefault();
            showError('Para registrar un abono, debe haber una caja abierta.');
            sessionInput.focus();
            return;
        }
        if (paid === null || compareMoney(paid, total) > 0) {
            event.preventDefault();
            showError(paid === null ? 'Revisa los montos de los abonos.' : 'Los abonos no pueden superar el precio acordado.');
            return;
        }
        showError('');
        submit.disabled = true;
        submit.setAttribute('aria-busy', 'true');
        submit.textContent = 'Creando…';
    });

    renderPayments();
    renderSummary();
}

function initializeOrderDetail() {
    const root = document.querySelector('[data-order-detail]');
    if (!root) return;
    const modals = root.parentElement.querySelectorAll('[data-order-modal]');
    const open = (name) => { modals.forEach((modal) => { modal.hidden = modal.dataset.modalName !== name; }); };
    const close = () => modals.forEach((modal) => { modal.hidden = true; });
    root.querySelector('[data-open-cancel]')?.addEventListener('click', () => open('cancel'));
    root.querySelector('[data-open-delivery]')?.addEventListener('click', () => open('delivery'));
    modals.forEach((modal) => modal.querySelectorAll('[data-close-modal]').forEach((button) => button.addEventListener('click', close)));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') close(); });

    root.parentElement.querySelectorAll('form[data-submit-once]').forEach((form) => form.addEventListener('submit', () => {
        form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
    }));

    const method = root.parentElement.querySelector('[data-delivery-method]');
    const receivedField = root.parentElement.querySelector('[data-delivery-received-field]');
    const received = root.parentElement.querySelector('[data-delivery-received]');
    const change = root.parentElement.querySelector('[data-delivery-change]');
    const updateCash = () => {
        const option = method?.selectedOptions?.[0];
        const cash = option?.dataset.affectsCash === '1';
        if (receivedField) receivedField.hidden = !cash;
        if (received) received.disabled = !cash;
        if (change) {
            try { change.textContent = cash && received.value ? `Vuelto ${formatMoney(subtractMoney(received.value, root.parentElement.querySelector('[name="final_payment[amount]"]')?.value || '0.00'))}` : ''; } catch { change.textContent = ''; }
        }
    };
    method?.addEventListener('change', updateCash);
    received?.addEventListener('input', updateCash);
}
