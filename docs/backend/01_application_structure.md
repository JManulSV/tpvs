# Backend Application Structure

## Current Structure

```text
backend/
├── app/
│   ├── Http/Controllers/
│   ├── Models/
│   └── Providers/
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── public/
├── resources/
├── routes/
├── storage/
├── tests/
├── artisan
├── composer.json
└── Dockerfile
```

## Ownership

| Location | Responsibility |
| --- | --- |
| `routes/` | Route declarations only |
| `app/Http/Controllers/` | HTTP coordination |
| `app/Models/` | Eloquent persistence, casts, and relationships |
| `app/Providers/` | Service container and application bootstrapping |
| `database/migrations/` | Database schema changes |
| `database/factories/` | Test data factories |
| `database/seeders/` | Development and test seed data |
| `tests/Feature/` | HTTP and workflow behavior |
| `tests/Unit/` | Isolated logic |
| `config/` | Configuration resolved from environment variables |
| `storage/` | Logs, cache, sessions, and application-managed files |

## Target Expansion

Create these locations only when a real feature requires them:

```text
app/
├── Actions/
├── Domain/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Resources/
├── Jobs/
├── Models/
├── Policies/
├── Services/
└── Support/
```

Use domain ownership for business code. Do not create one generic service directory that becomes a container for unrelated workflows.
