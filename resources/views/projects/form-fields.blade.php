{{--
    The customer field of the project form.

    It is a plain select2 element plus the Alpine state the drawer needs: which customer is picked,
    what it is called when the option list has not loaded it yet, and the two flags the submit
    button and the inline error read. The quick-create lives here too, because creating a customer
    is only ever done from this form.

    Everything is driven by data attributes so `resources/js/customer-select.js` owns the behaviour
    and this file only owns the markup and the labels.

    The values come from the flashed submission, which the base package seeds into the drawer from
    `projects13.views.form_state`. That is why the drawer still shows the chosen customer after a
    failed save without the base package knowing that customers exist.
--}}
@php
    $selectedCustomerId = old('customer_id', '');
    $selectedCustomerName = old('customer_name', '');
@endphp

<flux:field>
    <x-basics13::forms.field-label :request="\Projects13\Http\Requests\ProjectRequest::class" field="customer_id"
        test="project-customer-info" for="project-customer-id"
        :notices="[__('Choose a customer from the list or create a new one.')]">
        {{ __('Customer') }}
    </x-basics13::forms.field-label>
    <select id="project-customer-id" name="customer_id" x-model="form.customer_id"
        x-effect="
            const customerId = String(form.customer_id || '');
            if (customerId && form.customer_name) {
                let option = Array.from($el.options).find((item) => item.value === customerId);
                if (!option) {
                    option = new Option(form.customer_name, customerId, true, true);
                    $el.add(option);
                }
                option.selected = true;
            }
            window.jQuery($el).val(customerId || null).trigger('change.select2')
        "
        required data-test="project-customer" data-project-customer-select
        data-customer-store-url="{{ route('customers.store', [], false) }}"
        data-customer-search-url="{{ route('customers.options', [], false) }}"
        data-create-label="{{ __('Create customer') }}"
        data-create-error="{{ __('Unable to create customer.') }}"
        data-search-error="{{ __('Unable to load customers.') }}"
        data-no-results-label="{{ __('No customers found.') }}"
        data-placeholder="{{ __('Select a customer') }}">
        <option></option>
        @if ($selectedCustomerId !== '')
            <option value="{{ $selectedCustomerId }}" selected>{{ $selectedCustomerName }}</option>
        @endif
    </select>
    {{-- Carried back on the next submit so a failed save can name the chosen customer again. --}}
    <input type="hidden" name="customer_name" x-model="form.customer_name">
    <flux:error name="customer_id" />
    <p role="alert" x-show="form.customerCreateError" x-text="form.customerCreateError"
        class="text-sm text-red-600" data-test="project-customer-error"></p>
</flux:field>