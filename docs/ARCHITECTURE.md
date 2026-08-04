# WpbotReserva — Arquitectura v1.0

**Estado:** referencia arquitectónica, no guía de implementación. Describe el sistema **tal como quedó construido después del Hito 4** (commit `460bbe9`) — no la visión de largo plazo ni el roadmap.

**Relación con otros documentos:**
- La visión completa de largo plazo, el razonamiento de diseño (incluida la autocrítica que llevó a cada decisión) y el roadmap explícito viven en `C:\Users\amart\.claude\plans\recursive-bubbling-sundae.md` (Partes I–XVI, congelado como v1.0 de la *visión*, Parte XIII). Este documento es su contraparte "as-built": qué de esa visión ya existe en código, con qué forma exacta, y qué reglas se derivan de la implementación real.
- Decisiones arquitectónicas nuevas a partir de ahora se registran como ADR en `docs/adr/NNNN-titulo.md` (Parte XIII regla 5), no como ediciones a este archivo salvo que un hito cierre y haya que resincronizarlo.
- `docs/DEPLOYMENT.md` cubre el procedimiento operativo de arranque/deploy. `ARCHITECTURE.md` (raíz del repo) y `spec.md` documentan el bot de turismo/negociación **legado**, un sistema completamente distinto que coexiste en el mismo repositorio — ver "Relación con el sistema legado" más abajo.

---

## 1. Qué es WpbotReserva

Motor de reservas multi-tenant y multi-país operado 100% por WhatsApp, con IA como capa de conversación y el dominio (no la IA) como única fuente de verdad para cualquier invariante de negocio. Al cierre del Hito 4 existe: el modelo de dominio completo de Scheduling/Booking/Tenancy/CRM, los Application Commands que lo orquestan, y la capa de enrutamiento conversacional (`InboundMessageRouter`) que resuelve canal→sesión→organización→intención→agente. **No existe todavía** ningún Agente de negocio real (Hito 5/6), ni la integración con el webhook de WhatsApp (Hito 7) — el único Agent implementado es `OutOfScopeAgent`, que cierra el pipeline de punta a punta sin lógica de negocio.

---

## 2. Bounded contexts

| Contexto | Tipo | Estado | Namespace |
|---|---|---|---|
| **Scheduling & Availability** | Core | Implementado (Hito 1/2) | `App\Domain\Scheduling` |
| **Booking** | Core | Implementado (Hito 1/2/3) | `App\Domain\Booking` |
| **Identity & Tenancy** | Supporting/Genérico | Implementado (Hito 1/3/4) | `App\Domain\Tenancy` |
| **Customer Relationship** | Supporting | Mínimo, implementado (Hito 1) | `App\Domain\CRM` |
| **Conversational** | Generic (mecanismo de entrega) | Parcial — Router + clasificación implementados (Hito 4), sin Agentes de negocio | `App\Domain\Conversational`, `App\Application\Conversations` |
| **Payments & Deposits** | Supporting | No implementado — roadmap | — |
| **Subscription & Billing** | Supporting | No implementado — solo 3 columnas de ciclo de vida en `Organization` + `EntitlementCheckerInterface` trivial | — |
| **Audit Trail** | Cross-cutting | No implementado — pospuesto explícitamente | — |

Regla dura entre contextos (vigente, sin excepciones hasta ahora): ningún contexto accede al modelo interno de otro directamente. La comunicación es vía Application Commands o Domain Events — nunca un contexto hace `SomeOtherContextModel::query()` directo salvo relaciones Eloquent ya modeladas explícitamente como parte del propio aggregate (ej. `Organization::channels()`).

---

## 3. Capas y dirección de dependencia

```
Conversational (Jobs, Router, Classifiers, Agents)
        │  invoca
        ▼
Application (Commands, Contracts/*Interface, implementaciones MVP)
        │  orquesta
        ▼
Domain (Aggregates Eloquent, Domain Services, Value Objects, Events)
        │  persiste contra
        ▼
Infraestructura (MariaDB, Redis) — vía Eloquent/Cache, nunca referenciada por nombre desde Domain
```

