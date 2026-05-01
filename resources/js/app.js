/**
 * Sistema de Loading Global
 * Gerencia indicadores visuais de carregamento para toda a aplicação
 */

class LoadingManager {
    constructor() {
        this.loadingElement = null;
        this.activeRequests = 0;
        this.init();
    }

    init() {
        // Aguarda o DOM estar pronto
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => this.setup());
        } else {
            this.setup();
        }
    }

    setup() {
        this.loadingElement = document.getElementById('global-loading');

        if (!this.loadingElement) {
            console.warn('Elemento de loading não encontrado');
            return;
        }

        this.setupFormListeners();
        this.setupLivewireListeners();
        this.setupFetchInterceptor();
        this.setupXHRInterceptor();
    }

    /**
     * Mostra o loading
     */
    show(message = 'Carregando...') {
        if (!this.loadingElement) return;

        this.activeRequests++;

        const textElement = this.loadingElement.querySelector('.loading-text');
        if (textElement) {
            textElement.textContent = message;
        }

        this.loadingElement.classList.add('active');
    }

    /**
     * Esconde o loading
     */
    hide() {
        if (!this.loadingElement) return;

        this.activeRequests--;

        // Só esconde se não houver mais requisições ativas
        if (this.activeRequests <= 0) {
            this.activeRequests = 0;
            this.loadingElement.classList.remove('active');
        }
    }

    /**
     * Força esconder o loading
     */
    forceHide() {
        if (!this.loadingElement) return;

        this.activeRequests = 0;
        this.loadingElement.classList.remove('active');
    }

    /**
     * Configura listeners para formulários tradicionais
     */
    setupFormListeners() {
        document.addEventListener('submit', (e) => {
            // Ignora formulários Livewire
            if (e.target.hasAttribute('wire:submit') ||
                e.target.hasAttribute('wire:submit.prevent')) {
                return;
            }

            this.show('Enviando dados...');
        });

        // Esconde loading quando a página é carregada após submit
        window.addEventListener('pageshow', (e) => {
            // Se a página foi restaurada do cache (back/forward)
            if (e.persisted) {
                this.forceHide();
            }
        });
    }

    /**
     * Configura listeners para Livewire
     */
    setupLivewireListeners() {
        // Livewire 3.x
        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({ options }) => {
                this.show('Processando...');
            });

            Livewire.hook('response', ({ response }) => {
                this.hide();
            });

            Livewire.hook('exception', ({ message }) => {
                this.forceHide();
            });
        });

        // Navigate events (Livewire Wire Navigate)
        document.addEventListener('livewire:navigate', () => {
            this.show('Navegando...');
        });

        document.addEventListener('livewire:navigated', () => {
            this.forceHide();
        });

        document.addEventListener('livewire:navigating', () => {
            this.show('Navegando...');
        });
    }

    /**
     * Intercepta chamadas fetch
     */
    setupFetchInterceptor() {
        const originalFetch = window.fetch;
        const self = this;

        window.fetch = function(...args) {
            self.show('Carregando dados...');

            return originalFetch.apply(this, args)
                .then(response => {
                    self.hide();
                    return response;
                })
                .catch(error => {
                    self.hide();
                    throw error;
                });
        };
    }

    /**
     * Intercepta chamadas XMLHttpRequest
     */
    setupXHRInterceptor() {
        const self = this;
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function(...args) {
            this._url = args[1];
            return originalOpen.apply(this, args);
        };

        XMLHttpRequest.prototype.send = function(...args) {
            // Ignora requisições do Livewire (já tratadas)
            if (this._url && !this._url.includes('/livewire/')) {
                self.show('Carregando dados...');

                this.addEventListener('loadend', () => {
                    self.hide();
                });

                this.addEventListener('error', () => {
                    self.hide();
                });

                this.addEventListener('abort', () => {
                    self.hide();
                });
            }

            return originalSend.apply(this, args);
        };
    }
}

// Inicializa o gerenciador de loading
const loadingManager = new LoadingManager();

// Exporta para uso global
window.loadingManager = loadingManager;

// Funções globais de conveniência
window.showLoading = (message) => loadingManager.show(message);
window.hideLoading = () => loadingManager.hide();
window.forceHideLoading = () => loadingManager.forceHide();

// Previne loading infinito em casos de erro
window.addEventListener('error', () => {
    setTimeout(() => loadingManager.forceHide(), 1000);
});

window.addEventListener('unhandledrejection', () => {
    setTimeout(() => loadingManager.forceHide(), 1000);
});

console.log('Sistema de Loading inicializado ');
