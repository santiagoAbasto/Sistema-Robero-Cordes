# Sistema CORDES

Sistema interno de cotizaciones para una empresa de fabricación de caños, con lectura asistida por IA del pedido del cliente.

## Descripción

CORDES es un sistema de gestión comercial a medida para una empresa que cotiza caños y productos metálicos a partir de fórmulas de cálculo de peso (material, forma y medidas). Reemplaza un sistema anterior del que migra datos de catálogo (materiales, formas, empresas) y agrega control de acceso por usuario, historial de cambios y generación de PDF para el cliente.

El usuario tipo es interno: vendedores y administración de la empresa, que cargan cotizaciones, gestionan fichas de empresas/contactos y consultan el historial de precios y condiciones pactadas. El proyecto está en desarrollo activo: el backend cuenta con una suite de tests que cubre las reglas de cálculo y los flujos principales, y el frontend implementa las pantallas de login, dashboard y el módulo de "índice" (empresas, cotizaciones, formas, permisos).

## Funcionalidades principales

- Autenticación con token (Laravel Sanctum) y control de sesión desde el frontend.
- Gestión de empresas: alta, edición, archivado/restauración, contactos, razones sociales, enlaces (web/redes/mapa) y campos propios por empresa.
- Cotizaciones ("consultas"): carga de líneas, alternativas de línea, condiciones comerciales, observaciones, copiado de cotizaciones anteriores y consultas relacionadas.
- Cálculo de peso por fórmula: combina material (densidad), forma (fórmula) y medidas, con evaluador de fórmulas propio (sin `eval`) que solo admite operadores y funciones de una lista cerrada.
- Interpretación asistida por IA del texto del pedido del cliente, con lector de reglas como respaldo si no hay IA configurada o si falla.
- Autocompletado de direcciones vía Google Places (opcional, con clave restringida por IP del lado del servidor).
- Permisos por usuario ("Quién ve qué"): visibilidad de fichas, importes, notas de otros, y capacidad de modificar/imprimir/archivar.
- Historial de cambios automático sobre los modelos principales (qué campo cambió, quién y cuándo).
- Generación de PDF de la cotización (DomPDF) con registro de cada impresión/envío (vía, contacto, fecha).
- Migración de datos desde el sistema anterior (catálogo de materiales, empresas) con detección de materiales sin densidad cargada.
- Resumen numérico para la pantalla de inicio y sugerencias de alternativas a partir del historial.

## Arquitectura

Arquitectura desacoplada: SPA en React que consume una API REST de Laravel autenticada por token. No hay renderizado del lado del servidor para las pantallas de negocio (la vista `web.php` solo sirve la página de bienvenida por defecto de Laravel).

```
Usuario (vendedor / administración)
        |
Frontend SPA (React + Vite, puerto 5173)
        |  Axios + Bearer token
Backend API (Laravel 13, /api/*)
        |
Servicios de dominio (cálculo de peso, fórmulas, migración, IA)
        |
Base de datos (SQLite en desarrollo)
        |
Servicios externos opcionales: OpenAI (interpretación de pedidos), Google Places (direcciones)
```

No hay colas asíncronas propias del dominio más allá de la infraestructura estándar de Laravel (tabla `jobs`/`cache` incluida por el framework), ni multi-tenancy: es un sistema de una sola empresa con permisos por usuario y por ficha.

## Stack tecnológico

### Backend

- PHP 8.3, Laravel 13
- Laravel Sanctum (autenticación por token)
- Barryvdh/laravel-dompdf (generación de PDF)

### Frontend

- React 19 + TypeScript
- Vite 8
- React Router 7
- Axios
- Tailwind CSS 4
- lucide-react, motion (animaciones)

### Base de datos

- SQLite (`database/database.sqlite`), vía Eloquent ORM

### Testing

- PHPUnit (Pest no está instalado; se usa el runner nativo de Laravel/PHPUnit 12)
- 150 tests de backend, todos en verde al momento de escribir este README (`php artisan test`)
- oxlint configurado en el frontend (`npm run lint`)

### DevOps / Infraestructura

