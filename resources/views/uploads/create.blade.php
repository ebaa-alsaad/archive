@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-8">

    <h2 class="text-4xl font-extrabold text-gray-800 text-center">
        <i class="fa-solid fa-cloud-arrow-up text-blue-600 ml-3"></i> رفع ملف PDF جديد
    </h2>

    <div id="toast-container" class="fixed top-5 right-5 z-[100] space-y-2 max-w-sm"></div>

    <!-- حالة الرفع الأساسية -->
    <div id="upload-card" class="bg-white p-8 rounded-2xl shadow-xl border border-gray-100">
        <form id="upload-form" class="space-y-6" enctype="multipart/form-data">
            @csrf
            <div class="mb-6" x-data="{ isDragging: false }">
                <input id="file-input" type="file" name="pdf_file" accept="application/pdf" class="hidden" required/>
                <label for="file-input">
                    <div id="drop-zone"
                         @dragover.prevent="isDragging = true"
                         @dragleave.prevent="isDragging = false"
                         @drop.prevent="isDragging = false; handleDrop($event)"
                         :class="isDragging ?
                             'border-blue-500 bg-blue-50 border-4 scale-[1.02]' :
                             'border-gray-300 bg-gray-50 hover:bg-gray-100 border-3'"
                         class="flex flex-col items-center justify-center w-full h-64 border-dashed rounded-2xl cursor-pointer transition-all duration-300 ease-in-out">

                        <div class="flex flex-col items-center justify-center text-center px-6">
                            <i id="upload-icon"
                               :class="isDragging ? 'text-blue-600 scale-110' : 'text-blue-500'"
                               class="fa-solid fa-file-arrow-up text-6xl mb-4 transition-all duration-300"></i>
                            <p class="mb-2 text-xl text-gray-700 font-bold">
                                <span class="text-blue-700">اسحب وأفلت ملف PDF</span> أو انقر للتحميل
                            </p>
                            <p class="text-sm text-gray-500 mb-2">ملفات PDF فقط</p>
                            <p class="text-xs text-gray-400">الحد الأقصى 200MB • المعالجة تستغرق عدة دقائق</p>
                            <p id="file-name" class="mt-4 text-base text-gray-800 font-semibold max-w-md truncate hidden"></p>
                            <p id="file-size" class="text-sm text-gray-600 hidden"></p>
                        </div>
                    </div>
                </label>
            </div>

            <!-- معلومات إضافية -->
            <div id="file-info" class="hidden bg-blue-50 p-4 rounded-lg border border-blue-200">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="font-semibold text-blue-800" id="info-filename"></h4>
                        <p class="text-sm text-blue-600" id="info-filesize"></p>
                        <p class="text-xs text-blue-500" id="info-pages"></p>
                    </div>
                    <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700 p-2">
                        <i class="fa-solid fa-times"></i> إزالة
                    </button>
                </div>
            </div>

            <!-- تقدم الرفع -->
            <div id="upload-progress-container" class="space-y-3 hidden">
                <div class="flex justify-between items-center text-sm font-semibold text-gray-700">
                    <span id="upload-progress-message">جاري رفع الملف...</span>
                    <span id="upload-progress-percentage">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div id="upload-progress-bar" class="bg-blue-500 h-2.5 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                </div>
            </div>

            <!-- تقدم المعالجة -->
            <div id="processing-progress-container" class="space-y-3 hidden">
                <div class="flex justify-between items-center text-sm font-semibold text-gray-700">
                    <span id="processing-progress-message">جاري معالجة الملف...</span>
                    <span id="processing-progress-percentage">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5">
                    <div id="processing-progress-bar" class="bg-green-500 h-2.5 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                </div>
                <div class="text-xs text-gray-500 text-center" id="processing-details">
                    تجهيز الصفحات واستخراج المعلومات...
                </div>
            </div>

            <button type="submit" id="start-archiving"
                    disabled
                    class="w-full bg-gradient-to-r from-blue-600 to-blue-500 text-white px-8 py-4 rounded-xl font-bold text-lg shadow-lg disabled:opacity-50 disabled:cursor-not-allowed hover:from-blue-700 hover:to-blue-600 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98]">
                <i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة
            </button>

            <!-- معلومات المساعدة -->
            <div class="text-center text-sm text-gray-500 mt-4">
                <p>⏱️ قد تستغرق العملية من 2-10 دقائق حسب حجم الملف</p>
            </div>
        </form>
    </div>

    <!-- نتائج المعالجة -->
    <div id="results-container" class="hidden bg-green-50 p-6 rounded-2xl border border-green-200">
        <div class="text-center">
            <i class="fa-solid fa-circle-check text-green-500 text-4xl mb-3"></i>
            <h3 class="text-xl font-bold text-green-800 mb-2">تمت المعالجة بنجاح!</h3>
            <p class="text-green-600 mb-2" id="results-message"></p>
            <p class="text-green-500 text-sm mb-4" id="results-details"></p>
            <div class="flex gap-3 justify-center">
                <button onclick="resetToUpload()" class="bg-green-500 text-white px-6 py-2 rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fa-solid fa-plus ml-2"></i> رفع ملف جديد
                </button>
                <button onclick="viewUploads()" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fa-solid fa-list ml-2"></i> عرض جميع الملفات
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
const fileInput = document.getElementById('file-input');
const archiveButton = document.getElementById('start-archiving');
const fileNameDisplay = document.getElementById('file-name');
const fileSizeDisplay = document.getElementById('file-size');
const dropZone = document.getElementById('drop-zone');
const fileInfo = document.getElementById('file-info');
const infoFilename = document.getElementById('info-filename');
const infoFilesize = document.getElementById('info-filesize');
const infoPages = document.getElementById('info-pages');

