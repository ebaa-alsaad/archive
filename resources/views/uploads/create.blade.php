@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto space-y-8">
    <h2 class="text-4xl font-extrabold text-gray-800 text-center">
        <i class="fa-solid fa-bolt text-yellow-500 ml-3"></i> معالجة فائقة السرعة
    </h2>

    <div id="toast-container" class="fixed top-5 right-5 z-[100] space-y-2 max-w-sm"></div>

    <!-- حالة الرفع الأساسية -->
    <div id="upload-card" class="bg-white p-8 rounded-2xl shadow-xl border border-gray-100">
        <form id="upload-form" class="space-y-6" enctype="multipart/form-data">
            @csrf
            <div class="mb-6">
                <input id="file-input" type="file" name="pdf_files[]" accept="application/pdf" class="hidden" multiple required/>
                <div id="drop-zone"
                     class="flex flex-col items-center justify-center w-full h-64 border-3 border-dashed border-gray-300 bg-gray-50 hover:bg-gray-100 rounded-2xl cursor-pointer transition-all duration-300 ease-in-out">
                    <div class="flex flex-col items-center justify-center text-center px-6">
                        <i id="upload-icon" class="fa-solid fa-bolt text-6xl mb-4 text-yellow-500 transition-all duration-300"></i>
                        <p class="mb-2 text-xl text-gray-700 font-bold">
                            <span class="text-yellow-600">اسحب وأفلت ملفات PDF</span> أو انقر للتحميل
                        </p>
                        <p class="text-sm text-gray-500 mb-2">معالجة فائقة السرعة - بدون تخزين مؤقت</p>
                        <p class="text-xs text-gray-400">الحد الأقصى 500MB لكل ملف • معالجة فورية</p>
                        <div id="file-list" class="mt-4 space-y-2 max-h-32 overflow-y-auto hidden"></div>
                    </div>
                </div>
            </div>

            <!-- معلومات الملفات -->
            <div id="files-info" class="hidden bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                <h4 class="font-semibold text-yellow-800 mb-2">الملفات المختارة:</h4>
                <div id="files-list" class="space-y-2"></div>
                <div class="mt-3 flex justify-between items-center text-sm">
                    <span id="total-size" class="text-yellow-600 font-medium"></span>
                    <button type="button" onclick="clearFiles()" class="text-red-500 hover:text-red-700 p-2">
                        <i class="fa-solid fa-times"></i> إزالة الكل
                    </button>
                </div>
            </div>

            <!-- تقدم المعالجة -->
            <div id="processing-progress-container" class="space-y-3 hidden">
                <div class="flex justify-between items-center text-sm font-semibold text-gray-700">
                    <span id="processing-progress-message">جاري المعالجة الفائقة...</span>
                    <span id="processing-progress-percentage">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div id="processing-progress-bar" class="bg-yellow-500 h-2.5 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                </div>
                <div id="files-status-container" class="space-y-2 max-h-40 overflow-y-auto"></div>
            </div>

            <button type="submit" id="start-processing"
                    disabled
                    class="w-full bg-gradient-to-r from-yellow-500 to-yellow-400 text-white px-8 py-4 rounded-xl font-bold text-lg shadow-lg disabled:opacity-50 disabled:cursor-not-allowed hover:from-yellow-600 hover:to-yellow-500 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98]">
                <i class="fa-solid fa-bolt ml-2"></i> بدء المعالجة الفائقة
            </button>
        </form>
    </div>

    <!-- نتائج المعالجة -->
    <div id="results-container" class="hidden bg-green-50 p-6 rounded-2xl border border-green-200">
        <div class="text-center">
            <i class="fa-solid fa-circle-check text-green-500 text-4xl mb-3"></i>
            <h3 class="text-xl font-bold text-green-800 mb-2">تمت المعالجة بنجاح!</h3>
            <p class="text-green-600 mb-2" id="results-message"></p>
            <p class="text-green-500 text-sm mb-4" id="results-details"></p>
            <div class="flex gap-3 justify-center flex-wrap">
                <button id="download-all-btn" class="bg-green-500 text-white px-6 py-2 rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fa-solid fa-download ml-2"></i> تحميل النتائج
                </button>
                <button onclick="resetToUpload()" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fa-solid fa-plus ml-2"></i> معالجة ملفات جديدة
                </button>
            </div>
        </div>
    </div>

    <!-- حالة الخطأ -->
    <div id="error-container" class="hidden bg-red-50 p-6 rounded-2xl border border-red-200">
        <div class="text-center">
            <i class="fa-solid fa-circle-exclamation text-red-500 text-4xl mb-3"></i>
            <h3 class="text-xl font-bold text-red-800 mb-2">فشلت المعالجة</h3>
            <p class="text-red-600 mb-4" id="error-message"></p>
            <button onclick="resetToUpload()" class="bg-red-500 text-white px-6 py-2 rounded-lg hover:bg-red-600 transition-colors">
                <i class="fa-solid fa-rotate-left ml-2"></i> المحاولة مرة أخرى
            </button>
        </div>
    </div>
