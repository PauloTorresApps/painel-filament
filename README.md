## Se clonou este projeto, execute os comandos a baixo para ativar as funcionalidades de perfis e permissões

```bash
    php artisan db:seed
```
    ou, caso queria executar apenas um seeder específico
```bash
    php artisan db:seed --class=PermissionSeeder
```

## Outra alternativa é executar os comandos a seguir no terminal
```bash
→ php artisan migrate
→ php artisan vendor:publish --tag=filament-config
→ php artisan permission:create-permission access_admin
→ php artisan permission:create-role Admin
→ php artisan permission:create-role Admin web "access_admin"
→ php artisan permission:show
```
