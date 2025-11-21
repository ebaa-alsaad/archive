@extends('layouts.app')

@section('content')
<div class="space-y-8 p-4 sm:p-6 lg:p-8">
    <h2 class="text-3xl font-extrabold text-gray-800 pb-2 border-b-2 border-blue-500/10 inline-block">
        <i class="fa-solid fa-file-invoice ml-2 text-blue-600"></i> قائمة الملفات المرفوعة
    </h2>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 p-4 rounded-xl text-green-700 font-medium flex items-center shadow-md">
            <i class="fa-solid fa-circle-check text-xl ml-3"></i>
            {{ session('success') }}
        </div>
    @endif

    <div class="bg-white p-8 rounded-3xl shadow-2xl border border-gray-100">

        <h3 class="text-xl font-bold text-gray-700 mb-6 border-b pb-4">الملفات المرتبة حسب تاريخ الرفع</h3>

        <div class="overflow-x-auto rounded-xl border border-gray-200">
            <table class="w-full text-right divide-y divide-gray-200">
                <thead>
                    <tr class="bg-blue-50 text-blue-800 uppercase text-sm leading-normal font-bold tracking-wider">
                        <th class="py-3 px-6 text-center">
                            <i class="fa-solid fa-file ml-1"></i> اسم الملف الأصلي
                        </th>
                        <th class="py-3 px-6 text-center">
                            <i class="fa-solid fa-user ml-1"></i> الرافع
                        </th>
                        <th class="py-3 px-6 text-center">
                            <i class="fa-solid fa-layer-group ml-1"></i> المجموعات الناتجة
                        </th>
                        <th class="py-3 px-6 text-center">
                            <i class="fa-solid fa-clock ml-1"></i> حالة المعالجة
                        </th>
                        {{-- تم حذف عمود "رابط الملف" لدمج وظيفته في "الإجراءات" --}}
                        <th class="py-3 px-6 text-center">
                            <i class="fa-solid fa-gears ml-1"></i> الإجراءات
                        </th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm font-medium divide-y divide-gray-100">
                    @forelse($uploads as $upload)
                    <tr class="hover:bg-gray-50 transition duration-150">

                        {{-- اسم الملف الأصلي --}}
                        <td class="py-4 px-6 text-right whitespace-nowrap">
                            <span class="font-semibold text-gray-800">{{ $upload->original_filename }}</span>
                        </td>

                        {{-- الرافع --}}
                        <td class="py-4 px-6 text-center">
                            <span class="text-xs font-medium text-gray-500">
                                {{ $upload->user?->name ?? 'غير معروف' }}
                            </span>
                        </td>

                        {{-- المجموعات الناتجة --}}
                        <td class="py-4 px-6 text-center">
                            @php
                                $groupsCount = $upload->groups->count();
                            @endphp

                            @if($groupsCount > 0)
                                <a href="{{ route('groups.for_upload', ['upload' => $upload->id]) }}"
                                   class="bg-blue-600 text-white py-1.5 px-3 rounded-full text-xs font-bold inline-flex items-center hover:bg-blue-700 transition shadow-md">
                                    <i class="fa-solid fa-list-check ml-1"></i> عرض ({{ $groupsCount }}) مجموعات
                                </a>
                            @else
                                <span class="text-gray-400 text-xs">- لا يوجد -</span>
                            @endif
                        </td>

                        {{-- حالة المعالجة --}}
                       <td class="py-4 px-6 text-center">
                        @if($upload?->status == 'completed')
                            <span class="text-green-600 flex items-center bg-green-50 p-1 rounded-md min-w-[70px] justify-center mx-auto">
                                <i class="fa-solid fa-check-circle text-xs ml-1"></i> **مكتملة**
                            </span>
                        @elseif($upload?->status == 'processing')
                            <span class="text-yellow-600 flex items-center animate-pulse bg-yellow-50 p-1 rounded-md min-w-[70px] justify-center mx-auto">
                                <i class="fa-solid fa-spinner fa-spin text-xs ml-1"></i> جارٍ
                            </span>
                        @else
                            <span class="text-red-600 flex items-center bg-red-50 p-1 rounded-md min-w-[70px] justify-center mx-auto">
                                <i class="fa-solid fa-times-circle text-xs ml-1"></i> **فشل**
                            </span>
                        @endif
                        </td>

                        <td class="py-4 px-6 text-center">
                            <div class="flex items-center justify-center space-x-3 rtl:space-x-reverse">

                                {{-- 1. رابط عرض الملف الأصلي (العين) --}}
                                <a href="{{ route('uploads.show_file', ['upload' => $upload->id]) }}" target="_blank"
                                    title="عرض الملف الأصلي: {{ $upload->original_filename }}"
                                    class="text-green-600 hover:text-green-800 transition transform hover:scale-110">
                                    <i class="fa-solid fa-eye text-lg"></i>
                                </a>

                                {{-- 2. رابط عرض التفاصيل (المعلومات) --}}
                                <a href="{{ route('uploads.show', $upload->id) }}" title="عرض تفاصيل المعالجة"
                                    class="text-blue-600 hover:text-blue-800 transition transform hover:scale-110">
                                    <i class="fa-solid fa-info-circle text-lg"></i>
                                </a>

                                {{-- 3. الزر الذي يستدعي دالة الحذف (السلة) --}}
                                <button type="button"
                                        onclick="confirmDelete('{{ $upload->id }}', '{{ $upload->original_filename }}')"
                                        title="حذف الملف وجميع بياناته"
                                        class="text-red-600 hover:text-red-800 transition transform hover:scale-110 p-0 m-0 bg-transparent border-none cursor-pointer">
                                    <i class="fa-solid fa-trash-alt text-lg"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5" class="py-12 text-center text-gray-500 text-xl font-medium bg-gray-50">
                            <i class="fa-solid fa-file-upload text-3xl mb-3 text-gray-400"></i>
                            <p>لا توجد ملفات مرفوعة حالياً.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $uploads->links() }}
        </div>

    </div>

</div>

{{-- نموذج الحذف المخفي والدالة الخاصة به --}}
<form id="delete-form" method="POST" style="display: none;">
    @csrf
    @method('DELETE')
</form>

<script>
    function confirmDelete(uploadId, filename) {
        const message = `هل أنت متأكد من حذف الملف الأصلي (${filename}) وجميع المجموعات الناتجة عنه؟ لا يمكن التراجع عن هذا الإجراء.`;

        if (confirm(message)) {
            const form = document.getElementById('delete-form');

            form.action = '/uploads/' + uploadId;

            form.submit();
        }
    }
</script>

@endsection