- Servidor de desarrollo integrado de Laravel (`artisan serve`) + `queue:listen` + `pail` (logs) + Vite, orquestados con `concurrently` vía `composer dev`
- Sin Docker, CI/CD ni infraestructura cloud configurada en el repositorio

### IA

- OpenAI (Chat Completions, `response_format: json_object`) para interpretar el texto del pedido

### Integraciones

- Google Places API (New) para sugerencias y detalle de direcciones

## Modelo de datos

ORM: Eloquent, con `$guarded = []` en los modelos de dominio (los formularios validan explícitamente en los controladores/form requests).

Entidades principales: `Empresa` (con `contactos`, `razonesSociales`, `enlaces`, `campos`, relaciones a `localidad`/`provincia`/`pais`/`rubro`), `Consulta` (cotización/pedido/observación, con `lineas`, `condiciones`, `usuario`, `moneda`, `razonSocial`), `ConsultaLinea` y `ConsultaLineaOpcion` (alternativas de línea), `Forma` y `Material` (catálogo con fórmula y densidad), `CanoEstandar`, `Permiso` (por usuario), `HistorialCambio` e `Impresion` (auditoría).

Un trait común (`RegistraCambios`) intercepta los eventos `created`/`updated` de Eloquent y escribe automáticamente en `historial_cambios` qué campo cambió, el valor anterior y nuevo, quién lo hizo y cuándo, distinguiendo "Alta", "Modificación" y "Archivado". Las líneas de cotización guardan una fotografía de los datos usados en el cálculo (densidad, fórmula, factor) para que una corrección posterior en el catálogo no altere cotizaciones ya emitidas.

## Seguridad

- Las rutas de negocio requieren autenticación: todo `routes/api.php` salvo `/login` está bajo el middleware `auth:sanctum`.
- La autenticación se resuelve con tokens Bearer (Sanctum, `createToken`), no con sesión de cookies entre orígenes: CORS tiene `supports_credentials: false` y limita `allowed_origins` al `FRONTEND_URL`.
- Autorización por rol y por ficha: `PermisoController` controla, por usuario, si ve importes, notas ajenas, control de cambios, y si puede modificar/imprimir/archivar; un administrador no puede quedarse sin permisos y siempre ve todo. `Empresa.visible_para` permite reservar una ficha puntual.
- Las consultas a base de datos pasan por Eloquent con bindings parametrizados (no hay SQL crudo en los controladores revisados).
- Los datos de entrada se validan con `Request::validate()` y reglas de Laravel (`Rule::in`, `exists`, tipos) en cada controlador de escritura.
- El evaluador de fórmulas de cálculo (`EvaluadorDeFormulas`) no usa `eval()`: tokeniza el texto guardado y solo admite números, las medidas de la forma, los operadores `+ - * / ^` y una lista cerrada de funciones, rechazando cualquier otra expresión.
- Gestión de secretos por variables de entorno (`.env`, no versionado): credencial de OpenAI y clave de Google Places son opcionales y el sistema sigue funcionando sin ellas (fallback a reglas manuales).
- Si la IA falla o responde algo no interpretable, el sistema no bloquea la carga: cae al lector de reglas y deja un aviso explicando el motivo (credencial inválida, sin crédito, servicio caído).
- Auditoría: cada impresión/envío de PDF queda registrada (`Impresion`) con usuario, vía y destinatario, además del historial general de cambios.
- No se encontró configuración de rate limiting personalizada ni cabeceras CSP explícitas en el código revisado.

## APIs e integraciones

- API REST propia (`backend/routes/api.php`) consumida por el frontend React vía Axios con token Bearer.
- OpenAI (`config/services.php` → `openai`): interpretación del texto del pedido del cliente; configurable por variables de entorno (`OPENAI_API_KEY`, `OPENAI_MODEL`, `OPENAI_URL`, `OPENAI_TIMEOUT`). Sin credencial, el sistema usa un lector de reglas propio (`LectorDeSolicitud`).
- Google Places API (`GOOGLE_PLACES_KEY`): sugerencias y detalle de direcciones, resuelto del lado del servidor para no exponer la clave al navegador.

## Inteligencia Artificial

