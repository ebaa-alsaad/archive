document.addEventListener('DOMContentLoaded', function() {

    const startBtn = document.getElementById('start-archiving');
    const results = document.getElementById('results');
    const progressContainer = document.getElementById('progress-container');

    const UppyCore = window.Uppy.Core;
    const Dashboard = window.Uppy.Dashboard;
    const Tus = window.Uppy.Tus;

    const uppy = new UppyCore({
        restrictions: {
            maxNumberOfFiles: 10,
            allowedFileTypes: ['application/pdf']
        },
        autoProceed: false
    });

    uppy.use(Dashboard, {
        inline: true,
        target: '#drag-drop-area',
        showProgressDetails: true,
        proudlyDisplayPoweredByUppy: false,
        note: 'يسمح فقط برفع ملفات PDF'
    });

    uppy.use(Tus, {
        endpoint: '/tus',
        chunkSize: 5 * 1024 * 1024,
        retryDelays: [0, 1000, 3000, 5000]
    });

    uppy.on('file-added', () => {
        startBtn.disabled = false;
    });

    uppy.on('upload-progress', (file, progress) => {
        progressContainer.innerHTML =
            `${file.name} → ${Math.round(progress.percentage)}%`;
    });

    uppy.on('complete', (result) => {

        const uploaded = result.successful.map(f => ({
            original_filename: f.meta.name || f.name,
            upload_url: f.uploadURL
        }));

        uploaded.forEach(u => {
            fetch('/uploads/complete', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(u)
            })
            .then(r => r.json())
            .then(json => {
                results.classList.remove('hidden');
                results.innerHTML += `<div>✔ ${u.original_filename} تمت إضافته (ID ${json.upload_id})</div>`;
            })
            .catch(() => {
                results.classList.remove('hidden');
                results.innerHTML += `<div class="text-red-600">✖ فشل إكمال ${u.original_filename}</div>`;
            });
        });

    });

    startBtn.addEventListener('click', () => {
        uppy.upload();
    });

});