- **`app/Domain/*`** — aggregates (modelos Eloquent con invariantes), Value Objects, Domain Services (`AvailabilityCalculator`, `BookingScheduler`), Domain Events. No conoce Application ni Infraestructura. No importa `Illuminate\Support\Facades\Cache` ni nada fuera de Eloquent/Carbon.
- **`app/Application/*`** — Commands (`CreateBookingCommand`, `RegisterOrganizationCommand`), interfaces (`app/Application/Contracts/*`) y sus implementaciones MVP, orquestación conversacional (`InboundMessageRouter`, `AgentSelector`, clasificadores). Es la **única** capa que puede invocar un Domain Service o crear/mutar un aggregate desde fuera del propio dominio.
- **`app/Jobs/*`** (solo `ProcessInboundConversationMessage` es del dominio nuevo) — envoltorio de ejecución segura (mutex + dedup) alrededor de la capa de Application. No contiene lógica de negocio.
- **Nadie fuera de Application invoca un Domain Service o muta un aggregate directamente.** Un futuro Agent, un futuro endpoint de API pública, o Filament, siempre pasan por un Application Command.

---

## 4. Aggregates y sus invariantes (verificado contra las migraciones reales)

### Identity & Tenancy

- **`Organization`** — raíz de tenant. Defaults: `timezone=America/Bogota`, `locale=es`, `currency=COP`, `is_active=true`. Ciclo de vida: `is_active`/`suspended_at`/`suspension_reason` (para Subscription & Billing futuro, sin lógica todavía). `owner_phone` distingue al dueño del negocio de un cliente cualquiera (usado por `RegisterOrganizationCommand`, no todavía por ningún Agent).
- **`Location`** — pertenece a `Organization`; hereda `timezone` si no tiene el suyo.
- **`Channel`** — **excepción intencional**: no lleva `organization_id` directo. Relación N:N vía `channel_organization` (`unique(channel_id, organization_id)`, pivot `is_primary`). `phone_number_id` es único (`unique`, nullable) y es el identificador estable de proveedor (nunca el número visible). `status` (`ChannelStatus`: `PENDING_VERIFICATION|ACTIVE|DISCONNECTED|SUSPENDED|BLOCKED_BY_META|ERROR`), `provider` (`ChannelProvider`), `channel_type` (`ChannelType`), `credentials` (`encrypted:array`), `metadata` (`array`). `isActive(): bool` es el único método de negocio.

### Scheduling & Availability

- **`Resource`** — `organization_id`, `location_id` nullable (recurso flotante/remoto), `resource_type` (`ResourceType` enum), `subtype` (string simple, recorte del Hito 1 — sin tabla de vocabulario todavía), `capacity`.
- **`Service`** — `organization_id`, `duration_minutes` con **CHECK `duration_minutes > 0`** (`chk_services_duration_positive`, constraint real de MariaDB, no solo validación de aplicación), `cancellation_policy` texto libre (recorte — sin Value Object estructurado todavía).
- **`ServiceResourceRequirement`** / **`resource_service`** (pivot) — modelo N:N entre `Service` y `Resource`, ya soporta múltiples recursos aunque el algoritmo de `BookingScheduler` del MVP solo resuelve 0–1.
- **`ResourceSchedule`** — **CHECK `(resource_id IS NULL) != (location_id IS NULL)`** (`chk_resource_schedules_owner`): exige exactamente un dueño, nunca ambos ni ninguno. **CHECK `end_time > start_time`** (`chk_resource_schedules_time_order`).
- **`ScheduleException`** — Aggregate Root propio (no anidado en `Resource`/`Location`), referencia por ID.

### Booking

- **`Booking`** — **CHECK `ends_at > starts_at`** (`chk_bookings_time_order`). `status` es un enum simple (`BookingStatus`) encapsulado — **no** las 3 tablas de workflow configurable de la visión original (recorte explícito, Parte XII). Snapshotea `duration_minutes` al confirmarse (no precio/moneda — sin consumidor financiero real todavía).
- **`BookingResource`** — entidad interna de `Booking`, **`unique(booking_id, resource_id)`**: un mismo recurso no puede asignarse dos veces a la misma reserva.
- **Invariante central del sistema** (la razón de ser del producto): ningún `Resource` puede tener dos `Booking`s confirmados que se solapen más allá de su `capacity`. Enforced exclusivamente por `BookingScheduler` (ver §7) — **ningún otro código puede crear una `Booking` y hacer cumplir esta invariante**; no hay otro punto de entrada.

### Customer Relationship

