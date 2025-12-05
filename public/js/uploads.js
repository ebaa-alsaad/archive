document.addEventListener('DOMContentLoaded', function() {
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const fileList = document.getElementById('file-list');
    const startButton = document.getElementById('start-archiving');
    const progressContainer = document.getElementById('progress-container');
    const resultsSection = document.getElementById('results-section');
    const resultsTableBody = document.getElementById('results-table-body');
    const showUploadForm = document.getElementById('show-upload-form');

    // فتح نافذة الملفات عند النقر
    dropZone.addEventListener('click', () => fileInput.click());

    // التعامل مع الملفات
    fileInput.addEventListener('change', handleFiles);
    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        fileInput.files = e.dataTransfer.files;
        handleFiles();
    });
    dropZone.addEventListener('dragover', (e) => e.preventDefault());

    function handleFiles() {
        const files = fileInput.files;
        if (files.length > 0) {
            fileList.innerHTML = '';
            fileList.classList.remove('hidden');
            Array.from(files).forEach((file, index) => {
                const li = document.createElement('li');
                li.textContent = `${index + 1}. ${file.name} (${Math.round(file.size / 1024)} KB)`;
                li.classList.add('text-gray-700');
                fileList.appendChild(li);
            });
            startButton.disabled = false;
        } else {
            fileList.classList.add('hidden');
            startButton.disabled = true;
        }
    }

    async function fetchProgress(uploadId) {
        try {
            const res = await fetch(`/uploads/progress/${uploadId}`);
            if (!res.ok) return null;
            return await res.json();
        } catch {
            return null;
        }
    }

    function updateProgressBar(progress, message) {
        progressContainer.innerHTML = `
            <div class="w-full bg-gray-200 rounded h-4">
                <div class="bg-green-500 h-4 rounded" style="width:${progress}%"></div>
            </div>
            <p class="text-gray-700 mt-1">${message}</p>
        `;
    }

    // رفع الملفات ومعالجة التقدم
    document.getElementById('upload-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        startButton.disabled = true;
        startButton.textContent = "جاري الرفع...";

        try {
            const response = await fetch('/uploads/create', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('input[name=_token]').value },
                body: formData
            });

            const data = await response.json();
            const uploadId = data.upload_id;

            // متابعة التقدم
            const interval = setInterval(async () => {
                const prog = await fetchProgress(uploadId);
                if (prog) updateProgressBar(prog.progress, prog.message);

                if (prog && prog.progress >= 100) {
                    clearInterval(interval);
                    resultsSection.classList.remove('hidden');

                    // عرض النتائج
                    if (data.results) {
                        resultsTableBody.innerHTML = '';
                        data.results.forEach((item, index) => {
                            resultsTableBody.innerHTML += `
                                <tr>
                                    <td class="py-2 px-4 text-center">${index + 1}</td>
                                    <td class="py-2 px-4 text-center">${item.original_name}</td>
                                    <td class="py-2 px-4 text-center">${item.groups_count}</td>
                                    <td class="py-2 px-4 text-center text-green-600">تم</td>
                                    <td class="py-2 px-4 text-center">${item.processed_at}</td>
                                    <td class="py-2 px-4 text-center"><a href="${item.download_url}" class="text-blue-600 hover:underline">تحميل</a></td>
                                    <td class="py-2 px-4 text-center"><a href="${item.show_url}" class="text-blue-600 hover:underline">عرض</a></td>
                                </tr>
                            `;
                        });
                    }
                }
            }, 1000);

        } catch (err) {
            console.error(err);
        } finally {
            startButton.disabled = false;
            startButton.textContent = "بدء رفع ومعالجة الملفات";
        }
    });

    showUploadForm.addEventListener('click', () => {
        resultsSection.classList.add('hidden');
        fileList.classList.add('hidden');
        startButton.disabled = true;
        fileInput.value = '';
        progressContainer.innerHTML = '';
    });
});
