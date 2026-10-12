{{-- The cells that go with the three count columns above, in the same order. --}}
<flux:table.cell>
    <x-basics13::list.count icon="briefcase" :count="$row['projectsCount'] ?? 0"
        :url="$row['projectsUrl'] ?? null"
        :label="__('View :count projects', ['count' => $row['projectsCount'] ?? 0])"
        data-test="customer-projects-count-{{ $row['id'] }}" />
</flux:table.cell>
<flux:table.cell>
    <x-basics13::list.count icon="flag" :count="$row['epicsCount'] ?? 0"
        :label="__('Epics: :count', ['count' => $row['epicsCount'] ?? 0])"
        data-test="customer-epics-count-{{ $row['id'] }}" />
</flux:table.cell>
<flux:table.cell>
    <x-basics13::list.count icon="chat-bubble-left" :count="$row['commentsCount'] ?? 0"
        :label="__('Comments: :count', ['count' => $row['commentsCount'] ?? 0])"
        data-test="customer-comments-count-{{ $row['id'] }}" />
</flux:table.cell>
