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
        <strong>${file.name}</strong> - <span class="status text-gray-600">بدء الرفع...</span>
        <div class="progress bg-gray-200 rounded mt-2 h-4 w-full overflow-hidden">
            <div class="bar bg-blue-500 h-4 w-0 rounded"></div>
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

        // 3️⃣ رفع الأجزاء مع retry
        for (let partNumber = 1; partNumber <= totalParts; partNumber++) {
            const start = (partNumber - 1) * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const blob = file.slice(start, end);

            let presignResp, url;
            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    presignResp = await fetch('{{ route('uploads.presign') }}', {
                        method: 'POST',
                        headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}' },
                        body: JSON.stringify({ key, uploadId, partNumber })
                    });
                    url = (await presignResp.json()).url;
                    break;
                } catch (err) {
                    if (attempt === 3) throw err;
                }
            }

            let uploadResp;
            for (let attempt = 1; attempt <= 3; attempt++) {
                try {
                    uploadResp = await fetch(url, { method: 'PUT', body: blob });
                    break;
                } catch(err) {
                    if (attempt === 3) throw err;
                }
            }

            let etag = uploadResp.headers.get('ETag');
            parts.push({ PartNumber: partNumber, ETag: etag.replace(/"/g,'') });

            // تحديث شريط التقدم
            barEl.style.width = `${Math.round((parts.length / totalParts) * 100)}%`;
            statusEl.textContent = `جارٍ رفع الجزء ${partNumber} من ${totalParts}...`;
        }

        // 4️⃣ Complete multipart
        const completeResp = await fetch('{{ route('uploads.complete') }}', {
            method: 'POST',
            headers: { 'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}' },
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