El problema que resuelve: transcribir a mano cada línea de un pedido de cliente (material, forma, medidas, cantidad) es lento y propenso a errores de tipeo. `InterpreteIA` envía el texto libre del pedido a un modelo de OpenAI (Chat Completions con `response_format: json_object`) junto con las listas vigentes de materiales, formas y unidades activas, y espera de vuelta un JSON con las líneas propuestas.

- Proveedor: OpenAI, sin abstracción multi-proveedor (una sola integración).
- No hay orquestación de agentes ni RAG/embeddings: es una llamada única con contexto acotado (catálogos activos) y salida estructurada (JSON).
- Validación: si la respuesta no es JSON válido o no trae la clave `lineas`, se descarta y se usa el lector de reglas.
- Human-in-the-loop: la IA solo propone; las líneas se muestran en pantalla para que la persona las revise y corrija antes de guardar. El servicio nunca persiste datos por su cuenta.
- Lógica determinística vs IA: el cálculo de peso, fórmulas y precios es siempre determinístico (`CalculadoraDePeso`, `EvaluadorDeFormulas`); la IA solo interviene en la lectura del texto de entrada, nunca en el cálculo.
- Manejo de errores: timeouts, credenciales inválidas (401/403), falta de crédito (429) y caídas del servicio (5xx) se traducen a mensajes accionables en español y no interrumpen la carga de la cotización.

## Testing y calidad

- Framework: PHPUnit (vía `php artisan test`), configurado en `backend/phpunit.xml`.
- Se ejecutó la suite completa durante la elaboración de este README: **150 tests, 1096 assertions, todos en verde**.
- Cobertura por área (carpeta `tests/Feature`): cálculo de peso y paridad con el sistema anterior, fórmulas rotas, factor por unidad, procedencia del factor, condiciones de la cotización, guardado de líneas, alternativas, administración de formas, caños estándar, interpretación por IA, resumen.
- Lint de frontend disponible vía `npm run lint` (oxlint); no se ejecutaron pruebas automatizadas de frontend (no hay suite configurada en `frontend/package.json`).
- No hay pipeline de CI configurado en el repositorio (sin `.github/workflows/`, `.gitlab-ci.yml` ni `Jenkinsfile`).

## DevOps y despliegue

- No hay Docker, Docker Compose ni configuración de CI/CD en el repositorio.
- Entorno de desarrollo: SQLite como base de datos, servidor embebido de Laravel, Vite para el frontend.
- El script `composer dev` levanta en paralelo (`concurrently`) el servidor PHP, el worker de colas (`queue:listen`), los logs (`pail`) y Vite.
- Colas configuradas con driver `database` (tabla `jobs`) y caché/sesión también sobre `database`; no se identificaron workers ni colas específicas del dominio más allá de lo que provee el framework.

## Instalación local

Requiere PHP 8.3+, Composer, Node.js y npm.

1. Clonar el repositorio.
2. Backend:
   ```bash
   cd backend
   composer install
   cp .env.example .env
   php artisan key:generate
   touch database/database.sqlite
   php artisan migrate
   npm install
   ```
3. Configurar opcionalmente en `backend/.env` las claves de `OPENAI_API_KEY` y `GOOGLE_PLACES_KEY` (el sistema funciona sin ellas).
4. Frontend:
   ```bash
   cd frontend
   npm install
   cp .env.example .env   # ajustar VITE_API_URL si el backend no corre en localhost:8002
   ```
5. Levantar todo junto desde `backend` con `composer run dev`, o por separado: `php artisan serve` (backend) y `npm run dev` en `frontend` (SPA).
6. Ejecutar tests del backend: `cd backend && php artisan test`.

Nunca se deben commitear los archivos `.env` reales ni sus credenciales.

## Estructura del proyecto