// عناصر التقدم
const uploadProgressContainer = document.getElementById('upload-progress-container');
const uploadProgressBar = document.getElementById('upload-progress-bar');
const uploadProgressPercentage = document.getElementById('upload-progress-percentage');
const uploadProgressMessage = document.getElementById('upload-progress-message');

const processingProgressContainer = document.getElementById('processing-progress-container');
const processingProgressBar = document.getElementById('processing-progress-bar');
const processingProgressPercentage = document.getElementById('processing-progress-percentage');
const processingProgressMessage = document.getElementById('processing-progress-message');
const processingDetails = document.getElementById('processing-details');

const resultsContainer = document.getElementById('results-container');
const resultsMessage = document.getElementById('results-message');
const resultsDetails = document.getElementById('results-details');

const errorContainer = document.getElementById('error-container');
const errorMessage = document.getElementById('error-message');

const toastContainer = document.getElementById('toast-container');

let currentUploadId = null;
let pollInterval = null;

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

    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.classList.add('animate-fade-out');
        setTimeout(() => toast.remove(), 300);
    }, 5000);
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function updateFileInput(files) {
    if (files && files.length > 0) {
        const file = files[0];
        const fileSizeMB = file.size / 1024 / 1024;

        if (file.type !== 'application/pdf') {
            showToast('يجب أن يكون الملف من نوع PDF', 'error');
            resetFileInput();
            return;
        }

        if (fileSizeMB > 200) {
            showToast('حجم الملف يجب أن يكون أقل من 200MB', 'error');
            resetFileInput();
            return;
        }

        // عرض معلومات الملف
        fileNameDisplay.textContent = file.name;
        fileNameDisplay.classList.remove('hidden');

        fileSizeDisplay.textContent = formatFileSize(file.size);
        fileSizeDisplay.classList.remove('hidden');

        infoFilename.textContent = file.name;
        infoFilesize.textContent = formatFileSize(file.size);
        infoPages.textContent = 'جاري فحص عدد الصفحات...';
        fileInfo.classList.remove('hidden');

        archiveButton.disabled = false;

        showToast('تم اختيار الملف بنجاح', 'success');

        // فحص عدد الصفحات (اختياري)
        getPageCount(file).then(pages => {
            infoPages.textContent = `${pages} صفحة`;
        }).catch(() => {
            infoPages.textContent = 'غير معروف';
        });
    }
}

// دالة لفحص عدد الصفحات (اختيارية)
async function getPageCount(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const arr = new Uint8Array(e.target.result);
                const pdfText = new TextDecoder('utf-8').decode(arr);
                const pageMatch = pdfText.match(/\/Count\s+(\d+)/);
                if (pageMatch) {
                    resolve(parseInt(pageMatch[1]));
                } else {
                    resolve('?');
                }
            } catch (error) {
                resolve('?');
            }
        };
        reader.onerror = reject;
        reader.readAsArrayBuffer(file.slice(0, 5000)); // قراءة الجزء الأول فقط
    });
}

function resetFileInput() {
    fileInput.value = '';
    archiveButton.disabled = true;
    fileNameDisplay.classList.add('hidden');
    fileSizeDisplay.classList.add('hidden');
    fileInfo.classList.add('hidden');
}

function clearFile() {
    resetFileInput();
    showToast('تم إزالة الملف', 'info');
}

// 🔄 الدالة الرئيسية لإعادة التعيين
function resetToUpload() {
    // إعادة تعيين كل العناصر
    resetFileInput();
    uploadProgressContainer.classList.add('hidden');
    processingProgressContainer.classList.add('hidden');
    resultsContainer.classList.add('hidden');
    errorContainer.classList.add('hidden');

    // إعادة تعيين الزر
    archiveButton.disabled = true;
    archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';

    // تنظيف الـ polling
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }

    currentUploadId = null;

    // إظهار واجهة الرفع الأساسية
    document.getElementById('upload-card').classList.remove('hidden');

    showToast('يمكنك الآن رفع ملف جديد', 'info');
}

// أحداث الملف
fileInput.addEventListener('change', () => updateFileInput(fileInput.files));
dropZone.addEventListener('click', () => fileInput.click());

function handleDrop(e) {
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        fileInput.files = files;
        updateFileInput(files);
    }
}

