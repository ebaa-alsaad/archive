@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto space-y-10">

    <h2 class="text-4xl font-extrabold text-gray-800 text-center">
        <i class="fa-solid fa-cloud-arrow-up text-blue-600 ml-3"></i> رفع ملف PDF جديد
    </h2>

    <div id="toast-container" class="fixed top-5 right-5 z-[100] space-y-2"></div>

    <div id="upload-card" class="bg-white p-10 rounded-[30px] shadow-3xl shadow-blue-200/50 border border-gray-100">

        <form id="upload-form" class="space-y-8" enctype="multipart/form-data">
            @csrf
            <div class="mb-6" x-data="{ isDragging: false }">
                <input id="file-input" type="file" name="pdf_file" accept="application/pdf" class="hidden" required/>
                <div id="drop-zone"
                     @dragover.prevent="isDragging = true"
                     @dragleave.prevent="isDragging = false"
                     @drop.prevent="isDragging = false; handleDrop($event)"
                     class="flex flex-col items-center justify-center w-full h-56 border-4 border-dashed rounded-3xl cursor-pointer transition duration-300"
                     :class="isDragging ? 'border-blue-500 bg-blue-50/70 shadow-inner' : 'border-gray-300 bg-gray-50 hover:bg-gray-100'">

                    <div class="flex flex-col items-center justify-center pt-5 pb-6 text-center px-4">
                        <i id="upload-icon" class="fa-solid fa-file-arrow-up text-5xl text-blue-500 mb-4"></i>
                        <p class="mb-2 text-lg text-gray-600 font-semibold">
                            <span class="font-extrabold text-blue-700">اسحب وأفلت ملف PDF</span> أو انقر للتحميل
                        </p>
                        <p class="text-sm text-gray-400">ملفات PDF فقط | الحد الأقصى 200MB</p>
                        <p id="file-name" class="mt-4 text-base text-gray-700 font-bold max-w-sm truncate hidden"></p>
                    </div>
                </div>
            </div>

            <div id="progress-container" class="space-y-3 hidden">
                <div class="flex justify-between items-center text-sm font-semibold text-gray-700">
                    <span id="progress-message">جاري الرفع والمعالجة...</span>
                    <span id="progress-percentage">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div id="progress-bar" class="bg-blue-600 h-3 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                </div>
            </div>

            <button type="submit" id="start-archiving"
                    disabled
                    class="w-full bg-gradient-to-r from-blue-600 to-blue-500 text-white px-6 py-4 rounded-2xl font-extrabold text-xl shadow-2xl shadow-blue-500/60 disabled:opacity-50 disabled:cursor-not-allowed hover:from-blue-700 hover:to-blue-600">
                <i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة
            </button>

        </form>
    </div>
</div>

<script>
const fileInput = document.getElementById('file-input');
const archiveButton = document.getElementById('start-archiving');
const fileNameDisplay = document.getElementById('file-name');
const dropZone = document.getElementById('drop-zone');
const progressBar = document.getElementById('progress-bar');
const progressPercentage = document.getElementById('progress-percentage');
const progressContainer = document.getElementById('progress-container');
const progressMessage = document.getElementById('progress-message');
const toastContainer = document.getElementById('toast-container');

function showToast(message, type='info') {
    const colors = {
        success: 'bg-green-600 text-white',
        error: 'bg-red-600 text-white',
        info: 'bg-blue-600 text-white'
    };
    const toast = document.createElement('div');
    toast.className = `p-4 rounded-xl shadow-md ${colors[type]} animate-fade-in`;
    toast.textContent = message;
    toastContainer.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

function updateFileInput(files) {
    if (files && files.length > 0) {
        const file = files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);
        if (file.type !== 'application/pdf' || file.size > 200*1024*1024) {
            showToast('PDF أكبر من 200MB أو صيغة غير صحيحة', 'error');
            fileInput.value = '';
            archiveButton.disabled = true;
            fileNameDisplay.classList.add('hidden');
            return;
        }
        fileNameDisplay.textContent = `${file.name} (${fileSizeMB} MB)`;
        fileNameDisplay.classList.remove('hidden');
        archiveButton.disabled = false;
    }
}

fileInput.addEventListener('change', () => updateFileInput(fileInput.files));
dropZone.addEventListener('click', () => fileInput.click());
function handleDrop(e) { fileInput.files = e.dataTransfer.files; updateFileInput(fileInput.files); }

document.getElementById('upload-form').addEventListener('submit', async function(e){
    e.preventDefault();
    if (!fileInput.files.length) return showToast('اختر ملف أولاً', 'error');

    archiveButton.disabled = true;
    archiveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري الرفع...';

    const formData = new FormData(this);
    let response;
    try {
        response = await fetch('{{ route("uploads.store") }}', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        });
    } catch (err) {
        showToast('فشل الاتصال بالخادم', 'error');
        archiveButton.disabled = false;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
        return;
    }

    const data = await response.json();
    if (!data.success) {
        showToast(data.message, 'error');
        archiveButton.disabled = false;
        return;
    }

    const uploadId = data.upload_id;
    progressContainer.classList.remove('hidden');
    progressMessage.textContent = 'جاري رفع الملف والمعالجة...';

    // poll لتحديث progress من Redis
    const pollInterval = setInterval(async () => {
        try {
            const res = await fetch(`/uploads/progress/${uploadId}`);
            const json = await res.json();
            const percent = json.progress ?? 0;
            progressBar.style.width = percent + '%';
            progressPercentage.textContent = percent + '%';

            if (percent >= 100) {
                clearInterval(pollInterval);
                progressMessage.textContent = 'تمت المعالجة بنجاح!';
                showToast('تمت المعالجة بنجاح!', 'success');
                archiveButton.disabled = false;
                archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
            }
        } catch (err) {
            clearInterval(pollInterval);
            showToast('حدث خطأ أثناء التقدم', 'error');
            archiveButton.disabled = false;
            archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
        }
    }, 1000);
});
</script>

@endsection
