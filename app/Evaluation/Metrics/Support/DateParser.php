<?php

namespace App\Evaluation\Metrics\Support;

class DateParser
{
    /**
     * Extrai datas em formatos comuns e normaliza para Y-m-d quando possível.
     */
    public static function extractNormalizedDates(string $text): array
    {
        $dates = [];

        if (preg_match_all('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $dates[] = self::normalizeDate($m[0]);
            }
        }

        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $dates[] = self::normalizeDate($m[0]);
            }
        }

        return array_values(array_unique(array_filter($dates)));
    }

    /**
     * Normaliza datas em dd/mm/yyyy ou yyyy-mm-dd para Y-m-d.
     */
    public static function normalizeDate(string $date): ?string
    {
        $date = trim($date);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $date, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        return null;
    }
}