// منع السلوك الافتراضي للسحب والإفلات
['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    dropZone.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

// إرسال النموذج
document.getElementById('upload-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    if (!fileInput.files.length) {
        showToast('يرجى اختيار ملف PDF أولاً', 'error');
        return;
    }

    // إعداد الواجهة
    archiveButton.disabled = true;
    archiveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري البدء...';
    resetProgress();

    const formData = new FormData(this);
    let response;

    try {
        // بدء رفع الملف
        uploadProgressContainer.classList.remove('hidden');
        uploadProgressMessage.textContent = 'جاري رفع الملف...';

        response = await fetch('{{ route("uploads.store") }}', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.message || 'حدث خطأ غير معروف');
        }

        currentUploadId = data.upload_id;
        showToast('تم رفع الملف بنجاح، جاري المعالجة...', 'success');

        // الانتقال إلى مرحلة المعالجة
        uploadProgressContainer.classList.add('hidden');
        processingProgressContainer.classList.remove('hidden');
        startProgressPolling();

    } catch (error) {
        console.error('Upload error:', error);
        showToast(error.message || 'فشل في رفع الملف', 'error');
        resetToUpload();
    }
});

function resetProgress() {
    uploadProgressBar.style.width = '0%';
    uploadProgressPercentage.textContent = '0%';
    processingProgressBar.style.width = '0%';
    processingProgressPercentage.textContent = '0%';
}

function startProgressPolling() {
    if (pollInterval) {
        clearInterval(pollInterval);
    }

    pollInterval = setInterval(async () => {
        if (!currentUploadId) return;

        try {
            const response = await fetch(`/uploads/progress/${currentUploadId}`);

            if (!response.ok) {
                throw new Error('Failed to fetch progress');
            }

            const data = await response.json();
            const progress = data.progress || 0;
            const status = data.status || 'processing';

            // تحديث شريط التقدم
            processingProgressBar.style.width = progress + '%';
            processingProgressPercentage.textContent = progress + '%';

            // تحديث الرسائل حسب المرحلة
            updateProgressMessages(progress, status);

            if (progress >= 100 || status === 'completed') {
                clearInterval(pollInterval);
                onProcessingComplete(data);
            } else if (status === 'failed') {
                clearInterval(pollInterval);
                onProcessingFailed(data);
            }

        } catch (error) {
            console.error('Progress polling error:', error);
        }
    }, 2000);
}

function updateProgressMessages(progress, status) {
    const messages = {
        0: 'جاري تهيئة الملف...',
        20: 'جاري فحص الصفحات...',
        40: 'جاري استخراج الباركود...',
        60: 'جاري تقسيم المجموعات...',
        80: 'جاري إنشاء الملفات...',
        100: 'جاري الانتهاء...'
    };

    const closestProgress = Object.keys(messages).reduce((prev, curr) => {
        return Math.abs(curr - progress) < Math.abs(prev - progress) ? curr : prev;
    });

    processingProgressMessage.textContent = messages[closestProgress] || 'جاري المعالجة...';

    if (progress < 30) {
        processingDetails.textContent = 'فحص هيكل الملف والصفحات...';
    } else if (progress < 60) {
        processingDetails.textContent = 'استخراج الباركود والمعلومات...';
    } else if (progress < 90) {
        processingDetails.textContent = 'تقسيم المستند وإنشاء المجموعات...';
    } else {
        processingDetails.textContent = 'المراحل النهائية...';
    }
}

function onProcessingComplete(data) {
    processingProgressBar.style.width = '100%';
    processingProgressPercentage.textContent = '100%';
    processingProgressMessage.textContent = 'تم الانتهاء!';

    showToast('تمت معالجة الملف بنجاح', 'success');

    // عرض النتائج
    setTimeout(() => {
        processingProgressContainer.classList.add('hidden');
        resultsContainer.classList.remove('hidden');

        const groupsCount = data.groups_count || 0;
        const totalPages = data.total_pages || 0;

        resultsMessage.textContent = `تم إنشاء ${groupsCount} مجموعة بنجاح`;
        resultsDetails.textContent = `من أصل ${totalPages} صفحة`;

        // إخفاء واجهة الرفع الأساسية
        document.getElementById('upload-card').classList.add('hidden');

    }, 1000);
}

function onProcessingFailed(data) {
    showToast(data.error_message || 'فشلت معالجة الملف', 'error');

    // عرض رسالة الخطأ
    errorMessage.textContent = data.error_message || 'حدث خطأ غير معروف أثناء المعالجة';
    processingProgressContainer.classList.add('hidden');
    errorContainer.classList.remove('hidden');
    document.getElementById('upload-card').classList.add('hidden');
}

// الانتقال لصفحة عرض الـ Uploads
function viewUploads() {
    window.location.href = '{{ route("uploads.index") }}'; // غير المسار حسب تسمية الراوت عندك
}

// إعدادات إضافية للـ Alpine.js
document.addEventListener('alpine:init', () => {
    Alpine.data('uploadForm', () => ({
        isDragging: false,

        handleDrop(e) {
            this.isDragging = false;
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateFileInput(files);
            }
        }
    }));
});

// التنظيف عند مغادرة الصفحة
window.addEventListener('beforeunload', () => {
    if (pollInterval) {
        clearInterval(pollInterval);
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
    border-color: #3b82f6;
    background-color: #f8fafc;
}
</style>
@endsection
