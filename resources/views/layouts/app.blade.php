<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'نظام الأرشفة الإلكترونية' }}</title>

    <link rel="icon" type="image/png" href="{{ asset('/images/favicon.png') }}">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" xintegrity="sha512-SnH5WK+bZxgPHs44uWIX+LLMD/cd24yGIt9R/Nl14yF9wK2oQ9g/GvGtrYpB8WwS2K9mD3t3gD4f2nF5gD2Xw==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <script src="https://cdn.tailwindcss.com"></script>

    {{-- استيراد خط Cairo --}}
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">

    {{-- إضافة Alpine.js لدعم القائمة المنسدلة للبروفايل --}}
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js" defer></script>


    <style>
        .font-\[Cairo\] {
            font-family: 'Cairo', sans-serif;
        }
        /* 💡 تم تحديث نمط الخلفية لتكون أهدأ وأكثر بساطة (Minimalist) */
        .body-pattern {
            background-image: radial-gradient(rgba(209, 213, 219, 0.5) 0.5px, transparent 0); /* رمادي فاتح جداً */
            background-size: 20px 20px;
            background-color: #f7f9fd; /* لون أوف وايت/أزرق فاتح جداً */
        }

        /* 💡 نمط التحديد النشط للقائمة المنسدلة */
        .dropdown-active-link {
            background-color: #f0f4ff; /* bg-blue-50 */
            color: #1e40af; /* text-blue-800 */
        }
    </style>
