#!/usr/bin/env bash
# Activa el módulo «llms» (sirve /llms.txt generado) en una web.
# Uso:  tools/activar-llms.sh [--seco]     (ejecutar en la carpeta de WordPress)
#   --seco  imprime valor actual → nuevo sin escribir nada.
# Causa que corrige: /llms.txt da 404 porque el módulo está apagado en la lista
#   guardada (option estrategia_web_modules). Issue #16.
# Cómo se deshace: wp option update estrategia_web_modules "$(cat ~/copias/estrategia_web_modules.<fecha>.json)" --format=json
#   (o apagar «llms.txt» en Estrategia Web → Módulos), y wp sg purge.
set -euo pipefail
SECO=0; [ "${1:-}" = "--seco" ] && SECO=1
ACTUAL=$(wp option get estrategia_web_modules --format=json 2>/dev/null || echo '[]')
NUEVO=$(printf '%s' "$ACTUAL" | php -r '$a=json_decode(stream_get_contents(STDIN),true); $a=is_array($a)?$a:[]; if(!in_array("llms",$a,true)) $a[]="llms"; echo json_encode(array_values($a));')
echo "actual: $ACTUAL"; echo "nuevo:  $NUEVO"
[ "$SECO" = 1 ] && exit 0
[ "$ACTUAL" = "$NUEVO" ] && { echo "ya activo"; exit 0; }
mkdir -p ~/copias
printf '%s' "$ACTUAL" > ~/copias/estrategia_web_modules.$(date +%Y%m%d%H%M%S).json || exit 1
printf '%s' "$NUEVO" | wp option update estrategia_web_modules --format=json
wp sg purge || true
