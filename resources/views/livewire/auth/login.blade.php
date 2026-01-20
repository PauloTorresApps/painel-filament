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
            width: 56px;
            height: 56px;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 12px;
            color: var(--auth-primary);
            margin-bottom: 24px;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }

        .auth-logo-area svg {
            width: 32px;
            height: 32px;
        }

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
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
        </div>
        <h1 class="auth-title">Acesso ao Sistema</h1>
        <p class="auth-subtitle">Faca login para gerenciar processos e contratos.</p>
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
