import './bootstrap';
import './private-page';
import './login-form';

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
    const methods = Array.isArray(window.panGestionPaymentMethods) ? window.panGestionPaymentMethods : [];
    const products = new Map();
    const cart = [];
    const paymentRows = [];

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
            cart.push({ product, quantity: normalizeQuantity(line.quantity) });
        }
    });

    initialPayments.forEach((payment) => {
        paymentRows.push({
            methodId: String(payment.payment_method_id || methods[0]?.id || ''),
            amount: normalizeOptionalMoney(payment.amount),
            received: normalizeOptionalMoney(payment.received_amount),
            uuid: payment.payment_uuid || makeUuid(),
        });
    });

    if (cart.length > 0 && paymentRows.length === 0) {
        paymentRows.push(newPaymentRow(totalForCart()));
    }

    const money = (value) => formatMoney(value);

    function setFeedback(message = '', tone = 'neutral') {
        searchFeedback.textContent = message;
        searchFeedback.className = `sales-feedback${message ? ` is-${tone}` : ''}`;
    }

    function searchProducts(event) {
        event.preventDefault();
        const term = searchInput.value.trim();

        if (term === '') {
            setFeedback('Escribe un nombre, SKU, ID o código de barras.', 'error');
            return;
        }

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
                    addProduct(results[0]);
                    searchInput.value = '';
                    setFeedback(`${results[0].nombre} agregado al carrito.`, 'success');
                    focusSearch();
                    return;
                }

                renderResults(results);
                setFeedback(results.length === 0 ? 'No encontramos productos vendibles.' : 'Selecciona un producto para agregarlo.', 'neutral');
            })
            .catch(() => setFeedback('No se pudo consultar el catálogo. Inténtalo nuevamente.', 'error'));
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
                    <small>Stock ${escapeHtml(product.stock)}</small>
                </span>
            </button>
        `).join('');
    }

    function addProduct(product) {
        if (!product || product.precio === null) {
            setFeedback('El producto no tiene un precio de venta vigente.', 'error');
            return;
        }

        const existing = cart.find((line) => String(line.product.id) === String(product.id));
        if (existing) {
            existing.quantity = addQuantity(existing.quantity, '1.000');
        } else {
            cart.push({ product, quantity: '1.000' });
        }

        if (paymentRows.length === 0) {
            paymentRows.push(newPaymentRow(totalForCart()));
        }

        markIntentChanged();
        renderCart();
    }

    function renderCart() {
        cartEmpty.hidden = cart.length > 0;
        cartCount.textContent = `${cart.length} ${cart.length === 1 ? 'producto' : 'productos'}`;
        cartTotal.textContent = money(totalForCart());

        cartLines.querySelectorAll('[data-cart-line]').forEach((line) => line.remove());
        cart.forEach((line, index) => {
            const row = document.createElement('div');
            row.className = 'cart-line';
            row.dataset.cartLine = String(index);
            row.innerHTML = `
                <div class="cart-line-info">
                    <strong>${escapeHtml(line.product.nombre)}</strong>
                    <small>${escapeHtml(line.product.sku || 'Sin SKU')} · ${money(line.product.precio)} por ${escapeHtml(line.product.unidad_simbolo || line.product.unidad_codigo || 'unidad')}</small>
                    <small class="stock-note">Stock disponible: ${escapeHtml(line.product.stock)}</small>
                </div>
                <div class="cart-line-controls">
                    <button type="button" class="quantity-button" data-quantity-action="decrease" aria-label="Disminuir ${escapeHtml(line.product.nombre)}">−</button>
                    <input class="quantity-input" inputmode="decimal" aria-label="Cantidad de ${escapeHtml(line.product.nombre)}" data-quantity-input value="${escapeHtml(line.quantity)}">
                    <button type="button" class="quantity-button" data-quantity-action="increase" aria-label="Aumentar ${escapeHtml(line.product.nombre)}">+</button>
                </div>
                <strong class="cart-line-subtotal">${money(subtotalFor(line))}</strong>
                <button type="button" class="remove-line" data-remove-line aria-label="Eliminar ${escapeHtml(line.product.nombre)}">×</button>
            `;
            cartLines.appendChild(row);
        });

        renderPayments();
        syncHiddenInputs();
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
                            <label for="payment-amount-${index}">Monto</label>
                            <input id="payment-amount-${index}" class="form-input payment-amount-input" inputmode="decimal" data-payment-amount value="${escapeHtml(payment.amount)}">
                        </div>
                        <div class="field-group payment-received-field${isCash ? '' : ' is-hidden'}">
                            <label for="payment-received-${index}">Recibido</label>
                            <input id="payment-received-${index}" class="form-input payment-received-input" inputmode="decimal" data-payment-received value="${escapeHtml(payment.received)}" ${isCash ? '' : 'disabled'}>
                            <small class="payment-change" data-payment-change>${isCash ? `Vuelto ${money(changeFor(payment))}` : ''}</small>
                        </div>
                        <button type="button" class="remove-payment" data-remove-payment aria-label="Eliminar método de pago">×</button>
                    </div>
                `;
            }).join('');
        }

        const total = totalForCart();
        const paid = paidForPayments();
        const balance = subtractMoney(total, paid);
        paymentTotal.textContent = money(total);
        paidTotal.textContent = money(paid);
        paymentBalance.textContent = money(balance);
        paymentBalance.classList.toggle('is-settled', balance === '0.00');
        paymentBalance.classList.toggle('is-over', compareMoney(balance, '0.00') < 0);
    }

    function syncHiddenInputs() {
        lineInputs.innerHTML = cart.map((line, index) => `
            <input type="hidden" name="lines[${index}][article_id]" value="${escapeHtml(line.product.id)}">
            <input type="hidden" name="lines[${index}][quantity]" value="${escapeHtml(line.quantity)}">
        `).join('');
        paymentInputs.innerHTML = paymentRows.map((payment, index) => `
            <input type="hidden" name="payments[${index}][payment_method_id]" value="${escapeHtml(payment.methodId)}">
            <input type="hidden" name="payments[${index}][amount]" value="${escapeHtml(payment.amount)}">
            <input type="hidden" name="payments[${index}][received_amount]" value="${escapeHtml(payment.received)}">
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

    function changeFor(payment) {
        const method = methodFor(payment.methodId);
        if (!method?.afecta_efectivo || payment.received === '') {
            return '0.00';
        }

        try {
            const received = normalizeMoney(payment.received);
            const amount = normalizeMoney(payment.amount || '0.00');
            return compareMoney(received, amount) >= 0 ? subtractMoney(received, amount) : '0.00';
        } catch {
            return '0.00';
        }
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
        addProduct(product);
        searchInput.value = '';
        searchResults.innerHTML = '';
        setFeedback(`${product.nombre} agregado al carrito.`, 'success');
        focusSearch();
    });

    cartLines.addEventListener('click', (event) => {
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
            line.quantity = addQuantity(line.quantity, '1.000');
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
        if (!event.target.matches('[data-quantity-input]')) {
            return;
        }
        const lineElement = event.target.closest('[data-cart-line]');
        const line = cart[Number(lineElement?.dataset.cartLine)];
        try {
            const quantity = normalizeQuantity(event.target.value);
            if (compareQuantity(quantity, '0.000') <= 0) {
                throw new Error('quantity');
            }
            line.quantity = quantity;
        } catch {
            event.target.value = line.quantity;
            setFeedback('La cantidad debe ser mayor que cero y tener hasta tres decimales.', 'error');
            return;
        }
        markIntentChanged();
        renderCart();
    });

    paymentLines.addEventListener('change', (event) => {
        const lineElement = event.target.closest('[data-payment-line]');
        const payment = paymentRows[Number(lineElement?.dataset.paymentLine)];
        if (!payment) {
            return;
        }

        if (event.target.matches('[data-payment-method]')) {
            payment.methodId = event.target.value;
            if (!methodFor(payment.methodId)?.afecta_efectivo) {
                payment.received = '';
            }
        }
        if (event.target.matches('[data-payment-amount]')) {
            payment.amount = event.target.value.trim();
        }
        if (event.target.matches('[data-payment-received]')) {
            payment.received = event.target.value.trim();
        }
        markIntentChanged();
        renderPayments();
        syncHiddenInputs();
    });

    paymentLines.addEventListener('click', (event) => {
        if (!event.target.closest('[data-remove-payment]')) {
            return;
        }
        const lineElement = event.target.closest('[data-payment-line]');
        paymentRows.splice(Number(lineElement?.dataset.paymentLine), 1);
        markIntentChanged();
        renderPayments();
        syncHiddenInputs();
    });

    addPaymentButton?.addEventListener('click', () => {
        paymentRows.push(newPaymentRow());
        markIntentChanged();
        renderPayments();
        syncHiddenInputs();
    });

    checkoutForm.addEventListener('submit', (event) => {
        syncHiddenInputs();
        const total = totalForCart();
        const paid = paidForPayments();
        const balance = subtractMoney(total, paid);

        if (cart.length === 0) {
            event.preventDefault();
            setFeedback('Agrega al menos un producto antes de cobrar.', 'error');
            focusSearch();
            return;
        }

        if (compareMoney(balance, '0.00') !== 0) {
            event.preventDefault();
            setFeedback('La suma de los pagos debe coincidir exactamente con el total.', 'error');
            return;
        }

        for (const payment of paymentRows) {
            const method = methodFor(payment.methodId);
            if (method?.afecta_efectivo && (payment.received === '' || compareMoney(payment.received, payment.amount) < 0)) {
                event.preventDefault();
                setFeedback('El efectivo recibido debe cubrir el monto del pago.', 'error');
                return;
            }
        }

        checkoutButton.disabled = true;
        checkoutButton.setAttribute('aria-busy', 'true');
        checkoutButton.textContent = 'Procesando…';
    });

    renderCart();

    function parseJson(value, fallback) {
        try {
            return value ? JSON.parse(value) : fallback;
        } catch {
            return fallback;
        }
    }
}

function normalizeQuantity(value) {
    return normalizeDecimal(value, 3);
}

function normalizeMoney(value) {
    return normalizeDecimal(value, 2);
}

function normalizeOptionalMoney(value) {
    return value === null || value === undefined || value === '' ? '' : normalizeMoney(value);
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

function initializeOrderCreate() {
    const root = document.querySelector('[data-order-create]');
    if (!root) {
        return;
    }

    const form = root.querySelector('[data-order-form]');
    const articleInput = root.querySelector('[data-order-article-search-input]');
    const articleButton = root.querySelector('[data-order-article-search]');
    const articleResults = root.querySelector('[data-order-article-results]');
    const cartLines = root.querySelector('[data-order-cart-lines]');
    const cartEmpty = root.querySelector('[data-order-cart-empty]');
    const lineInputs = root.querySelector('[data-order-line-inputs]');
    const paymentLines = root.querySelector('[data-order-payment-lines]');
    const paymentInputs = root.querySelector('[data-order-payment-inputs]');
    const totalElement = root.querySelector('[data-order-total]');
    const clientInput = root.querySelector('[data-client-search]');
    const clientResults = root.querySelector('[data-client-results]');
    const selectedClient = root.querySelector('[data-selected-client]');
    const clientIdInput = root.querySelector('[data-client-id]');
    const sessionInput = root.querySelector('[data-order-cash-session]');
    const addPayment = root.querySelector('[data-add-order-payment]');
    const paymentMethods = Array.isArray(window.panGestionOrderPaymentMethods) ? window.panGestionOrderPaymentMethods : [];
    const products = new Map();
    const clients = new Map();
    const cart = [];
    const payments = [];

    Object.values(window.panGestionOrderArticles || {}).forEach((product) => {
        if (product?.id) {
            products.set(String(product.id), product);
        }
    });

    (Array.isArray(window.panGestionOrderLines) ? window.panGestionOrderLines : []).forEach((line) => {
        const product = products.get(String(line.article_id));
        if (!product) {
            return;
        }
        try {
            cart.push({ product, quantity: normalizeQuantity(line.quantity) });
        } catch {
            return;
        }
    });

    (Array.isArray(window.panGestionOrderPayments) ? window.panGestionOrderPayments : []).forEach((payment) => {
        payments.push({
            methodId: String(payment.payment_method_id || paymentMethods[0]?.id || ''),
            amount: normalizeOptionalMoney(payment.amount),
            received: normalizeOptionalMoney(payment.received_amount),
            uuid: payment.payment_uuid || makeUuid(),
        });
    });

    function markChanged() {
        const command = root.querySelector('[data-order-command-uuid]');
        if (command) {
            command.value = makeUuid();
        }
        payments.forEach((payment) => { payment.uuid = makeUuid(); });
    }

    function methodFor(id) {
        return paymentMethods.find((method) => String(method.id) === String(id));
    }

    function renderArticles(results) {
        articleResults.innerHTML = results.map((product) => `
            <button type="button" class="picker-result" data-order-product-id="${escapeHtml(product.id)}">
                <span><strong>${escapeHtml(product.nombre)}</strong><small>${escapeHtml(product.sku || 'Sin SKU')} · ${escapeHtml(product.unidad_simbolo || 'unidad')}</small></span>
                <strong>${formatMoney(product.precio)}</strong>
            </button>
        `).join('');
    }

    function searchArticles(event) {
        event?.preventDefault();
        const term = articleInput.value.trim();
        if (!term) {
            articleResults.innerHTML = '';
            return;
        }
        fetch(`${root.dataset.articleSearchUrl}?term=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('search')))
            .then((payload) => {
                const results = Array.isArray(payload.data) ? payload.data : [];
                results.forEach((product) => products.set(String(product.id), product));
                if (payload.exact_match && results.length === 1) {
                    addProduct(results[0]);
                    articleInput.value = '';
                    articleResults.innerHTML = '';
                    articleInput.focus();
                    return;
                }
                renderArticles(results);
            })
            .catch(() => { articleResults.innerHTML = '<p class="field-help">No se pudo consultar el catálogo.</p>'; });
    }

    function addProduct(product) {
        const existing = cart.find((line) => String(line.product.id) === String(product.id));
        if (existing) {
            existing.quantity = addQuantity(existing.quantity, '1.000');
        } else {
            cart.push({ product, quantity: '1.000' });
        }
        markChanged();
        render();
    }

    function render() {
        cartEmpty.hidden = cart.length > 0;
        totalElement.textContent = formatMoney(total());
        cartLines.innerHTML = cart.map((line, index) => `
            <div class="order-cart-line" data-order-cart-line="${index}">
                <div><strong>${escapeHtml(line.product.nombre)}</strong><small>${escapeHtml(line.product.sku || 'Sin SKU')} · ${formatMoney(line.product.precio)} por ${escapeHtml(line.product.unidad_simbolo || 'unidad')}</small></div>
                <div class="order-quantity-controls">
                    <button class="quantity-button" type="button" data-order-quantity="down" aria-label="Disminuir cantidad">−</button>
                    <input class="quantity-input" inputmode="decimal" value="${escapeHtml(line.quantity)}" data-order-quantity-input aria-label="Cantidad de ${escapeHtml(line.product.nombre)}">
                    <button class="quantity-button" type="button" data-order-quantity="up" aria-label="Aumentar cantidad">+</button>
                </div>
                <strong>${formatMoney(subtotal(line))}</strong>
                <button class="remove-line" type="button" data-order-remove aria-label="Quitar artículo">×</button>
            </div>
        `).join('');

        paymentLines.innerHTML = payments.length === 0
            ? '<p class="empty-inline">Sin anticipo. Puedes crearlo sin pago inicial.</p>'
            : payments.map((payment, index) => {
                const method = methodFor(payment.methodId);
                const cash = Boolean(method?.afecta_efectivo);
                return `<div class="order-payment-line" data-order-payment-line="${index}">
                    <label class="field-group"><span>Método</span><select class="form-input" data-order-payment-method>${paymentMethods.map((option) => `<option value="${option.id}"${String(option.id) === String(payment.methodId) ? ' selected' : ''}>${escapeHtml(option.nombre)}</option>`).join('')}</select></label>
                    <label class="field-group"><span>Monto</span><input class="form-input" inputmode="decimal" data-order-payment-amount value="${escapeHtml(payment.amount)}"></label>
                    <label class="field-group${cash ? '' : ' is-hidden'}"><span>Recibido</span><input class="form-input" inputmode="decimal" data-order-payment-received value="${escapeHtml(payment.received)}" ${cash ? '' : 'disabled'}></label>
                    <button class="remove-payment" type="button" data-order-remove-payment aria-label="Quitar anticipo">×</button>
                </div>`;
            }).join('');

        lineInputs.innerHTML = cart.map((line, index) => `<input type="hidden" name="lines[${index}][article_id]" value="${line.product.id}"><input type="hidden" name="lines[${index}][quantity]" value="${line.quantity}">`).join('');
        paymentInputs.innerHTML = payments.map((payment, index) => `<input type="hidden" name="payments[${index}][payment_method_id]" value="${escapeHtml(payment.methodId)}"><input type="hidden" name="payments[${index}][amount]" value="${escapeHtml(payment.amount)}"><input type="hidden" name="payments[${index}][received_amount]" value="${escapeHtml(payment.received)}"><input type="hidden" name="payments[${index}][payment_uuid]" value="${escapeHtml(payment.uuid)}">`).join('');
    }

    function total() {
        return cart.reduce((value, line) => addMoney(value, subtotal(line)), '0.00');
    }

    function subtotal(line) {
        return multiplyMoney(line.quantity, line.product.precio);
    }

    function renderClients(items) {
        items.forEach((client) => clients.set(String(client.id), client));
        clientResults.innerHTML = items.map((client) => `<button type="button" class="picker-result" data-order-client-id="${client.id}"><span><strong>${escapeHtml(client.nombre)}</strong><small>${escapeHtml(client.correo || 'Sin correo')}</small></span><span>#${client.id}</span></button>`).join('');
    }

    function searchClients() {
        const term = clientInput.value.trim();
        fetch(`${root.dataset.clientSearchUrl}?term=${encodeURIComponent(term)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('search')))
            .then((payload) => renderClients(Array.isArray(payload.data) ? payload.data : []))
            .catch(() => { clientResults.innerHTML = '<p class="field-help">No se pudo consultar clientes.</p>'; });
    }

    articleInput?.addEventListener('keydown', (event) => { if (event.key === 'Enter') searchArticles(event); });
    articleButton?.addEventListener('click', searchArticles);
    articleResults?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-product-id]');
        const product = products.get(String(button?.dataset.orderProductId));
        if (product) {
            addProduct(product);
            articleInput.value = '';
            articleResults.innerHTML = '';
            articleInput.focus();
        }
    });
    clientInput?.addEventListener('input', searchClients);
    clientResults?.addEventListener('click', (event) => {
        const button = event.target.closest('[data-order-client-id]');
        const client = clients.get(String(button?.dataset.orderClientId));
        if (!client) return;
        clientIdInput.value = client.id;
        selectedClient.textContent = `Seleccionado: ${client.nombre}`;
        clientResults.innerHTML = '';
        clientInput.value = client.nombre;
    });
    cartLines?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-order-cart-line]');
        if (!row) return;
        const index = Number(row.dataset.orderCartLine);
        if (event.target.closest('[data-order-remove]')) cart.splice(index, 1);
        if (event.target.closest('[data-order-quantity="up"]')) cart[index].quantity = addQuantity(cart[index].quantity, '1.000');
        if (event.target.closest('[data-order-quantity="down"]')) {
            const next = subtractQuantity(cart[index].quantity, '1.000');
            if (compareQuantity(next, '0.000') <= 0) cart.splice(index, 1); else cart[index].quantity = next;
        }
        markChanged();
        render();
    });
    cartLines?.addEventListener('change', (event) => {
        if (!event.target.matches('[data-order-quantity-input]')) return;
        const row = event.target.closest('[data-order-cart-line]');
        const line = cart[Number(row.dataset.orderCartLine)];
        try {
            const quantity = normalizeQuantity(event.target.value);
            if (compareQuantity(quantity, '0.000') <= 0) throw new Error('quantity');
            line.quantity = quantity;
            markChanged();
            render();
        } catch { event.target.value = line.quantity; }
    });
    paymentLines?.addEventListener('change', (event) => {
        const row = event.target.closest('[data-order-payment-line]');
        const payment = payments[Number(row?.dataset.orderPaymentLine)];
        if (!payment) return;
        if (event.target.matches('[data-order-payment-method]')) {
            payment.methodId = event.target.value;
            if (!methodFor(payment.methodId)?.afecta_efectivo) payment.received = '';
        }
        if (event.target.matches('[data-order-payment-amount]')) payment.amount = event.target.value.trim();
        if (event.target.matches('[data-order-payment-received]')) payment.received = event.target.value.trim();
        markChanged();
        render();
    });
    paymentLines?.addEventListener('click', (event) => {
        const row = event.target.closest('[data-order-payment-line]');
        if (!row || !event.target.closest('[data-order-remove-payment]')) return;
        payments.splice(Number(row.dataset.orderPaymentLine), 1);
        markChanged();
        render();
    });
    addPayment?.addEventListener('click', () => {
        const balance = subtractMoney(total(), payments.reduce((value, payment) => {
            try { return addMoney(value, normalizeMoney(payment.amount)); } catch { return value; }
        }, '0.00'));
        payments.push({ methodId: String(paymentMethods[0]?.id || ''), amount: compareMoney(balance, '0.00') > 0 ? balance : '', received: '', uuid: makeUuid() });
        markChanged();
        render();
    });
    form?.addEventListener('submit', (event) => {
        if (!clientIdInput.value || cart.length === 0) {
            event.preventDefault();
            selectedClient.textContent = !clientIdInput.value ? 'Selecciona un cliente para continuar.' : 'Agrega al menos un artículo.';
            return;
        }
        if (payments.length > 0 && !sessionInput.value) {
            event.preventDefault();
            sessionInput.focus();
            return;
        }
        const submit = form.querySelector('[data-order-submit]');
        if (submit) { submit.disabled = true; submit.setAttribute('aria-busy', 'true'); submit.textContent = 'Creando…'; }
    });

    render();
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
