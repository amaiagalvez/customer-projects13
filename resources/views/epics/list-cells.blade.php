{{-- The entity cell of the epic list. --}}
<flux:table.cell class="max-w-[16rem] truncate" :title="$row['entity'] ?? null">
    <span class="inline-flex items-center gap-1">
        {{ $row['entity'] ?? '' }}
        @if (isset($row['entity_active']) && $row['entity_active'] === false)
            <span
                class="shrink-0 rounded-full bg-zinc-800/5 px-1.5 py-0.5 text-[10px] text-zinc-600 dark:bg-white/10 dark:text-zinc-300">
                {{ __('basics13::messages.archived_label') }}
            </span>
        @endif
    </span>
</flux:table.cell>
