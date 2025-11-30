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

    <div id="uploads-container" class="mt-6 space-y-4"></div>
</div>

<script>
const fileInput = document.getElementById('file-input');
const startUploadBtn = document.getElementById('start-upload');
const uploadsContainer = document.getElementById('uploads-container');

startUploadBtn.addEventListener('click', async () => {
    const files = fileInput.files;
    if (!files.length) return alert('اختر الملفات أولاً');

    for (let file of files) {
        await uploadFile(file);
    }
});

async function uploadFile(file) {
    const fileDiv = document.createElement('div');
    fileDiv.classList.add('p-3', 'border', 'rounded', 'bg-gray-50');
    fileDiv.innerHTML = `
        <strong>${file.name}</strong> - <span class="status text-gray-600">جاري التحضير...</span>
        <div class="progress bg-gray-200 rounded mt-2 h-4 w-full overflow-hidden">
            <div class="bar bg-blue-500 h-4 w-0 rounded"></div>
        </div>`;
    uploadsContainer.appendChild(fileDiv);

    const statusEl = fileDiv.querySelector('.status');
    const barEl = fileDiv.querySelector('.bar');

    try {
        // 🔍 التحقق من اتصال الخادم أولاً
        statusEl.textContent = 'جاري الاتصال بالخادم...';

        // 1️⃣ بدء عملية الرفع
        const initResp = await fetch('{{ route('uploads.init') }}', {
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
            throw new Error(`فشل الاتصال: ${initResp.status} ${initResp.statusText}`);
        }

        const initData = await initResp.json();

        if (!initData.success) {
            throw new Error(initData.error || 'فشل في بدء عملية الرفع');
        }

        const { uploadId, key } = initData;

        // 2️⃣ تقسيم الملف إلى أجزاء
        const chunkSize = 5 * 1024 * 1024; // 5MB
        const totalParts = Math.ceil(file.size / chunkSize);
        let parts = [];

        statusEl.textContent = `جاري الرفع (0/${totalParts})...`;

        // 3️⃣ رفع الأجزاء
        for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
            const start = (partNumber - 1) * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const blob = file.slice(start, end);

            let presignResp;
            try {
                presignResp = await fetch('{{ route('uploads.presign') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ key, uploadId, partNumber })
                });

                if (!presignResp.ok) {
                    throw new Error(`فشل في الحصول على رابط الرفع: ${presignResp.status}`);
                }

                const presignData = await presignResp.json();
                const url = presignData.url;

                // رفع الجزء
                const uploadResp = await fetch(url, {
                    method: 'PUT',
                    body: blob,
                    headers: {
                        'Content-Type': file.type || 'application/octet-stream'
                    }
                });

                if (!uploadResp.ok) {
                    throw new Error(`فشل في رفع الجزء: ${uploadResp.status}`);
                }

                const etag = uploadResp.headers.get('ETag');
                parts.push({
                    PartNumber: partNumber,
                    ETag: etag ? etag.replace(/"/g, '') : ''
                });

                // تحديث التقدم
                const progress = Math.round((partNumber / totalParts) * 100);
                barEl.style.width = `${progress}%`;
                statusEl.textContent = `جاري الرفع (${partNumber}/${totalParts})...`;

            } catch (chunkError) {
                throw new Error(`فشل في الجزء ${partNumber}: ${chunkError.message}`);
            }
        }

        // 4️⃣ إكمال عملية الرفع
        const completeResp = await fetch('{{ route('uploads.complete') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({
                key,
                uploadId,
                parts,
                original_filename: file.name
            })
        });

        const completeData = await completeResp.json();

        if (completeData.success) {
            statusEl.textContent = 'تم رفع الملف بنجاح 🎉';
            statusEl.className = 'status text-green-600 font-bold';
            barEl.style.backgroundColor = '#10B981';
        } else {
            throw new Error(completeData.error || 'فشل في إكمال عملية الرفع');
        }

    } catch (err) {
        console.error('Upload error:', err);
        statusEl.textContent = `فشل الرفع: ${err.message}`;
        statusEl.className = 'status text-red-600 font-bold';
        barEl.style.backgroundColor = '#EF4444';

        // محاولة إلغاء الرفع في حالة الخطأ
        try {
            await fetch('{{ route('uploads.abort') }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ key, uploadId })
            });
        } catch (abortErr) {
            console.error('Abort failed:', abortErr);
        }
    }
}
</script>
@endsection
