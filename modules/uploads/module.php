<?php
/**
 * Module Name: Image uploads
 * Description: Phone photos can be uploaded (up to 20 MB by default); WordPress scales them down to 2560 px and only the scaled copy is kept on disk.
 * Default: on
 * Requires:
 *
 * Sustituye al MU-plugin de Lavandería wp-content/mu-plugins/estrategia-web-subidas.php
 * (12-10-2026), con lo mismo de serie: límite de subida 20 MB, umbral de
 * reducción 2560 px (el big_image_size_threshold de WordPress) y borrar el
 * original gigante cuando WordPress lo ha reducido. Los tres valores se
 * cambian en «Ajustes → Medios → Subidas de imágenes». Qué hace cada uno y
 * sus trampas, en includes/class-uploads.php.
 *
 * Por qué nace encendido: sustituye a lo que YA hacía la web que lo usa
 * (Lavandería) y no cambia nada visible en el front. Al desplegarlo en una
 * web que ya tenía la lista de módulos guardada se enciende solo (caso
 * frontera de includes/class-modules.php). AL PUBLICARLO EN LAVANDERÍA, EL
 * DESPLEGADOR BORRA EL MU-PLUGIN: los dos a la vez no rompen nada (el
 * límite se queda en el menor y el original ya está borrado cuando llega
 * el segundo), pero es el mismo trabajo hecho dos veces y dos sitios donde
 * mirar si algo falla.
 *
 * Cómo se mantiene:
 * - Quién: quien toque las subidas. Cuándo: si WordPress cambia su umbral
 *   de serie (hoy 2560) se cambia DEFAULTS en la clase, no aquí.
 * - Criterio: un ajuste más entra aquí si cambia CÓMO se guarda lo que se
 *   sube (tamaños, formato, límite). Lo que cambia cómo se SIRVE una foto
 *   (sizes, carga diferida) es de quien la pinta (el carrusel, el tema).
 * - Caso frontera, con nombre: el WebP (módulo webp). Con él, un JPEG
 *   PEQUEÑO, que WordPress no reduce, también se queda con original_image
 *   (el .jpg subido, porque el principal pasa a .webp), y ese .jpg también
 *   se borra: es lo que hacía el MU-plugin en producción. Si se quiere
 *   conservar, se apaga «Borrar el original» (o el filtro
 *   estrategia_web_uploads_delete_original).
 * - La trampa: la prueba del módulo webp espera el .jpg como
 *   original_image; con este módulo encendido ya no existe. Esa prueba
 *   apaga el borrado al empezar (ver modules/webp/tests/webp.test.mjs).
 * - Pruebas: modules/uploads/tests/ (nivel 1, los valores; nivel 2, una
 *   subida de verdad).
 */

namespace EstrategiaWeb\Uploads;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-uploads.php';

Uploads::boot();
