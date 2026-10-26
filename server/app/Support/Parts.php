<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Service;

/** Cómo se nombra una parte de una hoja (un servicio de un proyecto) en la API y en los correos. */
class Parts
{
    /** 'P-1 · Nombre'; con el servicio ('P-1 · Nombre · Servicio') solo si el proyecto tiene más de uno. */
    public static function label(Project $project, ?Service $service): string
    {
        $name = self::serviceName($project, $service);
        return $project->code.' · '.$project->name.($name !== null ? ' · '.$name : '');
    }

    /** Nombre del servicio solo si el proyecto tiene más de uno (si no, null). */
    public static function serviceName(Project $project, ?Service $service): ?string
    {
        if (! $service) {
            return null;
        }
        $project->loadMissing('services');
        return $project->services->count() > 1 ? $service->name : null;
    }

    /**
     * Las líneas de "qué cambió" de una hoja se guardan con el servicio al que pertenecen ("[#12] Añadida ..."), para que cada
     * aprobador solo vea los cambios de lo suyo. Una línea sin etiqueta (hojas viejas) la ven todos.
     */
    public static function tag(string $line, array $serviceIds): string
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($serviceIds))));
        return $ids ? '[#'.implode(',', $ids).'] '.$line : $line;
    }

    /** @param array<int>|null $serviceIds servicios que puede ver quien lee (null: todos) */
    public static function changes(?string $summary, ?array $serviceIds = null): array
    {
        $out = [];
        foreach (array_filter(explode("\n", (string) $summary)) as $line) {
            if (preg_match('/^\[#([\d,]+)\] (.*)$/s', $line, $m)) {
                if ($serviceIds !== null && ! array_intersect(array_map('intval', explode(',', $m[1])), $serviceIds)) {
                    continue;
                }
                $line = $m[2];
            }
            $out[] = $line;
        }
        return $out;
    }
}
