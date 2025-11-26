@extends('layouts.app')

@section('content')
<div class="space-y-8 p-4 sm:p-6 lg:p-8">
    <h2 class="text-3xl font-extrabold text-gray-800 pb-2 border-b-2 border-blue-500/10 inline-block">
        <i class="fa-solid fa-file-invoice ml-2 text-blue-600"></i> قائمة الملفات المرفوعة
    </h2>

    {{-- Toast --}}
    <div id="toast-success" class="hidden fixed top-5 right-5 bg-green-600 text-white px-5 py-3 rounded-xl shadow-lg z-50 text-sm font-bold">
        <i class="fa-solid fa-circle-check ml-2"></i> تم حذف الملف بنجاح
    </div>

    <div class="bg-white p-8 rounded-3xl shadow-2xl border border-gray-100">
        <h3 class="text-xl font-bold text-gray-700 mb-6 border-b pb-4">الملفات المرتبة حسب تاريخ الرفع</h3>

        <div class="overflow-x-auto rounded-xl border border-gray-200">
            <table class="w-full text-right divide-y divide-gray-200" id="uploads-table">
                <thead>
                    <tr class="bg-blue-50 text-blue-800 uppercase text-sm leading-normal font-bold tracking-wider">
                        <th class="py-3 px-6 text-center"><i class="fa-solid fa-file ml-1"></i> اسم الملف الأصلي</th>
                        <th class="py-3 px-6 text-center"><i class="fa-solid fa-user ml-1"></i> الرافع</th>
                        <th class="py-3 px-6 text-center"><i class="fa-solid fa-layer-group ml-1"></i> المجموعات الناتجة</th>
                        <th class="py-3 px-6 text-center"><i class="fa-solid fa-clock ml-1"></i> حالة المعالجة</th>
                        <th class="py-3 px-6 text-center"><i class="fa-solid fa-gears ml-1"></i> الإجراءات</th>
                    </tr>
                </thead>

                <tbody class="text-gray-700 text-sm font-medium divide-y divide-gray-100">
                    @forelse($uploads as $upload)

                    <tr id="row-{{ $upload->id }}" class="hover:bg-gray-50 transition duration-150">

                        <td class="py-4 px-6 text-right whitespace-nowrap">
                            <span class="font-semibold text-gray-800">{{ $upload->original_filename }}</span>
                        </td>

                        <td class="py-4 px-6 text-center">
                            <span class="text-xs font-medium text-gray-500">
                                {{ $upload->user?->name ?? 'غير معروف' }}
                            </span>
                        </td>

                        <td class="py-4 px-6 text-center">
                            @if($upload->groups->count() > 0)
                                <a href="{{ route('groups.for_upload', ['upload' => $upload->id]) }}"
                                   class="bg-blue-600 text-white py-1.5 px-3 rounded-full text-xs font-bold inline-flex items-center hover:bg-blue-700 transition shadow-md">
                                    <i class="fa-solid fa-list-check ml-1"></i> عرض ({{ $upload->groups->count() }}) مجموعات
                                </a>
                            @else
                                <span class="text-gray-400 text-xs">- لا يوجد -</span>
                            @endif
                        </td>

                        <td class="py-4 px-6 text-center">
                            @if($upload?->status == 'completed')
                                <span class="text-green-600 flex items-center bg-green-50 p-1 rounded-md min-w-[70px] justify-center mx-auto">
                                    <i class="fa-solid fa-check-circle text-xs ml-1"></i> مكتملة
                                </span>
                            @elseif($upload?->status == 'processing')
                                <span class="text-yellow-600 flex items-center animate-pulse bg-yellow-50 p-1 rounded-md min-w-[70px] justify-center mx-auto">
                                    <i class="fa-solid fa-spinner fa-spin text-xs ml-1"></i> جارٍ
                                </span>
                            @else
                                <span class="text-red-600 flex items-center bg-red-50 p-1 rounded-md min-w-[70px] justify-center mx-auto">
                                    <i class="fa-solid fa-times-circle text-xs ml-1"></i> فشل
                                </span>
                            @endif
                        </td>

                        <td class="py-4 px-6 text-center">
                            <div class="flex flex-col space-y-2 items-center justify-center">
                                @if($upload->groups->count() > 0 && $upload->groups->every(fn($group) => $group->pdf_path != null))
                                    <a href="{{ route('uploads.download_all_groups', $upload->id) }}"
                                       {{-- START: New stylish ZIP button classes --}}
                                       class="bg-gradient-to-r from-purple-600 to-indigo-600 text-white py-2 px-4 rounded-xl text-sm font-semibold inline-flex items-center
                                              shadow-lg hover:shadow-xl hover:from-purple-700 hover:to-indigo-700 transition duration-300 transform hover:scale-[1.02]">
                                       {{-- END: New stylish ZIP button classes --}}
                                        <i class="fa-solid fa-file-archive ml-2"></i> تحميل ZIP
                                    </a>
                                @endif

                                <div class="flex items-center justify-center space-x-3 rtl:space-x-reverse">

                                    {{-- عرض الملف الأصلي --}}
                                    <a href="{{ route('uploads.show_file', ['upload' => $upload->id]) }}" target="_blank"
                                        class="text-green-600 hover:text-green-800 transition transform hover:scale-110">
                                        <i class="fa-solid fa-eye text-lg"></i>
                                    </a>

                                    {{-- تفاصيل الملف الأصلي --}}
                                    <a href="{{ route('uploads.show', $upload->id) }}"
                                        class="text-blue-600 hover:text-blue-800 transition transform hover:scale-110">
                                        <i class="fa-solid fa-info-circle text-lg"></i>
                                    </a>

                                    {{-- DELETE BUTTON --}}
                                    <button type="button"
                                        onclick="openDeleteModal('{{ $upload->id }}', '{{ $upload->original_filename }}')"
                                        class="text-red-600 hover:text-red-800 transition transform hover:scale-110">
                                        <i class="fa-solid fa-trash-alt text-lg"></i>
                                    </button>
                                </div>

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