- **`Customer`** — minimalista a propósito. **`unique(organization_id, phone)`**. `phone` casteado a Value Object `PhoneNumber` (E.164 estricto, ver §6).

### Conversational

- **`ConversationSession`** — **`unique(channel_id, customer_phone)`** (no solo `customer_phone`: un mismo teléfono puede tener una sesión distinta por cada `Channel` con el que conversa, corregido en Hito 4 tras detectar que el diseño original de Hito 1 no soportaba esto). `organization_id` nullable (se completa cuando `OrganizationResolverInterface` resuelve). `current_intent` (string nullable) — guarda el último `Intent` **clasificado**, nunca un Agent concreto (renombrado de `current_agent` en Hito 4 por esta misma razón).

---

## 5. Value Objects

| VO | Ubicación | Invariante propia |
|---|---|---|
| `PhoneNumber` | `App\Domain\Shared` | Formato E.164 estricto (`+`, sin cero inicial tras el código de país); dos instancias son iguales por valor. |
| `TimeRange` | `App\Domain\Booking\ValueObjects` | `ends > starts`; expone `overlaps()`, `contains()`, `withTrailingBuffer()`. |
| `AvailableSlot` | `App\Domain\Booking\ValueObjects` | Resultado inmutable de `AvailabilityCalculator`. |
| `Intent` (enum) | `App\Domain\Conversational` | Vocabulario cerrado: `RegistroNegocio`, `Reserva`, `FueraDeAlcance`. Ampliar (ej. `GestionReserva`, Incremento 2) es agregar un case, nunca un string suelto. |
| `InboundMessage` | `App\Domain\Conversational` | DTO inmutable: `messageId` (WAMID — identidad del mensaje, clave de deduplicación), `phoneNumberId`, `fromPhone`, `text`, `receivedAt`. Ya normalizado (texto plano) — la normalización desde audio/imagen es responsabilidad del Hito 7, nunca de este VO. |
| `OrganizationResolution` | `App\Application\Organizations` | Result DTO con estado (`OrganizationResolutionStatus`: `Resolved\|PendingDisambiguation\|NotFound`) — nunca una excepción para un caso esperado. |

---

## 6. Multi-tenancy

- **Mecanismo:** trait `BelongsToOrganization` (`App\Domain\Tenancy`) + `OrganizationScope` (Global Scope de Eloquent). Todo aggregate que un tenant puede escribir lleva `organization_id` **directamente** en la tabla (nunca solo derivable por join) — regla sin excepciones salvo `Channel` (§4).
- **Estado actual del scope: no-op deliberado.** `OrganizationScope::apply()` solo filtra si algo dejó atado `domain.current_organization_id` en el contenedor — nada lo hace todavía (eso es un hito futuro, resolución de tenant en tiempo real). Es un placeholder correcto, no una promesa incumplida: el día que se resuelva tenant en runtime, el binding ocurre **una sola vez**, en el punto de resolución, nunca como `where('organization_id', ...)` repetido por el código de aplicación.

---

## 7. Concurrencia — dos mecanismos distintos, para dos problemas distintos

**No son intercambiables.** Usar el mecanismo equivocado para el problema del otro es un error de diseño, no una alternativa válida.

### 7.1 `BookingScheduler` — lock a nivel de fila (MariaDB)

Problema: la invariante central de no-solapamiento de `Resource` se valida contra estado **comprometido** en base de datos, y varios flujos futuros (Agente Reservas, comandos admin, una eventual API) van a converger en crear `Booking`s sobre el mismo `Resource`.

Mecanismo: `SELECT ... FOR UPDATE` sobre el `Resource` candidato dentro de una transacción, con reintento a través de slots candidatos si el primero deja de ser válido bajo lock. Revalidación bajo lock es la única fuente de verdad — nunca un chequeo optimista previo.

Prueba real (no solo "el test pasó"): `tests/Feature/Domain/Booking/ConcurrencyTest.php` abre dos conexiones reales a MariaDB y demuestra que la segunda efectivamente espera/bloquea.

### 7.2 `ProcessInboundConversationMessage` — mutex de Redis por conversación

Problema distinto: `InboundMessageRouter` es el único punto de entrada para procesar un mensaje de una conversación (a diferencia de `Booking`, no hay múltiples flujos convergiendo sobre el mismo recurso) — el riesgo es que dos mensajes de la **misma** conversación se procesen en paralelo y produzcan un estado inconsistente en `ConversationSession`/el borrador en `Cache`.

