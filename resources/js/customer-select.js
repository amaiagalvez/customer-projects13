/**
 * The customer selector of the project form.
 *
 * This file belongs to the package, not to the host application: the field it drives is the
 * integration's own, and its behaviour does not change when the host changes. A host that installs
 * this package imports it from its own entry point:
 *
 *     import { initializeCustomerSelects } from './vendor/customer-projects13/js/customer-select.js';
 *
 * or, with the asset published under `resources/js/vendor/customer-projects13`:
 *
 *     import { initializeCustomerSelects } from '../../vendor/customer-projects13/customer-select.js';
 *
 * and then calls `initializeCustomerSelects()` once the page is ready. `jquery` and `select2` are
 * read off the window, so the host decides which copy it bundles; nothing is imported here.
 */
export function initializeCustomerSelects(root = document) {
    if (root instanceof Element && root.matches('[data-project-customer-select]')) {
        initializeCustomerSelect(root);
    }

    root.querySelectorAll?.('[data-project-customer-select]').forEach((element) => {
        initializeCustomerSelect(element);
    });
}

function renderCustomerSelection(data, element) {
    if (data.element?.dataset.active !== 'false') {
        return data.text;
    }

    const selection = document.createElement('span');
    selection.append(document.createTextNode(`${data.text} `));

    const badge = document.createElement('span');
    badge.className = 'rounded-full bg-zinc-800/5 px-2 py-0.5 text-xs text-zinc-600 dark:bg-white/10 dark:text-zinc-300';
    badge.textContent = element.dataset.archivedLabel;
    selection.append(badge);

    return window.jQuery(selection);
}

/**
 * Every drawer in the page is re-rendered when it opens, so the selects are watched instead of
 * being bound once on load: an element that was not in the DOM yet has never seen select2.
 */
export function observeCustomerSelects(root = document.documentElement) {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    initializeCustomerSelects(node);
                }
            });
        });
    });

    observer.observe(root, { childList: true, subtree: true });

    return observer;
}

function initializeCustomerSelect(element) {
    const $ = window.jQuery;

    if (!$) {
        return;
    }

    const select = $(element);
    const form = document.querySelector('[data-project-form-root]');

    if (!form || select.hasClass('select2-hidden-accessible')) {
        return;
    }

    select.select2({
        width: '100%',
        dropdownParent: $(element.closest('dialog')),
        placeholder: element.dataset.placeholder,
        allowClear: true,
        templateSelection: (data) => renderCustomerSelection(data, element),
        tags: true,
        ajax: {
            url: element.dataset.customerSearchUrl,
            dataType: 'json',
            delay: 250,
            headers: { Accept: 'application/json' },
            data: (params) => ({ q: params.term ?? '' }),
            processResults: (data) => ({
                results: data.results.map((result) => ({
                    ...result,
                    // Select2 4.0.13 calls its AJAX normalizer unbound.
                    _resultId: `select2-${element.id}-result-${result.id}`,
                })),
            }),
            cache: true,
        },
        language: {
            noResults: () => element.dataset.noResultsLabel,
            errorLoading: () => element.dataset.searchError,
        },
        createTag: (params) => {
            const name = params.term.trim();

            if (!name || Array.from(element.options).some((option) =>
                option.text.trim().toLocaleLowerCase() === name.toLocaleLowerCase()
            )) {
                return null;
            }

            return {
                id: `new-customer:${name}`,
                text: `${element.dataset.createLabel}: ${name}`,
                newTag: true,
                customerName: name,
            };
        },
    }).on('select2:select', async (event) => {
        const option = event.params.data;
        const projectForm = window.Alpine.$data(form);

        if (!option.newTag) {
            projectForm.form.customer_name = option.text;
            projectForm.form.customer_active = true;
            projectForm.form.customerCreateError = '';
            element.dispatchEvent(new Event('change', { bubbles: true }));

            return;
        }

        const customerForm = element.closest('form');
        const temporaryValue = String(option.id);
        const csrfToken = customerForm.querySelector('input[name="_token"]').value;

        projectForm.form.customerCreateError = '';
        projectForm.form.customerCreating = true;

        try {
            const response = await fetch(element.dataset.customerStoreUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ name: option.customerName }),
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.errors?.name?.[0] ?? result.message ?? element.dataset.createError);
            }

            element.querySelector(`option[value="${CSS.escape(temporaryValue)}"]`)?.remove();

            const customerOption = new Option(result.name, String(result.id), true, true);
            customerOption.dataset.active = 'true';
            element.add(customerOption);
            projectForm.form.customer_name = result.name;
            projectForm.form.customer_active = true;
            select.val(String(result.id));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (error) {
            element.querySelector(`option[value="${CSS.escape(temporaryValue)}"]`)?.remove();
            select.val(null).trigger('change.select2');
            element.dispatchEvent(new Event('change', { bubbles: true }));
            projectForm.form.customer_name = '';
            projectForm.form.customer_active = null;
            projectForm.form.customerCreateError = error.message || element.dataset.createError;
        } finally {
            projectForm.form.customerCreating = false;
        }
    });
}