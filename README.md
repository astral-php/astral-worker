# astral-worker

File d'attente et worker pour **Astral MVC** : envoi d'e-mails et génération de PDF **en arrière-plan**, sans pénaliser la requête HTTP.

> Philosophie Astral respectée : **explicite, opt-in, sans magie**. Pas de lazy-loading, tout passe par un Service Provider et le Container ; le driver se choisit dans `.env` ; migrations SQLite + MySQL.

## Principe

```
Requête HTTP ──► Listener ──► $queue->push(Job, ['order_id' => 12])   (quelques ms)
                                        │
                                        ▼  table `jobs` (ou Redis)
Worker (CLI / cron) ──► pop ──► Job::handle($payload) ──► mail SMTP / PDF
                           └── échec ──► retry avec backoff ──► failed_jobs
```

| Driver | `QUEUE_DRIVER` | Usage |
|---|---|---|
| Base de données | `database` (défaut) | Production, mutualisé, VPS — SQLite ou MySQL, zéro dépendance |
| Redis | `redis` | Gros volumes (nécessite `ext-redis`) |
| Synchrone | `sync` | Dev / tests : le job s'exécute tout de suite, les erreurs remontent |

## Compatibilité

- PHP `^8.1`
- Astral MVC `^1.2` (`astral-core`) — utilise `Container`, `ServiceProviderInterface`, `Console`, `Logger`, `Mailer`

## Installation

**1. Package** (en local, avant publication : décompressez ce dossier à côté de votre site)

```json
// composer.json du site
"repositories": [{ "type": "path", "url": "../astral-worker" }]
```
```bash
composer require astral-php/astral-worker:@dev
```
Une fois publié sur Packagist : `composer require astral-php/astral-worker`.

**2. Variables d'environnement** (`.env`)

Copier le stub : `cp vendor/astral-php/astral-worker/stubs/.env.worker.example .env` (ou fusionner dans votre `.env` existant).

```dotenv
QUEUE_DRIVER=database        # database | redis | sync
QUEUE_RETRY_AFTER=90         # s : un job réservé depuis plus longtemps est repris (worker mort)

# Génération PDF (GeneratePdfJob) — chemin absolu recommandé
# WORKER_PDF_DIR=/var/www/site/storage/pdf

# Redis (uniquement si QUEUE_DRIVER=redis)
# REDIS_HOST=127.0.0.1
# REDIS_PORT=6379
# REDIS_PASSWORD=
# REDIS_DB=0
# QUEUE_PREFIX=astral:queue:
```

**3. Service Provider** (`config/dependencies.php`) — après les providers qui fournissent `PDO`, `Logger` et `Mailer`

```php
use Astral\Worker\WorkerServiceProvider;

return [
    FrameworkServiceProvider::class,
    DatabaseServiceProvider::class,
    AppServiceProvider::class,
    // MailServiceProvider::class,
    WorkerServiceProvider::class,   // ← nouveau
];
```

Le provider enregistre `QueueInterface`, `Worker`, `JobResolverInterface` et `GeneratePdfJob`. Rien n'est instancié (ni connexion Redis) avant la première utilisation.

**4. Migrations** (drivers `database` uniquement)

```bash
cp vendor/astral-php/astral-worker/database/migrations/*.php database/migrations/
php bin/console migrate
```
Crée `jobs` et `failed_jobs` (SQLite et MySQL, via `Astral\Worker\Database\Schema`).

**5. Commandes CLI** (`bin/console`)

```php
use Astral\Worker\Console\CommandFactory;

// $container : celui déjà construit dans votre bin/console
foreach (CommandFactory::all(fn (string $id) => $container->make($id)) as $command) {
    $console->register($command);
}
```

| Commande | Rôle |
|---|---|
| `php bin/console worker:work` | Traite la file |
| `php bin/console queue:failed` | Liste les jobs en échec |
| `php bin/console queue:retry <id>\|all` | Remet un job (ou tous) en file |
| `php bin/console queue:flush` | Vide les jobs en échec |

Options de `worker:work` : `--queue=default` `--sleep=3` `--max-jobs=0` `--max-time=0` `--stop-when-empty` `--once` `--memory=128` `--tries=3` `--backoff=30`

## Utilisation

### 1. Envoyer un mail sans bloquer la requête

