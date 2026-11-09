<?php
// Configuración de Menú de la Casa. Cambiá la clave antes de usarla.
return [
  // La clave que se escribe en la app para entrar. Que sea larga (ej.: tres palabras y un número).
  'clave' => 'CAMBIAR-ESTA-CLAVE',

  // Dónde se guarda la base. Mejor FUERA de la carpeta pública (ej.: '/home/usuario/menu-datos').
  // Si queda adentro (como acá), la carpeta trae un .htaccess que bloquea el acceso en Apache.
  'carpeta_datos' => __DIR__ . '/datos',

  // Cuántas copias diarias guardar (en datos/copias).
  'copias' => 30,

  // Tamaño máximo de la base (10 MB sobra: hoy pesa menos de 1 MB).
  'max_bytes' => 10 * 1024 * 1024,
];
