@extends('layouts.app')

@section('content')
<div class="max-w-4xl mx-auto p-6">
    <h1 class="text-2xl font-bold mb-4">رفع ملفات PDF (Uppy + tus)</h1>

    <div id="drag-drop-area"></div>

    <div class="mt-4">
        <button id="start-archiving" disabled class="px-4 py-2 bg-blue-600 text-white rounded">ابدأ الرفع</button>
    </div>

    <div id="progress-container" class="mt-4"></div>

    <div id="results" class="mt-6 hidden"></div>
</div>
@endsection

@section('scripts')
<script src="https://releases.transloadit.com/uppy/v2.12.1/uppy.min.js"></script>
<link href="https://releases.transloadit.com/uppy/v2.12.1/uppy.min.css" rel="stylesheet">
<script src="{{ asset('js/uploads.js') }}"></script>
@endsection