Remplacez l'envoi synchrone d'un listener par une mise en file (voir `examples/SendWelcomeEmailListener.php`) :

```php
use Astral\Worker\Job\SendMailJob;
use Astral\Worker\Queue\QueueInterface;

final class SendWelcomeEmail /* implements ListenerInterface */
{
    public function __construct(private QueueInterface $queue) {}

    public function handle($event): void
    {
        $this->queue->push(SendMailJob::class, [
            'to'      => $event->user->email,
            'subject' => 'Bienvenue !',
            'body'    => '…',
        ]);
    }
}
```

`SendMailJob` appelle le `Mailer` (PHPMailer) du framework, avec 5 tentatives et un backoff de 60 s.

### 2. Écrire un job applicatif

```php
namespace App\Jobs;

use Astral\Worker\Job\JobInterface;

final class SendOrderConfirmationJob implements JobInterface
{
    public const TRIES   = 5;    // optionnel
    public const BACKOFF = 60;   // optionnel : délai = BACKOFF × n° de tentative

    public function __construct(private OrderDao $orders, private Mailer $mailer) {} // autowiré

    public function handle(array $payload): void
    {
        $order = $this->orders->findById((int) $payload['order_id']);
        if ($order === null) { return; }
        $this->mailer->send($order->email, 'Confirmation #' . $order->id, '…');
    }
}

// Dans un listener d'OrderPlaced :
$this->queue->push(SendOrderConfirmationJob::class, ['order_id' => $event->order->id]);
```

**Règle d'or : le payload ne contient que des IDs et des scalaires** (JSON). Le job recharge les données via les DAOs : pas d'objet sérialisé, pas de données périmées.

### 3. Générer un PDF (facture…)

Le worker reste générique : le moteur PDF est choisi par l'application.

1. `composer require dompdf/dompdf`
2. Copiez `examples/DompdfRenderer.php` dans `app/Services/` et enregistrez-le :
   ```php
   $container->singleton(
       Astral\Worker\Job\PdfRendererInterface::class,
       fn (Container $c) => new App\Services\DompdfRenderer($c->make(Core\View::class))
   );
   ```
3. Générique : `$queue->push(GeneratePdfJob::class, ['template' => 'invoices/pdf', 'data' => [...], 'output' => 'invoices/2026-0001.pdf'])`
   — ou, pour une facture, un job applicatif qui reçoit un `order_id` (`examples/GenerateInvoicePdfJob.php`).

Le PDF est écrit de façon atomique dans `WORKER_PDF_DIR` (défaut : `storage/pdf` à la racine du projet). Les chemins `..`, absolus ou hors `.pdf` sont refusés.

Cas typique `astral-shop` : `OrderPlaced` → deux jobs (mail de confirmation + facture PDF), enchaînables dans le même listener.

### 4. Différer un job / utiliser plusieurs files

```php
$queue->push(Job::class, ['id' => 1], delay: 300);              // dans 5 minutes
$queue->push(SendMailJob::class, [...], 0, 'emails');           // file « emails »
```
```bash
php bin/console worker:work --queue=emails
```

## Faire tourner le worker

### Développement (Laragon, etc.)

`QUEUE_DRIVER=sync` : aucun worker, les jobs s'exécutent dans la requête.
Pour tester le vrai flux : `php bin/console worker:work` dans un terminal.

### Hébergement mutualisé / cron (aucun processus persistant)

```cron
# Variante légère : vide la file puis quitte (latence jusqu'à 1 min)
* * * * * cd /chemin/site && php bin/console worker:work --stop-when-empty >> storage/logs/worker.log 2>&1

# Variante quasi temps réel : tourne ~55 s, relancé chaque minute (flock évite l'empilement)
* * * * * cd /chemin/site && flock -n /tmp/astral-worker.lock php bin/console worker:work --max-time=55 >> storage/logs/worker.log 2>&1
```

Windows (Planificateur de tâches) :
```bat
schtasks /Create /SC MINUTE /MO 1 /TN "astral-worker" /TR "php C:\laragon\www\site\bin\console worker:work --stop-when-empty"
```

### VPS / serveur dédié (daemon)

Exemple Supervisor — `--max-time` recycle le process régulièrement (fuites mémoire), Supervisor le relance :