Mecanismo: `Cache::lock("conversation:{phoneNumberId}:{fromPhone}", 30)->block(10)` envolviendo la llamada al Router. Un mensaje que llega mientras otro de la misma conversación se procesa espera hasta 10s; si el lock nunca se libera, el job falla con `LockTimeoutException` (reintentable por la cola, `$tries = 3`).

Defensa en profundidad (no el mecanismo primario): `unique(channel_id, customer_phone)` en `conversation_sessions` — si el mutex fallara, la BD sigue impidiendo una sesión duplicada. El branch de retry ante esa colisión (`EloquentConversationSessionRepository::findOrCreateFor`) no tiene test automatizado porque reproducirlo de verdad requeriría dos procesos reales — documentado inline, mismo límite aceptado que en `ConcurrencyTest.php`.

Prueba real: `tests/Feature/Jobs/ProcessInboundConversationMessageTest.php` fuerza `cache.default=redis` (el resto de la suite usa el driver `array` vía `phpunit.xml`) para probar exclusión mutua contra Redis de verdad, no simulada en memoria de un solo proceso.

### 7.3 Deduplicación de mensajes — un tercer problema, no debe confundirse con 7.2

Un reenvío del webhook de Meta para el **mismo** `message_id` no es un problema de paralelismo (7.2 ya lo cubriría si llegaran simultáneos) sino de **reprocesamiento diferido**: el reenvío puede llegar después de que el mensaje original ya se procesó por completo, cuando el mutex de conversación ya se liberó hace rato. `ShouldBeUnique` de Laravel se evaluó y se descartó explícitamente para esto — libera su lock en cuanto el job *termina*, no durante toda la ventana de reintento de Meta.

Mecanismo real: `Cache::add("inbound_message_processed:{phoneNumberId}:{messageId}", true, ttl)` — `SETNX` atómico, ventana configurable (`config('conversations.message_dedup_hours')`, default 48h, valor conservador sin verificar contra la documentación exacta de Meta). La clave incluye `phoneNumberId`: **no se asume que `message_id` sea único de forma global entre proveedores** (cierto para el WAMID de Meta hoy, falso en general — Telegram, por ejemplo, tiene `message_id` único solo por chat).

Evolución identificada, no implementada (sin evidencia de que haga falta todavía): persistencia de mensajes procesados en BD como defensa adicional si algún día aparecen reintentos fuera de la ventana de Redis o reinicios de infraestructura — que además podría servir de base a la bitácora de auditoría append-only pospuesta.

---

## 8. El patrón "interfaz detrás de todo" — inventario completo (Hito 4)

Regla: ningún componente nuevo debe requerir tocar un caller existente. Se cumple así — cada fila es una interfaz con **una sola** implementación MVP, bindeada en `App\Providers\AppServiceProvider::register()`:

| Interfaz | Implementación MVP | Extensión futura = |
|---|---|---|
| `AvailabilityCalculatorInterface` | `AvailabilityCalculator` | Decorador con cache (Parte XI), sin tocar callers |
| `BookingSchedulerInterface` | `BookingScheduler` | — |
| `NotificationSenderInterface` | `WhatsAppNotificationSender` | Otro canal de salida |
| `ChannelClientInterface` | `MetaWhatsAppClient` | Otro proveedor de WhatsApp o canal (Telegram, etc.) — clase nueva, cero cambios en `WhatsAppNotificationSender` |
| `EntitlementCheckerInterface` | `UnlimitedEntitlementChecker` | Implementación real de límites por plan (Billing) |
| `ChannelResolverInterface` | `PhoneNumberIdChannelResolver` | — |
| `OrganizationResolverInterface` | `SingleOrganizationResolver` | Resolver real con desambiguación por nombre (Parte XIV, disparador: segundo piloto activo) |
| `ConversationSessionRepositoryInterface` | `EloquentConversationSessionRepository` | — |
| `IntentClassifierInterface` | `CompositeIntentClassifier` | Ver §9 |
| `App\Contracts\AiServiceInterface` | Closure → `OpenAIService`/`GrokService`/`GeminiService` según `config('services.intent_classifier.*')` | Nuevo proveedor de IA — un `case` más en el `match` |

