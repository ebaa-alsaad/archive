<!DOCTYPE html>
<html lang="ar" dir="rtl" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">


    <title>{{ $title ?? 'نظام الأرشفة الإلكترونية' }}</title>

    <link rel="icon" type="image/png" href="{{ asset('/images/favicon.png') }}">

    <script src="https://kit.fontawesome.com/a81368914c.js" crossorigin="anonymous"></script>

    <script src="https://cdn.tailwindcss.com"></script>

    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/css/toastr.min.css" rel="stylesheet">

    <style>
        /* إعدادات خط القاهرة */
        body {
            font-family: 'Cairo', sans-serif;
        }

        /* تحسين شكل الـ Toastr */
        #toast-container > div {
            opacity: 1;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            border-radius: 0.75rem; /* حواف مدورة أكثر */
        }
    </style>
</head>
<body class="bg-gray-100 h-full">

    @yield('content')

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/js/toastr.min.js"></script>

    <script>
        $(document).ready(function() {
            // تهيئة احترافية للـ Toastr
            toastr.options = {
                "closeButton": true,
                "progressBar": true,
                "positionClass": "toast-top-center",
                "timeOut": "4500", // زيادة بسيطة في وقت الظهور
                "extendedTimeOut": "1000",
                "showEasing": "swing",
                "hideEasing": "linear",
                "showMethod": "fadeIn",
                "hideMethod": "fadeOut",
                "tapToDismiss": false, // منع الإغلاق بالنقر
            };

            // عرض رسائل الجلسة
            @if (session('error'))
                toastr.error("{{ session('error') }}", "⚠️ خطأ في العملية");
            @endif

            @if (session('success'))
                toastr.success("{{ session('success') }}", "✅ تم بنجاح");
            @endif

            @if (session('warning'))
                toastr.warning("{{ session('warning') }}", "🔔 تنبيه");
            @endif

            @if (session('info'))
                toastr.info("{{ session('info') }}", "💡 معلومة إضافية");
            @endif
        });
    </script>

</body>
</html>