```ini
[program:astral-worker]
command=php /var/www/site/bin/console worker:work --sleep=3 --max-time=3600
directory=/var/www/site
autostart=true
autorestart=true
numprocs=2
process_name=%(program_name)s_%(process_num)02d
user=www-data
stopwaitsecs=60
stdout_logfile=/var/www/site/storage/logs/worker.log
```

Après un déploiement, relancez le worker (`supervisorctl restart astral-worker:*`) pour qu'il charge le nouveau code. Avec `pcntl` (Linux), SIGTERM/SIGINT terminent proprement le job en cours.

## Fiabilité

- **Livraison *at-least-once*** : un job peut s'exécuter deux fois (worker tué après l'envoi, avant l'acquittement). Rendez vos jobs **idempotents** (ex. : vérifier un champ `confirmation_sent_at` avant d'envoyer).
- **Retries** : `TRIES` (défaut 3), backoff linéaire `BACKOFF × tentative` (défaut 30 s).
- **Échec définitif** : le job passe dans `failed_jobs` avec l'exception → `queue:failed`, `queue:retry`.
- **Worker tué** : un job réservé depuis plus de `QUEUE_RETRY_AFTER` s est repris ; s'il a déjà épuisé ses tentatives sans jamais finir, il est abandonné. Choisissez `QUEUE_RETRY_AFTER` **supérieur à la durée du job le plus long**.
- **Concurrence** : plusieurs workers en parallèle sont sûrs (réservation atomique). SQLite n'a qu'un écrivain : privilégiez 1 worker, ou MySQL / Redis au-delà.
- **PDO** doit être en `ERRMODE_EXCEPTION` (c'est le cas du `Connection` Astral).

## Structure

```
astral-worker/
├── composer.json · phpunit.xml · LICENSE · CHANGELOG.md
├── stubs/.env.worker.example   # variables QUEUE_* / WORKER_PDF_DIR
├── database/migrations/        # jobs, failed_jobs (à copier dans database/migrations/ du site)
├── examples/                   # listener, job, renderer dompdf, job facture
├── src/Astral/Worker/
│   ├── WorkerServiceProvider.php
│   ├── Worker.php · WorkerOptions.php
│   ├── Queue/                  # QueueInterface, DatabaseQueue, RedisQueue, SyncQueue, QueuedJob
│   ├── Job/                    # JobInterface, résolveur, SendMailJob, GeneratePdfJob
│   ├── Console/                # worker:work, queue:failed, queue:retry, queue:flush
│   ├── Database/Schema.php     # DDL SQLite + MySQL (source unique)
│   └── Exception/
└── tests/                      # PHPUnit, SQLite en mémoire
```

## Tests

```bash
composer install
vendor/bin/phpunit
```
Les tests (`DatabaseQueue`, `Worker`, `SyncQueue`) tournent sur SQLite en mémoire et n'ont pas besoin d'`astral-core`. Le driver Redis nécessite un serveur Redis et n'est pas couvert par la suite par défaut.

## Points à vérifier à l'intégration

Ce package a été écrit à partir du README public d'Astral (`astral-php/astral`) et de la convention d'`astral-form`. Contrôlez ces points dans votre version d'`astral-core` :

1. **`Core\Console\Console`** : les commandes utilisent `$console->success()` et `echo` pour le reste ; remplacez par les helpers ANSI de votre version si vous préférez.
2. **`bin/console`** : l'accès au `Container` dans ce fichier peut différer de l'exemple ci-dessus.
3. **`Core\Mailer\Mailer::send($to, $subject, $html): void`** : aligné astral-core ^1.2 (exception si échec). Adaptez `SendMailJob` si vous ajoutez pièces jointes / texte brut.
4. **Namespaces** `EventInterface` / `ListenerInterface` : à recopier depuis vos listeners existants.
5. **`Container::make()`** doit pouvoir autowirer les jobs ; sinon, déclarez-les avec `$container->bind(MonJob::class, fn ($c) => new MonJob(...))`, comme pour vos contrôleurs.

## Évolutions envisagées

- Mode daemon avec rechargement automatique au changement de code
- Jobs uniques (déduplication), chaînes de jobs
- Timeout par job (avec `pcntl_alarm`)
- Commande `queue:size` / tableau de bord admin dans `astral-template`

## Licence

MIT
