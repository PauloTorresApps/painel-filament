<x-layouts.auth>
    <style>
        .auth-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .auth-logo-area {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }

        .auth-logo-area svg {
            width: 72px;
            height: 72px;
        }

        /* Animações do logo */
        @keyframes scanMove {
            0%, 100% { transform: translateY(-12px); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(20px); opacity: 0; }
        }

        @keyframes eyePulse {
            0%, 100% { transform: scale(1); opacity: 0.9; }
            50% { transform: scale(1.1); opacity: 1; }
        }

        @keyframes eyeGlow {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 0.6; }
        }

        .scan-line {
            animation: scanMove 2.5s ease-in-out infinite;
        }

        .ai-eye {
            animation: eyePulse 2s ease-in-out infinite;
        }

        .eye-glow {
            animation: eyeGlow 2s ease-in-out infinite;
        }

        /* Cores para tema escuro */
        .logo-doc-bg { fill: #1e293b; }
        .logo-doc-border { stroke: #3b82f6; }
        .logo-fold { fill: #334155; stroke: #3b82f6; }
        .logo-section-symbol { fill: #94a3b8; }
        .logo-scan-line { stroke: #22d3ee; }
        .logo-scan-glow { fill: url(#scanGradient); }
        .logo-eye-outer { fill: #0f172a; stroke: #3b82f6; }
        .logo-eye-iris { fill: #3b82f6; }
        .logo-eye-pupil { fill: #0f172a; }
        .logo-eye-highlight { fill: #ffffff; }
        .logo-eye-glow { fill: #3b82f6; }

        /* Cores para tema claro */
        html:not(.dark) .logo-doc-bg { fill: #f8fafc; }
        html:not(.dark) .logo-doc-border { stroke: #3b82f6; }
        html:not(.dark) .logo-fold { fill: #e2e8f0; stroke: #3b82f6; }
        html:not(.dark) .logo-section-symbol { fill: #64748b; }
        html:not(.dark) .logo-eye-outer { fill: #ffffff; stroke: #3b82f6; }
        html:not(.dark) .logo-eye-pupil { fill: #1e293b; }

        .auth-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
            letter-spacing: -0.025em;
            color: var(--auth-text-main);
        }

        .auth-subtitle {
            color: var(--auth-text-muted);
            font-size: 0.875rem;
            line-height: 1.5;
        }

        .auth-form-group {
            margin-bottom: 20px;
        }

        .auth-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--auth-text-main);
        }

        .auth-input {
            width: 100%;
            padding: 12px 16px;
            background-color: var(--auth-input-bg);
            border: 1px solid var(--auth-border);
            border-radius: 8px;
            color: var(--auth-text-main);
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .auth-input:focus {
            border-color: var(--auth-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
            outline: none;
        }

        .auth-input::placeholder {
            color: var(--auth-text-muted);
            opacity: 0.7;
        }

        .auth-input-wrapper {
            position: relative;
        }

        .auth-toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--auth-text-muted);
            cursor: pointer;
            background: none;
            border: none;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .auth-toggle-password:hover {
            color: var(--auth-text-main);
        }

        .auth-toggle-password svg {
            width: 20px;
            height: 20px;
        }

        .auth-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            font-size: 0.875rem;
        }

        .auth-checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .auth-checkbox-wrapper input {
            accent-color: var(--auth-primary);
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .auth-checkbox-wrapper span {
            color: var(--auth-text-muted);
        }

        .auth-forgot-link {
            color: var(--auth-primary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .auth-forgot-link:hover {
            color: var(--auth-primary-hover);
            text-decoration: underline;
        }

        .auth-btn-submit {
            width: 100%;
            padding: 12px;
            background-color: var(--auth-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .auth-btn-submit:hover {
            background-color: var(--auth-primary-hover);
        }

        .auth-btn-submit:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .auth-footer {
            margin-top: 32px;
            text-align: center;
            font-size: 0.875rem;
            color: var(--auth-text-muted);
        }

        .auth-footer a {
            color: var(--auth-text-main);
            text-decoration: none;
            font-weight: 600;
            margin-left: 4px;
        }

        .auth-footer a:hover {
            color: var(--auth-primary);
        }

        .auth-error {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 4px;
        }

        .auth-status {
            padding: 12px 16px;
            background-color: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 8px;
            color: #22c55e;
            font-size: 0.875rem;
            margin-bottom: 24px;
            text-align: center;
        }

        .auth-label-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
        }
    </style>

    <header class="auth-header">
        <div class="auth-logo-area">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">
                <defs>
                    <linearGradient id="scanGradient" x1="0%" y1="0%" x2="0%" y2="100%">
                        <stop offset="0%" stop-color="#22d3ee" stop-opacity="0"/>
                        <stop offset="50%" stop-color="#22d3ee" stop-opacity="0.8"/>
                        <stop offset="100%" stop-color="#22d3ee" stop-opacity="0"/>
                    </linearGradient>
                </defs>

                <!-- Documento base -->
                <path class="logo-doc-bg logo-doc-border" d="M12 4 L44 4 L52 12 L52 56 C52 58 50 60 48 60 L16 60 C14 60 12 58 12 56 Z" stroke-width="2"/>

                <!-- Dobra do canto -->
                <path class="logo-fold" d="M44 4 L44 12 L52 12 Z" stroke-width="1"/>

                <!-- Símbolo § centralizado -->
                <text x="32" y="38" text-anchor="middle" class="logo-section-symbol" style="font-family: 'Times New Roman', serif; font-size: 24px; font-weight: bold;">§</text>

                <!-- Linha de scan animada -->
                <g class="scan-line">
                    <rect class="logo-scan-glow" x="16" y="20" width="32" height="4" rx="2"/>
                    <line class="logo-scan-line" x1="16" y1="22" x2="48" y2="22" stroke-width="2" stroke-linecap="round"/>
                </g>

                <!-- Indicador de IA (olho) no canto -->
                <g class="ai-eye" transform="translate(44, 8)">
                    <circle class="logo-eye-outer" cx="6" cy="6" r="5" stroke-width="1.5"/>
                    <circle class="logo-eye-iris" cx="6" cy="6" r="3"/>
                    <circle class="logo-eye-pupil" cx="6" cy="6" r="1.5"/>
                    <circle class="logo-eye-highlight" cx="4.5" cy="4.5" r="0.8" opacity="0.8"/>
                </g>

                <!-- Brilho do olho -->
                <circle class="eye-glow logo-eye-glow" cx="50" cy="14" r="8" opacity="0.3"/>
            </svg>
        </div>
        <h1 class="auth-title">Acesso ao Sistema</h1>
        <p class="auth-subtitle">Faça login para gerenciar processos e contratos.</p>
    </header>

    <!-- Session Status -->
    @if (session('status'))
        <div class="auth-status">
            {{ session('status') }}
        </div>
    @endif

    <form method="POST" action="{{ route('login.store') }}">
        @csrf

        <!-- Email Address -->
        <div class="auth-form-group">
            <label for="email" class="auth-label">E-mail corporativo</label>
            <div class="auth-input-wrapper">
                <input
                    type="email"
                    id="email"
                    name="email"
                    class="auth-input"
                    placeholder="email@exemplo.com"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="email"
                >
            </div>
            @error('email')
                <p class="auth-error">{{ $message }}</p>
            @enderror
        </div>

        <!-- Password -->
        <div class="auth-form-group">
            <label for="password" class="auth-label">Senha</label>
            <div class="auth-input-wrapper">
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="auth-input"
                    placeholder="Digite sua senha"
                    required
                    autocomplete="current-password"
                    style="padding-right: 44px;"
                >
                <button type="button" class="auth-toggle-password" aria-label="Mostrar senha" onclick="togglePasswordVisibility()">
                    <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                    <svg id="eye-off-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="display: none;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                    </svg>
                </button>
            </div>
            @error('password')
                <p class="auth-error">{{ $message }}</p>
            @enderror
        </div>

        <!-- Actions Row -->
        <div class="auth-actions">
            <label class="auth-checkbox-wrapper">
                <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}>
                <span>Lembrar de mim</span>
            </label>
            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="auth-forgot-link">Esqueceu a senha?</a>
            @endif
        </div>

        <button type="submit" class="auth-btn-submit">
            Acessar Plataforma
        </button>
    </form>

    @if (Route::has('register'))
        <div class="auth-footer">
            <p>Ainda nao tem acesso? <a href="{{ route('register') }}">Solicitar cadastro</a></p>
        </div>
    @endif

    <script>
        function togglePasswordVisibility() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            const eyeOffIcon = document.getElementById('eye-off-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.style.display = 'none';
                eyeOffIcon.style.display = 'block';
            } else {
                passwordInput.type = 'password';
                eyeIcon.style.display = 'block';
                eyeOffIcon.style.display = 'none';
            }
        }
    </script>
</x-layouts.auth>
