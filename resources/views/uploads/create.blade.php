@extends('layouts.app')

@section('content')
<div class="max-w-3xl mx-auto p-6 bg-white shadow rounded">
    <h2 class="text-2xl font-bold mb-4">رفع الملفات</h2>

    <input type="file" id="file-input" multiple class="mb-4 p-2 border rounded w-full">
    <button id="start-upload" class="bg-blue-600 text-white px-4 py-2 rounded">رفع الملفات</button>

    <div id="uploads-container" class="mt-4 space-y-4"></div>
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

// دالة رفع ملف مع دعم رفع الأجزاء المتوازية و retry عند الخطأ
async function uploadFile(file) {
    const fileDiv = document.createElement('div');
    fileDiv.classList.add('p-2', 'border', 'rounded');
    fileDiv.innerHTML = `
        <strong>${file.name}</strong> - <span class="status">بدء الرفع...</span>
        <div class="progress bg-gray-200 rounded mt-1 h-3 w-full">
            <div class="bar bg-blue-500 h-3 w-0 rounded"></div>
        </div>`;
    uploadsContainer.appendChild(fileDiv);

    const statusEl = fileDiv.querySelector('.status');
    const barEl = fileDiv.querySelector('.bar');

    try {
        // 1️⃣ Init multipart
        const initResp = await fetch('{{ route('uploads.init') }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ filename: file.name, content_type: file.type })
        });
        const { uploadId, key } = await initResp.json();

        // 2️⃣ Split file into chunks
        const chunkSize = 5 * 1024 * 1024; // 5MB
        const totalParts = Math.ceil(file.size / chunkSize);
        let parts = [];

        // 3️⃣ رفع الأجزاء بعدد محدود متوازي (concurrency)
        const concurrency = 3; // عدد الأجزاء المتوازية في نفس الوقت
        let current = 0;

        async function uploadPart(partNumber) {
            const start = (partNumber - 1) * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const blob = file.slice(start, end);

            // retry mechanism
            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    const completeResp = await fetch('{{ route('uploads.complete') }}', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    body: JSON.stringify({ key, uploadId, parts, original_filename: file.name })
                    });

                    // تحقق قبل parse
                    const text = await completeResp.text();
                    try {
                    const completeData = JSON.parse(text);
                    if (completeData.success) {
                        statusEl.textContent = 'تم رفع الملف بنجاح';
                        barEl.style.backgroundColor = 'green';
                    } else {
                        statusEl.textContent = 'فشل الرفع: ' + (completeData.error || '');
                        barEl.style.backgroundColor = 'red';
                    }
                    } catch(e) {
                    console.error('Response ليس JSON:', text);
                    statusEl.textContent = 'فشل الرفع: Response غير صالح';
                    barEl.style.backgroundColor = 'red';
                    }

                    const { url } = await presignResp.json();

                    const uploadResp = await fetch(url, { method: 'PUT', body: blob });
                    let etag = uploadResp.headers.get('ETag');
                    if (etag) etag = etag.replace(/"/g,'');

                    // تحديث شريط التقدم
                    const uploadedParts = parts.length + 1;
                    barEl.style.width = `${Math.round((uploadedParts / totalParts) * 100)}%`;

                    return { PartNumber: partNumber, ETag: etag };
                } catch (err) {
                    console.warn(`Part ${partNumber} attempt ${attempt} failed`, err);
                    if (attempt === 3) throw err;
                }
            }
        }

        // دالة للتحكم في رفع الأجزاء بالتوازي
        async function uploadQueue() {
            while (current < totalParts) {
                const partNumber = current + 1;
                current++;
                const part = await uploadPart(partNumber);
                parts.push(part);
            }
        }

        // تشغيل عدة توازيات
        const runners = Array.from({ length: concurrency }, uploadQueue);
        await Promise.all(runners);

        // 4️⃣ Complete multipart
        const completeResp = await fetch('{{ route('uploads.complete') }}', {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}'},
            body: JSON.stringify({ key, uploadId, parts, original_filename: file.name })
        });
        const completeData = await completeResp.json();

        if (completeData.success) {
            statusEl.textContent = 'تم رفع الملف بنجاح';
            barEl.style.backgroundColor = 'green';
        } else {
            statusEl.textContent = 'فشل الرفع: ' + (completeData.error || '');
            barEl.style.backgroundColor = 'red';
        }

    } catch(err) {
        statusEl.textContent = 'فشل الرفع: ' + err.message;
        barEl.style.backgroundColor = 'red';
        console.error(err);
    }
}
</script>
@endsection
