@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-4 sm:p-6 lg:p-8">

    <div class="flex items-center justify-between mb-8 pb-4 border-b border-gray-200">
        <h2 class="text-3xl font-extrabold text-gray-800">
            <i class="fa-solid fa-layer-group ml-3 text-blue-600"></i> تفاصيل المجموعة: <span class="text-blue-700">{{ $group->code }}</span>
        </h2>

        <a href="{{ route('groups.index') }}" class="text-gray-600 hover:text-blue-600 font-medium transition flex items-center">
            <i class="fa-solid fa-arrow-right mr-2 text-sm"></i> العودة لقائمة المجموعات
        </a>
    </div>

    {{-- بطاقة تفاصيل المجموعة --}}
    <div class="bg-white p-8 rounded-2xl shadow-xl border border-gray-100 mb-10">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-lg">

            <div class="border-b md:border-b-0 md:border-l pb-3 md:pb-0 md:pl-6 border-gray-100">
                <p class="text-gray-500 font-medium text-sm mb-1">تاريخ الإنشاء</p>
                <p class="font-bold text-gray-800">
                    <i class="fa-solid fa-calendar ml-2 text-blue-500"></i>
                    {{ $group->created_at->format('Y-m-d H:i') }}
                </p>
            </div>

            <div class="border-b md:border-b-0 md:border-l pb-3 md:pb-0 md:pl-6 border-gray-100">
                <p class="text-gray-500 font-medium text-sm mb-1">عدد الملفات</p>
                {{-- 💡 تصحيح: استخدام شرط للعد (1 إن وجد، 0 إن لم يوجد) --}}
                <p class="font-bold text-gray-800">
                    <i class="fa-solid fa-copy ml-2 text-blue-500"></i>
                    {{ $group->upload ? 1 : 0 }} ملف
                </p>
            </div>

            <div>
                <p class="text-gray-500 font-medium text-sm mb-1">ملف PDF المُدمج</p>
                @if($group->pdf_path)
                   <a href="{{ route('groups.download', $group->id) }}" target="_blank"
                    class="inline-flex items-center bg-red-600 text-white py-2 px-4 rounded-full text-sm font-bold hover:bg-red-700 transition shadow-md">
                        <i class="fa-solid fa-file-pdf ml-2"></i> تحميل ملف PDF المدمج
                   </a>
                @else
                    <span class="text-gray-400 font-medium">- لم يتم الإنشاء بعد -</span>
                @endif
            </div>
        </div>
    </div>

    {{-- 🏆 تفاصيل الملف المرتبط (قسم الملف الواحد) --}}
    <h3 class="text-2xl font-bold text-gray-700 mb-6 border-b pb-3">تفاصيل الملف المرتبط</h3>

    {{-- 💡 التحقق الشرطي: فقط @if($group->upload) لتجنب الخطأ --}}
    @if($group->upload)

        <div class="bg-white p-6 rounded-2xl shadow-lg border border-gray-100 grid grid-cols-1 md:grid-cols-4 gap-4">

            {{-- اسم الملف --}}
            <div class="col-span-1">
                <p class="text-gray-500 text-sm font-medium">اسم الملف الأصلي</p>
                {{-- 💡 وصول آمن ومباشر --}}
                <p class="font-bold text-gray-800">{{ $group->upload?->original_filename }}</p>
            </div>

            {{-- حالة المعالجة --}}
            <div class="col-span-1">
                <p class="text-gray-500 text-sm font-medium">حالة المعالجة</p>
                {{-- 💡 وصول آمن ومباشر --}}
                @if($group->upload?->status == 'completed')
                    <span class="text-green-600 font-bold">مكتملة</span>
                @elseif($group->upload?->status == 'processing')
                    <span class="text-yellow-600 font-bold">جارٍ</span>
                @else
                    <span class="text-red-600 font-bold">فشل</span>
                @endif
            </div>

            {{-- الرافع --}}
            <div class="col-span-1">
                <p class="text-gray-500 text-sm font-medium">الرافع</p>
                {{-- 💡 وصول آمن ومباشر لعلاقة user داخل upload --}}
                <p class="font-bold text-gray-800">{{ $group->upload?->user?->name ?? 'مستخدم محذوف' }}</p>
            </div>

            {{-- الإجراء --}}
            <div class="col-span-1 flex items-center justify-end">
                <a href="{{ route('uploads.show_file', ['upload' => $group->upload?->id]) }}" target="_blank"
                   class="inline-flex items-center bg-blue-600 text-white py-2.5 px-6 rounded-xl text-base font-bold hover:bg-blue-700 transition shadow-md">
                    <i class="fa-solid fa-eye ml-2"></i> عرض الملف المرفوع
                </a>
            </div>

        </div>
    @else
        <div class="p-8 text-center text-gray-500 bg-white rounded-xl shadow-lg border border-gray-100">
            <p>لا يوجد ملف مرفوع مرتبط مباشرة بهذه المجموعة (Upload ID مفقود).</p>
        </div>
    @endif

</div>
@endsection
