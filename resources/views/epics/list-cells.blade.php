{{-- The customer cell of the epic list. --}}
<flux:table.cell class="max-w-[16rem] truncate" :title="$row['customer'] ?? null">
    {{ $row['customer'] ?? '' }}
</flux:table.cell>