</div>

<script>
// المتغيرات العامة
let currentUploadIds = [];
let processingInterval = null;

// دالة لعرض الإشعارات
function showToast(message, type = 'info') {
    const colors = {
        success: 'bg-green-500 text-white border-green-600',
        error: 'bg-red-500 text-white border-red-600',
        warning: 'bg-yellow-500 text-white border-yellow-600',
        info: 'bg-blue-500 text-white border-blue-600'
    };

    const icons = {
        success: 'fa-circle-check',
        error: 'fa-circle-exclamation',
        warning: 'fa-triangle-exclamation',
        info: 'fa-circle-info'
    };

    const toast = document.createElement('div');
    toast.className = `p-4 rounded-lg shadow-lg border ${colors[type]} animate-fade-in flex items-center space-x-3 space-x-reverse`;
    toast.innerHTML = `
        <i class="fa-solid ${icons[type]}"></i>
        <span>${message}</span>
    `;

    document.getElementById('toast-container').appendChild(toast);

    setTimeout(() => {
        toast.classList.add('animate-fade-out');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

// تنسيق حجم الملف
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// تحديث واجهة الملفات المختارة
function updateFileInput(files) {
    const fileList = document.getElementById('files-list');
    const filesInfo = document.getElementById('files-info');
    const startButton = document.getElementById('start-processing');

    fileList.innerHTML = '';

    let totalSize = 0;
    let hasInvalidFile = false;

    Array.from(files).forEach((file, index) => {
        const fileSizeMB = file.size / 1024 / 1024;

        // التحقق من صحة الملف
        if (file.type !== 'application/pdf') {
            showToast(`الملف "${file.name}" ليس من نوع PDF`, 'error');
            hasInvalidFile = true;
            return;
        }

        if (fileSizeMB > 500) {
            showToast(`الملف "${file.name}" حجمه أكبر من 500MB`, 'error');
            hasInvalidFile = true;
            return;
        }

        totalSize += file.size;

        // إضافة الملف للقائمة
        const fileElement = document.createElement('div');
        fileElement.className = 'flex justify-between items-center p-2 bg-white rounded border';
        fileElement.innerHTML = `
            <div class="flex items-center space-x-2 space-x-reverse">
                <i class="fa-solid fa-file-pdf text-red-500"></i>
                <span class="text-sm font-medium truncate max-w-xs">${file.name}</span>
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <span class="text-xs text-gray-500">${formatFileSize(file.size)}</span>
                <button type="button" onclick="removeFile(${index})" class="text-red-400 hover:text-red-600">
                    <i class="fa-solid fa-times"></i>
                </button>
            </div>
        `;
        fileList.appendChild(fileElement);
    });

    if (hasInvalidFile) {
        resetFileInput();
        return;
    }

    if (files.length > 0) {
        filesInfo.classList.remove('hidden');
        document.getElementById('total-size').textContent = `إجمالي الحجم: ${formatFileSize(totalSize)}`;
        startButton.disabled = false;

        showToast(`تم اختيار ${files.length} ملف للمعالجة الفائقة`, 'success');
    } else {
        resetFileInput();
    }
}

// إزالة ملف محدد
function removeFile(index) {
    const fileInput = document.getElementById('file-input');
    const files = Array.from(fileInput.files);
    files.splice(index, 1);

    const newFileList = new DataTransfer();
    files.forEach(file => newFileList.items.add(file));
    fileInput.files = newFileList.files;

    updateFileInput(fileInput.files);
}

// مسح جميع الملفات
function clearFiles() {
    resetFileInput();
    showToast('تم إزالة جميع الملفات', 'info');
}

// إعادة تعيين المدخلات
function resetFileInput() {
    const fileInput = document.getElementById('file-input');
    fileInput.value = '';
    document.getElementById('start-processing').disabled = true;
    document.getElementById('files-info').classList.add('hidden');
}

// إعادة التعيين للرفع الجديد
function resetToUpload() {
    resetFileInput();
    currentUploadIds = [];

    document.getElementById('processing-progress-container').classList.add('hidden');
    document.getElementById('results-container').classList.add('hidden');
    document.getElementById('error-container').classList.add('hidden');
    document.getElementById('upload-card').classList.remove('hidden');

    if (processingInterval) {
        clearInterval(processingInterval);
        processingInterval = null;
    }
}

// أحداث السحب والإفلات
document.getElementById('file-input').addEventListener('change', (e) => {
    updateFileInput(e.target.files);
});

const dropZone = document.getElementById('drop-zone');

dropZone.addEventListener('click', (e) => {
    e.stopPropagation();
    document.getElementById('file-input').click();
});

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    e.stopPropagation();
    dropZone.classList.add('border-yellow-500', 'bg-yellow-50');
});

dropZone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    e.stopPropagation();
    dropZone.classList.remove('border-yellow-500', 'bg-yellow-50');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    e.stopPropagation();
    dropZone.classList.remove('border-yellow-500', 'bg-yellow-50');

    const files = e.dataTransfer.files;
    if (files.length > 0) {
        const dataTransfer = new DataTransfer();
        Array.from(files).forEach(file => {
            if (file.type === 'application/pdf') {
                dataTransfer.items.add(file);
            }
        });
        document.getElementById('file-input').files = dataTransfer.files;
        updateFileInput(document.getElementById('file-input').files);
    }
});

