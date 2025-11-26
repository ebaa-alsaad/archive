@extends('layouts.app')

@section('content')
<div class="space-y-12">
    <h2 class="text-3xl font-extrabold text-gray-800 pb-4">لوحة التحكم</h2>

    {{-- بطاقات الإحصائيات مع التدرج اللوني --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

        {{-- بطاقة عدد الملفات (تدرج أزرق) --}}
        <div class="p-8 rounded-3xl shadow-2xl border-b-4 border-blue-600
                    bg-gradient-to-br from-white to-blue-50
                    transition duration-300 transform hover:-translate-y-1 hover:shadow-blue-300/40">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-blue-800">إجمالي الملفات المرفوعة</h3>
                <i class="fa-solid fa-file-lines text-4xl text-blue-500 opacity-70"></i>
            </div>
            <p class="text-6xl font-extrabold text-blue-600">{{ $uploadsCount ?? 0 }}</p>
        </div>

        {{-- بطاقة عدد المجموعات (تدرج أخضر) --}}
        <div class="p-8 rounded-3xl shadow-2xl border-b-4 border-green-600
                    bg-gradient-to-br from-white to-green-50
                    transition duration-300 transform hover:-translate-y-1 hover:shadow-green-300/40">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-green-800">المجموعات النشطة</h3>
                <i class="fa-solid fa-layer-group text-4xl text-green-500 opacity-70"></i>
            </div>
            <p class="text-6xl font-extrabold text-green-600">{{ $groupsCount ?? 0 }}</p>
        </div>

        {{-- بطاقة إضافية (تدرج رمادي/أصفر) --}}
        <div class="p-8 rounded-3xl shadow-2xl border-b-4 border-yellow-500
                    bg-gradient-to-br from-white to-yellow-50
                    transition duration-300 transform hover:-translate-y-1 hover:shadow-yellow-300/40">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-yellow-800">المستخدمين الحاليين</h3>
                <i class="fa-solid fa-users text-4xl text-yellow-500 opacity-70"></i>
            </div>
            <p class="text-6xl font-extrabold text-yellow-600">{{ $usersCount }}</p>
        </div>

    </div>

    {{-- قسم الملفات الأخيرة (بتظليل أعمق) --}}
    <div class="bg-white rounded-3xl shadow-2xl p-8 border border-gray-100">
        <div class="flex justify-between items-center mb-6 border-b pb-4">
            <h3 class="text-2xl font-bold text-gray-800">الملفات الأخيرة</h3>
            <a href="{{ route('uploads.index') }}" class="text-blue-600 font-bold hover:text-blue-700 transition">
                عرض كل الملفات <i class="fa-solid fa-arrow-left mr-1 text-sm"></i>
            </a>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
            @forelse ($uploads as $upload)
                <x-file-card :upload="$upload" />
            @empty
                <div class="col-span-full bg-gray-50 p-8 rounded-xl text-center border border-dashed border-gray-300">
                    <p class="text-gray-500 font-medium text-lg">لا توجد ملفات مرفوعة حديثًا بعد.</p>
                    <a href="{{ route('uploads.create') }}" class="mt-3 inline-flex items-center text-blue-600 hover:text-blue-700 font-semibold">
                        <i class="fa-solid fa-plus-circle ml-2"></i> ارفع ملفك الأول الآن
                    </a>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
