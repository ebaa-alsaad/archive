@props(['upload'])

<div class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-md transition">
    <div class="flex justify-between items-center">
        <h4 class="font-semibold text-gray-700 truncate">{{ $upload->original_filename }}</h4>
        <span class="text-xs text-gray-500">{{ $upload->created_at->diffForHumans() }}</span>
    </div>

    <div class="mt-3 text-sm text-gray-600">
        <p>عدد الصفحات: {{ $upload->total_pages }}</p>
        <p>الحالة:
            <span class="font-semibold
                @if($upload->status == 'done') text-green-600
                @elseif($upload->status == 'processing') text-yellow-600
                @else text-gray-500 @endif">
                {{ $upload->status }}
            </span>
        </p>
    </div>

    <div class="mt-4 flex justify-between">
        <a href="{{ route('uploads.show', $upload->id) }}"
           class="text-blue-600 hover:underline">عرض التفاصيل</a>
    </div>
</div>