// إرسال النموذج - معالجة فائقة السرعة
document.getElementById('upload-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const files = document.getElementById('file-input').files;
    if (files.length === 0) {
        showToast('يرجى اختيار ملفات PDF أولاً', 'error');
        return;
    }

    const startButton = document.getElementById('start-processing');
    startButton.disabled = true;
    startButton.innerHTML = '<i class="fa-solid fa-bolt fa-spin ml-2"></i> جاري المعالجة الفائقة...';

    // إظهار شريط التقدم
    document.getElementById('processing-progress-container').classList.remove('hidden');
    updateProcessingStatus(10, 'جاري بدء المعالجة الفائقة...');

    const formData = new FormData();
    Array.from(files).forEach(file => {
        formData.append('pdf_files[]', file);
    });

    try {
        const response = await fetch('{{ route("uploads.store") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: formData
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || "خطأ في المعالجة");
        }

        // حفظ معرّفات الرفع
        currentUploadIds = data.results.map(result => result.upload_id);

        showToast(`تم بدء معالجة ${data.file_count} ملف بنجاح!`, 'success');

        // بدء تتبع الحالة
        startProcessingTracking();

    } catch (error) {
        console.error('Processing error:', error);
        showToast(error.message, 'error');
        resetToUpload();
    }
});

// تتبع حالة المعالجة
function startProcessingTracking() {
    if (processingInterval) {
        clearInterval(processingInterval);
    }

    processingInterval = setInterval(async () => {
        if (currentUploadIds.length === 0) return;

        try {
            const response = await fetch('{{ route("uploads.status.multi") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({
                    upload_ids: currentUploadIds
                })
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.error || 'خطأ في التحقق من الحالة');
            }

            updateProcessingUI(data);

            if (data.all_completed) {
                clearInterval(processingInterval);
                showProcessingResults(data);
            } else if (data.any_failed) {
                clearInterval(processingInterval);
                showError('فشلت معالجة بعض الملفات');
            }

        } catch (error) {
            console.error('Status check error:', error);
        }
    }, 2000); // التحقق كل ثانيتين
}

