<?php

namespace App\Evaluation\Support;

class EvaluationConfig
{
    public static function get(string $key, mixed $default = null): mixed
    {
        if (function_exists('app')) {
            try {
                $app = app();
                if (method_exists($app, 'bound') && $app->bound('config')) {
                    return config($key, $default);
                }
            } catch (\Throwable) {
                return $default;
            }
        }

        return $default;
    }
}
