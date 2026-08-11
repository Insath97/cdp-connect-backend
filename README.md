# CDP Connect API

> **Confidential** — This repository and its contents are proprietary to **CDP Empire (Pvt) Ltd**. Unauthorized access, copying, or distribution is strictly prohibited.

---

## Overview

CDP Connect API is the backend REST API powering the CDP Empire investment management platform. It handles the complete lifecycle of customer investments, agent hierarchy management, commission tracking, target management, payout scheduling, legal documentation, and regulatory compliance for CDP Empire (Pvt) Ltd — a Sri Lankan financial services company.

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Framework** | Laravel 12 (PHP 8.2+) |
| **Database** | MySQL 8.0 |
| **Authentication** | JWT (php-open-source-saver/jwt-auth) |
| **Authorization** | Spatie Laravel Permission (Roles & Permissions) |
| **Queue** | Database driver |
| **SMS Gateway** | Dialog e-SMS API v2 |
| **Social Login** | Google OAuth (Laravel Socialite) |
| **Testing** | Pest PHP |
| **Build Tool** | Vite 7 |

---

## Prerequisites

- PHP 8.2 or higher
- MySQL 8.0
- Composer
- Node.js & NPM
- Laragon (recommended for local development on Windows)

---

## Installation

1. **Clone the repository**
   ```bash
   git clone <repo-url>
   cd cdp-connect-api
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install frontend dependencies**
   ```bash
   npm install
   ```

4. **Configure environment**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Configure `.env`** with your database credentials, JWT secret, SMS gateway credentials, and other environment-specific values.

6. **Run database migrations & seeders**
   ```bash
   php artisan migrate --seed
   ```

7. **Start the development server**
   ```bash
   composer dev
   ```
   This runs the API server, queue worker, log viewer, and Vite dev server concurrently.

---

## Project Structure

```
app/
├── Console/Commands/        # Custom artisan commands
├── Http/
│   ├── Controllers/
│   │   ├── External/        # External API integrations
│   │   └── V1/              # API v1 controllers (29 controllers)
│   ├── Middleware/           # Custom middleware
│   └── Requests/            # Form request validators (34 requests)
├── Mail/                    # Mailable classes
├── Models/                  # Eloquent models (23 models)
├── Services/                # Business logic services
├── Traits/                  # Reusable traits
└── Utilities/               # Helper utilities
config/                      # Configuration files
database/
├── migrations/              # 54 migration files
├── seeders/                 # Database seeders
└── factories/               # Model factories
routes/                      # Route definitions (5 files)
tests/                       # Unit & Feature tests
```

---

## Architecture

### Organizational Hierarchy

The system enforces a **17-level sales hierarchy** with tree-based parent-child relationships:

```
Level 1:  General Sales Manager (GSM)
Level 2:  Head of National Sales (HNS)
Level 3:  Senior Provincial Sales Manager
Level 4:  Provincial Sales Manager
Level 5:  Senior Zonal Sales Manager
Level 6:  Zonal Sales Manager
Level 7:  Deputy Zonal Sales Manager
Level 8:  Senior Regional Sales Manager
Level 9:  Regional Sales Manager
Level 10: Assistant Regional Sales Manager
Level 11: Senior Branch Sales Manager
Level 12: Branch Sales Manager
Level 13: Assistant Branch Sales Manager
Level 14: Senior Group Leader (SGL)
Level 15: Group Leader (GL)
Level 16: Senior Consultant (SC)
Level 17: Consultant (C)
```

### Geographic Hierarchy

```
Country → Province → Zone → Region → Branch
```

### Investment Lifecycle

```
Quotation → Investment Created (Pending) → Approved → Policy Generated
                                              ↓
                                     Cancel / Terminate
                                              ↓
                                      Refund + Commission Recovery
```

---

## API Documentation

### Authentication

All protected endpoints require a valid JWT token via the `Authorization: Bearer {token}` header or HTTP-only cookie.

### Base URL

```
/api/v1
```

### Response Format

```json
{
  "status": true,
  "message": "Success message",
  "data": { ... }
}
```

### Public Endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/health` | Health check with DB status |
| `POST` | `/api/v1/login` | User login (JWT) |

### Protected Endpoints