</head>
<body class="body-pattern font-[Cairo] text-gray-800">

    {{-- 💡 الهيدر: أصبح أكثر نظافة مع ظلال ناعمة --}}
    <header class="sticky top-0 z-50 bg-white shadow-xl shadow-gray-200/50 border-b border-gray-100/70">

        {{-- الصف العلوي: الشعار، زر الرفع، البروفايل --}}
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">

            {{-- الشعار والعنوان (تم توحيد الخط) --}}
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 flex-shrink-0 group">
                <span class="text-blue-600 text-3xl transition-transform group-hover:scale-105">
                    <i class="fa-solid fa-folder-open"></i>
                </span>
                <h1 class="text-2xl font-extrabold text-gray-900 transition-colors group-hover:text-blue-700">نظام الأرشفة الإلكترونية</h1>
            </a>

            {{-- زر الرفع ومنطقة البروفايل --}}
            <div class="flex items-center gap-6 flex-shrink-0">

                {{-- 💡 زر الرفع: تصميم أكثر فخامة (Neumorphism-like shadow) --}}
                <a href="{{ route('uploads.create') }}"
                   class="bg-blue-600 text-white px-6 py-2.5 rounded-xl font-extrabold text-base
                          shadow-2xl shadow-blue-500/70
                          hover:bg-blue-700 transition transform hover:-translate-y-0.5 hover:shadow-blue-500/90 duration-300">
                    <i class="fa-solid fa-cloud-upload-alt ml-2"></i> رفع ملف جديد
                </a>

                @if (Auth::check())
                    @php
                        // دالة لاستخلاص الأحرف الأولى
                        $getInitials = function($name) {
                            $parts = explode(' ', trim($name));
                            $initials = '';
                            foreach ($parts as $part) {
                                if (strlen($initials) < 2) {
                                    $initials .= strtoupper(mb_substr($part, 0, 1)); // استخدام mb_substr لدعم العربية
                                }
                            }
                            return $initials ?: 'U';
                        };
                        $userName = Auth::user()->name ?? 'اسم المستخدم';
                        $userEmail = Auth::user()->email ?? 'mock.user@domain.com';
                        $initials = $getInitials($userName);
                    @endphp

                    {{-- 💡 منطقة البروفايل: تصميم أنظف --}}
                    <div x-data="{ open: false }" @keydown.escape.window="open = false" class="relative">
                        <button @click="open = !open" type="button"
                                class="flex items-center gap-2 p-1 bg-white rounded-full
                                       border border-gray-300 hover:border-blue-400
                                       transition duration-150 focus:outline-none focus:ring-4 focus:ring-blue-500/50">
                            {{-- صورة/رمز البروفايل (الأحرف الأولى ديناميكية) --}}
                            <div class="w-10 h-10 bg-blue-600 rounded-full flex items-center justify-center
                                        text-white font-bold text-lg border-2 border-white shadow-md">
                                {{ $initials }}
                            </div>
                            {{-- الاسم الديناميكي للمستخدم --}}
                            <span class="font-bold text-gray-700 hidden sm:block ml-1">{{ $userName }}</span>
                            <i class="fa-solid fa-chevron-down text-gray-500 text-xs mr-2 transition-transform" :class="{ 'rotate-180': open }"></i>
                        </button>

                        {{-- القائمة المنسدلة للبروفايل --}}
                        <div x-show="open" @click.away="open = false" x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 scale-90 -translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                            x-transition:leave-end="opacity-0 scale-90 -translate-y-2"
                            class="absolute left-0 mt-3 w-64 rounded-xl shadow-2xl bg-white border border-gray-200 ring-1 ring-black ring-opacity-5 z-50 origin-top-left"
                            style="display: none;">

                            <div class="p-4 border-b border-gray-100">
                                <p class="font-extrabold text-lg text-gray-800">{{ $userName }}</p>
                                <p class="text-sm text-gray-500 truncate">{{ $userEmail }}</p>
                            </div>

                            <div class="py-1">
                                {{-- رابط تعديل البيانات الشخصية --}}
                                <a href="{{ route('profile.edit') }}"
                                   class="w-full text-right block px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-700 transition duration-150 rounded-lg mx-2 my-1">
                                    <i class="fa-solid fa-user-edit ml-2 w-4"></i> تعديل البيانات الشخصية
                                </a>

                                {{-- تسجيل الخروج --}}
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit"
                                        class="w-full text-right block px-4 py-2 text-sm text-red-600 hover:bg-red-50 hover:text-red-700 transition duration-150 font-bold border-t border-gray-100 mt-2 rounded-b-xl">
                                        <i class="fa-solid fa-right-from-bracket ml-2 w-4"></i> تسجيل الخروج
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- 💡 خط فاصل بسيط --}}
        <div class="h-px bg-gray-200/70"></div>

        {{-- شريط التنقل --}}
        <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center gap-2 text-base font-semibold">

                @php
                    // دالة مساعدة لتحديد ما إذا كان الرابط نشطاً
                    $isActive = fn($route) => request()->routeIs($route) ?
                        'text-blue-700 border-b-4 border-blue-600 font-extrabold -mb-px bg-blue-50' :
                        'text-gray-600 hover:text-blue-700 hover:bg-blue-50/50';
                @endphp

                {{-- الرئيسية --}}
                <a href="{{ route('dashboard') }}"
                   class="py-3 px-4 transition duration-200 rounded-t-lg flex items-center {{ $isActive('dashboard') }}">
                   <i class="fa-solid fa-house-chimney ml-2"></i> الرئيسية
                </a>

                {{-- الملفات المرفوعة --}}
                <a href="{{ route('uploads.index') }}"
                   class="py-3 px-4 transition duration-200 rounded-t-lg flex items-center {{ $isActive('uploads.index') }}">
                   <i class="fa-solid fa-file-invoice ml-2"></i> الملفات المرفوعة
                </a>

                {{-- المجموعات الناتجة --}}
                <a href="{{ route('groups.index') }}"
                   class="py-3 px-4 transition duration-200 rounded-t-lg flex items-center {{ $isActive('groups.index') }}">
                   <i class="fa-solid fa-layer-group ml-2"></i> المجموعات الناتجة
                </a>
            </div>
        </nav>
    </header>


    {{-- المحتوى --}}
    <main class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
        @yield('content')
    </main>

</body>
</html>
