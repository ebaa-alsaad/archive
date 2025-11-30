@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-6 bg-white shadow-lg rounded-lg">
    <h2 class="text-3xl font-bold mb-6 text-gray-800">رفع الملفات</h2>

    <div class="mb-4">
        <input type="file" id="file-input" multiple class="p-2 border border-gray-300 rounded w-full">
    </div>
    <button id="start-upload" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded transition">
        رفع الملفات
    </button>

    <div class="mt-4 text-sm text-gray-600">
        <p>• يمكنك رفع ملفات متعددة في نفس الوقت</p>
        <p>• الملفات الكبيرة سيتم تقسيمها تلقائياً</p>
        <p>• الحد الأقصى للملف: 2GB</p>
    </div>

    <div id="uploads-container" class="mt-6 space-y-4"></div>
</div>

<script>
const fileInput = document.getElementById('file-input');
const startUploadBtn = document.getElementById('start-upload');
const uploadsContainer = document.getElementById('uploads-container');

// دالة لتحويل الحجم إلى صيغة مقروءة
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

startUploadBtn.addEventListener('click', async () => {
    const files = fileInput.files;
    if (!files.length) return alert('اختر الملفات أولاً');

    // تعطيل الزر أثناء الرفع
    startUploadBtn.disabled = true;
    startUploadBtn.textContent = 'جاري الرفع...';

    try {
        for (let file of files) {
            await uploadFile(file);
        }
    } finally {
        // إعادة تمكين الزر
        startUploadBtn.disabled = false;
        startUploadBtn.textContent = 'رفع الملفات';
    }
});

async function uploadFile(file) {
    const fileDiv = document.createElement('div');
    fileDiv.classList.add('p-4', 'border', 'rounded-lg', 'bg-gray-50', 'shadow-sm');
    fileDiv.innerHTML = `
        <div class="flex justify-between items-start mb-2">
            <div>
                <strong class="text-lg">${file.name}</strong>
                <span class="text-sm text-gray-500 ml-2">(${formatFileSize(file.size)})</span>
            </div>
            <span class="status text-gray-600 font-medium">جاري التحضير...</span>
        </div>
        <div class="progress bg-gray-200 rounded-full h-3 w-full overflow-hidden">
            <div class="bar bg-blue-500 h-3 w-0 rounded-full transition-all duration-300"></div>
        </div>
        <div class="text-xs text-gray-500 mt-1 text-left">0%</div>`;

    uploadsContainer.appendChild(fileDiv);

    const statusEl = fileDiv.querySelector('.status');
    const barEl = fileDiv.querySelector('.bar');
    const percentText = fileDiv.querySelector('.text-xs');

    // إذا كان الملف صغيراً (أقل من 10MB) استخدم الرفع المباشر
    if (file.size < 10 * 1024 * 1024) {
        await uploadDirect(file, fileDiv, statusEl, barEl, percentText);
    } else {
        // للملفات الكبيرة استخدم الرفع المتعدد الأجزاء
        await uploadChunked(file, fileDiv, statusEl, barEl, percentText);
    }
}

// الرفع المباشر للملفات الصغيرة
async function uploadDirect(file, fileDiv, statusEl, barEl, percentText) {
    const formData = new FormData();
    formData.append('files[]', file);

    try {
        statusEl.textContent = 'جاري الرفع المباشر...';

        const response = await fetch('{{ route("uploads.direct") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            statusEl.textContent = 'تم الرفع بنجاح 🎉';
            statusEl.className = 'status text-green-600 font-bold';
            barEl.style.width = '100%';
            barEl.style.backgroundColor = '#10B981';
            percentText.textContent = '100%';
        } else {
            throw new Error(data.error || 'فشل في الرفع');
        }
    } catch (err) {
        statusEl.textContent = `فشل الرفع: ${err.message}`;
        statusEl.className = 'status text-red-600 font-bold';
        barEl.style.backgroundColor = '#EF4444';
        console.error('Upload error:', err);
    }
}

