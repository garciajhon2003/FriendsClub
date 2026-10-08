# FriendsClub

> Red social para conocer gente a través de sus hobbies: clubs, eventos, publicaciones y mensajes.

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?logo=php&logoColor=white)](https://www.php.net/) [![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com/) [![Filament](https://img.shields.io/badge/Filament-5-FDAE4B)](https://filamentphp.com/) [![Livewire](https://img.shields.io/badge/Livewire-4-FB70A9)](https://livewire.laravel.com/) [![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE.md)

FriendsClub está construido sobre [Liberu Social Network](https://github.com/liberusoftware/social-network-laravel), una plataforma Laravel modular y de código abierto. Este repositorio añade lo propio de FriendsClub (hobbies, clubs con ubicación y horario, la página pública *Explorar*) y las pantallas que hacen usable la red social desde el panel de miembros.

## Qué se puede hacer

Todo esto está en el panel de miembros, en `/app`:

| Sección | Qué permite |
|---|---|
| **Feed** (portada) | Ver lo que publican las personas que sigues y tus amigos; reaccionar, comentar, reportar, ocultar y ordenar por relevancia o por fecha |
| **Publications** | Escribir posts y artículos con menciones, hashtags, enlaces y encuestas; publicar ya, programar o guardar como borrador |
| **Communities** (clubs) | Clubs por hobby, presenciales u online, con ciudad, día, hora y plazas. Los privados se piden y el dueño aprueba o rechaza; el dueño edita su club y expulsa miembros |
| **Events** | Crear eventos, publicarlos, invitar, confirmar asistencia respetando el cupo, avisos a asistentes y recordatorios |
| **Conversations** | Chat privado y en grupo con reacciones, mensajes cifrados y contador de no leídos |
| **Notifications** | Bandeja con marcar como leída, descartar y preferencias (canales, horas de silencio, resumen) |
| **Engagements** | Tu actividad: reacciones, comentarios, compartidos y guardados, con editar y deshacer |
| **Media / Albums** | Subir fotos, vídeos y archivos y organizarlos en álbumes públicos, de amigos o privados |
| **Search** | Buscador con tendencias, por tipo y popularidad |
| **Relationships, Lists, Blocks, Suggestions** | Seguir, amistades, listas de personas, bloqueos y sugerencias |
| **Reports** | Los miembros reportan contenido; los administradores deciden en la cola de moderación |
| **Analytics** | Solo administradores: métricas con cifras, gráfico de evolución y periodos |

Además, la página pública **Explorar** permite buscar clubs por hobby, modalidad, día y distancia.

### Cómo se conecta todo

Las secciones se comunican entre sí a través del módulo `social-network-activity`:

- Al **publicar**, la publicación llega al feed del autor, de sus seguidores y de sus amigos, respetando la audiencia elegida y los bloqueos, y se indexa en el buscador. También las programadas, cuando salen a su hora.
- Al **borrar** una publicación, desaparece de todos los feeds y del buscador.

## Cómo está organizado

El proyecto es un *anfitrión* que compone módulos. Casi todo el código vive en `modules/`, no en `app/`:

```text
modules/
├── social-network-<dominio>            # lógica y datos: acciones, modelos, permisos
├── social-network-<dominio>-filament   # las pantallas del panel /app
├── social-network-<dominio>-livewire   # componentes sueltos para páginas públicas
├── social-network-<dominio>-api        # API HTTP
├── social-network-hobbies*             # el catálogo de hobbies (propio de FriendsClub)
├── social-network-activity             # conecta los módulos entre sí
└── …                                   # módulos de base: identidad, ajustes, búsqueda…
themes/                                 # temas visuales
app/                                    # composición: lo que une módulos que no se conocen
```

Reglas que sigue el código:

- **Las pantallas nunca guardan nada por su cuenta:** siempre llaman a una *acción* del módulo (`CreateEvent`, `ApproveMembership`…), que valida los datos y comprueba los permisos.
- **Un módulo no depende de `app/`.** Cuando la composición necesita añadir algo a una pantalla de un módulo (por ejemplo, el hobby en eventos y clubs), lo hace mediante un *punto de extensión*: un contrato que el módulo define y que otro paquete implementa.
- **Lo nuevo se hace como módulo**, no en `app/`.

Más detalle en [docs/MODULE_DEVELOPMENT.md](docs/MODULE_DEVELOPMENT.md) y en [CLAUDE.md](CLAUDE.md).

## Requisitos

| Dependencia | Versión |
|---|---|
| PHP | 8.5 |
| Laravel | 13.x |
| Filament | 5.x |
| Livewire | 4.x |
| Composer | 2.x |
| Node.js | Una versión con soporte actual |
| Base de datos | MySQL u otra soportada por Laravel |

## Instalación

```bash
git clone https://github.com/garciajhon2003/FriendsClub.git
cd FriendsClub

composer install
cp .env.example .env
php artisan key:generate

npm install
npm run build

php artisan migrate
php artisan storage:link
```

Configura la base de datos, el correo y la URL de la aplicación en `.env` antes de migrar.

**En Windows:** Composer pide la extensión `pcntl`, que solo existe en Linux. Añade `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` a `composer install` o `composer update`.

### Datos de ejemplo

Para ver la red social con contenido (personas, clubs, eventos, publicaciones, conversaciones…):

```bash
php artisan db:seed --class="Database\Seeders\DemoDataSeeder" --force
```

Los usuarios de ejemplo entran con su correo, como `laura@friendsclub.test`, y la contraseña `password`. **No lo ejecutes en un servidor con usuarios reales:** también añade actividad de ejemplo a todas las cuentas existentes.

## Pruebas

```bash
vendor/bin/pest                                   # todas (tarda más de 10 minutos)
vendor/bin/pest tests/Feature/FeedPageTest.php    # las de una pantalla
vendor/bin/pint                                   # formato del código
```

Cada pantalla tiene su archivo de pruebas en `tests/Feature/`. Las pruebas usan una base de datos SQLite en memoria, así que no tocan la tuya.

Fallo conocido: en Windows, `ThemeManagerTest` y `ThemeServiceProviderTest` fallan porque esperan rutas con `/` y Windows usa `\`.

## Pendiente

Lo más importante que falta:

- Que **seguir, reaccionar, comentar, enviar mensajes** o ser aceptado en un club generen **notificaciones**.
- Que los **bloqueos** se respeten también al seguir y en los mensajes.
- Poder **dejar de seguir** y **quitar amigos**.
- Que al empezar a seguir a alguien aparezcan sus publicaciones anteriores en el feed, y que eventos y clubs también lleguen al feed y al buscador.
- Ejecutar las **decisiones de moderación** (hoy solo se anotan) y **calcular las métricas** de forma automática.
- Mensajes y notificaciones **en tiempo real** (hoy las páginas se refrescan solas cada 15 o 30 segundos).

## Licencia y créditos

FriendsClub es software de código abierto bajo la [licencia MIT](LICENSE.md). Está basado en [Liberu Social Network](https://github.com/liberusoftware/social-network-laravel), © Liberu Software, también bajo licencia MIT.
