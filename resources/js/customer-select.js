/**
 * The entity selector of the project form.
 *
 * This file belongs to the package, not to the host application: the field it drives is the
 * integration's own, and its behaviour does not change when the host changes. A host that installs
 * this package imports it from its own entry point:
 *
 *     import { initializeEntitySelects } from './vendor/entity-projects13/js/entity-select.js';
 *
 * or, with the asset published under `resources/js/vendor/entity-projects13`:
 *
 *     import { initializeEntitySelects } from '../../vendor/entity-projects13/entity-select.js';
 *
 * and then calls `initializeEntitySelects()` once the page is ready. `jquery` and `select2` are
 * read off the window, so the host decides which copy it bundles; nothing is imported here.
 */
export function initializeEntitySelects(root = document) {
    if (root instanceof Element && root.matches('[data-project-entity-select]')) {
        initializeEntitySelect(root);
    }

    root.querySelectorAll?.('[data-project-entity-select]').forEach((element) => {
        initializeEntitySelect(element);
    });
}

function renderEntitySelection(data, element) {
    if (data.element?.dataset.active !== 'false') {
        return data.text;
    }

    return renderArchivedEntity(data.text, element);
}

function renderEntityResult(data, element) {
    if (data.active !== false) {
        return data.text;
    }

    return renderArchivedEntity(data.text, element);
}

function renderArchivedEntity(text, element) {
    const selection = document.createElement('span');
    selection.append(document.createTextNode(`${text} `));

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
export function observeEntitySelects(root = document.documentElement) {
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    initializeEntitySelects(node);
                }
            });
        });
    });

    observer.observe(root, { childList: true, subtree: true });

    return observer;
}

function initializeEntitySelect(element) {
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
        templateSelection: (data) => renderEntitySelection(data, element),
        templateResult: (data) => renderEntityResult(data, element),
        tags: true,
        ajax: {
            url: element.dataset.entitySearchUrl,
            dataType: 'json',
            delay: 250,
            headers: { Accept: 'application/json' },
            data: (params) => ({ q: params.term ?? '' }),
            processResults: (data) => {
                const projectForm = window.Alpine.$data(form).form;
                const selectedArchivedEntity = projectForm.method === 'PUT'
                    && projectForm.entity_active === false
                    && projectForm.entity_id
                    && projectForm.entity_name
                    ? [{
                        id: String(projectForm.entity_id),
                        text: projectForm.entity_name,
                        active: false,
                    }]
                    : [];
                const archivedEntityId = selectedArchivedEntity[0]?.id;
                const results = archivedEntityId
                    ? data.results.filter((result) => String(result.id) !== archivedEntityId)
                    : data.results;

                return {
                    results: [...selectedArchivedEntity, ...results].map((result) => ({
                        ...result,
                        // Select2 4.0.13 calls its AJAX normalizer unbound.
                        _resultId: `select2-${element.id}-result-${result.id}`,
                    })),
                };
            },
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
                id: `new-entity:${name}`,
                text: `${element.dataset.createLabel}: ${name}`,
                newTag: true,
                entityName: name,
            };
        },
    }).on('select2:select', async (event) => {
        const option = event.params.data;
        const projectForm = window.Alpine.$data(form);

        if (!option.newTag) {
            projectForm.form.entity_name = option.text;
            projectForm.form.entity_active = option.active === false ? false : true;
            projectForm.form.entityCreateError = '';
            element.dispatchEvent(new Event('change', { bubbles: true }));

            return;
        }

        const entityForm = element.closest('form');
        const temporaryValue = String(option.id);
        const csrfToken = entityForm.querySelector('input[name="_token"]').value;

        projectForm.form.entityCreateError = '';
        projectForm.form.entityCreating = true;

        try {
            const response = await fetch(element.dataset.entityStoreUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ name: option.entityName }),
            });
            const result = await response.json();

            if (!response.ok) {
                throw new Error(result.errors?.name?.[0] ?? result.message ?? element.dataset.createError);
            }

            element.querySelector(`option[value="${CSS.escape(temporaryValue)}"]`)?.remove();

            const entityOption = new Option(result.name, String(result.id), true, true);
            entityOption.dataset.active = 'true';
            element.add(entityOption);
            projectForm.form.entity_name = result.name;
            projectForm.form.entity_active = true;
            select.val(String(result.id));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (error) {
            element.querySelector(`option[value="${CSS.escape(temporaryValue)}"]`)?.remove();
            select.val(null).trigger('change.select2');
            element.dispatchEvent(new Event('change', { bubbles: true }));
            projectForm.form.entity_name = '';
            projectForm.form.entity_active = null;
            projectForm.form.entityCreateError = error.message || element.dataset.createError;
        } finally {
            projectForm.form.entityCreating = false;
        }
    });
}