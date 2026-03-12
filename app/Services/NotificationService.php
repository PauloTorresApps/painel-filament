<?php

namespace App\Services;

use App\Models\User;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\StatusCode;

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
        $span = null;
        $scope = null;

        if (class_exists(Globals::class)) {
            try {
                $tracer = Globals::tracerProvider()->getTracer('painel-laravel-notification');
                $span = $tracer->spanBuilder('notification.send')->startSpan();
                $scope = $span->activate();
            } catch (\Throwable) {
                $span = null;
                $scope = null;
            }
        }

        $metrics = app(OtelMetricsService::class);

        if ($span !== null) {
            $span->setAttribute('notification.status', $status);
            $span->setAttribute('notification.title', $title);
            $span->setAttribute('notification.has_user', $user !== null);
        }

        if (!$user) {
            try {
                $metrics->recordNotification('ignored', false);
            } catch (\Throwable) {
            }

            if ($span !== null) {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            if ($scope !== null) {
                $scope->detach();
            }

            if ($span !== null) {
                $span->end();
            }

            Log::debug('NotificationService: Usuário nulo, notificação ignorada', [
                'title' => $title,
            ]);
            return;
        }

        if ($span !== null) {
            $span->setAttribute('app.user_id', $user->id);
        }

        try {
            FilamentNotification::make()
                ->title($title)
                ->body($body)
                ->status($status)
                ->sendToDatabase($user);

            try {
                $metrics->recordNotification($status, true);
            } catch (\Throwable) {
            }

            if ($span !== null) {
                $span->setStatus(StatusCode::STATUS_OK);
            }

            Log::debug('NotificationService: Notificação enviada', [
                'user_id' => $user->id,
                'title' => $title,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            try {
                $metrics->recordNotification('failed', true);
            } catch (\Throwable) {
            }

            if ($span !== null) {
                $span->recordException($e);
                $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            }

            Log::warning('NotificationService: Erro ao enviar notificação', [
                'user_id' => $user?->id,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($scope !== null) {
                $scope->detach();
            }

            if ($span !== null) {
                $span->end();
            }
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
     * Envia uma notificação informativa
     */
    public static function info(?User $user, string $title, string $body): void
    {
        self::send($user, $title, $body, 'info');
    }
}
