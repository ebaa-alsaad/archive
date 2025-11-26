@props(['upload'])

<div class="border rounded-2xl p-4 bg-white shadow-sm hover:shadow-lg transition duration-200">

    <div class="flex justify-between items-start mb-3">
        {{-- اسم الملف الأصلي --}}
        <h4 class="font-bold text-gray-800 truncate max-w-[70%] leading-tight">
            <i class="fa-solid fa-file-invoice ml-2 text-blue-500"></i>
            {{ $upload?->original_filename }}
        </h4>

        {{-- تاريخ الرفع --}}
        <span class="text-xs text-gray-500 whitespace-nowrap" title="{{ $upload?->created_at?->format('Y-m-d H:i') }}">
            {{ $upload?->created_at?->diffForHumans() }}
        </span>
    </div>

    <div class="mt-3 text-sm text-gray-600 space-y-2 border-t pt-3 border-gray-100">

        {{-- عدد الصفحات --}}
        <p class="flex justify-between">
            <span class="font-medium text-gray-500">عدد الصفحات:</span>
            <span class="font-semibold text-gray-800">{{ $upload?->total_pages ?? 'N/A' }}</span>
        </p>

        <p class="flex justify-between items-center">
            <span class="font-medium text-gray-500">الحالة:</span>

            <span class="font-extrabold
                @if($upload?->status == 'completed') text-green-600
                @elseif($upload?->status == 'processing') text-yellow-600 animate-pulse
                @else text-red-600 @endif">

                @if($upload?->status == 'completed')
                    <i class="fa-solid fa-check-circle ml-1"></i> مكتملة
                @elseif($upload?->status == 'processing')
                    <i class="fa-solid fa-spinner fa-spin ml-1"></i> جارٍ المعالجة
                @else
                    {{-- يشمل failed أو أي حالة أخرى غير معروفة --}}
                    <i class="fa-solid fa-times-circle ml-1"></i> فشل
                @endif
            </span>
        </p>
    </div>

    {{-- رابط التفاصيل --}}
    <div class="mt-4 border-t pt-3">
        <a href="{{ route('uploads.show', $upload?->id) }}"
           class="text-blue-600 hover:text-blue-800 transition font-bold text-sm inline-flex items-center">
            عرض التفاصيل والروابط <i class="fa-solid fa-arrow-left mr-2 text-xs"></i>
        </a>
    </div>
</div>
