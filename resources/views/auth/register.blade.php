@extends('layouts.guest')

@section('content')

{{-- 🛑 تأكد من وجود الميتا تاج هذا في الـ head في ملف layouts.guest.blade.php --}}
{{-- <meta name="csrf-token" content="{{ csrf_token() }}"> --}}

<div class="min-h-screen flex items-center justify-center relative p-4 bg-white overflow-hidden">
    
    {{-- 🛑 حاوية رسائل التوستر --}}
    <div id="toast-container" class="fixed top-5 right-5 z-50 space-y-3"></div>
    
    {{-- الحاوية الرئيسية --}}
    <div class="z-10 w-full max-w-5xl mx-auto flex shadow-5xl rounded-3xl overflow-hidden bg-white dark:bg-gray-800 transition-colors duration-500 transform scale-90 opacity-0 animate-slideUp">

        {{-- قسم تسجيل الحساب --}}
        <div class="w-full lg:w-3/5 p-10 sm:p-14 lg:p-20 flex flex-col justify-center dark:text-gray-100">
            <div class="mx-auto w-full max-w-md">

                <div class="text-center mb-12">
                    <h2 class="text-5xl font-extrabold text-gray-900 dark:text-white mb-3 tracking-tighter">
                        إنشاء حساب جديد
                    </h2>
                    <p class="text-lg text-gray-600 dark:text-gray-400">
                        الرجاء إدخال البيانات المطلوبة لإنشاء الحساب.
                    </p>
                </div>

                {{-- الفورم --}}
                <form id="register-form" class="space-y-10">
                    @csrf

                    {{-- الاسم الكامل --}}
                    <div class="relative group">
                        <input id="name" name="name" type="text" required autofocus autocomplete="name" placeholder=" "
                               class="block w-full px-4 pt-6 pb-2 text-lg appearance-none bg-transparent border-b-2 border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-0 focus:border-blue-500 peer dark:text-white transition-all duration-300">
                        <label for="name" class="absolute right-0 top-4 text-base text-gray-500 dark:text-gray-400 duration-300 transform -translate-y-4 scale-75 origin-top-right peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600 dark:peer-focus:text-blue-400">
                            <i class="fa-solid fa-user mr-2"></i> الاسم الكامل
                        </label>
                    </div>

                    {{-- البريد الإلكتروني --}}
                    <div class="relative group">
                        <input id="email" name="email" type="email" required autocomplete="email" placeholder=" "
                               class="block w-full px-4 pt-6 pb-2 text-lg appearance-none bg-transparent border-b-2 border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-0 focus:border-blue-500 peer dark:text-white transition-all duration-300">
                        <label for="email" class="absolute right-0 top-4 text-base text-gray-500 dark:text-gray-400 duration-300 transform -translate-y-4 scale-75 origin-top-right peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600 dark:peer-focus:text-blue-400">
                            <i class="fa-solid fa-envelope mr-2"></i> البريد الإلكتروني
                        </label>
                    </div>

                    {{-- كلمة المرور --}}
                    <div class="relative group">
                        <input id="password" name="password" type="password" required autocomplete="new-password" placeholder=" "
                               class="block w-full px-4 pt-6 pb-2 text-lg appearance-none bg-transparent border-b-2 border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-0 focus:focus:border-blue-500 peer dark:text-white transition-all duration-300">
                        <label for="password" class="absolute right-0 top-4 text-base text-gray-500 dark:text-gray-400 duration-300 transform -translate-y-4 scale-75 origin-top-right peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600 dark:peer-focus:text-blue-400">
                            <i class="fa-solid fa-lock mr-2"></i> كلمة المرور
                        </label>
                    </div>

                    {{-- تأكيد كلمة المرور --}}
                    <div class="relative group">
                        <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" placeholder=" "
                               class="block w-full px-4 pt-6 pb-2 text-lg appearance-none bg-transparent border-b-2 border-gray-300 dark:border-gray-600 focus:outline-none focus:ring-0 focus:focus:border-blue-500 peer dark:text-white transition-all duration-300">
                        <label for="password_confirmation" class="absolute right-0 top-4 text-base text-gray-500 dark:text-gray-400 duration-300 transform -translate-y-4 scale-75 origin-top-right peer-placeholder-shown:scale-100 peer-placeholder-shown:translate-y-0 peer-focus:scale-75 peer-focus:-translate-y-4 peer-focus:text-blue-600 dark:peer-focus:text-blue-400">
                            <i class="fa-solid fa-lock-open mr-2"></i> تأكيد كلمة المرور
                        </label>
                    </div>
                    
                    {{-- زر إنشاء الحساب --}}
                    <div class="pt-6">
                        <button type="submit" id="register-button"
                                class="w-full flex justify-center items-center py-4 px-4 border border-transparent rounded-full shadow-lg text-xl font-bold text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-4 focus:ring-blue-500/50 transition duration-300 ease-in-out transform hover:shadow-2xl hover:shadow-blue-500/50 active:scale-95 disabled:bg-blue-400 disabled:cursor-not-allowed">
                            <span id="button-text"> إنشاء حساب جديد </span>
                            <svg id="loading-spinner" class="animate-spin -ml-1 mr-3 h-6 w-6 text-white hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </button>
                    </div>
                </form>

                {{-- رابط تسجيل الدخول --}}
                <p class="mt-12 text-center text-md text-gray-600 dark:text-gray-400 pt-6 border-t border-gray-200 dark:border-gray-700">
                    لديك حساب بالفعل؟
                    <a href="{{ route('login') }}" class="font-extrabold text-blue-500 hover:text-blue-400 transition duration-150 mr-1">
                        تسجيل الدخول
                    </a>
                </p>
            </div>
        </div>

        {{-- القسم الأيسر --}}
        <div class="hidden lg:flex relative lg:w-2/5 flex-col justify-center p-12 overflow-hidden bg-blue-900 text-white archive-3d-panel">
            <div class="absolute inset-0 z-0 archive-3d-flow"></div>
            <div class="absolute inset-0 z-0 archive-extra-flow"></div>
            
            <div class="relative z-10 text-center space-y-8">
                <div class="text-8xl font-black tracking-widest text-white transform hover:scale-105 transition-transform duration-500">
                    <span class="text-blue-400">D</span>OC
                </div>
                <h2 class="text-3xl font-bold leading-snug text-blue-200">
                    بوابتك للتحول الرقمي الآمن
                </h2>
                <p class="text-lg opacity-90 mt-4 max-w-sm mx-auto font-light border-t border-white/30 pt-4">
                    نظام الأرشفة الذكي لإدارة وحفظ مستنداتك بكفاءة وخصوصية عالية.
                </p>
                <div class="mt-8">
                    <i class="fa-solid fa-cloud-arrow-up text-6xl text-blue-400 animate-pulse-slow"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* نفس CSS كما في Login (shadow, animations, floating labels, toast) */
