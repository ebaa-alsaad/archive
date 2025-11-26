@extends('layouts.app')

@section('content')
<div class="space-y-10 max-w-7xl mx-auto p-4 sm:p-6">

    <h2 class="text-3xl font-extrabold text-gray-800 pb-2">إدارة الملفات والمجموعات</h2>

    <div class="flex border-b border-gray-300 mb-6">

        <a href="{{ route('groups.index') }}"
           class="px-6 py-3 text-lg font-bold border-b-4 border-blue-600 text-blue-700 transition duration-200">
            <i class="fa-solid fa-file-invoice ml-2"></i> المجموعات الناتجة
        </a>

    </div>

    {{-- بطاقة المحتوى الرئيسية للمجموعات --}}
    <div class="bg-white p-8 rounded-3xl shadow-2xl border border-gray-100">

        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h3 class="text-2xl font-bold text-gray-700">قائمة المجموعات الناتجة</h3>
            {{-- زر تحميل الكل  --}}
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
