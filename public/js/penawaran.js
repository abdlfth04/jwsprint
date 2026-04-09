document.addEventListener('DOMContentLoaded', function () {
    const rowsContainer = document.getElementById('quotationItems');
    const addButton = document.getElementById('addQuotationRow');
    const template = document.getElementById('quotationItemTemplate');
    const discountInput = document.getElementById('quotationDiscount');
    const taxInput = document.getElementById('quotationTax');
    const subtotalDisplay = document.getElementById('quotationSubtotalDisplay');
    const discountDisplay = document.getElementById('quotationDiscountDisplay');
    const taxDisplay = document.getElementById('quotationTaxDisplay');
    const grandTotalDisplay = document.getElementById('quotationGrandTotalDisplay');

    if (!rowsContainer || !template) {
        return;
    }

    function parseNumber(value) {
        const normalized = parseFloat(value || '0');
        return Number.isFinite(normalized) ? normalized : 0;
    }

    const formatCurrency = function(value) {
        return window.jwsFormatCurrency(parseNumber(value));
    };

    function syncRowSubtotal(row) {
        const qty = parseNumber(row.querySelector('.quotation-qty-input')?.value);
        const price = parseNumber(row.querySelector('.quotation-price-input')?.value);
        const finishCost = parseNumber(row.querySelector('.quotation-finish-input')?.value);
        const subtotal = (qty * price) + finishCost;
        const subtotalInput = row.querySelector('.quotation-row-subtotal');
        if (subtotalInput) {
            subtotalInput.value = subtotal.toFixed(2);
        }

        return subtotal;
    }

    function syncSummary() {
        let subtotal = 0;
        rowsContainer.querySelectorAll('[data-quotation-row]').forEach(function (row) {
            subtotal += syncRowSubtotal(row);
        });

        const discount = parseNumber(discountInput?.value);
        const tax = parseNumber(taxInput?.value);
        const grandTotal = Math.max(0, subtotal - discount + tax);

        if (subtotalDisplay) subtotalDisplay.textContent = formatCurrency(subtotal);
        if (discountDisplay) discountDisplay.textContent = formatCurrency(discount);
        if (taxDisplay) taxDisplay.textContent = formatCurrency(tax);
        if (grandTotalDisplay) grandTotalDisplay.textContent = formatCurrency(grandTotal);
    }

    function renumberRows() {
        rowsContainer.querySelectorAll('[data-quotation-row]').forEach(function (row, index) {
            const label = row.querySelector('.quotation-item-index');
            if (label) {
                label.textContent = String(index + 1);
            }
        });
    }

    function bindRow(row) {
        const productSelect = row.querySelector('.quotation-product-select');
        const itemNameInput = row.querySelector('.quotation-item-name');
        const categorySelect = row.querySelector('.quotation-category-select');
        const unitInput = row.querySelector('.quotation-unit-input');
        const removeButton = row.querySelector('[data-remove-quotation-row]');

        if (productSelect) {
            productSelect.addEventListener('change', function () {
                const selectedOption = productSelect.options[productSelect.selectedIndex];
                if (!selectedOption || !selectedOption.value) {
                    return;
                }

                if (itemNameInput) itemNameInput.value = selectedOption.dataset.name || '';
                if (categorySelect) categorySelect.value = selectedOption.dataset.category || 'lainnya';
                if (unitInput) unitInput.value = selectedOption.dataset.unit || 'pcs';

                const priceInput = row.querySelector('.quotation-price-input');
                if (priceInput) {
                    priceInput.value = selectedOption.dataset.price || '0';
                }

                syncSummary();
            });
        }

        row.querySelectorAll('.quotation-calc').forEach(function (input) {
            input.addEventListener('input', syncSummary);
        });

        if (removeButton) {
            removeButton.addEventListener('click', function () {
                row.remove();
                if (!rowsContainer.querySelector('[data-quotation-row]')) {
                    addRow();
                }
                renumberRows();
                syncSummary();
            });
        }
    }

    function addRow() {
        const fragment = template.content.cloneNode(true);
        const row = fragment.querySelector('[data-quotation-row]');
        if (!row) {
            return;
        }

        rowsContainer.appendChild(fragment);
        const appendedRow = rowsContainer.lastElementChild;
        bindRow(appendedRow);
        renumberRows();
        syncSummary();
    }

    if (addButton) {
        addButton.addEventListener('click', addRow);
    }

    rowsContainer.querySelectorAll('[data-quotation-row]').forEach(function (row) {
        bindRow(row);
    });

    if (discountInput) discountInput.addEventListener('input', syncSummary);
    if (taxInput) taxInput.addEventListener('input', syncSummary);

    renumberRows();
    syncSummary();
});
