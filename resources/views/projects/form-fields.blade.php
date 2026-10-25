{{--
    The entity field of the project form.

    It is a plain select2 element plus the Alpine state the drawer needs: which entity is picked,
    what it is called when the option list has not loaded it yet, and the two flags the submit
    button and the inline error read. The quick-create lives here too, because creating a entity
    is only ever done from this form.

    Everything is driven by data attributes so `resources/js/entity-select.js` owns the behaviour
    and this file only owns the markup and the labels.

    The values come from the flashed submission, which the base package seeds into the drawer from
    `projects13.views.form_state`. That is why the drawer still shows the chosen entity after a
    failed save without the base package knowing that entities exist.
--}}
@php
    $selectedEntityId = old('entity_id', '');
    $selectedEntityName = old('entity_name', '');
@endphp

<flux:field>
    <x-basics13::forms.field-label :request="\Projects13\Http\Requests\ProjectRequest::class" field="entity_id" test="project-entity-info"
        for="project-entity-id" :notices="[__('Choose a entity from the list or create a new one.')]">
        {{ __('Entity') }}
    </x-basics13::forms.field-label>
    <select id="project-entity-id" name="entity_id" x-model="form.entity_id"
        x-effect="
            const entityId = String(form.entity_id || '');
            if (entityId && form.entity_name) {
                let option = Array.from($el.options).find((item) => item.value === entityId);
                if (!option) {
                    option = new Option(form.entity_name, entityId, true, true);
                    $el.add(option);
                }
                option.dataset.active = String(form.entity_active !== false);
                option.selected = true;
            }
            window.entitySelects?.setValue($el, entityId)
        "
        required data-test="project-entity" data-project-entity-select
        data-entity-store-url="{{ route('entities.store', [], false) }}"
        data-entity-search-url="{{ route('entities.options', [], false) }}"
        data-create-label="{{ __('Create entity') }}"
        data-create-error="{{ __('Unable to create entity.') }}"
        data-search-error="{{ __('Unable to load entities.') }}"
        data-no-results-label="{{ __('No entities found.') }}"
        data-archived-label="{{ __('basics13::messages.archived_label') }}"
        data-placeholder="{{ __('Select a entity') }}">
        <option></option>
        @if ($selectedEntityId !== '')
            <option value="{{ $selectedEntityId }}" selected data-active="true">
                {{ $selectedEntityName }}</option>
        @endif
    </select>
    {{-- Carried back on the next submit so a failed save can name the chosen entity again. --}}
    <input type="hidden" name="entity_name" x-model="form.entity_name">
    <flux:error name="entity_id" />
    <p role="alert" x-show="form.entityCreateError" x-text="form.entityCreateError"
        class="text-sm text-red-600" data-test="project-entity-error"></p>
</flux:field>
