@extends('layouts.app')

@section('content')

<div class="max-w-4xl mx-auto p-6">

    <h1 class="text-3xl font-bold mb-8 text-center">
        رفع ملفات PDF باستخدام Uppy + tus
    </h1>

    <div id="drag-drop-area"></div>

    <div class="mt-4 text-center">
        <button id="start-archiving"
                disabled
                class="px-6 py-3 bg-blue-600 text-white rounded-xl font-bold disabled:opacity-40">
            ابدأ الرفع
        </button>
    </div>

    <div id="progress-container" class="mt-6 text-center text-gray-700"></div>

    <div id="results" class="mt-8 hidden"></div>

</div>

@endsection

@section('scripts')
    <script src="https://releases.transloadit.com/uppy/v2.12.1/uppy.min.js"></script>
    <link href="https://releases.transloadit.com/uppy/v2.12.1/uppy.min.css" rel="stylesheet">
    <script src="{{ asset('js/uploads.js') }}"></script>
@endsection
