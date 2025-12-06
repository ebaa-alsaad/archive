@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto space-y-8">

    <h2 class="text-4xl font-extrabold text-gray-800 text-center">
        <i class="fa-solid fa-cloud-arrow-up text-blue-600 ml-3"></i> رفع ملف PDF جديد
    </h2>

    <div id="toast-container" class="fixed top-5 right-5 z-[100] space-y-2 max-w-sm"></div>

    <!-- حالة الرفع الأساسية -->
    <div id="upload-card" class="bg-white p-8 rounded-2xl shadow-xl border border-gray-100">
        <!-- منطقة الرفع الرئيسية -->
        <div id="drag-drop-area" class="w-full">
            <!-- سوف يتم إضافة Uppy Dashboard هنا -->
        </div>

        <!-- معلومات إضافية -->
        <div id="file-info" class="hidden bg-blue-50 p-4 rounded-lg border border-blue-200 mt-4">
            <div class="flex items-center justify-between">
                <div>
                    <h4 class="font-semibold text-blue-800" id="info-filename"></h4>
                    <p class="text-sm text-blue-600" id="info-filesize"></p>
                </div>
                <button type="button" onclick="resetUploadUI()" class="text-red-500 hover:text-red-700 p-2">
                    <i class="fa-solid fa-times"></i> إزالة
                </button>
            </div>
        </div>

        <!-- تقدم الرفع -->
        <div id="upload-progress-container" class="space-y-3 hidden mt-4">
            <div class="flex justify-between items-center text-sm font-semibold text-gray-700">
                <span id="upload-progress-message">جاري رفع الملف...</span>
                <span id="upload-progress-percentage">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
                <div id="upload-progress-bar" class="bg-blue-500 h-2.5 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
            </div>
        </div>

        <!-- تقدم المعالجة -->
        <div id="processing-progress-container" class="space-y-3 hidden mt-4">
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

        <button type="button" id="start-archiving"
                disabled
                class="w-full bg-gradient-to-r from-blue-600 to-blue-500 text-white px-8 py-4 rounded-xl font-bold text-lg shadow-lg disabled:opacity-50 disabled:cursor-not-allowed hover:from-blue-700 hover:to-blue-600 transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98] mt-4">
            <i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة
        </button>
    </div>

    <!-- نتائج المعالجة -->
    <div id="results-container" class="hidden bg-green-50 p-6 rounded-2xl border border-green-200 mt-4">
        <div class="text-center">
            <i class="fa-solid fa-circle-check text-green-500 text-4xl mb-3"></i>
            <h3 class="text-xl font-bold text-green-800 mb-2">تمت المعالجة بنجاح!</h3>
            <p class="text-green-600 mb-2" id="results-message"></p>
            <p class="text-green-500 text-sm mb-4" id="results-details"></p>
            <div class="flex gap-3 justify-center">
                <button onclick="resetUploadUI()" class="bg-green-500 text-white px-6 py-2 rounded-lg hover:bg-green-600 transition-colors">
                    <i class="fa-solid fa-plus ml-2"></i> رفع ملف جديد
                </button>
                <button onclick="window.location.href='{{ route('uploads.index') }}'" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600 transition-colors">
                    <i class="fa-solid fa-list ml-2"></i> عرض جميع الملفات
                </button>
            </div>
        </div>
    </div>

    <!-- حالة الخطأ -->
    <div id="error-container" class="hidden bg-red-50 p-6 rounded-2xl border border-red-200 mt-4">
        <div class="text-center">
            <i class="fa-solid fa-circle-exclamation text-red-500 text-4xl mb-3"></i>
            <h3 class="text-xl font-bold text-red-800 mb-2">فشلت المعالجة</h3>
            <p class="text-red-600 mb-4" id="error-message"></p>
            <button onclick="resetUploadUI()" class="bg-red-500 text-white px-6 py-2 rounded-lg hover:bg-red-600 transition-colors">
                <i class="fa-solid fa-rotate-left ml-2"></i> المحاولة مرة أخرى
            </button>
        </div>
    </div>
</div>

<script>
// انتظر حتى تحميل المكتبات
document.addEventListener('DOMContentLoaded', function() {
    initializeUppy();
});

