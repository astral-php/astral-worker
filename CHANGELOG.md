# Changelog

## [0.1.0] - 2026-10-25

### Ajouté
- Contrats `QueueInterface`, `JobInterface`, `JobResolverInterface`
- Drivers : `SyncQueue` (dev/tests), `DatabaseQueue` (SQLite + MySQL via PDO), `RedisQueue` (ext-redis)
- `Worker` : retries, backoff linéaire, `failed_jobs`, arrêt sur `--max-jobs` / `--max-time` / mémoire, signaux SIGTERM/SIGINT si `pcntl` est disponible
- Commandes CLI : `worker:work`, `queue:failed`, `queue:retry`, `queue:flush`
- Jobs fournis : `SendMailJob` (aligné `Mailer::send(): void`), `GeneratePdfJob` (+ `PdfRendererInterface`)
- `WorkerServiceProvider` (pilotage par `.env`, `WORKER_PDF_DIR` / `BASE_PATH`)
- Migrations SQLite/MySQL (`jobs`, `failed_jobs`)
- Stub `stubs/.env.worker.example`
- Dépendance `astral-php/astral-core` `^1.2`
