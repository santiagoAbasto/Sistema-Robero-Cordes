<div align="center">
<img src="docs/readme/banner.svg" alt="Sistema CORDES — Cotizaciones de caños y productos metálicos a partir de fórmulas de peso" width="100%">
<br/>

**Sistema interno de cotizaciones: cálculo de peso por fórmula, permisos por usuario, historial de cambios y lectura asistida por IA del pedido.**

<img alt="Laravel 13" src="https://img.shields.io/badge/Laravel-13-FF2D20?style=for-the-badge&logo=laravel&logoColor=white&labelColor=1B2A3A">
<img alt="React 19" src="https://img.shields.io/badge/React-19-61DAFB?style=for-the-badge&logo=react&logoColor=1B2A3A&labelColor=1B2A3A">
<img alt="TypeScript" src="https://img.shields.io/badge/TypeScript-strict-3178C6?style=for-the-badge&logo=typescript&logoColor=white&labelColor=1B2A3A">
<img alt="Sanctum" src="https://img.shields.io/badge/Sanctum-token-FF2D20?style=for-the-badge&labelColor=1B2A3A">
<img alt="Vite 8" src="https://img.shields.io/badge/Vite-8-646CFF?style=for-the-badge&logo=vite&logoColor=white&labelColor=1B2A3A">
<br/>
<img alt="PHP 8.3" src="https://img.shields.io/badge/PHP-%5E8.3-777BB4?style=flat-square&logo=php&logoColor=white">
<img alt="SQLite" src="https://img.shields.io/badge/SQLite-desarrollo-003B57?style=flat-square&logo=sqlite&logoColor=white">
<img alt="Tailwind CSS 4" src="https://img.shields.io/badge/Tailwind_CSS-4-06b6d4?style=flat-square&logo=tailwindcss&logoColor=white">
<img alt="OpenAI" src="https://img.shields.io/badge/OpenAI-opcional-412991?style=flat-square">
<img alt="Sin CI/CD" src="https://img.shields.io/badge/CI%2FCD-no_configurado-1B2A3A?style=flat-square">
</div>

<img src="docs/readme/divider.svg" alt="" width="100%">

## 📑 Contenido

