@extends('layouts.app')

@section('content')
<div class="max-w-7xl mx-auto p-6 space-y-6">
    <!-- العنوان الرئيسي -->
    <div class="flex justify-between items-center mb-6">
        <h2 class="text-3xl font-bold text-gray-800 flex items-center">
            <i class="fa-solid fa-folder-tree ml-2 text-blue-600"></i> المجموعات الناتجة
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

    <!-- Delete Modal -->
    <div id="delete-modal" class="hidden fixed inset-0 z-[9999] overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center">
            <!-- Overlay -->
            <div class="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity"></div>

            <!-- Modal Content -->
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-md w-full mx-auto overflow-hidden transform transition-all">
                <!-- Modal Header -->
                <div class="p-6 text-center border-b border-gray-200">
                    <div class="inline-flex items-center justify-center w-16 h-16 bg-red-100 rounded-full mb-4">
                        <i class="fa-solid fa-trash-alt text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-2 text-red-700">تأكيد الحذف</h3>
                </div>

                <!-- Modal Body -->
                <div class="p-6">
                    <p id="delete-modal-text" class="text-gray-700 mb-6 text-center text-lg"></p>
                </div>

                <!-- Modal Footer -->
                <div class="p-6 bg-gray-50 border-t border-gray-200 flex justify-center space-x-4 rtl:space-x-reverse">
                    <button id="cancel-delete"
                            class="px-6 py-2.5 bg-gray-200 text-gray-700 rounded-xl hover:bg-gray-300 transition-colors font-medium flex-1 max-w-[120px]">
                        إلغاء
                    </button>
                    <button id="confirm-delete"
                            class="px-6 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-colors font-medium flex-1 max-w-[120px] flex items-center justify-center">
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
                    <tr class="bg-gradient-to-r from-blue-50 to-blue-100 text-blue-900 uppercase text-sm font-bold tracking-wider">
                        <th class="py-4 px-4 text-center"><input type="checkbox" id="select-all"></th>
                        <th class="py-4 px-4 text-center">اسم الملف الأصلي</th>
                        <th class="py-4 px-4 text-center">الرافع</th>
                        <th class="py-4 px-4 text-center">عدد المجموعات الناتجة</th>
                        <th class="py-4 px-4 text-center">الحالة</th>
                        <th class="py-4 px-4 text-center">الإجراءات</th>
                    </tr>
                </thead>
                <tbody class="text-gray-700 text-sm divide-y divide-gray-100">
                    @forelse($uploads as $upload)
                    <tr id="row-{{ $upload->id }}" class="hover:bg-gray-50 transition">
                        <!-- Checkbox -->
                        <td class="py-4 px-4 text-center">
                            <input type="checkbox" class="select-row" value="{{ $upload->id }}" data-filename="{{ $upload->original_filename }}">
                        </td>

                        <!-- اسم الملف الأصلي -->
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
                            <span class="text-xs text-gray-500">{{ $upload->user?->name ?? 'غير معروف' }}</span>
                        </td>

                        <!-- عدد المجموعات الناتجة -->
                        <td class="py-4 px-4 text-center">
                            @if($upload->groups->count() > 0)
                                <span class="inline-flex items-center justify-center w-10 h-10 bg-purple-100 text-purple-700 rounded-full font-bold text-lg">
                                    {{ $upload->groups->count() }}
                                </span>
                            @else
                                <span class="text-gray-400">-</span>
                            @endif
                        </td>

                        <!-- الحالة -->
                        <td class="py-4 px-4 text-center">
                            @if($upload->status == 'completed')
                                <span class="text-green-700 bg-green-50 px-3 py-1.5 rounded-lg flex justify-center items-center min-w-[85px] border border-green-200">
                                    <i class="fa-solid fa-check-circle text-sm ml-1"></i> مكتملة
                                </span>
                            @elseif($upload->status == 'processing')
                                <span class="text-yellow-700 bg-yellow-50 px-3 py-1.5 rounded-lg flex justify-center items-center min-w-[85px] border border-yellow-200 animate-pulse">
                                    <i class="fa-solid fa-spinner fa-spin text-sm ml-1"></i> جارٍ
                                </span>
                            @else
                                <span class="text-red-700 bg-red-50 px-3 py-1.5 rounded-lg flex justify-center items-center min-w-[85px] border border-red-200">
                                    <i class="fa-solid fa-times-circle text-sm ml-1"></i> فشل
                                </span>
                            @endif
                        </td>

                        <!-- الإجراءات -->
                        <td class="py-4 px-4 text-center">
                            <div class="flex justify-center space-x-3 rtl:space-x-reverse">
                                <!-- عرض الملف الأصلي -->
                                <a href="{{ route('uploads.show_file', ['upload' => $upload->id]) }}" target="_blank"
                                    class="text-green-600 hover:text-green-800 transition transform hover:scale-110">
                                    <i class="fa-solid fa-eye text-lg"></i>
                                </a>

                                <!-- تفاصيل الملف الأصلي -->
                                <a href="{{ route('uploads.show', $upload->id) }}"
                                    class="text-blue-600 hover:text-blue-800 transition transform hover:scale-110">
                                    <i class="fa-solid fa-info-circle text-lg"></i>
                                </a>

                                <!-- DELETE BUTTON -->
                                <button type="button"
                                    class="delete-btn text-red-600 hover:text-red-800 transition transform hover:scale-110"
                                    data-id="{{ $upload->id }}"
                                    data-filename="{{ $upload->original_filename }}">
                                    <i class="fa-solid fa-trash-alt text-lg"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-16 text-center text-gray-500">
                            <div class="flex flex-col items-center justify-center">
                                <i class="fa-solid fa-inbox text-5xl mb-4 text-gray-300"></i>
                                <p class="text-lg font-medium text-gray-400 mb-2">لا توجد ملفات مرفوعة حالياً</p>
                                <p class="text-sm text-gray-400">قم برفع ملفات PDF لمعالجتها وتقسيمها إلى مجموعات</p>
                                <a href="{{ route('uploads.create') }}"
                                   class="mt-4 bg-gradient-to-r from-blue-600 to-blue-500 text-white px-6 py-2 rounded-lg font-medium hover:from-blue-700 hover:to-blue-600 transition-all">
                                    <i class="fa-solid fa-plus ml-2"></i> رفع أول ملف
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- حذف متعدد -->
        <div class="flex justify-between items-center mt-6">
            <div class="text-gray-600">
                <span id="selected-count">0</span> ملف/ملفات محددة
            </div>
            <div>
                <button id="multi-delete-btn"
                        class="px-6 py-2.5 bg-red-600 text-white rounded-xl hover:bg-red-700 transition-all font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center"
                        disabled>
                    <i class="fa-solid fa-trash ml-2"></i> حذف المحدد
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let selectedIds = [];

