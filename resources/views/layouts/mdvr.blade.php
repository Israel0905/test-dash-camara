<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'MDVR Dashboard')</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Heroicons -->
    <script src="https://unpkg.com/@heroicons/vue@2.0.18/dist/cjs/index.js"></script>

    <style>
        body {
            font-family: 'Inter', sans-serif;
        }

        .sidebar {
            width: 260px;
        }

        .main-content {
            margin-left: 260px;
        }

        @media (max-width: 768px) {
            .sidebar {
                display: none;
            }

            .main-content {
                margin-left: 0;
            }
        }
    </style>

    @stack('styles')
</head>

<body class="bg-gray-100">
    <!-- Sidebar -->
    <aside class="sidebar fixed top-0 left-0 h-full bg-gray-900 text-white z-50">
        <div class="p-6">
            <h1 class="text-xl font-bold flex items-center gap-2">
                <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                    </path>
                </svg>
                MDVR Monitor
            </h1>
        </div>

        <nav class="mt-6">
            <a href="{{ route('mdvr.dashboard') }}"
                class="flex items-center gap-3 px-6 py-3 hover:bg-gray-800 transition {{ request()->routeIs('mdvr.dashboard') ? 'bg-gray-800 border-l-4 border-blue-500' : '' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z">
                    </path>
                </svg>
                Dashboard
            </a>

            <a href="{{ route('mdvr.devices') }}"
                class="flex items-center gap-3 px-6 py-3 hover:bg-gray-800 transition {{ request()->routeIs('mdvr.devices*') ? 'bg-gray-800 border-l-4 border-blue-500' : '' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z">
                    </path>
                </svg>
                Dispositivos
            </a>

            <a href="{{ route('mdvr.map') }}"
                class="flex items-center gap-3 px-6 py-3 hover:bg-gray-800 transition {{ request()->routeIs('mdvr.map') ? 'bg-gray-800 border-l-4 border-blue-500' : '' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l5.447 2.724A1 1 0 0021 18.382V7.618a1 1 0 00-1.447-.894L15 4m0 13V4m0 0L9 7">
                    </path>
                </svg>
                Mapa
            </a>

            <a href="{{ route('mdvr.alarms') }}"
                class="flex items-center gap-3 px-6 py-3 hover:bg-gray-800 transition {{ request()->routeIs('mdvr.alarms*') ? 'bg-gray-800 border-l-4 border-blue-500' : '' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z">
                    </path>
                </svg>
                Alarmas
            </a>

            <a href="{{ route('mdvr.monitoring') }}"
                class="flex items-center gap-3 px-6 py-3 hover:bg-gray-800 transition {{ request()->routeIs('mdvr.monitoring') ? 'bg-gray-800 border-l-4 border-blue-500' : '' }}">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                    </path>
                </svg>
                Monitor en Vivo
            </a>
        </nav>

        <div class="absolute bottom-0 left-0 right-0 p-6 border-t border-gray-700">
            <div class="text-sm text-gray-400">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                    Servidor Activo
                </div>
                <div class="mt-2 text-xs">Puerto: 8808</div>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content min-h-screen">
        <!-- Top Bar -->
        <header class="bg-white shadow-sm border-b px-6 py-4">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800">@yield('header', 'Dashboard')</h2>
                <div class="flex items-center gap-4">
                    <span class="text-sm text-gray-500" id="current-time"></span>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="p-6">
            @yield('content')
        </div>
    </main>

    <script>
        // Update time
        function updateTime() {
            const now = new Date();
            document.getElementById('current-time').textContent = now.toLocaleString('es-MX');
        }
        updateTime();
        setInterval(updateTime, 1000);
    </script>

    @stack('scripts')
</body>

</html>
