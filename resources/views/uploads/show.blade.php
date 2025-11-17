@extends('layouts.app')

@section('content')
<div class="space-y-12 max-w-6xl mx-auto">

    {{-- رأس الصفحة والملاحة --}}
    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-4 border-b border-gray-200">
        <h2 class="text-4xl font-extrabold text-gray-800 mb-2 sm:mb-0">
            <i class="fa-solid fa-folder-open text-blue-600 ml-3"></i> تفاصيل الملف المرفوع
        </h2>
        <a href="{{ route('uploads.index') }}"
           class="flex items-center text-blue-600 font-bold hover:text-blue-700 transition duration-300 py-3 px-6 rounded-xl bg-blue-50 hover:bg-blue-100 shadow-md">
           <i class="fa-solid fa-arrow-right ml-2"></i> رجوع لقائمة الملفات
        </a>
    </div>

    {{-- بطاقة تفاصيل الملف الأساسية --}}
    <div class="bg-white rounded-[24px] shadow-3xl shadow-gray-200/50 p-10 border border-gray-100">

        <h3 class="text-2xl font-extrabold text-gray-700 mb-8 border-b pb-4 flex items-center">
            <i class="fa-solid fa-info-circle text-orange-500 ml-2"></i> بيانات الملف الأساسية
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">

            {{-- اسم الملف --}}
            <div class="p-5 bg-blue-50 rounded-xl border border-blue-200 shadow-inner">
                <p class="text-sm text-blue-700 mb-2 font-semibold flex items-center"><i class="fa-solid fa-file-alt ml-2"></i> اسم الملف الأصلي</p>
                <p class="font-bold text-gray-800 break-words text-lg">{{ $upload->original_filename }}</p>
            </div>

            {{-- عدد الصفحات --}}
            <div class="p-5 bg-purple-50 rounded-xl border border-purple-200 flex flex-col justify-center shadow-inner">
                <p class="text-sm text-purple-700 mb-2 font-semibold flex items-center"><i class="fa-solid fa-clone ml-2"></i> عدد الصفحات</p>
                <p class="font-extrabold text-4xl text-purple-700">{{ $upload->total_pages ?? 'غير محدد' }}</p>
            </div>

            {{-- تاريخ الرفع --}}
            <div class="p-5 bg-gray-50 rounded-xl border border-gray-200 flex flex-col justify-center shadow-inner">
                <p class="text-sm text-gray-700 mb-2 font-semibold flex items-center"><i class="fa-solid fa-calendar-alt ml-2"></i> تاريخ ووقت الرفع</p>
                <p class="font-bold text-md text-gray-800">{{ $upload->created_at->format('Y-m-d H:i') }}</p>
            </div>

            {{-- حالة المعالجة (تفاعلية) --}}
           <div class="p-5 rounded-xl border flex flex-col justify-center shadow-lg
                @if($upload?->status == 'completed') bg-green-50 border-green-300
                @elseif($upload?->status == 'processing') bg-yellow-50 border-yellow-300 animate-pulse-slow
                @else bg-red-50 border-red-300 @endif">

                <p class="text-sm text-gray-700 mb-2 font-semibold flex items-center"><i class="fa-solid fa-tasks ml-2"></i> حالة المعالجة</p>

                <span class="font-extrabold text-xl
                    @if($upload?->status == 'completed') text-green-700
                    @elseif($upload?->status == 'processing') text-yellow-700
                    @else text-red-700 @endif">

                    @if($upload?->status == 'completed')
                        <i class="fa-solid fa-check-circle ml-1"></i> تمت المعالجة
                    @elseif($upload?->status == 'processing')
                        <i class="fa-solid fa-spinner fa-spin ml-1"></i> جارٍ المعالجة
                    @else
                        {{-- الحالة الافتراضية تشمل 'failed' وأي قيمة null --}}
                        <i class="fa-solid fa-exclamation-triangle ml-1"></i> فشل المعالجة
                    @endif
                </span>
            </div>
        </div>
    </div>



    {{-- قسم المجموعات الناتجة --}}

    <div class="bg-white rounded-[24px] shadow-3xl shadow-gray-200/50 p-10 border border-gray-100">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 border-b pb-4">
            <h3 class="text-2xl font-extrabold text-gray-800 flex items-center mb-4 md:mb-0">
                <i class="fa-solid fa-layer-group text-teal-600 ml-2"></i> المجموعات الناتجة عن تقسيم الملف
            </h3>

            {{-- 🏆 التعديل 1: زر تحميل الكل (ZIP) --}}
            @if ($upload->groups->isNotEmpty() && $upload->status == 'completed')
                <a href="{{ route('uploads.download_all_groups', $upload->id) }}"
                   class="inline-flex items-center text-white bg-green-600 hover:bg-green-700 px-5 py-3 rounded-xl text-base font-bold transition shadow-lg transform hover:scale-[1.02]">
                    <i class="fa-solid fa-file-archive ml-2 text-lg"></i> تحميل كل المجموعات (ZIP)
                </a>
            @elseif ($upload->status == 'processing')
                <span class="inline-flex items-center text-yellow-700 bg-yellow-100 px-4 py-2 rounded-xl text-sm font-bold shadow-md">
                    التحميل متاح بعد انتهاء المعالجة
                </span>
            @endif
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse ($upload->groups as $group)
                <div class="bg-teal-50 p-6 rounded-xl border-2 border-teal-300 shadow-lg transition duration-300 hover:shadow-xl hover:bg-teal-100">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-lg font-bold text-teal-800 flex items-center">
                            <i class="fa-solid fa-tag ml-2 text-xl"></i> المجموعة رقم {{ $group->id }}
                        </p>
                        <span class="text-xs font-semibold text-teal-600 bg-white rounded-full px-3 py-1 shadow-sm">
                            {{ $group->pages_count ?? 'عدد الصفحات غير متوفر' }} صفحة
                        </span>
                    </div>
                    
                    {{-- 🏆 التعديل 2: عرض اسم الملف الناتج --}}
                    @if($group->pdf_path)
                    <p class="text-sm text-gray-700 mb-2 font-medium">
                        <i class="fa-solid fa-file-pdf ml-1 text-red-600"></i> اسم الملف الناتج:
                    </p>
                    <span class="font-mono bg-teal-200 text-teal-800 px-2 py-1 rounded-md text-sm block mt-1 mb-4 break-all">
                        {{ basename($group->pdf_path) }}
                    </span>
                    @endif


                    <p class="text-sm text-gray-600 mb-4">
                        تم إنشاء هذه المجموعة بناءً على الباركود:
                        <span class="font-mono bg-teal-200 text-teal-800 px-2 py-1 rounded-md text-base block mt-2 break-all">{{ $group->code ?? 'N/A' }}</span>
                    </p>

                    <a href="{{ route('groups.show', $group->id) }}"
                        class="inline-flex items-center text-white bg-teal-600 hover:bg-teal-700 px-5 py-2 rounded-xl text-sm font-semibold transition transform hover:scale-[1.02] shadow-md">
                        عرض تفاصيل المجموعة
                        <i class="fa-solid fa-chevron-left mr-2"></i>
                    </a>
                </div>
            @empty
                <div class="col-span-3 bg-gray-50 p-8 rounded-xl text-center border border-dashed border-gray-300">
                    <p class="text-gray-500 font-medium text-lg">لم يتم إنشاء مجموعات أرشفة لهذا الملف بعد (ربما لا يزال قيد المعالجة).</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

<style>
/* CSS لتأثير النبض البطيء */
@keyframes pulse-slow {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}
.animate-pulse-slow {
    animation: pulse-slow 3s infinite ease-in-out;
}
.shadow-3xl {
    /* تظليل عميق وأنيق */
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1), 0 10px 20px -5px rgba(0, 0, 0, 0.05);
}
</style>
@endsection