```
backend/
├── app/
│   ├── Http/Controllers/   # Controladores de la API (Empresa, Consulta, Auth, Permiso, Pdf, Migracion...)
│   ├── Models/             # Eloquent: Empresa, Consulta, Forma, Material, Permiso, HistorialCambio...
│   ├── Models/Concerns/    # RegistraCambios (auditoría automática)
│   └── Services/           # CalculadoraDePeso, EvaluadorDeFormulas, InterpreteIA, LectorDeSolicitud, Migracion/
├── database/
│   ├── migrations/         # 20 migraciones
│   └── seeders/
├── routes/
│   ├── api.php             # Rutas de negocio (auth:sanctum)
│   └── web.php             # Solo vista de bienvenida
├── tests/Feature/          # Suite principal de tests
└── config/services.php     # Configuración de OpenAI y Google Places

frontend/
├── src/
│   ├── pages/indice/       # Empresas, cotizaciones, formas, permisos, control de cambios...
│   ├── components/         # Sidebar, Topbar, calculadora de peso, buscador de dirección...
│   ├── lib/                # api.ts (cliente Axios), auth.tsx, calculadora.ts
│   └── layouts/            # AppShell
└── package.json
```

## Decisiones técnicas destacables

- Evaluador de fórmulas propio, tokenizado, en vez de `eval()`: las fórmulas de cálculo son datos editables en el catálogo, pero se ejecutan con una lista cerrada de operadores y funciones para evitar ejecución de código arbitrario.
- Auditoría transversal mediante un trait (`RegistraCambios`) enganchado a los eventos de Eloquent, en vez de lógica de logging repetida en cada controlador.
- Cada línea de cotización guarda una copia de los datos usados en el cálculo (densidad, fórmula, factor) para que cotizaciones ya emitidas no cambien si el catálogo se corrige después.
- Separación clara entre lectura determinística del pedido (`LectorDeSolicitud`) y lectura asistida por IA (`InterpreteIA`), con la segunda como capa opcional que nunca reemplaza ni bloquea a la primera.
- Autenticación por token (Sanctum) en vez de cookies de sesión entre orígenes, simplificando CORS al no requerir credenciales compartidas entre el SPA y la API.

## Desafíos técnicos resueltos

- Problema: una fórmula de cálculo mal escrita en el catálogo (por ejemplo, con una función no soportada) no debía romper la carga de una cotización.
  Solución: el evaluador de fórmulas valida token por token y hay un test dedicado (`FormulaRotaTest`) que cubre el comportamiento ante una fórmula inválida.
- Problema: la migración de datos del sistema anterior podía traer materiales sin densidad cargada, lo que rompería el cálculo de peso.
  Solución: `MigracionController::materialesSinDensidad` expone un listado específico para detectarlos y completarlos antes de operar.
- Problema: si la IA de interpretación fallaba (timeout, sin crédito, credencial vencida), la persona veía un error genérico sin poder actuar.
  Solución: `InterpreteIA` traduce el código de error HTTP a un mensaje accionable en español y cae automáticamente al lector de reglas, sin interrumpir la carga.

## Estado del proyecto

Sistema interno (proyecto cliente) en desarrollo activo. El backend tiene una suite de tests consolidada (150 tests en verde) que cubre las reglas de negocio críticas (cálculo de peso, fórmulas, permisos), y el frontend implementa los flujos principales de carga y consulta. No hay evidencia en el repositorio de despliegue en producción (sin Docker/CI/CD configurados).

## Mi contribución

Trabajé en el diseño e implementación del backend Laravel del sistema: modelado de datos (empresas, consultas, catálogo de materiales y formas), el motor de cálculo de peso con su evaluador de fórmulas sin `eval()`, el mecanismo de auditoría automática de cambios (`RegistraCambios`), el control de permisos por usuario y por ficha, la generación de PDF de cotizaciones y la integración opcional con IA (OpenAI) para interpretar pedidos en texto libre, con su capa de respaldo determinística. También desarrollé el frontend en React/TypeScript que consume esta API (autenticación, módulo de empresas y cotizaciones).

### Portfolio Summary

Nombre:
Sistema CORDES

Tipo:
Sistema interno / proyecto cliente

Rol:
Desarrollador full-stack (backend Laravel + frontend React)

Descripción corta:
Sistema de cotizaciones para una empresa de caños, con cálculo de peso por fórmula, control de permisos por usuario, auditoría automática de cambios y lectura asistida por IA del pedido del cliente.