.shadow-5xl { box-shadow: 0 50px 100px -25px rgba(0,0,0,0.2); }
.animate-slideUp { animation: slideUp 0.8s cubic-bezier(0.25,0.46,0.45,0.94) forwards; animation-delay:0.2s; }
@keyframes slideUp { from {opacity:0; transform:translateY(20px) scale(0.95);} to {opacity:1; transform:translateY(0) scale(1);} }
@keyframes pulse-slow { 0%,100%{opacity:1;}50%{opacity:0.8;} }
.animate-pulse-slow { animation: pulse-slow 3s infinite ease-in-out; }
.archive-3d-panel { perspective:1000px; overflow:hidden; box-shadow:inset 0 0 50px rgba(0,0,0,0.5); }
.archive-3d-flow { position:absolute; inset:0; background-image: repeating-linear-gradient(60deg,#ffffff1a,#ffffff1a 1px,transparent 1px,transparent 150px); background-size:300% 300%; transform: rotateX(60deg) rotateZ(45deg) scale(2.5); transform-origin:0 0; animation: flow-3d 30s linear infinite; opacity:0.4; }
@keyframes flow-3d { from {background-position:0% 0%;} to {background-position:200% 200%;} }
.archive-extra-flow { position:absolute; inset:-20%; background: linear-gradient(90deg,transparent,rgba(66,135,245,0.2),transparent), linear-gradient(45deg,transparent,rgba(255,255,255,0.1),transparent); background-size:200% 200%; animation: extra-flow-move 50s linear infinite; opacity:0.8; }
@keyframes extra-flow-move { 0%{background-position:0% 0%;}50%{background-position:100% 100%;}100%{background-position:0% 0%;} }
input:focus ~ label, input:not(:placeholder-shown) ~ label { transform: translateY(-1rem) scale(0.75); }
.toast { transition: transform 0.3s ease-out, opacity 0.3s ease-out; transform: translateX(100%); opacity:0; }
.toast-error { background-color: rgba(30,41,59,0.95); color:#fff; border-right:5px solid #ef4444; }
</style>

<script>
const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

function showToast(message){
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast max-w-sm w-full shadow-lg rounded-lg pointer-events-auto overflow-hidden ring-1 ring-black ring-opacity-10 toast-error';
    toast.innerHTML = `<div class="p-3 text-sm flex items-center" dir="rtl"><i class="fa-solid fa-circle-exclamation text-red-400 ml-3"></i><p class="font-medium flex-1">${message}</p></div>`;
    container.appendChild(toast);
    setTimeout(()=>{toast.style.transform='translateX(0)'; toast.style.opacity='1';},10);
    setTimeout(()=>{toast.style.transform='translateX(120%)'; toast.style.opacity='0'; toast.addEventListener('transitionend',()=>toast.remove());},5000);
}

document.addEventListener('DOMContentLoaded', function(){
    const form = document.getElementById('register-form');
    const button = document.getElementById('register-button');
    const buttonText = document.getElementById('button-text');
    const spinner = document.getElementById('loading-spinner');

    form.addEventListener('submit', async function(e){
        e.preventDefault();
        button.disabled = true;
        buttonText.textContent='جاري الإنشاء...';
        spinner.classList.remove('hidden');

        const formData = {
            name: document.getElementById('name').value,
            email: document.getElementById('email').value,
            password: document.getElementById('password').value,
            password_confirmation: document.getElementById('password_confirmation').value
        };

        try {
            const res = await fetch('{{ route("register") }}',{
                method:'POST',
                headers:{
                    'Content-Type':'application/json',
                    'Accept':'application/json',
                    'X-CSRF-TOKEN':csrfToken,
                    'X-Requested-With':'XMLHttpRequest'
                },
                body: JSON.stringify(formData)
            });

            if(res.ok){
                window.location.href = "{{ url('/login') }}";
                return;
            }else{
                const errorData = await res.json();
                let msg='حدث خطأ غير معروف أثناء إنشاء الحساب.';
                if(res.status===419) msg='انتهت صلاحية الجلسة. يرجى تحديث الصفحة.';
                else if(errorData.message) msg=errorData.message;
                else if(errorData.errors && Object.keys(errorData.errors).length>0){
                    const firstKey = Object.keys(errorData.errors)[0];
                    msg = errorData.errors[firstKey][0];
                }
                showToast(msg);
            }

        }catch(err){
            console.error(err);
            showToast('حدث خطأ في الاتصال بالخادم.');
        }finally{
            button.disabled=false;
            buttonText.textContent='إنشاء حساب جديد';
            spinner.classList.add('hidden');
        }
    });
});
</script>

@endsection