**Nota de higiene detectada durante el Hito 4, no corregida (fuera de alcance, código legado):** `App\Services\AI\AIServiceInterface` y su `AIServiceFactory` están desalineados — las implementaciones concretas de `app/Services/AI/*` en realidad satisfacen `App\Contracts\AiServiceInterface` (`getResponse()`), no esa interfaz (`generateResponse()`). El dominio nuevo depende exclusivamente de la interfaz correcta; no se tocó el código legado.

---

## 9. Clasificación de intención — Chain of Responsibility

`AgentSelector` **nunca** interpreta contenido ni estado de conversación — es un lookup puro `Intent → AgentInterface`. Toda la interpretación vive antes, en la cadena de clasificadores:

```
CompositeIntentClassifier (implementa IntentClassifierInterface)
  ├─ ConversationContinuityStrategy   (primero: ¿hay un Intent activo en la sesión?)
  └─ AiIntentClassifierStrategy       (fallback: clasifica por contenido vía IA)
```

- Cada `IntentClassifierStrategy::attempt()` devuelve `?Intent` — `null` significa "sin opinión, seguí a la próxima". Ninguna estrategia devuelve un Agent concreto, ejecuta un Application Command, ni tiene efectos conversacionales/de dominio (regla explícita, validada antes de construir el Hito 4).
- Orden **explícito** en el binding (`AppServiceProvider`), no un registro dinámico con prioridades — mismo criterio que evita un Command Bus genérico.
- Si ninguna estrategia responde (ej. la IA falló), el composite dispara `RouterIntentUnresolved` y devuelve `Intent::FueraDeAlcance` como default — distinto de que la IA haya clasificado activamente `FueraDeAlcance` (resultado de negocio válido, no dispara el evento).
- Agregar una estrategia nueva (ej. comandos admin determinista, Incremento 2) es una clase nueva insertada en el array del binding — cero cambios en `CompositeIntentClassifier`, `AgentSelector` o `InboundMessageRouter`.

---

## 10. `InboundMessageRouter` — contrato del orquestador

```
handle(InboundMessage $message): void

  ChannelResolverInterface::resolve(phoneNumberId)
    → null | inactivo               ⇒ InboundMessageRejected, return
  ConversationSessionRepositoryInterface::findOrCreateFor(channel, fromPhone)
  OrganizationResolverInterface::resolve(channel, session)
    → NotFound                      ⇒ InboundMessageRejected, return
    → PendingDisambiguation         ⇒ InboundMessageRejected, return (ver nota)
    → Resolved                      ⇒ attachOrganization(session, organization)
  IntentClassifierInterface::classify(message, session)
  ConversationSessionRepositoryInterface::recordIntent(session, intent)
  AgentSelector::selectFor(intent)
    → null                         ⇒ InboundMessageRejected, return
  AgentInterface::handle(message, session, organization)
```

**Disciplina que blinda esto contra scope creep** (acordada explícitamente antes de construir el Hito 4, no un accidente de diseño):
- Los únicos condicionales del Router son guardas de flujo sobre resultados **ya resueltos** por sus colaboradores — nunca interpretación de contenido del mensaje.
- El Router **nunca** invoca un Application Command directamente. Quien lo hace es el `AgentInterface` seleccionado — cada Agent recibe por constructor solo el/los Command(s) de su propio allow-list (Parte XI punto 7). "Ejecutar el Command" es responsabilidad del Agent, no del Router.
- Nota sobre `PendingDisambiguation`: la desambiguación interactiva real (preguntarle al cliente cuál es su negocio) es roadmap explícito (Parte XIV, disparador: segundo piloto activo con canal compartido) — hoy se rechaza de forma segura en vez de proceder con una organización adivinada.

---

## 11. Application Commands

Único punto de entrada para mutar el dominio desde fuera de `App\Domain`. Clases explícitas por caso de uso, sin Command Bus genérico:

- **`RegisterOrganizationCommand`** — crea `Organization` + `Location` + `Resource` + `Service` + `ServiceResourceRequirement` + `ResourceSchedule`(s) + vincula el `Channel`, todo en una transacción, consultando `EntitlementCheckerInterface` antes de cada creación limitada por plan.
- **`CreateBookingCommand`** — invoca `BookingSchedulerInterface`, nunca lo bypasea.

Ninguno de los dos está todavía invocado por ningún Agent real (eso es Hito 5/6) — hoy se invocan solo desde tests.

