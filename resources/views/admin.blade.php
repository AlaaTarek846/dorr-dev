<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="ltr" data-nav-layout="vertical" data-theme-mode="light" data-header-styles="light" data-menu-styles="dark" data-toggled="close">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Admin | {{ config('app.name', 'Laravel') }}</title>

        <script>
            (function () {
                var locale = localStorage.getItem('admin_locale');
                var isRtl = locale === 'ar' || (locale === null && localStorage.getItem('ynexrtl'));

                if (isRtl) {
                    document.documentElement.setAttribute('dir', 'rtl');
                    document.documentElement.setAttribute('lang', 'ar');
                }
            })();
        </script>

        <link rel="icon" href="{{ asset('dashboard/assets/images/brand-logos/favicon.ico') }}" type="image/x-icon">
        <link
            id="style"
            rel="stylesheet"
            href="/dashboard/assets/libs/bootstrap/css/bootstrap.min.css"
        >
        <link rel="stylesheet" href="{{ asset('dashboard/assets/css/styles.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/css/icons.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/libs/node-waves/waves.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/libs/simplebar/simplebar.min.css') }}">
        <link rel="stylesheet" href="{{ asset('dashboard/assets/libs/swiper/swiper-bundle.min.css') }}">
        <link rel="stylesheet" href="{{ asset('css/admin-dashboard.css') }}">

        @vite(['resources/js/app.js'])
    </head>
    <body>
        <div id="app"></div>

        <script src="{{ asset('dashboard/assets/libs/@popperjs/core/umd/popper.min.js') }}"></script>
        <script src="{{ asset('dashboard/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
        <script src="{{ asset('dashboard/assets/js/main.js') }}"></script>
        <script>
            (function () {
                var isRtl = document.documentElement.getAttribute('dir') === 'rtl';
                var link = document.getElementById('style');

                if (link) {
                    link.href = isRtl
                        ? '/dashboard/assets/libs/bootstrap/css/bootstrap.rtl.min.css'
                        : '/dashboard/assets/libs/bootstrap/css/bootstrap.min.css';
                }
            })();
        </script>
        <script src="{{ asset('dashboard/assets/libs/swiper/swiper-bundle.min.js') }}"></script>
    </body>
</html>