// تحديث واجهة المعالجة
function updateProcessingUI(data) {
    const statusContainer = document.getElementById('files-status-container');
    const progressBar = document.getElementById('processing-progress-bar');
    const progressPercentage = document.getElementById('processing-progress-percentage');

    statusContainer.innerHTML = '';

    let completedCount = 0;

    data.statuses.forEach(status => {
        const statusElement = document.createElement('div');
        statusElement.className = 'flex justify-between items-center p-2 bg-white rounded border text-sm';

        let statusColor = 'text-gray-500';
        let statusIcon = 'fa-clock';

        switch (status.status) {
            case 'completed':
                statusColor = 'text-green-600';
                statusIcon = 'fa-circle-check';
                completedCount++;
                break;
            case 'processing':
                statusColor = 'text-yellow-600';
                statusIcon = 'fa-bolt fa-spin';
                break;
            case 'failed':
                statusColor = 'text-red-600';
                statusIcon = 'fa-circle-exclamation';
                break;
        }

        statusElement.innerHTML = `
            <div class="flex items-center space-x-2 space-x-reverse">
                <i class="fa-solid ${statusIcon} ${statusColor}"></i>
                <span class="truncate max-w-xs">${status.filename}</span>
            </div>
            <div class="flex items-center space-x-2 space-x-reverse">
                <span class="text-xs ${statusColor}">${status.message}</span>
                ${status.groups_count > 0 ?
                    `<span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">${status.groups_count} مجموعة</span>` : ''}
            </div>
        `;
        statusContainer.appendChild(statusElement);
    });

    // تحديث شريط التقدم العام
    const overallProgress = data.progress_percentage;
    progressBar.style.width = overallProgress + '%';
    progressPercentage.textContent = overallProgress + '%';

    document.getElementById('processing-progress-message').textContent =
        `جاري المعالجة الفائقة... (${completedCount}/${data.total_files})`;
}

// تحديث حالة المعالجة
function updateProcessingStatus(progress, message) {
    document.getElementById('processing-progress-bar').style.width = progress + '%';
    document.getElementById('processing-progress-percentage').textContent = progress + '%';
    document.getElementById('processing-progress-message').textContent = message;
}

// عرض نتائج المعالجة
function showProcessingResults(data) {
    document.getElementById('processing-progress-container').classList.add('hidden');
    document.getElementById('results-container').classList.remove('hidden');
    document.getElementById('upload-card').classList.add('hidden');

    document.getElementById('results-message').textContent =
        `تمت معالجة ${data.processed_files} ملف بنجاح`;
    document.getElementById('results-details').textContent =
        `تم إنشاء ${data.total_groups} مجموعة من ${data.total_pages} صفحة`;

    // إعداد زر التحميل
    document.getElementById('download-all-btn').onclick = () => {
        downloadResults();
    };
}

// تحميل النتائج
async function downloadResults() {
    showToast('جاري تحضير الملفات للتحميل...', 'info');

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '{{ route("uploads.download.results") }}';

    const tokenInput = document.createElement('input');
    tokenInput.type = 'hidden';
    tokenInput.name = '_token';
    tokenInput.value = '{{ csrf_token() }}';
    form.appendChild(tokenInput);

    currentUploadIds.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'upload_ids[]';
        input.value = id;
        form.appendChild(input);
    });

    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}

// عرض الخطأ
function showError(message) {
    document.getElementById('processing-progress-container').classList.add('hidden');
    document.getElementById('error-container').classList.remove('hidden');
    document.getElementById('upload-card').classList.add('hidden');
    document.getElementById('error-message').textContent = message;
}

// التنظيف
window.addEventListener('beforeunload', () => {
    if (processingInterval) {
        clearInterval(processingInterval);
    }
});
</script>

<style>
.animate-fade-in {
    animation: fadeIn 0.3s ease-in-out;
}

.animate-fade-out {
    animation: fadeOut 0.3s ease-in-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeOut {
    from { opacity: 1; transform: translateY(0); }
    to { opacity: 0; transform: translateY(-10px); }
}

#drop-zone {
    transition: all 0.3s ease;
}

#drop-zone:hover {
    border-color: #eab308;
    background-color: #fefce8;
}

#processing-progress-bar {
    transition: width 0.3s ease;
}
</style>
@endsection