---

## 12. Domain Events — catálogo implementado (no el catálogo completo de la visión)

| Evento | Contexto | Disparado por | Consumido por |
|---|---|---|---|
| `BookingConfirmed` | Booking | `BookingScheduler` | `SendBookingConfirmationNotification` → `NotificationSenderInterface` |
| `InboundMessageRejected` | Conversational | `InboundMessageRouter` (5 guardas distintas, `$reason` los distingue) | Ninguno todavía — observabilidad |
| `RouterIntentUnresolved` | Conversational | `CompositeIntentClassifier` | Ninguno todavía — observabilidad |

El resto del catálogo de la visión (`BookingCancelled`, `BookingRescheduled`, `OrganizationRegistered`, etc.) es vocabulario documentado en el plan de arquitectura, **no código** — se implementa cada evento cuando el incremento que lo consume se construye, nunca antes (Parte XII).

---

## 13. Superficie de configuración nueva

| Archivo | Clave | Propósito |
|---|---|---|
| `config/services.php` | `intent_classifier.{provider,model,key}` | Credenciales de IA **de la plataforma** (no las que cada negocio trae) — usadas por `AiIntentClassifierStrategy`. |
| `config/conversations.php` | `message_dedup_hours` (default 48) | Ventana de deduplicación de `message_id` (§7.3). |
| `.env` | `INTENT_CLASSIFIER_AI_{PROVIDER,MODEL,API_KEY}` | Sin `API_KEY` configurada, resolver `IntentClassifierInterface` lanza `TypeError` al construir el cliente de IA — pendiente de una credencial real antes de que el pipeline complete una clasificación real. |
| `.env` | `CONVERSATION_MESSAGE_DEDUP_HOURS` | Override del default de arriba. |

---

## 14. Filosofía de testing aplicada (no solo declarada)

- **MariaDB real, nunca SQLite**, vía `RefreshDatabase` — los `CHECK` constraints de §4 no son expresables/verificables igual en SQLite.
- **Redis real cuando la garantía depende de Redis específicamente** — `phpunit.xml` fija `CACHE_STORE=array` por defecto (rápido, aislado), pero los tests de mutex/dedup fuerzan `Config::set('cache.default', 'redis')` explícitamente, porque un mutex de aplicación solo es evidencia real si se prueba contra el backend compartido entre procesos que se usa en producción.
- **Dos conexiones reales a la BD** para probar contención genuina (`ConcurrencyTest.php`, el test de `unique(channel_id, customer_phone)`) — nunca solo "el código llamó a `lockForUpdate()`".
- **Result DTOs, nunca excepciones, para resultados esperados** (`OrganizationResolution`, `CreateBookingResult`) — una excepción se reserva para lo verdaderamente excepcional.
- **Fakes que implementan la interfaz real** (`fakeChannelClient`, `aiClassifierFakeService`, agentes anónimos) en vez de mocks de framework donde es viable — si el fake compila y funciona sin conocer la implementación concreta, es evidencia de que el caller depende genuinamente de la interfaz.
- **Límites de lo que un test de un solo proceso puede probar, documentados explícitamente** cuando se topan con esa pared (el branch de retry de `EloquentConversationSessionRepository`, la ejecución paralela real de `BookingScheduler`) — nunca se simula una prueba falsa para forzar cobertura.

---

## 15. Infraestructura (resumen — detalle operativo en `docs/DEPLOYMENT.md`)

- **Docker Compose:** `app`, `nginx`, `mariadb`, `redis`, `queue`, `scheduler` (base, cara a producción) + `docker-compose.override.yml` (solo dev: bind mounts, `phpmyadmin`, `mailpit`).
- **`Dockerfile` multi-stage:** `vendor` → `vendor-dev` → `frontend` → `php-base` (php:8.4-fpm-alpine) → `app` → `app-dev` → `nginx`. El VPS de producción nunca necesita PHP/Composer/Node instalados — todo el build ocurre dentro de la imagen.
- **Healthcheck de aplicación:** `GET /health` (`routes/web.php`), sin auth, sin lógica de dominio.
- **`pcov`** solo en la imagen `app-dev`, nunca en producción.

---

## 16. Relación con el sistema legado (bot de turismo/negociación)

