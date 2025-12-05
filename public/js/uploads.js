// Make sure you built/up-to-date this file (npm build) or place it directly in public/js
document.addEventListener('DOMContentLoaded', function() {
    const startBtn = document.getElementById('start-archiving');
    const results = document.getElementById('results');
    const progressContainer = document.getElementById('progress-container');

    // create Uppy
    const Uppy = window.Uppy.Core;
    const Dashboard = window.Uppy.Dashboard;
    const Tus = window.Uppy.Tus;

    const uppy = new Uppy({
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
        note: 'يمكن رفع ملفات PDF فقط'
    });

    // Configure tus endpoint: ensure it matches route '/tus'
    uppy.use(Tus, {
        endpoint: '/tus', // your tus server entrypoint
        chunkSize: 5 * 1024 * 1024, // 5MB chunks
        retryDelays: [0, 1000, 3000, 5000]
    });

    uppy.on('file-added', () => {
        startBtn.disabled = false;
    });

    uppy.on('upload-progress', (file, progress) => {
        progressContainer.innerHTML = `<p>${file.name} — ${Math.round(progress.bytesUploaded / 1024)} KB uploaded (${Math.round(progress.percentage)}%)</p>`;
    });

    uppy.on('complete', (result) => {
        // result.successful -> array of uploaded files with tus upload URLs
        const uploaded = result.successful.map(f => ({
            original_filename: f.meta.name || f.name,
            upload_url: f.uploadURL // this is the tus location url
        }));

        // send to server to finalize and queue processing
        uploaded.forEach(u => {
            fetch('/uploads/complete', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(u)
            }).then(r => r.json())
            .then(json => {
                console.log('complete response', json);
                results.classList.remove('hidden');
                results.innerHTML += `<div>Uploaded: ${u.original_filename} — queued (id: ${json.upload_id ?? 'n/a'})</div>`;
            }).catch(err => {
                console.error('complete error', err);
                results.classList.remove('hidden');
                results.innerHTML += `<div class="text-red-600">Upload finalize failed for ${u.original_filename}</div>`;
            });
        });
    });

    startBtn.addEventListener('click', () => {
        uppy.upload().catch(err => console.error(err));
    });
});
