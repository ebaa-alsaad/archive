@props(['group'])

<div class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-md transition">
    <div class="flex justify-between items-center mb-2">
        <h4 class="font-semibold text-gray-700">رقم القيد: {{ $group->code }}</h4>
        <span class="text-xs text-gray-500">{{ $group->created_at->diffForHumans() }}</span>
    </div>

    <p class="text-sm text-gray-600 mb-3">عدد الصفحات: {{ $group->pages_count }}</p>

    <div class="flex justify-between">
        <a href="{{ route('groups.download', $group->id) }}"  target="_blank"
           class="text-blue-600 hover:underline">تحميل PDF</a>
        <a href="{{ route('groups.show', $group->id) }}"
           class="text-gray-600 hover:underline">عرض التفاصيل</a>
    </div>
</div>