`ARCHITECTURE.md` (raíz) y `spec.md` documentan un sistema **completamente distinto**: negociación de precios de productos vía WhatsApp, modelo `Store`/`Product`/`Lead`. Coexiste con WpbotReserva en el mismo repositorio y la misma infraestructura Docker (DB, Redis), pero:

- Cero tablas compartidas — `organizations` es una tabla nueva, no un rename de `stores`.
- Cero código compartido — `ProcessWhatsAppMessage`, `WhatsAppController`, `WhatsAppService`, los modelos en `app/Models/*` pertenecen exclusivamente al sistema legado y **no se tocan** al construir WpbotReserva.
- `AiIntentClassifierStrategy` reutiliza las clases concretas de `app/Services/AI/*` (vía la interfaz correcta, §8) porque son infraestructura de bajo nivel (llamadas HTTP a proveedores de IA) genuinamente compartible — no reutiliza nada del dominio de negociación.

---

## 17. Principios arquitectónicos que no deben romperse

1. **Dirección de dependencia única:** Conversational → Application → Domain → Infraestructura. Nunca al revés, nunca un salto que se salte una capa (ej. un Agent tocando un aggregate Eloquent directo).
2. **Application Commands son el único punto de mutación del dominio desde fuera.** Ningún Agent, endpoint, o job invoca `BookingScheduler`/`AvailabilityCalculator`/un aggregate directamente.
3. **Todo lo que hable con infraestructura externa (notificación, IA, resolución de canal/organización, persistencia de sesión) vive detrás de una interfaz con una sola implementación MVP.** Extender es agregar una implementación + una línea de binding, nunca una rama condicional dentro de un caller existente.
4. **`InboundMessageRouter` es y debe seguir siendo un orquestador puro.** Sin condicionales sobre contenido del mensaje, sin invocar Commands directamente, sin lógica conversacional o de dominio.
5. **`IntentClassifierStrategy` produce solo un `Intent`.** Nunca un Agent concreto, nunca una llamada a Application Command, nunca un efecto conversacional. Nuevas estrategias se agregan al array ordenado del composite, nunca crecen dentro de una estrategia existente ni dentro del Router.
6. **`AgentSelector` es un lookup puro `Intent → Agent`.** Si algún día la selección necesita depender de algo más que el Intent, esa lógica se extrae a una pieza nueva sin modificar `AgentSelector` ni el Router.
7. **`Channel` es la única excepción a "todo aggregate mutable lleva `organization_id` directo".** Es N:N por diseño (Parte XVI) — cualquier código que lo toque debe razonar explícitamente sobre "para qué organizaciones aplica esto", nunca asumir un solo tenant.
8. **Dos mecanismos de concurrencia, no intercambiables:** lock de fila en MariaDB para invariantes de dominio sobre estado comprometido (`BookingScheduler`); mutex de Redis para serializar un único punto de entrada lógico (`InboundMessageRouter` por conversación). No usar uno para el problema del otro.
9. **Nunca asumir que un identificador de proveedor (message_id, etc.) es único de forma global entre proveedores.** Toda clave de cache/dedup que derive de un ID externo va scopeada por el identificador estable del canal/proveedor.
10. **Nunca tocar código, tablas o modelos del sistema legado** (`app/Models/*`, `ProcessWhatsAppMessage`, `WhatsAppController`, `WhatsAppService`) al construir WpbotReserva. Reutilizar infraestructura de bajo nivel genuinamente compartible (ej. clases de `app/Services/AI/*` vía la interfaz correcta) está bien; reutilizar dominio de negociación no.
11. **Ningún cambio estructural sin evidencia real** (Parte XIII regla 1). Lo que hoy es un recorte deliberado (workflow como enum simple, `cancellation_policy` como texto libre, sin desambiguación interactiva de organización) se documenta como tal, con su disparador de activación nombrado — nunca se construye por adelantado "porque total es barato".
12. **Tests contra infraestructura real cuando la garantía depende de esa infraestructura específica** (MariaDB real por los `CHECK` constraints, Redis real para mutex/dedup). Un test que pasa contra un doble no es evidencia de que el mecanismo real funciona.
13. **Cada hito termina funcionando de punta a punta, nunca al 80%**, con su propio commit como punto de restauración y su verificación evidence-based (suite completa, cobertura de lo nuevo, Pint, salud del stack Docker) antes de darlo por cerrado.
