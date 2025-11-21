@extends('layouts.app')

@section('content')
<div class="space-y-10 max-w-3xl mx-auto">

    <h2 class="text-4xl font-extrabold text-gray-800 pb-2 text-center">
        <i class="fa-solid fa-cloud-arrow-up text-blue-600 ml-3"></i> رفع ملف PDF جديد للأرشفة
    </h2>

    {{-- Toast الرسائل --}}
    <div id="toast-container" class="fixed top-5 right-5 z-[100] space-y-2"></div>

    {{-- بطاقة رفع الملفات الرئيسية --}}
    <div id="upload-card" class="bg-white p-10 rounded-[30px] shadow-3xl shadow-blue-200/50 border border-gray-100 transition duration-500">

        <form id="upload-form" class="space-y-8">
            @csrf

            {{-- حقل اختيار الملف (منطقة السحب والإفلات) --}}
            <div class="mb-6" x-data="{ isDragging: false }">
                <input id="file-input" type="file" name="pdf_file" accept="application/pdf" class="hidden" required/>

                <div id="drop-zone"
                     @dragover.prevent="isDragging = true"
                     @dragleave.prevent="isDragging = false"
                     @drop.prevent="isDragging = false; handleDrop($event)"
                     class="flex flex-col items-center justify-center w-full h-56 border-4 border-dashed rounded-3xl cursor-pointer transition duration-300"
                     :class="isDragging ? 'border-blue-500 bg-blue-50/70 shadow-inner' : 'border-gray-300 bg-gray-50 hover:bg-gray-100'">

                    <div class="flex flex-col items-center justify-center pt-5 pb-6 text-center px-4">
                        <i id="upload-icon" class="fa-solid fa-file-arrow-up text-5xl text-blue-500 mb-4 transition duration-300"></i>
                        <p class="mb-2 text-lg text-gray-600 font-semibold">
                            <span class="font-extrabold text-blue-700">اسحب وأفلت ملف PDF</span> أو انقر للتحميل
                        </p>
                        <p class="text-sm text-gray-400">ملفات PDF فقط | الحد الأقصى 100MB</p>

                        {{-- عرض اسم الملف المختار --}}
                        <p id="file-name" class="mt-4 text-base text-gray-700 font-bold max-w-sm truncate hidden"></p>
                    </div>
                </div>
            </div>

            {{-- شريط التقدم (Progress Bar) --}}
            <div id="progress-container" class="space-y-3 hidden">
                <div class="flex justify-between items-center text-sm font-semibold text-gray-700">
                    <span id="progress-message">جاري الرفع والمعالجة...</span>
                    <span id="progress-percentage">0%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-3">
                    <div id="progress-bar" class="bg-blue-600 h-3 rounded-full transition-all duration-300 ease-out" style="width: 0%"></div>
                </div>
                <div class="text-center">
                    <button id="cancel-upload" type="button" class="text-red-600 text-sm font-medium hover:text-red-700 hidden">
                        <i class="fa-solid fa-xmark ml-1"></i> إلغاء العملية
                    </button>
                </div>
            </div>

            {{-- زر الأرشفة --}}
            <button type="submit" id="start-archiving"
                    disabled
                    class="w-full bg-gradient-to-r from-blue-600 to-blue-500 text-white px-6 py-4 rounded-2xl font-extrabold text-xl shadow-2xl shadow-blue-500/60 transition duration-300 disabled:opacity-50 disabled:cursor-not-allowed hover:from-blue-700 hover:to-blue-600 transform hover:scale-[1.005]">
                <i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة
            </button>

        </form>
    </div>

</div>

<script>
// ==========================================================
// الدوال الأساسية
// ==========================================================
function showToast(message, type = 'info') {
    const toastContainer = document.getElementById('toast-container');
    const colors = {
        success: { bg: 'bg-green-600', border: 'border-green-700', text: 'text-white', icon: 'fa-circle-check' },
        error: { bg: 'bg-red-600', border: 'border-red-700', text: 'text-white', icon: 'fa-circle-xmark' },
        info: { bg: 'bg-blue-600', border: 'border-blue-700', text: 'text-white', icon: 'fa-info-circle' }
    };

    const c = colors[type];
    const toast = document.createElement('div');
    toast.className = `min-w-[300px] border-l-8 ${c.border} p-4 rounded-xl shadow-2xl ${c.bg} ${c.text} animate-fade-in transition duration-500 ease-out transform`;

    toast.innerHTML = `
        <div class="flex items-center space-x-3 rtl:space-x-reverse">
            <i class="fa-solid ${c.icon} text-xl flex-shrink-0"></i>
            <span class="font-semibold text-sm">${message}</span>
        </div>
    `;

    toastContainer.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}

