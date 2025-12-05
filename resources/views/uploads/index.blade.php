@extends('layouts.app')

@section('title', 'الملفات المرفوعة')

@section('content')
<div class="max-w-7xl mx-auto p-6 space-y-6">

    <!-- العنوان الرئيسي -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-gray-800 flex items-center">
            <i class="fa-solid fa-file-invoice ml-2 text-blue-600"></i>
            قائمة الملفات المرفوعة
        </h2>

        <a href="{{ route('uploads.create') }}"
           class="bg-gradient-to-r from-blue-600 to-blue-500 text-white px-6 py-3 rounded-xl font-bold hover:from-blue-700 hover:to-blue-600 transition-all flex items-center">
            <i class="fa-solid fa-plus ml-2"></i> رفع ملفات جديدة
        </a>
    </div>

    <!-- Toast -->
    <div id="toast" class="fixed top-4 right-4 z-[100] pointer-events-none">
        <div class="bg-white shadow-lg rounded-xl px-6 py-4 flex items-center space-x-3 rtl:space-x-reverse opacity-0 transition-opacity duration-300 border border-green-200">
            <i class="fa-solid fa-check-circle text-green-500"></i>
            <span class="text-gray-800 font-semibold"></span>
        </div>
    </div>

    <!-- Modal حذف -->
    <div id="delete-modal" class="hidden fixed inset-0 z-[9999] overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 text-center">
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm"></div>

            <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full mx-auto overflow-hidden transform transition-all">
                <div class="p-6 text-center border-b border-gray-200">
                    <div class="w-16 h-16 mx-auto bg-red-100 rounded-full flex items-center justify-center mb-4">
                        <i class="fa-solid fa-trash-alt text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2 text-red-700">تأكيد الحذف</h3>
                </div>

                <div class="p-6">
                    <p id="delete-modal-text" class="text-gray-700 mb-6 text-center text-lg"></p>
                </div>

                <div class="p-6 bg-gray-50 border-t border-gray-200 flex justify-center space-x-4 rtl:space-x-reverse">
                    <button id="cancel-delete" class="px-6 py-2.5 bg-gray-200 rounded-xl hover:bg-gray-300 transition font-medium">
                        إلغاء
                    </button>

                    <button id="confirm-delete"
                        class="px-6 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 transition font-medium flex items-center justify-center">
                        <i class="fa-solid fa-trash ml-2"></i> تأكيد
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول الملفات -->
    <div class="bg-white p-6 rounded-2xl shadow-xl border border-gray-100">

        <div class="overflow-x-auto rounded-xl border border-gray-200">
            <table class="w-full text-right divide-y divide-gray-200">
                <thead>
                    <tr class="bg-gradient-to-r from-blue-50 to-blue-100 text-blue-900 uppercase text-sm font-bold">
                        <th class="py-4 px-4 text-center"><input type="checkbox" id="select-all"></th>
                        <th class="py-4 px-4 text-center">اسم الملف</th>
                        <th class="py-4 px-4 text-center">الرافع</th>
                        <th class="py-4 px-4 text-center">المجموعات</th>
                        <th class="py-4 px-4 text-center">الحالة</th>
                        <th class="py-4 px-4 text-center">الإجراءات</th>
                    </tr>
                </thead>

                <tbody class="text-gray-700 text-sm divide-y divide-gray-100">
                    @forelse($uploads as $upload)
                    <tr id="row-{{ $upload->id }}" class="hover:bg-gray-50 transition">

                        <!-- Checkbox -->
                        <td class="py-4 px-4 text-center">
                            <input type="checkbox" class="select-row" value="{{ $upload->id }}">
                        </td>

                        <!-- اسم الملف -->
                        <td class="py-4 px-4">
                            <div class="flex items-center">
                                <i class="fa-solid fa-file-pdf text-red-500 ml-2 text-lg"></i>
                                <div>
                                    <p class="font-semibold text-gray-800 truncate max-w-[200px]" title="{{ $upload->original_filename }}">
                                        {{ $upload->original_filename }}
                                    </p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        {{ $upload->created_at->format('Y-m-d H:i') }}
                                    </p>
                                </div>
                            </div>
                        </td>

                        <!-- الرافع -->
                        <td class="py-4 px-4 text-center">
                            {{ $upload->user->name ?? 'غير معروف' }}
                        </td>

                        <!-- عدد المجموعات -->
                        <td class="py-4 px-4 text-center">
                            @if($upload->groups->count())
                                <a href="{{ route('groups.for_upload', $upload->id) }}"
                                   class="bg-blue-600 text-white px-3 py-1 rounded-lg text-xs shadow hover:bg-blue-700">
                                   {{ $upload->groups->count() }} مجموعات
                                </a>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>

                        <!-- الحالة -->
                        <td class="py-4 px-4 text-center">
                            @include('uploads.partials.status', ['status' => $upload->status])
                        </td>

                        <!-- الإجراءات -->
                        <td class="py-4 px-4 text-center">
                            <div class="flex justify-center space-x-3 rtl:space-x-reverse">

                                <a href="{{ route('uploads.show_file', $upload->id) }}"
                                   class="text-green-600 hover:text-green-800 text-lg">
                                    <i class="fa-solid fa-eye"></i>
                                </a>

                                <a href="{{ route('uploads.show', $upload->id) }}"
                                   class="text-blue-600 hover:text-blue-800 text-lg">
                                    <i class="fa-solid fa-circle-info"></i>
                                </a>

                                <button type="button"
                                    class="delete-btn text-red-600 hover:text-red-800 text-lg"
                                    data-id="{{ $upload->id }}"
                                    data-filename="{{ $upload->original_filename }}">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>

                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-12 text-center text-gray-400">
                            <i class="fa-solid fa-inbox text-5xl mb-4"></i>
                            <p class="text-lg">لا توجد ملفات مرفوعة حالياً</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- حذف متعدد -->
        @if($uploads->count())
        <div id="multi-delete-section" class="flex justify-between items-center mt-6">
            <div class="text-gray-600">
                <span id="selected-count">0</span> ملف/ملفات محددة
            </div>

            <button id="multi-delete-btn"
                class="px-6 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 disabled:opacity-50"
                disabled>
                <i class="fa-solid fa-trash ml-2"></i> حذف المحدد
            </button>
        </div>
        @endif

    </div>