Stack principal:
Laravel 13 (PHP 8.3) + Sanctum, React 19 + TypeScript + Vite, SQLite, OpenAI

Arquitectura:
SPA React consumiendo una API REST Laravel autenticada por token, sin renderizado del lado del servidor para el negocio.

Seguridad:
Autenticación Sanctum por token, autorización por rol y por ficha, validación explícita en cada endpoint, evaluador de fórmulas sin `eval()`, gestión de secretos por variables de entorno con funcionamiento degradado si faltan.

Testing:
150 tests de backend (PHPUnit) en verde, cubriendo cálculo de peso, fórmulas, permisos y flujos de cotización.

DevOps:
Sin Docker ni CI/CD; entorno de desarrollo con SQLite y servidor embebido de Laravel.

IA:
Interpretación de pedidos en texto libre vía OpenAI, con fallback determinístico y revisión humana obligatoria antes de guardar.

5 habilidades clave:
1. Diseño de modelos de dominio y migraciones en Laravel/Eloquent.
2. Implementación de un evaluador de expresiones seguro (sin `eval()`).
3. Integración de servicios de IA con manejo de fallos y fallback determinístico.
4. Control de acceso granular (roles y visibilidad por registro).
5. Desarrollo de SPA en React/TypeScript consumiendo una API REST propia.

3 logros o aportes verificables:
- Suite de 150 tests de backend en verde, incluyendo casos límite como fórmulas rotas y paridad con el sistema anterior.
- Evaluador de fórmulas tokenizado que evita ejecución de código arbitrario en cálculos definidos por datos.
- Integración de IA opcional que nunca bloquea el flujo principal ante fallos del proveedor.

Nivel de madurez:
Desarrollo activo, con base de tests sólida en backend; sin infraestructura de despliegue automatizada todavía.

Elementos que no deben publicarse:
- Contenido de los archivos `.env` (credenciales de OpenAI, Google Places y configuración de base de datos).
- Los archivos de negocio incluidos en la raíz del repositorio (planillas, PDFs e informes con datos comerciales del cliente).

## Evidencia técnica

| Afirmación | Evidencia |
| --- | --- |
| Laravel 13 / PHP 8.3 | [backend/composer.json](backend/composer.json) |
| Autenticación por token (Sanctum) | [backend/app/Http/Controllers/AuthController.php](backend/app/Http/Controllers/AuthController.php) |
| API REST con rutas protegidas | [backend/routes/api.php](backend/routes/api.php) |
| Evaluador de fórmulas sin `eval()` | [backend/app/Services/EvaluadorDeFormulas.php](backend/app/Services/EvaluadorDeFormulas.php) |
| Interpretación de pedidos por IA (OpenAI) | [backend/app/Services/InterpreteIA.php](backend/app/Services/InterpreteIA.php) |
| Auditoría automática de cambios | [backend/app/Models/Concerns/RegistraCambios.php](backend/app/Models/Concerns/RegistraCambios.php) |
| Permisos por usuario y por ficha | [backend/app/Http/Controllers/PermisoController.php](backend/app/Http/Controllers/PermisoController.php) |
| Generación de PDF de cotización | [backend/app/Http/Controllers/PdfController.php](backend/app/Http/Controllers/PdfController.php) |
| 150 tests backend en verde | `php artisan test` ejecutado en `backend/` (150 tests, 1096 assertions) |
| Frontend React + TypeScript + Vite | [frontend/package.json](frontend/package.json) |
| CORS sin credenciales cross-origin | [backend/config/cors.php](backend/config/cors.php) |

---

**Datos excluidos deliberadamente de este README:** valores reales de variables de entorno (`.env`), y el contenido de los archivos comerciales del proyecto (`CORDES-Estructura-Datos-Empresas V3.xlsx`, `CORDES-Indice-Telefonico.pdf`, `OSOLE_Plan_Implementacion_Modulo_1_CRM*.docx`, `Plan-Proyecto-CORDES.pdf`), que por tratarse de información comercial del cliente no se versionan: quedan fuera del repositorio junto con las exportaciones del sistema anterior y los respaldos de la base. Ver `.gitignore`.