{{-- DELETE MODAL --}}
<div id="delete-modal"
     class="fixed inset-0 bg-black/70 hidden flex items-center justify-center z-50">
    <div class="bg-white w-full max-w-md rounded-2xl p-6 shadow-2xl border border-gray-200">

        <h3 class="text-xl font-bold text-gray-800 mb-4">
            <i class="fa-solid fa-triangle-exclamation text-red-500 ml-2"></i>
            تأكيد الحذف
        </h3>

        <p id="delete-message" class="text-gray-600 text-sm leading-relaxed mb-6"></p>

        <div class="flex justify-end space-x-3 rtl:space-x-reverse">
            <button onclick="closeDeleteModal()"
                    class="px-4 py-2 bg-gray-200 rounded-lg hover:bg-gray-300">
                إلغاء
            </button>

            <button id="confirm-delete-btn"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                حذف
            </button>
        </div>
    </div>
</div>

<script>
let selectedId = null;

function openDeleteModal(id, filename) {
    selectedId = id;

    document.getElementById('delete-message').innerHTML =
        `هل أنت متأكد من حذف الملف <span class="font-bold text-red-600">${filename}</span> وجميع المجموعات؟`;

    document.getElementById('delete-modal').classList.remove('hidden');
}

function closeDeleteModal() {
    selectedId = null;
    document.getElementById('delete-modal').classList.add('hidden');
}

document.getElementById('confirm-delete-btn').addEventListener('click', function () {

    fetch(`/uploads/${selectedId}`, {
        method: 'DELETE',
        headers: {
            "X-CSRF-TOKEN": "{{ csrf_token() }}",
            "Accept": "application/json"
        }
    })
    .then(res => res.json())
    .then(data => {

        if (data.success) {
            document.getElementById(`row-${selectedId}`).remove();

            showToast();
        }

        closeDeleteModal();
    });
});

function showToast() {
    let toast = document.getElementById('toast-success');
    toast.classList.remove('hidden');

    setTimeout(() => toast.classList.add('hidden'), 2500);
}
</script>

@endsection
