<?php

namespace App\Domain\Reportes;

/**
 * M16 · Marcador de autorización de reportes. Los reportes no tienen tabla propia
 * (son lectura agregada del núcleo), pero la matriz Fase 7 exige una policy; este
 * marcador es el "sujeto" contra el que se resuelve ReportePolicy (registrada en
 * AppServiceProvider vía Gate::policy). No es un modelo Eloquent.
 */
final class Reporte {}