- [El problema](#-el-problema)
- [La solución](#-la-solución)
- [Funcionalidades](#-funcionalidades)
- [Arquitectura](#%EF%B8%8F-arquitectura)
- [Flujos de trabajo](#-flujos-de-trabajo)
- [Modelo de datos](#%EF%B8%8F-modelo-de-datos)
- [Criterios y decisiones](#-criterios-y-decisiones)
- [Stack](#%EF%B8%8F-stack)
- [Estructura del repo](#%EF%B8%8F-estructura-del-repo)
- [Puesta en marcha](#-puesta-en-marcha)
- [Pruebas y calidad](#-pruebas-y-calidad)
- [Despliegue](#-despliegue)
- [Estado y pendientes](#-estado-y-pendientes)
- [Licencia y autoría](#-licencia-y-autoría)

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🧩 El problema

Cotizar un caño o un producto metálico exige calcular el peso a partir del material, la forma y las medidas, y luego transcribir cada línea del pedido del cliente. Esa transcripción es lenta y propensa a errores de tipeo. Además, las condiciones pactadas con cada empresa y el precio de cada cotización se perdían en el historial del sistema anterior, y no había control de quién veía qué.

## 💡 La solución

Un sistema a medida que reemplaza al anterior: el vendedor carga la cotización con líneas calculadas por fórmula, puede pedir a la IA que lea el texto del pedido del cliente, y el sistema guarda una copia de todo lo que se usó en el cálculo. Administración y vendedores trabajan cada uno con sus permisos.

| Usuario | Qué hace |
|---|---|
| **Vendedor** | Carga cotizaciones, gestiona fichas de empresas y contactos, consulta historial y condiciones pactadas. |
| **Administración** | Mantiene el catálogo de materiales, formas y fórmulas, y controla los permisos por usuario y por ficha. |

El sistema es de una sola empresa, con permisos por usuario y por ficha; no es multi-tenant.

<img src="docs/readme/divider.svg" alt="" width="100%">

## ✨ Funcionalidades

<details open>
<summary><b>Empresas y contactos</b></summary>

- Alta, edición, archivado y restauración de empresas, con contactos, razones sociales, enlaces (web, redes, mapa) y campos propios.
- Autocompletado de direcciones con Google Places, opcional y con clave del lado del servidor.
- Visibilidad de una ficha puntual para usuarios concretos.

</details>

<details open>
<summary><b>Cotizaciones</b></summary>

- Carga de líneas, alternativas de línea, condiciones comerciales y observaciones.
- Copia de cotizaciones anteriores y consultas relacionadas.
- Cálculo de peso por fórmula: material (densidad), forma (fórmula) y medidas.
- Interpretación del texto del pedido con IA (OpenAI), con lector de reglas propio como respaldo.
- PDF de la cotización con registro de cada impresión o envío (vía, contacto y fecha).

</details>

<details open>
<summary><b>Administración</b></summary>

- Permisos por usuario: ver importes, ver notas de otros, control de cambios y capacidad de modificar, imprimir o archivar.
- Historial de cambios automático sobre los modelos principales: campo, valor anterior y nuevo, quién y cuándo.
- Migración de datos del sistema anterior (materiales, empresas), con listado de materiales sin densidad cargada.
- Resumen numérico para la pantalla de inicio y sugerencias de alternativas según el historial.

</details>

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🏗️ Arquitectura

<p align="center">
  <img src="docs/readme/flujo.svg" alt="Cómo viaja una petición: vendedor, SPA en React, API Laravel con token, servicios de dominio y base de datos" width="100%">
</p>

Arquitectura desacoplada: una SPA en React consume una API REST de Laravel autenticada por token. Las pantallas de negocio no se renderizan en el servidor; `routes/web.php` solo sirve la página de bienvenida por defecto.

```mermaid
flowchart TB
    U(["Vendedor · administración"]) --> F["SPA React + Vite<br/>Axios + Bearer token"]
    F -->|"/api"| A["API Laravel 13<br/>auth:sanctum"]
    A --> S["Servicios de dominio<br/>CalculadoraDePeso · EvaluadorDeFormulas<br/>LectorDeSolicitud · Migracion"]
    A --> IA["InterpreteIA"]
    S --> DB[("SQLite en desarrollo")]
    A --> DB
    IA -.->|"opcional"| OAI(["OpenAI"])
    A -.->|"opcional"| GP(["Google Places"])
    A --> PDF["DomPDF<br/>PDF de cotización"]
```

No hay colas propias del dominio más allá de las tablas que Laravel crea por defecto (`jobs`, `cache`).

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🔄 Flujos de trabajo

### Interpretación de un pedido con IA

La IA solo propone líneas. Si no hay credencial o la respuesta no sirve, el lector de reglas toma el texto y la carga no se bloquea.

```mermaid
sequenceDiagram
    autonumber
    participant V as Vendedor
    participant F as SPA
    participant A as API Laravel
    participant I as InterpreteIA
    participant O as OpenAI
    participant L as LectorDeSolicitud
    V->>F: pega el texto del pedido
    F->>A: envía el texto
    A->>I: interpretar (materiales, formas y unidades activas)
    alt respuesta JSON válida con lineas
        I->>O: Chat Completions (json_object)
        O-->>I: líneas propuestas
        I-->>F: líneas para revisar
    else sin credencial, timeout o respuesta inválida
        I->>L: lectura por reglas
        L-->>F: líneas y aviso del motivo
    end
    V->>F: revisa, corrige y guarda
```

### Estados de una empresa

```mermaid
stateDiagram-v2
    [*] --> Activa: alta
    Activa --> Archivada: archivar
    Archivada --> Activa: restaurar
    Archivada --> [*]
```

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🗃️ Modelo de datos

Eloquent con `$guarded = []` en los modelos de dominio; los formularios validan explícitamente en los controladores.

```mermaid
erDiagram
    EMPRESA ||--o{ CONTACTO : tiene
    EMPRESA ||--o{ RAZON_SOCIAL : tiene
    EMPRESA ||--o{ CONSULTA : recibe
    EMPRESA ||--o{ HISTORIAL_CAMBIO : registra
    CONSULTA ||--o{ CONSULTA_LINEA : contiene
    CONSULTA_LINEA ||--o{ CONSULTA_LINEA_OPCION : "tiene alternativas"
    CONSULTA ||--o{ IMPRESION : registra
    FORMA ||--o{ CONSULTA_LINEA : "se usa en"
    MATERIAL ||--o{ CONSULTA_LINEA : "se usa en"
    USUARIO ||--o{ PERMISO : tiene
    USUARIO ||--o{ CONSULTA : crea

    EMPRESA { bigint id PK string estado }
    CONSULTA { bigint id PK string tipo }
    CONSULTA_LINEA { bigint id PK }
    CONSULTA_LINEA_OPCION { bigint id PK }
    MATERIAL { bigint id PK decimal densidad }
    FORMA { bigint id PK string formula }
    USUARIO { bigint id PK }
    PERMISO { bigint id PK bigint usuario_id }
    HISTORIAL_CAMBIO { bigint id PK }
    IMPRESION { bigint id PK }
```

Un trait (`RegistraCambios`) escribe en `historial_cambios` cada alta, modificación o archivado. Las líneas de cotización guardan una copia de la densidad, la fórmula y el factor usados, para que una corrección posterior del catálogo no altere cotizaciones ya emitidas.

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🧭 Criterios y decisiones

| Decisión | Por qué |
|---|---|
| **Evaluador de fórmulas propio, sin `eval()`** | Las fórmulas son datos editables, así que se tokenizan y solo admiten números, medidas, `+ - * / ^` y una lista cerrada de funciones. |
| **Auditoría por trait** | `RegistraCambios` se engancha a los eventos de Eloquent en vez de repetir el registro en cada controlador. |
| **Copia de datos en cada línea** | Una cotización emitida no cambia si el catálogo se corrige después. |
| **Lector determinístico + IA opcional** | La IA solo interviene en la lectura del texto de entrada, nunca en el cálculo. Sin credencial, el sistema sigue funcionando. |
| **Token Sanctum en vez de cookies entre orígenes** | Simplifica CORS: `supports_credentials: false` y `allowed_origins` limitado al `FRONTEND_URL`. |

**Seguridad, en resumen:**

- Todas las rutas de negocio de `routes/api.php` están bajo `auth:sanctum`, salvo el login.
- Autorización por usuario y por ficha; un administrador siempre ve todo, y no puede quedarse sin permisos.
- Entradas validadas con `Request::validate()` y reglas de Laravel en cada escritura.
- Secretos por variables de entorno. Las claves de OpenAI y de Google Places son opcionales.
- Cada impresión o envío de PDF queda registrado con usuario, vía y destinatario.
- No se encontró limitación de tasa personalizada ni cabeceras CSP explícitas en el código revisado.

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🛠️ Stack

| Capa | Tecnología | Versión |
|---|---|---|
| Backend | PHP · Laravel | 8.3 o superior · 13 |
| Autenticación | Laravel Sanctum | 4 |
| PDF | Barryvdh DomPDF | 3 |
| Frontend | React · TypeScript · Vite | 19 · strict · 8 |
| Enrutado y HTTP | React Router · Axios | 7 · — |
| Estilos | Tailwind CSS | 4 |
| Datos | SQLite (`database/database.sqlite`) | — |
| IA (opcional) | OpenAI Chat Completions | `OPENAI_MODEL` |
| Direcciones (opcional) | Google Places API (New) | — |
| Pruebas | PHPUnit | 12 |

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🗂️ Estructura del repo

```text
backend/
├── app/Http/Controllers/     empresas, consultas, autenticación, permisos, PDF, migración
├── app/Models/               Empresa, Consulta, Forma, Material, Permiso, HistorialCambio…
├── app/Models/Concerns/      RegistraCambios (auditoría automática)
├── app/Services/             CalculadoraDePeso, EvaluadorDeFormulas, InterpreteIA, LectorDeSolicitud
├── database/migrations/      esquema
├── routes/api.php            rutas de negocio (auth:sanctum)
└── tests/Feature/            suite principal
frontend/
├── src/pages/indice/         empresas, cotizaciones, formas, permisos, control de cambios
├── src/components/           barra lateral, calculadora de peso, buscador de direcciones
└── src/lib/                  cliente de la API, autenticación, calculadora
```

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🚀 Puesta en marcha

Requisitos: PHP 8.3 o superior, Composer, Node.js y npm.

```bash
# backend
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install

# frontend (en otra terminal)
cd frontend
npm install
cp .env.example .env     # ajustar VITE_API_URL si el backend no corre en localhost:8002
```

- Para levantar todo junto desde `backend`: `composer run dev` (servidor PHP, cola, logs y Vite).
- Por separado: `php artisan serve` en el backend y `npm run dev` en el frontend.
- Opcional: `OPENAI_API_KEY` y `GOOGLE_PLACES_KEY` en `backend/.env`. Sin ellas el sistema funciona con el lector de reglas.
- Nunca se commitean los `.env` reales.

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🧪 Pruebas y calidad

```bash
cd backend && php artisan test
cd frontend && npm run lint
```

- Suite de backend en `backend/tests/Feature`: cálculo de peso y paridad con el sistema anterior, fórmulas rotas, factor por unidad, condiciones de la cotización, alternativas, formas, caños estándar, interpretación por IA y resumen.
- El README anterior registraba **150 pruebas y 1096 aserciones en verde**. **No se volvió a ejecutar para esta versión.**
- Lint del frontend con oxlint (`npm run lint`). No hay suite automatizada de frontend ni CI en el repositorio.

<img src="docs/readme/divider.svg" alt="" width="100%">

## 🐳 Despliegue

No hay Docker, Docker Compose ni CI/CD configurados en el repositorio. Para desarrollo se usa SQLite y el servidor embebido de Laravel con Vite. **No hay despliegue productivo documentado en el repositorio.**

<img src="docs/readme/divider.svg" alt="" width="100%">

## 📌 Estado y pendientes

- Último commit: **12-11-2026** (`cd1f5c0`, aviso de versión nueva en la pantalla y actualización automática cuando es posible).
- Sistema en desarrollo activo. Sin evidencia en el repositorio de despliegue en producción.
- Repositorio hermano del sitio corporativo: [Roberto-cordes](https://github.com/santiagoAbasto/Roberto_cordes).

<img src="docs/readme/divider.svg" alt="" width="100%">

## 📄 Licencia y autoría

Desarrollado por el **Ing. Santiago Abasto**.


Sistema de uso interno del cliente. El repositorio no incluye archivo de licencia. Los archivos comerciales del cliente (planillas, PDFs e informes) no se versionan y quedan fuera del repositorio junto con los respaldos de la base.
