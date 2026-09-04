#!/bin/bash
set -e

# Si el archivo composer.json existe pero no la carpeta vendor, instala las dependencias
if [ -f "composer.json" ] && [ ! -d "vendor" ]; then
    echo "📦 Instalando dependencias de Slim internamente..."
    composer install --no-interaction --optimize-autoloader
fi

# Continuar con el comando por defecto (levantar el servidor de PHP)
exec "$@"
