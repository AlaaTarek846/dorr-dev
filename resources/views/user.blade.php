@include('dashboard.shell', [
    'titlePrefix' => 'User',
    'viteEntries' => ['resources/js/apps/user/user-app.js'],
    'readyClass' => 'user-app-ready',
    'branding' => $branding,
    'appName' => $appName,
    'defaultDashboardLocale' => $defaultDashboardLocale,
    'dashboardTheme' => $dashboardTheme,
])
