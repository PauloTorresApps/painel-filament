## Se clonou este projeto, execute os comandos a baixo para ativar as funcionalidades de perfis e permissões

Em ambiente Docker, execute os comandos dentro do container `app`:

```bash
    docker compose exec -T app php artisan db:seed
```
    ou, caso queria executar apenas um seeder específico
```bash
    docker compose exec -T app php artisan db:seed --class=PermissionSeeder
```

Se estiver executando o Laravel fora do Docker, ajuste o `.env` para um host acessível localmente (por exemplo `DB_HOST=127.0.0.1`) antes de rodar `php artisan db:seed`.

## Outra alternativa é executar os comandos a seguir no terminal
```bash
→ php artisan migrate
→ php artisan vendor:publish --tag=filament-config
→ php artisan permission:create-permission access_admin
→ php artisan permission:create-role Admin
→ php artisan permission:create-role Admin web "access_admin"
→ php artisan permission:show
```
