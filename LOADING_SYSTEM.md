# Sistema de Loading Global

## Visão Geral

Sistema de loading visual implementado para melhorar a experiência do usuário durante operações assíncronas, requisições e processamentos.

## Características

- **Loading automático** para formulários tradicionais
- **Integração com Livewire** (requisições e navegação)
- **Interceptação de requisições** (Fetch e XMLHttpRequest)
- **Animação moderna** com múltiplos anéis coloridos
- **Suporte a tema escuro**
- **Mensagens customizáveis**

## Uso Automático

O loading é ativado automaticamente nas seguintes situações:

### 1. Formulários HTML Tradicionais
```html
<form action="/rota" method="POST">
    @csrf
    <!-- O loading será exibido automaticamente ao submeter -->
    <button type="submit">Enviar</button>
</form>
```

### 2. Requisições Livewire
```html
<div wire:click="processar">
    <!-- Loading automático em métodos Livewire -->
    Processar
</div>
```

### 3. Navegação com Wire Navigate
```html
<a href="/pagina" wire:navigate>
    <!-- Loading automático na navegação -->
    Ir para página
</a>
```

### 4. Requisições AJAX/Fetch
```javascript
// Loading automático em requisições fetch
fetch('/api/dados')
    .then(response => response.json())
    .then(data => console.log(data));

// Loading automático em XMLHttpRequest
const xhr = new XMLHttpRequest();
xhr.open('GET', '/api/dados');
xhr.send();
```

## Uso Manual

Você pode controlar o loading manualmente quando necessário:

### Mostrar Loading
```javascript
// Com mensagem padrão
window.showLoading();

// Com mensagem customizada
window.showLoading('Processando pagamento...');
```

### Esconder Loading
```javascript
// Esconde respeitando outras requisições ativas
window.hideLoading();

// Força o fechamento imediato
window.forceHideLoading();
```

### Exemplo Completo
```javascript
async function processarDados() {
    try {
        window.showLoading('Processando dados...');

        const resultado = await minhaFuncaoAssincrona();

        window.showLoading('Salvando...');
        await salvarResultado(resultado);

        window.hideLoading();
        alert('Processamento concluído!');
    } catch (error) {
        window.forceHideLoading();
        console.error('Erro:', error);
    }
}
```

## Customização

### Alterar Mensagem do Loading
Edite o arquivo [resources/views/components/loading.blade.php](resources/views/components/loading.blade.php):

```blade
<p class="loading-text">Sua mensagem aqui...</p>
```

### Alterar Cores dos Anéis
Edite o arquivo [resources/css/app.css](resources/css/app.css):

```css
.spinner-ring:nth-child(1) {
    border-top-color: #sua-cor-1; /* Azul por padrão */
}
.spinner-ring:nth-child(2) {
    border-top-color: #sua-cor-2; /* Roxo por padrão */
}
.spinner-ring:nth-child(3) {
    border-top-color: #sua-cor-3; /* Rosa por padrão */
}
.spinner-ring:nth-child(4) {
    border-top-color: #sua-cor-4; /* Ciano por padrão */
}
```

### Alterar Tamanho do Spinner
```css
.loading-spinner {
    width: 100px;  /* padrão: 80px */
    height: 100px; /* padrão: 80px */
}
```

### Alterar Velocidade da Animação
```css
.spinner-ring {
    animation: spin 2s cubic-bezier(0.5, 0, 0.5, 1) infinite; /* padrão: 1.5s */
}
```

## Arquivos do Sistema

1. **[resources/views/components/loading.blade.php](resources/views/components/loading.blade.php)** - Componente HTML do loading
2. **[resources/js/app.js](resources/js/app.js)** - Lógica JavaScript
3. **[resources/css/app.css](resources/css/app.css)** - Estilos e animações

## Integração nos Layouts

O componente está integrado nos seguintes layouts:
- [resources/views/components/layouts/app/sidebar.blade.php](resources/views/components/layouts/app/sidebar.blade.php) (linha 143)
- [resources/views/components/layouts/auth/simple.blade.php](resources/views/components/layouts/auth/simple.blade.php) (linha 21)

## API JavaScript

### LoadingManager

```javascript
// Acesso direto ao gerenciador
window.loadingManager.show('Mensagem');
window.loadingManager.hide();
window.loadingManager.forceHide();

// Verificar número de requisições ativas
console.log(window.loadingManager.activeRequests);
```

## Tratamento de Erros

O sistema possui proteção contra loading infinito:
- Erros JavaScript forçam o fechamento após 1 segundo
- Promises rejeitadas forçam o fechamento após 1 segundo
- Navegação com back/forward limpa o estado do loading

## Compatibilidade

- ✅ Livewire 3.x
- ✅ Formulários HTML tradicionais
- ✅ Fetch API
- ✅ XMLHttpRequest
- ✅ Wire Navigate
- ✅ Navegação SPA

## Troubleshooting

### Loading não aparece
1. Verifique se o JavaScript foi compilado: `npm run build`
2. Verifique o console do navegador por erros
3. Confirme que o elemento `#global-loading` existe no DOM

### Loading não desaparece
```javascript
// Force o fechamento no console do navegador
window.forceHideLoading();
```

### Loading em requisições específicas
Se precisar desabilitar o loading em alguma requisição específica:

```javascript
// Desabilitar temporariamente
const tempManager = window.loadingManager;
window.loadingManager = { show: () => {}, hide: () => {} };

// Sua requisição aqui
await minhaRequisicao();

// Restaurar
window.loadingManager = tempManager;
```

## Próximos Passos

Possíveis melhorias futuras:
- [ ] Adicionar diferentes estilos de loading (dots, bars, etc)
- [ ] Suporte a loading por componente/região
- [ ] Indicador de progresso percentual
- [ ] Fila de mensagens sequenciais
