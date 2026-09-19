@include('dashboard.shell', [
    'titlePrefix' => 'Admin',
    'viteEntries' => ['resources/js/apps/admin/app.js'],
    'readyClass' => 'admin-app-ready',
    'branding' => $branding,
    'appName' => $appName,
    'defaultDashboardLocale' => $defaultDashboardLocale,
    'dashboardTheme' => $dashboardTheme,
])