// الرفع المتعدد الأجزاء للملفات الكبيرة
async function uploadChunked(file, fileDiv, statusEl, barEl, percentText) {
    let uploadId, key;

    try {
        // 1️⃣ بدء عملية الرفع
        statusEl.textContent = 'جاري بدء عملية الرفع...';

        const initResp = await fetch('{{ route("uploads.init") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                filename: file.name,
                content_type: file.type || 'application/octet-stream'
            })
        });

        if (!initResp.ok) {
            throw new Error(`فشل الاتصال: ${initResp.status}`);
        }

        const initData = await initResp.json();
        if (!initData.success) {
            throw new Error(initData.error || 'فشل في بدء عملية الرفع');
        }

        uploadId = initData.uploadId;
        key = initData.key;

        // 2️⃣ تقسيم الملف إلى أجزاء
        const chunkSize = 5 * 1024 * 1024; // 5MB
        const totalChunks = Math.ceil(file.size / chunkSize);
        let uploadedChunks = 0;

        statusEl.textContent = `جاري الرفع (0/${totalChunks})...`;

        // 3️⃣ رفع الأجزاء
        for (let chunkNumber = 1; chunkNumber <= totalChunks; chunkNumber++) {
            const start = (chunkNumber - 1) * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const chunk = file.slice(start, end);

            const formData = new FormData();
            formData.append('key', key);
            formData.append('uploadId', uploadId);
            formData.append('chunkNumber', chunkNumber);
            formData.append('totalChunks', totalChunks);
            formData.append('file', chunk);

            const response = await fetch('{{ route("uploads.chunk") }}', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: formData
            });

            if (!response.ok) {
                throw new Error(`فشل في رفع الجزء ${chunkNumber}`);
            }

            const data = await response.json();
            if (!data.success) {
                throw new Error(data.error || `فشل في رفع الجزء ${chunkNumber}`);
            }

            uploadedChunks++;
            const progress = Math.round((uploadedChunks / totalChunks) * 100);

            barEl.style.width = `${progress}%`;
            percentText.textContent = `${progress}%`;
            statusEl.textContent = `جاري الرفع (${uploadedChunks}/${totalChunks})...`;

            // إذا كان هذا آخر جزء، سيعود الرد بالنتيجة النهائية
            if (chunkNumber === totalChunks && data.upload_id) {
                statusEl.textContent = 'تم رفع الملف بنجاح 🎉';
                statusEl.className = 'status text-green-600 font-bold';
                barEl.style.backgroundColor = '#10B981';

                // متابعة حالة المعالجة
                checkProcessingStatus(data.upload_id, fileDiv, statusEl);
            }
        }

    } catch (err) {
        console.error('Upload error:', err);
        statusEl.textContent = `فشل الرفع: ${err.message}`;
        statusEl.className = 'status text-red-600 font-bold';
        barEl.style.backgroundColor = '#EF4444';

        // إلغاء الرفع في حالة الخطأ
        if (uploadId) {
            try {
                await fetch('{{ route("uploads.abort") }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ uploadId: uploadId })
                });
            } catch (abortErr) {
                console.error('Abort failed:', abortErr);
            }
        }
    }
}

// متابعة حالة معالجة الملف
async function checkProcessingStatus(uploadId, fileDiv, statusEl) {
    try {
        const response = await fetch(`/uploads/${uploadId}/status`);
        const data = await response.json();

        if (data.success) {
            if (data.status === 'completed') {
                statusEl.textContent = `تم المعالجة (${data.total_pages} صفحة) ✅`;
            } else if (data.status === 'processing') {
                statusEl.textContent = 'جاري معالجة الملف...';
                setTimeout(() => checkProcessingStatus(uploadId, fileDiv, statusEl), 2000);
            } else if (data.status === 'failed') {
                statusEl.textContent = `فشل المعالجة: ${data.message}`;
                statusEl.className = 'status text-red-600 font-bold';
            }
        }
    } catch (err) {
        console.error('Status check error:', err);
    }
}
</script>
@endsection