function initializeUppy() {
    try {
        // إعداد Uppy
        const uppy = new Uppy.Core({
            autoProceed: false,
            restrictions: {
                maxFileSize: 250 * 1024 * 1024, // 250MB
                allowedFileTypes: ['.pdf', 'application/pdf'],
                maxNumberOfFiles: 1
            },
            locale: {
                strings: {
                    chooseFiles: 'اختر ملف',
                    orDragDrop: 'أو اسحب وأفلت هنا',
                    dropHereOr: 'أفلت الملف هنا أو %{browse}',
                    browse: 'تصفح'
                }
            }
        });

        // إضافة Dashboard
        uppy.use(Uppy.Dashboard, {
            inline: true,
            target: '#drag-drop-area',
            height: 300,
            showLinkToFileUploadResult: false,
            proudlyDisplayPoweredByUppy: false,
            showProgressDetails: true,
            hideUploadButton: true,
            hideCancelButton: false,
            note: 'ملفات PDF فقط • الحد الأقصى 250MB • ملف واحد فقط',
            locale: {
                strings: {
                    dropPasteFiles: 'اسحب ملف PDF هنا أو %{browse}',
                    browse: 'تصفح'
                }
            }
        });

        // إضافة Tus للرفع
        uppy.use(Uppy.Tus, {
            endpoint: '/uploads/chunk',
            chunkSize: 5 * 1024 * 1024,
            retryDelays: [0, 1000, 3000, 5000],
            meta: {
                user_id: '{{ auth()->id() }}'
            },
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            }
        });

        // عند إضافة ملف
        uppy.on('file-added', (file) => {
            console.log('File added:', file.name);
            updateFileInfo(file);
            document.getElementById('start-archiving').disabled = false;
        });

        // عند إزالة ملف
        uppy.on('file-removed', () => {
            console.log('File removed');
            resetUploadUI();
        });

        // تقدم الرفع
        uppy.on('upload-progress', (file, progress) => {
            console.log('Upload progress:', progress);
            document.getElementById('upload-progress-container').classList.remove('hidden');
            const percentage = Math.round(progress.bytesUploaded / progress.bytesTotal * 100);
            document.getElementById('upload-progress-bar').style.width = percentage + '%';
            document.getElementById('upload-progress-percentage').textContent = percentage + '%';
        });

        // نجاح الرفع
        uppy.on('upload-success', async (file, response) => {
            console.log('Upload success:', response);
            showToast('تم الرفع بنجاح – جاري المعالجة...', 'success');
            document.getElementById('upload-progress-container').classList.add('hidden');
            
            currentUploadId = response.body?.upload_id || response.uploadURL?.split('/').pop();
            if (currentUploadId) {
                await startProcessing();
            }
        });

        // خطأ في الرفع
        uppy.on('upload-error', (file, error) => {
            console.error('Upload error:', error);
            showToast('فشل الرفع: ' + (error.message || 'خطأ غير معروف'), 'error');
            resetUploadUI();
        });

        // تعيين Uppy كمتغير عام للوصول من الدوال الأخرى
        window.uppyInstance = uppy;

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        showToast('خطأ في تحميل نظام الرفع', 'error');
    }
}

// --- متغيرات عالمية ---
let currentUploadId = null;
let statusInterval = null;
const archiveButton = document.getElementById('start-archiving');
const fileInfo = document.getElementById('file-info');
const infoFilename = document.getElementById('info-filename');
const infoFilesize = document.getElementById('info-filesize');
const toastContainer = document.getElementById('toast-container');

// --- دوال مساعدة ---
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
    toast.innerHTML = `<i class="fa-solid ${icons[type]}"></i><span>${message}</span>`;
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

function updateFileInfo(file) {
    infoFilename.textContent = file.name;
    infoFilesize.textContent = formatFileSize(file.size);
    fileInfo.classList.remove('hidden');
}

function resetUploadUI() {
    if (window.uppyInstance) {
        window.uppyInstance.reset();
    }
    archiveButton.disabled = true;
    fileInfo.classList.add('hidden');
    document.getElementById('upload-progress-container').classList.add('hidden');
    document.getElementById('processing-progress-container').classList.add('hidden');
    document.getElementById('results-container').classList.add('hidden');
    document.getElementById('error-container').classList.add('hidden');
    currentUploadId = null;
    if (statusInterval) {
        clearInterval(statusInterval);
        statusInterval = null;
    }
}

