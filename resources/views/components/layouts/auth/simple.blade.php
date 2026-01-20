<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>
            /* Variáveis para tema escuro (padrão) */
            :root {
                --auth-bg-body: #0f172a;
                --auth-bg-card: #1e293b;
                --auth-primary: #3b82f6;
                --auth-primary-hover: #2563eb;
                --auth-text-main: #f8fafc;
                --auth-text-muted: #94a3b8;
                --auth-border: #334155;
                --auth-input-bg: #0f172a;
            }

            /* Variáveis para tema claro */
            html:not(.dark) {
                --auth-bg-body: #f1f5f9;
                --auth-bg-card: #ffffff;
                --auth-primary: #3b82f6;
                --auth-primary-hover: #2563eb;
                --auth-text-main: #1e293b;
                --auth-text-muted: #64748b;
                --auth-border: #e2e8f0;
                --auth-input-bg: #f8fafc;
            }

            .auth-body {
                background-color: var(--auth-bg-body);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }

            html.dark .auth-body {
                background-image: radial-gradient(at center top, #1e293b 0%, #0f172a 80%);
            }

            html:not(.dark) .auth-body {
                background-image: radial-gradient(at center top, #e2e8f0 0%, #f1f5f9 80%);
            }

            .auth-container {
                background-color: var(--auth-bg-card);
                width: 100%;
                max-width: 440px;
                padding: 48px 40px;
                border-radius: 12px;
                border: 1px solid var(--auth-border);
                animation: authFadeIn 0.6s ease-out;
            }

            html.dark .auth-container {
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            }

            html:not(.dark) .auth-container {
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
            }

            @keyframes authFadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
        </style>
    </head>
    <body class="auth-body antialiased">
        <main class="auth-container">
            {{ $slot }}
        </main>

        <x-loading />

        @fluxScripts
    </body>
</html>
