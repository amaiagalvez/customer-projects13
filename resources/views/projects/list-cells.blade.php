{{-- The customer cell of the project list. --}}
<flux:table.cell class="max-w-[16rem] truncate" :title="$row['customer'] ?? null">
    {{ $row['customer'] ?? '' }}
</flux:table.cell>