// توست
function showToast(message, type = 'success'){
    const toast = document.querySelector('#toast > div');
    const icon = toast.querySelector('i');
    const text = toast.querySelector('span');

    icon.className = type === 'error' ? 'fa-solid fa-triangle-exclamation text-red-500' : 'fa-solid fa-check-circle text-green-500';
    toast.classList.remove('border-red-200', 'border-green-200');
    toast.classList.add(type === 'error' ? 'border-red-200' : 'border-green-200');
    text.innerText = message;

    toast.parentElement.classList.add('pointer-events-auto');
    toast.classList.add('opacity-100');
    setTimeout(() => {
        toast.classList.remove('opacity-100');
        toast.parentElement.classList.remove('pointer-events-auto');
    }, 2500);
}

// فتح البوب اب
function openDeleteModal(mode, items){
    selectedIds = items.map(i => i.id);
    let text = '';
    if(mode === 'single'){
        text = `هل أنت متأكد من حذف الملف "<span class="font-bold text-red-600">${items[0].filename}</span>"؟`;
    } else {
        text = `هل أنت متأكد من حذف <span class="font-bold text-red-600">${items.length}</span> ملف/ملفات؟`;
    }
    document.getElementById('delete-modal-text').innerHTML = text;
    document.getElementById('delete-modal').classList.remove('hidden');
    document.body.classList.add('overflow-hidden');
}

// إغلاق البوب اب
function closeDeleteModal(){
    selectedIds = [];
    document.getElementById('delete-modal').classList.add('hidden');
    document.body.classList.remove('overflow-hidden');
}

// حذف فردي
document.querySelectorAll('.delete-btn').forEach(btn=>{
    btn.addEventListener('click', ()=>{
        openDeleteModal('single', [{id: btn.dataset.id, filename: btn.dataset.filename}]);
    });
});

// تحديد الكل
document.getElementById('select-all')?.addEventListener('change', function(){
    document.querySelectorAll('.select-row').forEach(cb=>{
        cb.checked = this.checked;
    });
    updateSelectedCount();
});

// تحديث عدد المحدد
function updateSelectedCount(){
    const selected = Array.from(document.querySelectorAll('.select-row:checked'));
    document.getElementById('selected-count').textContent = selected.length;
    document.getElementById('multi-delete-btn').disabled = selected.length === 0;
}

// تحديث عند تغيير أي checkbox
document.querySelectorAll('.select-row').forEach(cb=>{
    cb.addEventListener('change', updateSelectedCount);
});
updateSelectedCount();

// حذف متعدد
document.getElementById('multi-delete-btn').addEventListener('click', ()=>{
    const selected = Array.from(document.querySelectorAll('.select-row:checked')).map(cb=>({id: cb.value, filename: cb.dataset.filename}));
    if(selected.length > 0){
        openDeleteModal('multi', selected);
    }
});

// تأكيد الحذف
document.getElementById('confirm-delete').addEventListener('click', async ()=>{
    if(selectedIds.length === 0) return;
    for(const id of selectedIds){
        try{
            const res = await fetch(`/uploads/${id}`, {
                method: 'DELETE',
                headers: {
                    "X-CSRF-TOKEN": "{{ csrf_token() }}",
                    "Accept": "application/json"
                }
            });
            const data = await res.json();
            if(data.success){
                document.getElementById(`row-${id}`)?.remove();
            }
        }catch(e){
            console.error(e);
            showToast('فشل الحذف', 'error');
        }
    }
    showToast('تم الحذف بنجاح');
    closeDeleteModal();
    updateSelectedCount();
});

// إلغاء الحذف
document.getElementById('cancel-delete').addEventListener('click', closeDeleteModal);
</script>

<style>
tbody tr {
    transition: all 0.2s ease;
}
tbody tr:hover {
    background-color: #f9fafb;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

/* شريط التمرير */
.overflow-x-auto::-webkit-scrollbar {
    height: 8px;
}
.overflow-x-auto::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}
.overflow-x-auto::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}
.overflow-x-auto::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* تأثيرات للـ modal */
#delete-modal {
    animation: fadeIn 0.2s ease-out;
}
@keyframes fadeIn { from {opacity:0;} to {opacity:1;} }
#delete-modal > div:first-child {
    animation: slideUp 0.3s ease-out;
}
@keyframes slideUp { from {opacity:0; transform:translateY(20px) scale(0.95);} to {opacity:1; transform:translateY(0) scale(1);} }
</style>
@endsection
