<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * Cache key prefix
     */
    protected static string $cachePrefix = 'settings_';

    /**
     * Cache TTL em segundos (1 hora)
     */
    protected static int $cacheTtl = 3600;

    /**
     * Obtém o valor de uma configuração
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::$cachePrefix . $key;

        return Cache::remember($cacheKey, self::$cacheTtl, function () use ($key, $default) {
            $setting = self::where('key', $key)->first();

            if (!$setting) {
                return $default;
            }

            return self::castValue($setting->value, $setting->type);
        });
    }

    /**
     * Define o valor de uma configuração
     */
    public static function set(string $key, mixed $value, ?string $type = null, ?string $group = null, ?string $description = null): self
    {
        $setting = self::updateOrCreate(
            ['key' => $key],
            array_filter([
                'value' => is_bool($value) ? ($value ? 'true' : 'false') : (string) $value,
                'type' => $type,
                'group' => $group,
                'description' => $description,
            ], fn($v) => $v !== null)
        );

        // Limpa o cache
        Cache::forget(self::$cachePrefix . $key);

        return $setting;
    }

    /**
     * Verifica se debug de arquivos de análise está ativo
     */
    public static function isDebugAnalysisFilesEnabled(): bool
    {
        return (bool) self::get('debug_save_analysis_files', false);
    }

    /**
     * Converte o valor para o tipo correto
     */
    protected static function castValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'json' => json_decode($value, true),
            default => $value,
        };
    }

}
