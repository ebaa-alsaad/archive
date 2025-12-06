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
        <div id="drag-drop-area" class="w-full h-64">
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
// انتظر تحميل جميع المكتبات
document.addEventListener('DOMContentLoaded', function() {
    // تأكد من تحميل Uppy قبل الاستخدام
    if (typeof Uppy === 'undefined') {
        showToast('جاري تحميل نظام الرفع...', 'info');
        // حاول إعادة المحاولة بعد 500ms
        setTimeout(initializeUppy, 500);
    } else {
        initializeUppy();
    }
});

// متغيرات عامة
let uppyInstance = null;
let currentUploadId = null;
let statusInterval = null;

function initializeUppy() {
    try {
        // تأكد من وجود Uppy
        if (typeof Uppy === 'undefined') {
            showToast('لم يتم تحميل مكتبة الرفع. يرجى تحديث الصفحة.', 'error');
            return;
        }

        // إنشاء نسخة Uppy
        uppyInstance = new Uppy.Core({
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
        uppyInstance.use(Uppy.Dashboard, {
            inline: true,
            target: '#drag-drop-area',
            height: 250,
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
        uppyInstance.use(Uppy.Tus, {
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

        // الأحداث
        uppyInstance.on('file-added', handleFileAdded);
        uppyInstance.on('file-removed', handleFileRemoved);
        uppyInstance.on('upload-progress', handleUploadProgress);
        uppyInstance.on('upload-success', handleUploadSuccess);
        uppyInstance.on('upload-error', handleUploadError);

        // تعيين للوصول العالمي
        window.uppy = uppyInstance;
        
        showToast('نظام الرفع جاهز', 'success');

    } catch (error) {
        console.error('Error initializing Uppy:', error);
        showToast('خطأ في تحميل نظام الرفع: ' + error.message, 'error');
    }
}

// معالج الأحداث
function handleFileAdded(file) {
    console.log('File added:', file.name);
    updateFileInfo(file);
    document.getElementById('start-archiving').disabled = false;
}

function handleFileRemoved(file) {
    console.log('File removed:', file.name);
    resetUploadUI();
}

function handleUploadProgress(file, progress) {
    document.getElementById('upload-progress-container').classList.remove('hidden');
    const percentage = Math.round(progress.bytesUploaded / progress.bytesTotal * 100);
    document.getElementById('upload-progress-bar').style.width = percentage + '%';
    document.getElementById('upload-progress-percentage').textContent = percentage + '%';
}

async function handleUploadSuccess(file, response) {
    console.log('Upload success:', response);
    showToast('تم الرفع بنجاح – جاري المعالجة...', 'success');
    document.getElementById('upload-progress-container').classList.add('hidden');
    
    // استخراج upload_id من الاستجابة
    let uploadId = null;
    if (response.body && response.body.upload_id) {
        uploadId = response.body.upload_id;
    } else if (response.uploadURL) {
        // استخراج من URL
        const matches = response.uploadURL.match(/uploads\/([^\/]+)/);
        if (matches) uploadId = matches[1];
    }
    
    if (uploadId) {
        currentUploadId = uploadId;
        await startProcessing();
    } else {
        showToast('لم يتم الحصول على معرف الرفع', 'error');
    }
}

function handleUploadError(file, error) {
    console.error('Upload error:', error);
    showToast('فشل الرفع: ' + (error.message || 'خطأ غير معروف'), 'error');
    resetUploadUI();
}

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
    
    const container = document.getElementById('toast-container');
    if (container) {
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.add('animate-fade-out');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function updateFileInfo(file) {
    const infoFilename = document.getElementById('info-filename');
    const infoFilesize = document.getElementById('info-filesize');
    const fileInfo = document.getElementById('file-info');
    
    if (infoFilename) infoFilename.textContent = file.name;
    if (infoFilesize) infoFilesize.textContent = formatFileSize(file.size);
    if (fileInfo) fileInfo.classList.remove('hidden');
}

function resetUploadUI() {
    if (uppyInstance) {
        uppyInstance.reset();
    }
    
    const archiveButton = document.getElementById('start-archiving');
    if (archiveButton) {
        archiveButton.disabled = true;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
    }
    
    const elementsToHide = [
        'file-info',
        'upload-progress-container',
        'processing-progress-container',
        'results-container',
        'error-container'
    ];
    
    elementsToHide.forEach(id => {
        const element = document.getElementById(id);
        if (element) element.classList.add('hidden');
    });
    
    currentUploadId = null;
    if (statusInterval) {
        clearInterval(statusInterval);
        statusInterval = null;
    }
}

// --- بدء الرفع ---
document.getElementById('start-archiving')?.addEventListener('click', async function() {
    if (!uppyInstance || uppyInstance.getFiles().length === 0) {
        showToast('الرجاء اختيار ملف أولاً', 'warning');
        return;
    }

    const button = this;
    try {
        button.disabled = true;
        button.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري الرفع...';
        
        // بدء الرفع
        await uppyInstance.upload();
        
    } catch (error) {
        console.error('Upload start error:', error);
        showToast('فشل بدء الرفع: ' + error.message, 'error');
        button.disabled = false;
        button.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
    }
});

// --- بدء المعالجة ---
async function startProcessing() {
    if (!currentUploadId) {
        showToast('خطأ: لم يتم الحصول على معرّف الرفع', 'error');
        return;
    }

    const processingContainer = document.getElementById('processing-progress-container');
    if (processingContainer) {
        processingContainer.classList.remove('hidden');
    }
    
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
            
            if (progressBar && data.progress !== undefined) {
                const progress = Math.min(Math.max(data.progress, 0), 100);
                progressBar.style.width = progress + '%';
                if (progressPercent) progressPercent.textContent = progress + '%';
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
    const processingContainer = document.getElementById('processing-progress-container');
    const resultsContainer = document.getElementById('results-container');
    
    if (processingContainer) processingContainer.classList.add('hidden');
    if (resultsContainer) {
        resultsContainer.classList.remove('hidden');
        
        const groupsCount = data.groups_count || 0;
        const totalPages = data.total_pages || 0;
        
        const resultsMessage = document.getElementById('results-message');
        const resultsDetails = document.getElementById('results-details');
        
        if (resultsMessage) resultsMessage.textContent = `تم إنشاء ${groupsCount} مجموعة بنجاح`;
        if (resultsDetails) resultsDetails.textContent = `من أصل ${totalPages} صفحة`;
    }

    // إعادة تعيين زر البدء
    const archiveButton = document.getElementById('start-archiving');
    if (archiveButton) {
        archiveButton.disabled = true;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
    }
}

function showError(message) {
    const processingContainer = document.getElementById('processing-progress-container');
    const errorContainer = document.getElementById('error-container');
    
    if (processingContainer) processingContainer.classList.add('hidden');
    if (errorContainer) {
        errorContainer.classList.remove('hidden');
        const errorMessage = document.getElementById('error-message');
        if (errorMessage) errorMessage.textContent = message;
    }
    
    // إعادة تعيين زر البدء
    const archiveButton = document.getElementById('start-archiving');
    if (archiveButton) {
        archiveButton.disabled = false;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
    }
}

// --- تنظيف عند الخروج ---
window.addEventListener('beforeunload', () => {
    if (statusInterval) clearInterval(statusInterval);
});
</script>