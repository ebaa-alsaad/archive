@props(['group'])

{{-- بطاقة مجموعة بتصميم عصري --}}
<div class="bg-white rounded-xl p-6 border border-gray-100 shadow-xl transition-all duration-300 hover:shadow-2xl hover:border-blue-300">

    {{-- رأس البطاقة: رقم المجموعة وعدد الصفحات --}}
    <div class="flex items-start justify-between mb-4 border-b pb-3 border-blue-50">
        <h4 class="font-extrabold text-xl text-blue-700 flex items-center">
            <i class="fa-solid fa-box-archive ml-2 text-2xl text-blue-500"></i> مجموعة رقم {{ $group->id }}
        </h4>
        <span class="text-xs font-bold text-gray-500 bg-gray-100 px-3 py-1 rounded-full shadow-inner">
            <i class="fa-solid fa-clock mr-1"></i> {{ $group->created_at->diffForHumans() }}
        </span>
    </div>

    {{-- اسم الملف الناتج (أكثر وضوحاً) --}}
    <div class="mb-3">
        <p class="text-sm text-gray-600 font-medium flex items-center mb-1">
            <i class="fa-solid fa-file-pdf ml-1 text-red-600"></i> اسم الملف الناتج:
        </p>
        <span class="font-semibold text-gray-800 text-sm block break-words bg-gray-50 px-2 py-1 rounded-md">
            {{ $group->pdf_path ? basename($group->pdf_path) : 'الملف غير متوفر' }}
        </span>
    </div>

    <div class="mb-4">
        <p class="text-sm text-gray-600 font-medium flex items-center mb-1">
            <i class="fa-solid fa-barcode ml-1 text-orange-600"></i> الباركود :
        </p>
        <span class="font-mono bg-blue-100 text-blue-800 px-3 py-1.5 rounded-lg text-base block break-all font-bold shadow-sm">
            {{ $group->code ?? 'N/A' }}
        </span>
    </div>

    {{-- معلومات إضافية --}}
    <p class="text-sm text-gray-700 mb-5 font-bold flex items-center">
        <i class="fa-solid fa-clone ml-1 text-purple-500"></i> عدد الصفحات: <span class="text-blue-600 font-extrabold mr-1">{{ $group->pages_count }}</span>
    </p>

    {{-- أزرار الإجراءات --}}
    <div class="flex justify-between space-x-3 rtl:space-x-reverse">
        <a href="{{ route('groups.download', $group->id) }}" target="_blank"
           class="flex-1 inline-flex items-center justify-center bg-teal-600 text-white px-4 py-2 rounded-xl text-sm font-semibold transition hover:bg-teal-700 transform hover:scale-[1.02] shadow-md shadow-teal-300/50">
            <i class="fa-solid fa-download ml-2"></i> تحميل PDF
        </a>
        <a href="{{ route('groups.show', $group->id) }}"
           class="flex-1 inline-flex items-center justify-center bg-white text-gray-700 border border-gray-300 px-4 py-2 rounded-xl text-sm font-semibold transition hover:bg-gray-100 transform hover:scale-[1.02] shadow-sm">
            <i class="fa-solid fa-eye ml-2"></i> عرض التفاصيل
        </a>
    </div>
</div>
