<?php

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Log;

/**
 * Serviço centralizado para envio de notificações
 * 
 * Encapsula a lógica de notificação de usuários via Filament,
 * eliminando duplicação em múltiplos Jobs.
 */
class NotificationService
{
    /**
     * Envia uma notificação para um usuário
     *
     * @param User|null $user Usuário destinatário (null = ignora notificação)
     * @param string $title Título da notificação
     * @param string $body Corpo da notificação
     * @param string $status Status (info, success, warning, danger)
     * @return void
     */
    public static function send(
        ?User $user,
        string $title,
        string $body,
        string $status = 'info'
    ): void {
        if (!$user) {
            Log::debug('NotificationService: Usuário nulo, notificação ignorada', [
                'title' => $title,
            ]);
            return;
        }

        try {
            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->status($status)
                ->sendToDatabase($user);

            Log::debug('NotificationService: Notificação enviada', [
                'user_id' => $user->id,
                'title' => $title,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            Log::warning('NotificationService: Erro ao enviar notificação', [
                'user_id' => $user?->id,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envia uma notificação de sucesso
     */
    public static function success(?User $user, string $title, string $body): void
    {
        self::send($user, $title, $body, 'success');
    }

    /**
     * Envia uma notificação de erro
     */
    public static function error(?User $user, string $title, string $body): void
    {
        self::send($user, $title, $body, 'danger');
    }

    /**
     * Envia uma notificação de aviso
     */
    public static function warning(?User $user, string $title, string $body): void
    {
        self::send($user, $title, $body, 'warning');
    }

    /**
     * Envia uma notificação informativa
     */
    public static function info(?User $user, string $title, string $body): void
    {
        self::send($user, $title, $body, 'info');
    }
}
