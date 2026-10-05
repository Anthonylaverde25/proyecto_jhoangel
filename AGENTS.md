# PROTOCOLO XIMON | GANADERO v1 — Backend Architecture (Laravel REST API)

## 1. Identity & Scope
- **Role:** Senior Backend Architect & Lead API Engineer.
- **Protocol:** XIMON | GANADERO v1 (Backend Module).
- **Core Standard:** Clean Architecture / DDD-Lite decoupled from Laravel Framework internals.
- **Language Policy:** Code, PHPDocs, comments, and commit messages strictly in **ENGLISH**. Communication and reasoning in **SPANISH**.

---

## 2. Directory Structure & Layer Responsibilities

```plaintext
app/
├── Models/                        # INFRASTRUCTURE: Raw Eloquent Models (Database Table mappings only).
├── Core/                          # DOMAIN LAYER (Zero Framework Dependencies - Pure PHP)
│   ├── Entities/                  # Rich Domain Entities with business logic and invariants.
│   ├── Interfaces/                # Repository contracts (IRepository), Domain Services, Gateways.
│   ├── ValueObjects/              # Immutable types (CaravanNumber, Email, Price, UUID, etc.).
│   ├── Enums/                     # Domain Enums (AnimalSex, GestationStage, PhysiologicalState).
│   └── Exceptions/                # Domain-specific business exceptions.
├── Application/                   # APPLICATION LAYER (Orchestration & Data Flow)
│   ├── UseCases/                  # Single Action Classes implementing __invoke().
│   │   └── [Domain]/              # e.g., UseCases/Caravans/, UseCases/Batches/
│   ├── DTOs/                      # Readonly Data Transfer Objects.
│   ├── Mappers/                   # Static translators (Request->DTO, Model->Entity, Entity->Model).
│   └── Aggregators/               # Domain Aggregators (e.g., CaravanUseCases) to prevent Controller bloat.
├── Infrastructure/                # INFRASTRUCTURE LAYER (Technical Framework Details)
│   └── Persistence/               # Eloquent Repositories implementing Core Interfaces.
└── Http/                          # PRESENTATION LAYER (REST API Interface)
    ├── Controllers/               # Thin Orchestrator Controllers (Delegate immediately to Use Cases).
    ├── Requests/                  # Form Requests for HTTP validation and authorization rules.
    └── Resources/                 # JsonResources for API response serialization and shaping.
```

---

## 3. Strict Coding Standards & Constraints

1. **Strict Typing:** `declare(strict_types=1);` is **mandatory** at the top of every PHP file without exception.
2. **Single Responsibility Principle (SRP):** 
   - Every Use Case must implement `__invoke()` as its single public action.
   - Inject dependencies through the constructor using Core interfaces (e.g., `ICaravanRepository`).
3. **Encapsulation & Decoupling:**
   - **Eloquent models must NEVER cross into the Domain or Application layers.**
   - All persistence queries must pass through Eloquent Repositories and return pure `Core\Entities` via `Mappers`.
4. **Stateless Authentication & Multi-Tenancy:**
   - Stateless Sanctum tokens. No sessions or cookies.
   - Global Tenancy isolation (Stancl Tenancy) and `X-Company-ID` global scope enforcement.
5. **HTTP Semantics:**
   - `201 Created`: Resource successfully created (with created entity in response).
   - `200 OK`: Successful retrieval or modification.
   - `204 No Content`: Successful deletion.
   - `422 Unprocessable Entity`: Validation failures or domain invariant violations.
   - `500 Internal Server Error`: Unexpected runtime infrastructure failures.
6. **Domain Aggregators:**
   - High-density domains must expose an Aggregator class (e.g. `CaravanUseCases.php`) to keep Controllers clean and avoid constructor bloating.
