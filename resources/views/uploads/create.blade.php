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
// إرسال الفورم - الإصدار المحسن
// ==========================================================
let uploadController = null;

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
    archiveButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin ml-2"></i> جاري الرفع...';

    // إظهار شريط التقدم
    showProgress('جاري رفع الملف... 0%');

    // إنشاء AbortController
    uploadController = new AbortController();

    try {
        console.log('Starting upload for file:', file.name, 'Size:', fileSizeMB + 'MB');

        // محاكاة تقدم واقعية للرفع
        let uploadProgress = 0;
        const progressInterval = setInterval(() => {
            if (uploadProgress < 90) {
                uploadProgress += 2;

                // رسائل واقعية بناءً على مرحلة التقدم
                let message = 'جاري رفع الملف...';
                if (uploadProgress > 50) message = 'جاري إكمال الرفع...';
                if (uploadProgress > 80) message = 'جاري بدء المعالجة...';

                updateProgress(uploadProgress, `${message} ${uploadProgress}%`);
            }
        }, 400);

        // الرفع الفعلي
        const response = await fetch('{{ route('uploads.store') }}', {
            method: 'POST',
            body: formData,
            signal: uploadController.signal,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        });

        // أوقف محاكاة التقدم
        clearInterval(progressInterval);

        console.log('Upload response status:', response.status);

        // قراءة الـ response
        const responseText = await response.text();
        console.log('Response received');

        let data;
        try {
            data = JSON.parse(responseText);
        } catch (jsonError) {
            console.error('JSON parse failed:', jsonError);
            throw new Error('استجابة غير صحيحة من الخادم');
        }

        console.log('Upload response data:', data);

        if (!response.ok) {
            throw new Error(data.error || data.message || `خطأ في السيرفر: ${response.status}`);
        }

        if (data.success) {
            // الانتقال إلى 100% فوراً
            updateProgress(100, 'تمت المعالجة بنجاح!');
            showToast(data.message, 'success');

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
            }, 1500);

        } else {
            throw new Error(data.error || 'حدث خطأ غير معروف أثناء المعالجة');
        }

    } catch (error) {
        console.error('Upload error:', error);

        let errorMessage = 'حدث خطأ أثناء الرفع';
        if (error.name === 'AbortError') {
            errorMessage = 'تم إلغاء عملية الرفع';
        } else if (error.message.includes('Failed to fetch')) {
            errorMessage = 'فشل في الاتصال بالخادم. تأكد من اتصال الإنترنت.';
        } else if (error.message.includes('network')) {
            errorMessage = 'مشكلة في الشبكة. حاول مرة أخرى.';
        } else {
            errorMessage = error.message;
        }

        showToast(errorMessage, 'error');
        updateProgress(0, 'فشل في الرفع');

    } finally {
        // إعادة تعيين الواجهة
        archiveButton.disabled = false;
        archiveButton.innerHTML = '<i class="fa-solid fa-paper-plane ml-2"></i> بدء عملية الأرشفة';
        uploadController = null;
        setTimeout(hideProgress, 3000);
    }
});

// إمكانية إلغاء الرفع
document.getElementById('cancel-upload')?.addEventListener('click', function() {
    if (uploadController) {
        uploadController.abort();
        showToast('تم إلغاء عملية الرفع', 'info');
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
