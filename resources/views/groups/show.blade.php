@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto p-4 sm:p-6 lg:p-8">

    <div class="flex items-center justify-between mb-8 pb-4 border-b border-gray-200">
        <h2 class="text-3xl font-extrabold text-gray-800 flex items-center">
            <i class="fa-solid fa-layer-group ml-3 text-blue-600"></i> تفاصيل المجموعة
        </h2>

        <a href="{{ route('groups.for_upload', ['upload' => $group->upload_id]) }}" class="text-blue-600 hover:text-blue-700 font-semibold transition flex items-center group">
            <i class="fa-solid fa-arrow-right mr-2 text-sm group-hover:translate-x-1 transition-transform"></i> العودة لقائمة المجموعات
        </a>
    </div>

    {{-- 🏆 القسم الأول: بطاقة ملخص المجموعة الرئيسية --}}
    <div class="bg-white p-8 rounded-3xl shadow-2xl border border-gray-100 mb-10 transform transition-shadow hover:shadow-3xl border-t-4 border-red-500">
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center pb-4 border-b border-gray-100 mb-6">

            <div>
                <p class="text-gray-500 font-medium text-sm mb-1">رمز المجموعة</p>
                <h3 class="text-4xl font-black text-red-700 leading-tight">{{ $group->code }}</h3>
            </div>

            {{-- تحميل ملف PDF المدمج --}}
            @if($group->pdf_path)
                <a href="{{ route('groups.download', $group->id) }}" target="_blank"
                    class="mt-4 md:mt-0 inline-flex items-center bg-red-600 text-white py-2.5 px-6 rounded-xl text-base font-bold hover:bg-red-700 transition shadow-lg hover:shadow-xl transform hover:scale-[1.02]">
                    <i class="fa-solid fa-file-pdf ml-2"></i> عرض و تحميل ملف PDF 
                </a>
            @else
                <span class="mt-4 md:mt-0 text-orange-500 bg-orange-50 py-2 px-4 rounded-lg font-semibold text-sm">
                    <i class="fa-solid fa-hourglass-half ml-2"></i> لم يتم دمج ملف PDF بعد
                </span>
            @endif
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 text-base">

            {{-- 1. اسم الملف الناتج (المدمج) --}}
            <div class="p-3 border rounded-xl bg-gray-50/50 lg:col-span-2">
                <p class="text-gray-500 font-medium text-xs mb-1">اسم الملف الناتج</p>
                <p class="font-bold text-gray-800 flex items-center text-sm">
                    <i class="fa-solid fa-file-pdf ml-2 text-red-500"></i>
                    <span class="font-mono bg-gray-100 px-2 py-0.5 rounded-md break-all">
                        {{ $group->pdf_path ? basename($group->pdf_path) : 'غير متوفر' }}
                    </span>
                </p>
            </div>

            {{-- 2. عدد صفحات الملف المدمج (تعديل الطلب) --}}
            <div class="p-3 border rounded-xl bg-gray-50/50">
                <p class="text-gray-500 font-medium text-xs mb-1">عدد الصفحات</p>
                <p class="font-bold text-gray-800 flex items-center text-lg">
                    <i class="fa-solid fa-book-open ml-2 text-red-500"></i>
                    {{-- يفترض أن لديك حقل pages_count في Group --}}
                    <span class="text-blue-600 font-extrabold">{{ $group->pages_count ?? 'غير متوفر' }}</span> صفحة
                </p>
            </div>

            {{-- 3. تاريخ الإنشاء --}}
            <div class="p-3 border rounded-xl bg-gray-50/50">
                <p class="text-gray-500 font-medium text-xs mb-1">تاريخ ووقت الإنشاء</p>
                <p class="font-bold text-gray-800 flex items-center">
                    <i class="fa-solid fa-calendar-day ml-2 text-red-500"></i>
                    {{ $group->created_at->format('Y-m-d H:i') }}
                </p>
            </div>

        </div>
    </div>


    {{--  القسم الثاني: تفاصيل الملف الأصلي المرتبط --}}
    <h3 class="text-2xl font-bold text-gray-700 mb-6 border-b pb-3 flex items-center">
        <i class="fa-solid fa-file-alt ml-2 text-blue-500"></i> تفاصيل الملف الأصلي
    </h3>

    @if($group->upload)

        <div class="bg-white p-6 rounded-2xl shadow-xl border border-gray-100">
            <div class="grid grid-cols-1 md:grid-cols-5 gap-6 items-center">

                {{-- 1. اسم الملف الأصلي (أكثر وضوحاً) --}}
                <div class="md:col-span-2">
                    <p class="text-gray-500 text-sm font-medium">اسم الملف الأصلي</p>
                    <p class="text-xl font-extrabold text-gray-900 truncate" title="{{ $group->upload->original_filename }}">
                        <i class="fa-solid fa-file-pdf ml-2 text-blue-600"></i>
                        {{ $group->upload->original_filename }}
                    </p>
                </div>

                {{-- 2. حالة المعالجة --}}
                <div class="md:col-span-1">
                    <p class="text-gray-500 text-sm font-medium">حالة المعالجة</p>
                    @if($group->upload->status == 'completed')
                        <span class="bg-green-100 text-green-700 py-1 px-3 rounded-full text-sm font-bold flex items-center w-fit">
                            <i class="fa-solid fa-check-circle text-xs ml-2"></i> مكتملة
                        </span>
                    @elseif($group->upload->status == 'processing')
                        <span class="bg-yellow-100 text-yellow-700 py-1 px-3 rounded-full text-sm font-bold flex items-center w-fit animate-pulse">
                            <i class="fa-solid fa-spinner fa-spin text-xs ml-2"></i> جارٍ المعالجة
                        </span>
                    @else
                        <span class="bg-red-100 text-red-700 py-1 px-3 rounded-full text-sm font-bold flex items-center w-fit">
                            <i class="fa-solid fa-times-circle text-xs ml-2"></i> فشل المعالجة
                        </span>
                    @endif
                </div>

                {{-- 3. الرافع --}}
                <div class="md:col-span-1">
                    <p class="text-gray-500 text-sm font-medium">الرافع</p>
                    <p class="font-bold text-gray-800 flex items-center">
                        <i class="fa-solid fa-user ml-2 text-blue-500"></i>
                        {{ $group->upload->user->name ?? 'مستخدم محذوف' }}
                    </p>
                </div>

                {{-- 4. الإجراء (زر العرض) --}}
                <div class="md:col-span-1 flex items-center justify-end">
                    <a href="{{ route('uploads.show_file', ['upload' => $group->upload->id]) }}" target="_blank"
                        class="inline-flex items-center bg-blue-600 text-white py-2 px-4 rounded-xl text-sm font-bold hover:bg-blue-700 transition shadow-md">
                        <i class="fa-solid fa-eye ml-2"></i> عرض الملف المرفوع
                    </a>
                </div>

            </div>
        </div>


    @else
        <div class="p-8 text-center text-gray-500 bg-white rounded-xl shadow-lg border border-gray-100">
            <p>لا يوجد ملف مرفوع مرتبط مباشرة بهذه المجموعة (Upload ID مفقود).</p>
        </div>
    @endif

</div>
@endsection