function updateProgress(percentage, message = '') {
    const progressBar = document.getElementById('progress-bar');
    const progressPercentage = document.getElementById('progress-percentage');
    const progressMessage = document.getElementById('progress-message');

    if (progressBar) progressBar.style.width = percentage + '%';
    if (progressPercentage) progressPercentage.textContent = percentage + '%';

    if (message && progressMessage) {
        progressMessage.textContent = message;
    }
}

function showProgress(message = 'جاري الرفع والمعالجة...') {
    const progressContainer = document.getElementById('progress-container');
    const progressMessage = document.getElementById('progress-message');

    if (progressMessage) progressMessage.textContent = message;
    if (progressContainer) progressContainer.classList.remove('hidden');
    updateProgress(0, message);
}

function hideProgress() {
    const progressContainer = document.getElementById('progress-container');
    if (progressContainer) {
        setTimeout(() => {
            progressContainer.classList.add('hidden');
            updateProgress(0, '');
        }, 1000);
    }
}

// ==========================================================
// تحليل الأخطاء
// ==========================================================
function analyzeError(error) {
    if (error.name === 'AbortError') {
        return { type: 'abort', message: 'تم إلغاء العملية' };
    }
    if (error instanceof TypeError) {
        return { type: 'network', message: 'خطأ في الاتصال بالخادم' };
    }
    if (error instanceof SyntaxError) {
        return { type: 'json', message: 'خطأ في معالجة البيانات' };
    }
    if (error.message?.includes('Failed to fetch')) {
        return { type: 'network', message: 'فشل في الاتصال بالخادم' };
    }
    if (error.message?.includes('Network request failed')) {
        return { type: 'network', message: 'فشل في الاتصال بالشبكة' };
    }
    if (error.message?.includes('استجابة غير متوقعة')) {
        return { type: 'server', message: 'مشكلة في استجابة الخادم' };
    }
    if (error.message?.includes('timeout')) {
        return { type: 'timeout', message: 'انتهت مهلة الاتصال' };
    }
    return { type: 'unknown', message: error.message || 'حدث خطأ غير معروف' };
}

// ==========================================================
// إدارة الملفات
// ==========================================================
const fileInput = document.getElementById('file-input');
const fileNameDisplay = document.getElementById('file-name');
const uploadIcon = document.getElementById('upload-icon');
const archiveButton = document.getElementById('start-archiving');
const dropZone = document.getElementById('drop-zone');

// 1. تحديث حالة الملف عند الاختيار
function updateFileInput(files) {
    console.log('updateFileInput called with:', files);

    if (files && files.length > 0) {
        const file = files[0];
        const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);

        console.log('File selected:', {
            name: file.name,
            type: file.type,
            size: file.size,
            sizeMB: fileSizeMB
        });

        // التحقق من نوع الملف
        if (file.type !== 'application/pdf') {
            showToast('الرجاء اختيار ملف بصيغة PDF فقط.', 'error');
            fileInput.value = '';
            if (fileNameDisplay) fileNameDisplay.classList.add('hidden');
            if (archiveButton) archiveButton.disabled = true;
            if (uploadIcon) uploadIcon.className = 'fa-solid fa-file-arrow-up text-5xl text-blue-500 mb-4 transition duration-300';
            return;
        }

        // التحقق من حجم الملف (100MB الآن)
        if (file.size > 100 * 1024 * 1024) {
            showToast(`حجم الملف كبير جداً (${fileSizeMB} MB). الحد الأقصى 100MB.`, 'error');
            fileInput.value = '';
            if (fileNameDisplay) fileNameDisplay.classList.add('hidden');
            if (archiveButton) archiveButton.disabled = true;
            return;
        }

        // عرض اسم الملف والحجم
        if (fileNameDisplay) {
            fileNameDisplay.textContent = `${file.name} (${fileSizeMB} MB)`;
            fileNameDisplay.classList.remove('hidden');
        }

        // تحديث الأيقونة
        if (uploadIcon) {
            uploadIcon.className = 'fa-solid fa-file-circle-check text-5xl text-green-500 mb-4 transition duration-300';
        }

        // تفعيل الزر
        if (archiveButton) {
            archiveButton.disabled = false;
        }

        console.log('File successfully selected and validated');

    } else {
        // لا يوجد ملف
        console.log('No file selected');
        if (fileNameDisplay) {
            fileNameDisplay.textContent = '';
            fileNameDisplay.classList.add('hidden');
        }
        if (archiveButton) {
            archiveButton.disabled = true;
        }
        if (uploadIcon) {
            uploadIcon.className = 'fa-solid fa-file-arrow-up text-5xl text-blue-500 mb-4 transition duration-300';
        }
    }
}

