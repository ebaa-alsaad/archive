@extends('layouts.app')

@section('content')
<div class="space-y-10 max-w-7xl mx-auto p-4 sm:p-6">

    <h2 class="text-3xl font-extrabold text-gray-800 pb-2">إدارة الملفات والمجموعات</h2>

    {{-- نظام التبويبات --}}
    <div class="flex border-b border-gray-300 mb-6">
        
        {{-- 🛑 تبويب المجموعات الناتجة (نشط في هذه الصفحة) --}}
        <a href="{{ route('groups.index') }}"
           class="px-6 py-3 text-lg font-bold border-b-4 border-blue-600 text-blue-700 transition duration-200">
            <i class="fa-solid fa-file-invoice ml-2"></i> المجموعات الناتجة
        </a>
        
        {{-- 🛑 تبويب الملفات الأصلية (يجب أن يؤدي إلى صفحة مختلفة) --}}
        <a href="{{ route('uploads.index') }}" {{-- افترضنا أن هذا هو مسار صفحة عرض الملفات --}}
           class="px-6 py-3 text-lg font-medium text-gray-500 hover:text-gray-700 hover:border-gray-400 border-b-4 border-transparent transition duration-200">
            <i class="fa-solid fa-upload ml-2"></i> الملفات الأصلية المرفوعة
        </a>
    </div>

    {{-- بطاقة المحتوى الرئيسية للمجموعات --}}
    <div class="bg-white p-8 rounded-3xl shadow-2xl border border-gray-100">

        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h3 class="text-2xl font-bold text-gray-700">قائمة المجموعات الناتجة</h3>
            <a href="{{ route('groups.index') }}" class="text-blue-600 font-bold hover:text-blue-700 transition flex items-center">
                عرض في جدول <i class="fa-solid fa-table-list mr-2 text-sm"></i>
            </a>
        </div>


        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse ($groups as $group)
                {{-- نفترض أن x-group-card موجودة وتتبع التصميم العصري --}}
                <x-group-card :group="$group" />
            @empty
                <div class="col-span-3 bg-white p-10 rounded-2xl text-center border-4 border-dashed border-blue-200 shadow-inner">
                    <i class="fa-solid fa-box-open text-6xl text-blue-400 mb-4"></i>
                    <p class="text-gray-600 font-bold text-xl mb-3">لا توجد مجموعات أرشفة حالياً!</p>
                    <p class="text-gray-500 font-medium mb-4">يتم إنشاء المجموعات تلقائيًا بعد رفع ملف PDF وإتمام عملية القراءة.</p>
                    <a href="{{ route('uploads.create') }}"
                       class="mt-3 inline-flex items-center bg-blue-600 text-white px-5 py-2.5 rounded-full font-bold shadow-lg shadow-blue-300/50 hover:bg-blue-700 transition transform hover:scale-[1.03]">
                        <i class="fa-solid fa-plus-circle ml-2"></i> ارفع ملفاً جديداً للبدء
                    </a>
                </div>
            @endforelse
        </div>
        
        {{-- الترقيم (Pagination) --}}
        @isset($groups)
            <div class="mt-8">
                {{ $groups->links() }}
            </div>
        @endisset

    </div>
</div>
@endsection