</div>

{{-- ================= JS بالكامل ================= --}}
<script>
let deleteQueue = [];

function showToast(message, type = 'success') {
    const toast = document.querySelector('#toast > div');
    const icon = toast.querySelector('i');
    const text = toast.querySelector('span');

    if (type === 'error') {
        icon.className = 'fa-solid fa-triangle-exclamation text-red-500';
    } else {
        icon.className = 'fa-solid fa-check-circle text-green-500';
    }

    text.innerText = message;
    toast.classList.add('opacity-100');

    setTimeout(() => {
        toast.classList.remove('opacity-100');
    }, 2500);
}

function updateSelectedCount() {
    const selected = document.querySelectorAll('.select-row:checked').length;
    document.getElementById('selected-count').innerText = selected;
    document.getElementById('multi-delete-btn').disabled = selected === 0;
}

document.getElementById('select-all')?.addEventListener('change', function(){
    document.querySelectorAll('.select-row').forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});

document.querySelectorAll('.select-row').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});

document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        deleteQueue = [{ id: btn.dataset.id, filename: btn.dataset.filename }];

        document.getElementById('delete-modal-text').innerHTML =
            `هل أنت متأكد من حذف "<span class="font-bold text-red-600">${btn.dataset.filename}</span>"؟`;

        document.getElementById('delete-modal').classList.remove('hidden');
    });
});

document.getElementById('multi-delete-btn')?.addEventListener('click', () => {
    const selected = [...document.querySelectorAll('.select-row:checked')].map(cb => ({
        id: cb.value,
        filename: ''
    }));

    deleteQueue = selected;

    document.getElementById('delete-modal-text').innerHTML =
        `هل أنت متأكد من حذف <span class="font-bold text-red-600">${selected.length}</span> ملف؟`;

    document.getElementById('delete-modal').classList.remove('hidden');
});

document.getElementById('cancel-delete').addEventListener('click', () => {
    document.getElementById('delete-modal').classList.add('hidden');
});

document.getElementById('confirm-delete').addEventListener('click', () => {

    deleteQueue.forEach(item => {
        fetch(`/uploads/${item.id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': "{{ csrf_token() }}" }
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById(`row-${item.id}`)?.remove();
            }
        });
    });

    showToast("تم الحذف بنجاح");
    document.getElementById('delete-modal').classList.add('hidden');
});
</script>

@endsection