// --- بدء الرفع ---
archiveButton.addEventListener('click', async function() {
    if (!window.uppyInstance || window.uppyInstance.getFiles().length === 0) {
        showToast('الرجاء اختيار ملف أولاً', 'warning');
        return;
    }

    try {
        archiveButton.disabled = true;
        archiveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري الرفع...';
        
        // بدء الرفع
        const uploadResult = await window.uppyInstance.upload();
        console.log('Upload started:', uploadResult);
        
    } catch (error) {
        console.error('Upload start error:', error);
        showToast('فشل بدء الرفع: ' + error.message, 'error');
        archiveButton.disabled = false;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
    }
});

// --- بدء المعالجة ---
async function startProcessing() {
    if (!currentUploadId) {
        showToast('خطأ: لم يتم الحصول على معرّف الرفع', 'error');
        return;
    }

    document.getElementById('processing-progress-container').classList.remove('hidden');
    
    try {
        const processResponse = await fetch(`/uploads/${currentUploadId}/process`, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        });

        const processData = await processResponse.json();
        
        if (processData.success) {
            showToast('تم بدء المعالجة بنجاح!', 'success');
            startStatusChecking();
        } else {
            throw new Error(processData.error || 'فشلت المعالجة');
        }
    } catch (err) {
        console.error('Processing error:', err);
        showError(err.message);
    }
}

// --- تتبع الحالة ---
async function startStatusChecking() {
    if (!currentUploadId) return;
    
    if (statusInterval) clearInterval(statusInterval);
    
    statusInterval = setInterval(async () => {
        try {
            const response = await fetch(`/uploads/${currentUploadId}/status`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.error || 'خطأ في التحقق من الحالة');
            }

            // تحديث شريط التقدم
            const progressBar = document.getElementById('processing-progress-bar');
            const progressPercent = document.getElementById('processing-progress-percentage');
            const progressMsg = document.getElementById('processing-progress-message');
            const details = document.getElementById('processing-details');
            
            if (data.progress !== undefined) {
                const progress = Math.min(Math.max(data.progress, 0), 100);
                progressBar.style.width = progress + '%';
                progressPercent.textContent = progress + '%';
            }
            
            if (progressMsg) progressMsg.textContent = data.message || 'جاري المعالجة...';
            if (details) details.textContent = data.details || 'قد تستغرق العملية عدة دقائق...';

            // معالجة الحالات النهائية
            if (data.status === 'completed') {
                clearInterval(statusInterval);
                showResults(data);
            } else if (data.status === 'failed') {
                clearInterval(statusInterval);
                showError(data.message || 'فشلت المعالجة');
            }
        } catch (error) {
            console.error('Status check error:', error);
        }
    }, 2000);
}

// --- عرض النتائج ---
function showResults(data) {
    document.getElementById('processing-progress-container').classList.add('hidden');
    document.getElementById('results-container').classList.remove('hidden');

    const groupsCount = data.groups_count || 0;
    const totalPages = data.total_pages || 0;
    document.getElementById('results-message').textContent = `تم إنشاء ${groupsCount} مجموعة بنجاح`;
    document.getElementById('results-details').textContent = `من أصل ${totalPages} صفحة`;

    // إعادة تعيين زر البدء
    archiveButton.disabled = true;
    archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
}

function showError(message) {
    document.getElementById('processing-progress-container').classList.add('hidden');
    document.getElementById('error-container').classList.remove('hidden');
    document.getElementById('error-message').textContent = message;
    
    // إعادة تعيين زر البدء
    archiveButton.disabled = false;
    archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
}

// --- تنظيف عند الخروج ---
window.addEventListener('beforeunload', () => {
    if (statusInterval) clearInterval(statusInterval);
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

/* تأكيد ظهور Uppy */
.uppy-Dashboard-inner {
    border: 3px dashed #d1d5db !important;
    border-radius: 1rem !important;
    background-color: white !important;
}

.uppy-Dashboard-inner:hover {
    border-color: #3b82f6 !important;
    background-color: #f8fafc !important;
}

.uppy-Dashboard-browse {
    color: #3b82f6 !important;
    font-weight: bold !important;
}

.uppy-Dashboard-AddFiles-title {
    font-family: 'Cairo', sans-serif !important;
    font-size: 1.25rem !important;
}
</style>
@endsection