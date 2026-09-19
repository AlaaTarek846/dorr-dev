@include('dashboard.shell', [
    'titlePrefix' => 'Provider',
    'viteEntries' => ['resources/js/apps/provider/provider-app.js'],
    'readyClass' => 'provider-app-ready',
    'branding' => $branding,
    'appName' => $appName,
    'defaultDashboardLocale' => $defaultDashboardLocale,
    'dashboardTheme' => $dashboardTheme,
])
