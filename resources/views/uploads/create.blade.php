@extends('layouts.app')

@section('content')
<div class="max-w-6xl mx-auto space-y-10 p-4 sm:p-6 lg:p-8">

    <!-- عنوان الصفحة -->
    <h2 class="text-5xl font-extrabold text-gray-900 text-center mb-10">
        <i class="fa-solid fa-cloud-arrow-up text-blue-600 ml-3"></i> رفع ومعالجة ملفات PDF متعددة
    </h2>

    <!-- Toast Messages -->
    <div id="toast-container" class="fixed top-5 right-5 z-[100] space-y-2 max-w-sm"></div>

    <!-- قسم رفع الملفات -->
    <div id="upload-section">
        <div class="bg-white p-6 md:p-10 rounded-3xl shadow-2xl border border-gray-200">
            <form id="upload-form" enctype="multipart/form-data">
                @csrf
                <input id="file-input" type="file" name="file[]" accept="application/pdf" class="hidden" multiple/>

                <div id="drop-zone" class="flex flex-col items-center justify-center w-full h-56 border-4 border-dashed border-gray-300 bg-gray-50 hover:bg-gray-100 rounded-2xl cursor-pointer transition-all duration-300">
                    <i class="fa-solid fa-file-arrow-up text-7xl mb-4 text-blue-500"></i>
                    <p class="text-2xl font-extrabold text-gray-800 text-center">اسحب وأفلت ملفات PDF هنا</p>
                    <p class="text-md text-gray-500 mt-1 text-center">أو <span class="text-blue-600 font-semibold hover:text-blue-800 transition-colors">انقر للتحميل</span>. (يمكنك رفع عدة ملفات)</p>
                </div>

                <!-- قائمة الملفات المحددة -->
                <ul id="file-list" class="space-y-4 mt-6 hidden"></ul>

                <button type="submit" id="start-archiving"
                        disabled
                        class="w-full mt-8 bg-gradient-to-r from-blue-700 to-blue-500 text-white px-8 py-4 rounded-xl font-extrabold text-xl shadow-lg shadow-blue-200 disabled:opacity-50 hover:from-blue-800 hover:to-blue-600 transition-all duration-300">
                    بدء رفع ومعالجة الملفات
                </button>

                <!-- شريط التقدم -->
                <div id="progress-container" class="mt-6"></div>
            </form>
        </div>
    </div>

    <!-- قسم النتائج -->
    <div id="results-section" class="hidden">
        <div class="bg-white p-8 rounded-3xl shadow-2xl border border-green-200">

            <div class="text-center mb-8">
                <i class="fa-solid fa-circle-check text-green-500 text-5xl mb-3"></i>
                <h3 class="text-2xl font-extrabold text-green-800">تم رفع ومعالجة الملفات بنجاح!</h3>
                <p class="text-gray-600 mt-2">المجموعات الناتجة من الملفات المعالجة:</p>
            </div>

            <!-- جدول النتائج -->
            <div class="overflow-x-auto rounded-xl border border-gray-200 mb-8">
                <table class="w-full text-right divide-y divide-gray-200">
                    <thead>
                        <tr class="bg-gradient-to-r from-green-50 to-green-100 text-green-900 uppercase text-sm font-bold tracking-wider">
                            <th class="py-4 px-4 text-center">#</th>
                            <th class="py-4 px-4 text-center">اسم الملف الأصلي</th>
                            <th class="py-4 px-4 text-center">عدد المجموعات</th>
                            <th class="py-4 px-4 text-center">الحالة</th>
                            <th class="py-4 px-4 text-center">تاريخ المعالجة</th>
                            <th class="py-4 px-4 text-center">تحميل</th>
                            <th class="py-4 px-4 text-center">عرض</th>
                        </tr>
                    </thead>
                    <tbody id="results-table-body" class="text-gray-700 text-sm divide-y divide-gray-100">
                        <tr id="results-placeholder">
                            <td colspan="7" class="py-8 text-center text-gray-500">
                                <i class="fa-solid fa-spinner fa-spin text-xl mb-2"></i>
                                <p>جاري تحميل النتائج...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ملخص النتائج -->
            <div class="bg-blue-50 rounded-xl p-6 mb-8 border border-blue-200">
                <h4 class="text-lg font-bold text-blue-800 mb-3 flex items-center">
                    <i class="fa-solid fa-chart-bar ml-2"></i> ملخص النتائج
                </h4>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-center">
                    <div class="bg-white p-4 rounded-lg border border-blue-100">
                        <p class="text-2xl font-bold text-blue-600" id="total-files">0</p>
                        <p class="text-sm text-gray-600">عدد الملفات المعالجة</p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-blue-100">
                        <p class="text-2xl font-bold text-green-600" id="total-groups">0</p>
                        <p class="text-sm text-gray-600">إجمالي المجموعات</p>
                    </div>
                    <div class="bg-white p-4 rounded-lg border border-blue-100">
                        <p class="text-2xl font-bold text-purple-600" id="success-rate">100%</p>
                        <p class="text-sm text-gray-600">معدل النجاح</p>
                    </div>
                </div>
            </div>

            <!-- أزرار التحكم -->
            <div class="flex justify-center gap-4">
                <button id="show-upload-form" class="bg-gradient-to-r from-blue-600 to-blue-500 text-white px-6 py-2.5 rounded-lg font-semibold hover:from-blue-700 hover:to-blue-600 flex items-center">
                    <i class="fa-solid fa-arrow-left ml-2"></i> العودة للرفع
                </button>
                <a href="{{ route('uploads.index') }}" class="bg-gradient-to-r from-purple-600 to-purple-500 text-white px-6 py-2.5 rounded-lg font-semibold hover:from-purple-700 hover:to-purple-600 flex items-center">
                    <i class="fa-solid fa-list ml-2"></i> عرض الكل
                </a>
            </div>
        </div>
    </div>

</div>
@endsection

@section('scripts')
<script src="{{ asset('js/uploads.js') }}"></script>
@endsection
