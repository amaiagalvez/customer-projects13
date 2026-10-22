{{-- The entity cell of the epic list. --}}
<flux:table.cell class="max-w-[16rem] truncate" :title="$row['entity'] ?? null">
    <span class="inline-flex items-center gap-1">
        {{ $row['entity'] ?? '' }}
        @if (isset($row['entity_active']) && $row['entity_active'] === false)
            <span
                class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                {{ __('basics13::messages.archived_label') }}
            </span>
        @endif
    </span>
</flux:table.cell>