#### Auth & Profile
| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/api/v1/logout` | Logout |
| `GET` | `/api/v1/me` | Current user info |
| `GET/PATCH` | `/api/v1/profile` | View/update profile |
| `PATCH` | `/api/v1/profile/change-password` | Change password |

#### Users
| Method | Endpoint | Description |
|---|---|---|
| `GET/POST` | `/api/v1/users` | List / Create users |
| `GET/PUT/DELETE` | `/api/v1/users/{id}` | Show / Update / Delete user |
| `PATCH` | `/api/v1/users/{id}/toggle-status` | Toggle active status |

#### Investments
| Method | Endpoint | Description |
|---|---|---|
| `GET/POST` | `/api/v1/investments` | List / Create investments |
| `GET/PUT/DELETE` | `/api/v1/investments/{id}` | Show / Update / Delete |
| `POST` | `/api/v1/investments/{id}/approve` | Approve & generate policy |
| `POST` | `/api/v1/investments/{id}/cancel` | Cancel investment |
| `POST` | `/api/v1/investments/{id}/terminate` | Terminate investment |
| `GET` | `/api/v1/investments/{id}/certificate` | Print certificate |
| `GET` | `/api/v1/investments/maturity-report` | Maturity report |

#### Customers
| Method | Endpoint | Description |
|---|---|---|
| `GET/POST` | `/api/v1/customers` | List / Create customers |
| `GET/PUT/DELETE` | `/api/v1/customers/{id}` | Show / Update / Delete |
| `PATCH` | `/api/v1/customers/{id}/restore` | Restore soft-deleted |
| `DELETE` | `/api/v1/customers/{id}/force` | Permanent delete |

#### Quotations
| Method | Endpoint | Description |
|---|---|---|
| `GET/POST` | `/api/v1/quotations` | List / Create |
| `GET/PUT/DELETE` | `/api/v1/quotations/{id}` | Show / Update / Delete |

#### Targets
| Method | Endpoint | Description |
|---|---|---|
| `GET/POST` | `/api/v1/targets` | List / Create targets |
| `GET` | `/api/v1/my-targets` | Current user's targets |
| `POST` | `/api/v1/targets/bulk-setup` | Bulk copy targets to new month |

#### Reports
| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/reports/hierarchy` | Hierarchy performance |
| `GET` | `/api/v1/reports/agent-performance` | Agent search & performance |
| `GET` | `/api/v1/reports/hierarchy-performance` | Hierarchy performance search |
| `GET` | `/api/v1/reports/hierarchy-detailed` | Detailed hierarchy + business |
| `GET` | `/api/v1/reports/investor-maturity` | Investor maturity report |
| `GET` | `/api/v1/reports/plan-wise-hierarchy` | Plan-wise hierarchy breakdown |
| `GET` | `/api/v1/reports/plan-wise-admin` | Plan-wise admin breakdown |

#### Geographic Entities (CRUD + Toggle)
- Countries, Provinces, Regions, Zones, Branches

#### Other Modules
- Welcome Calls, Billings, Investment Payouts, Receipts, Legal Agreements
- Permissions, Roles, Levels
- Activity Logs, Bulk Import, SMS, Database Export/Import

---

## Key Features

- **Investment Lifecycle Management** — Full workflow from quotation to maturity with auto-generated policy numbers
- **17-Level Hierarchical Access Control** — Role-based permissions with data visibility scoped to user hierarchy
- **Commission Engine** — Two-tier commission calculation (unit head + parent override)
- **Target & Achievement Tracking** — Monthly targets with hierarchical achievement propagation
- **Automated Notifications** — Email and SMS notifications for key events
- **Legal Document Generation** — Multi-language legal agreements (English, Sinhala, Tamil)
- **Bulk Data Management** — CSV import/export, database backup/restore
- **Activity Logging** — Complete audit trail for all user actions
- **ROI Calculation** — Fixed and variable rate investment return calculations

---

## Default Credentials

After running seeders, the default admin account is:

| Field | Value |
|---|---|
| **Email** | `admin@cdpempire.com` |
| **Password** | `password` |

> **Note:** Change these credentials immediately in production.

---

## Custom Artisan Commands

| Command | Description |
|---|---|
| `php artisan targets:bulk-setup {source} {target}` | Copy targets from one month to another |
| `php artisan app:send-monthly-payout-notifications` | Send payout emails & SMS |
| `php artisan recalculate-payouts` | Recalculate payout schedules |
| `php artisan recalculate-targets` | Recalculate target achievements |

---

## Testing

```bash
# Run all tests
composer test

# Run with coverage
php artisan test --coverage
```

---

## Deployment Notes

- Ensure `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- Set a strong `APP_KEY` and `JWT_SECRET`
- Configure proper mail and SMS credentials
- Set up queue workers for background jobs: `php artisan queue:work`
- Schedule cron: `php artisan schedule:run`
- Symlink storage: `php artisan storage:link`

---

## Security

- JWT tokens are stored in HTTP-only secure cookies
- Token TTL: 60 minutes (configurable via `JWT_TTL`)
- Refresh TTL: ~14 days
- All sensitive operations are logged via `ActivityLogTrait`
- Soft deletes enabled on core models (Customer, Investment, Quotation)

---

## License

This is **proprietary software** owned by **CDP Empire (Pvt) Ltd**. All rights reserved. This repository and its contents may not be copied, modified, distributed, or used in any manner without prior written consent from CDP Empire (Pvt) Ltd.

---

<p align="center"><b>CDP Empire (Pvt) Ltd</b><br>Ceylon Development Plantation Empire</p>