// 2. استماع لتغيير الملف
fileInput.addEventListener('change', function () {
    console.log('File input changed');
    updateFileInput(this.files);
});

// 3. دالة السحب والإفلات
function handleDrop(e) {
    console.log('File dropped');
    if (e.dataTransfer.files.length) {
        fileInput.files = e.dataTransfer.files;
        updateFileInput(fileInput.files);
    }
}

// 4. جعل منطقة الرفع قابلة للنقر
dropZone.addEventListener('click', function(e) {
    console.log('Drop zone clicked - opening file dialog');
    fileInput.click();
});

// ==========================================================
// اختبار اتصال الخادم
// ==========================================================
async function checkServerConnection() {
    try {
        const testResponse = await fetch('{{ route('uploads.store') }}', {
            method: 'HEAD',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        return testResponse.ok;
    } catch (error) {
        console.error('Server connection test failed:', error);
        return false;
    }
}

// ==========================================================
// إرسال الفورم - الإصدار المحسن للوقت الطويل
// ==========================================================
let uploadController = null;
let uploadTimeout = null;
let progressInterval = null;

document.getElementById('upload-form').addEventListener('submit', async function (e) {
    e.preventDefault();

    if (!fileInput.files.length) {
        showToast('يجب رفع ملف قبل بدء الأرشفة', 'error');
        return;
    }

    const form = e.target;
    const formData = new FormData(form);
    const file = fileInput.files[0];
    const fileSizeMB = (file.size / 1024 / 1024).toFixed(2);

    // إعداد حالة الرفع
    archiveButton.disabled = true;
    archiveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري المعالجة...';

    // إظهار شريط التقدم
    showProgress('جاري رفع الملف...');

    // إنشاء AbortController
    uploadController = new AbortController();

    try {
        console.log('Starting upload...', {
            file: file.name,
            size: file.size,
            type: file.type,
            sizeMB: fileSizeMB
        });

        // محاكاة تقدم الرفع (أبطأ وأكثر واقعية)
        let uploadProgress = 0;
        progressInterval = setInterval(() => {
            uploadProgress += 1; // أبطأ بكثير
            if (uploadProgress < 85) {
                const messages = [
                    'جاري رفع الملف...',
                    'جاري معالجة البيانات...',
                    'جاري تقسيم المستند...',
                    'جاري استخراج النصوص...'
                ];
                const randomMessage = messages[Math.floor(Math.random() * messages.length)];
                updateProgress(uploadProgress, `${randomMessage} ${uploadProgress}%`);
            }
        }, 800);

        // إعداد رسائل تطمين للوقت الطويل
        uploadTimeout = setTimeout(() => {
            showToast('المعالجة تستغرق وقتاً، الرجاء الانتظار...', 'info');
        }, 15000); // بعد 15 ثانية

        // الرفع الفعلي مع timeout طويل جداً (20 دقيقة)
        const fetchPromise = fetch('{{ route('uploads.store') }}', {
            method: 'POST',
            body: formData,
            signal: uploadController.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        });

        // إضافة timeout منفصل للـ fetch
        const timeoutPromise = new Promise((_, reject) => {
            setTimeout(() => reject(new Error('Request timeout - took too long')), 1200000); // 20 دقيقة
        });

        const response = await Promise.race([fetchPromise, timeoutPromise]);

        // تنظيف الـ intervals والـ timeouts
        if (progressInterval) clearInterval(progressInterval);
        if (uploadTimeout) clearTimeout(uploadTimeout);

        console.log('Upload response status:', response.status);

        // قراءة الـ response كـ text أولاً
        const responseText = await response.text();
        console.log('Raw response length:', responseText.length);
        console.log('Raw response preview:', responseText.substring(0, 200));

        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.warn('JSON parse failed, trying to extract JSON from response');

            // محاولة استخراج JSON من الـ response إذا كان مختلطاً مع نصوص أخرى
            const jsonMatch = responseText.match(/\{[\s\S]*\}/);
            if (jsonMatch) {
                try {
                    data = JSON.parse(jsonMatch[0]);
                    console.log('Successfully extracted JSON from response');
                } catch (extractError) {
                    console.error('Failed to extract JSON:', extractError);
                    // إذا فشل كل شيء ولكن الـ response ناجح، افترض النجاح
                    if (response.ok) {
                        data = {
                            success: true,
                            message: 'تمت معالجة الملف بنجاح',
                            redirect_url: '{{ route("uploads.index") }}'
                        };
                    } else {
                        throw new Error(`استجابة غير متوقعة من الخادم (${response.status})`);
                    }
                }
            } else if (response.ok) {
                // إذا كان الـ response ناجحاً ولكن ليس JSON، افترض النجاح
                data = {
                    success: true,
                    message: 'تمت معالجة الملف بنجاح',
                    redirect_url: '{{ route("uploads.index") }}'
                };
            } else {
                throw new Error(`استجابة غير متوقعة: ${response.status} ${response.statusText}`);
            }
        }

        console.log('Upload response data:', data);

        if (!response.ok) {
            throw new Error(data.error || data.message || `خطأ في السيرفر: ${response.status}`);
        }

        if (data.success) {
            updateProgress(100, 'تمت المعالجة بنجاح!');
            showToast(data.message || 'تمت معالجة الملف بنجاح', 'success');

            // إعادة تعيين الواجهة
            fileInput.value = '';
            updateFileInput([]);

            // الانتقال إلى صفحة العرض بعد تأخير قصير
            setTimeout(() => {
                if (data.redirect_url) {
                    window.location.href = data.redirect_url;
                } else if (data.upload_id) {
                    window.location.href = `/uploads/${data.upload_id}`;
                } else {
                    window.location.href = '{{ route("uploads.index") }}';
                }
            }, 2000);

        } else {
            throw new Error(data.error || 'حدث خطأ غير معروف أثناء المعالجة');
        }

    } catch (error) {
        console.error('Upload error details:', error);

        // تنظيف الـ intervals والـ timeouts
        if (progressInterval) clearInterval(progressInterval);
        if (uploadTimeout) clearTimeout(uploadTimeout);

        const errorAnalysis = analyzeError(error);

        // معالجة خاصة لأخطاء الوقت
        if (errorAnalysis.type === 'timeout') {
            showToast('المعالجة تستغرق وقتاً أطول من المتوقع. جاري التحقق من النتيجة...', 'info');
            // انتظر قليلاً ثم انتقل إلى صفحة الـ uploads
            setTimeout(() => {
                window.location.href = '{{ route("uploads.index") }}';
            }, 3000);
        } else {
            showToast(errorAnalysis.message, 'error');
            updateProgress(0, 'فشل في المعالجة');
        }

    } finally {
        // إعادة تعيين الواجهة
        archiveButton.disabled = false;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
        uploadController = null;

        // تنظيف نهائي
        if (progressInterval) clearInterval(progressInterval);
        if (uploadTimeout) clearTimeout(uploadTimeout);

        setTimeout(hideProgress, 3000);
    }
});

// إمكانية إلغاء الرفع
document.getElementById('cancel-upload')?.addEventListener('click', function() {
    if (uploadController) {
        uploadController.abort();
        showToast('تم إلغاء عملية الرفع', 'info');
    }
    if (progressInterval) clearInterval(progressInterval);
    if (uploadTimeout) clearTimeout(uploadTimeout);
});

// ==========================================================
// التهيئة الأولية
// ==========================================================
document.addEventListener('DOMContentLoaded', function() {
    console.log('Page loaded - file upload system ready');
    // التأكد من أن الزر معطل في البداية
    if (archiveButton) {
        archiveButton.disabled = true;
    }
});
</script>

<style>
@keyframes fade-in {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.animate-fade-in {
    animation: fade-in 0.3s ease-out;
}
.shadow-3xl {
    box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.1), 0 10px 20px -5px rgba(0, 0, 0, 0.05);
}

/* تحسين عرض اسم الملف */
#file-name {
    word-break: break-word;
    overflow-wrap: break-word;
    max-width: 100%;
}
</style>
@endsection
