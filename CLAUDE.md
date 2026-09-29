# CLAUDE.md

## Project Summary

This repository is a Laravel monolith for the SiMarsel V2 project. It follows the default Laravel 13 structure with an MVC architecture, Blade views, Eloquent ORM, and Laravel routing for HTTP endpoints.

The primary objective is to build a structured, maintainable web application for managing work, tracking progress, and supporting operational workflows in a single system.

## Tech Stack

- PHP 8.3+
- Laravel 13
- Blade templating
- Vite for frontend assets
- PHPUnit for testing
- Composer for dependency management
- NPM for frontend assets

## Important Project Structure

- `app/` — application logic, models, controllers, providers, services
- `routes/` — HTTP route definitions
- `resources/views/` — Blade templates
- `database/migrations/` — database schema migrations
- `database/seeders/` — seed data
- `config/` — application configuration
- `public/` — public web entrypoint
- `tests/` — automated tests
- `storage/` — runtime files
- `bootstrap/` — application bootstrap

## Development Rules

### 1. Follow Laravel conventions

- Prefer Artisan commands when generating code:
    - `php artisan make:model`
    - `php artisan make:controller`
    - `php artisan make:migration`
    - `php artisan make:request`
    - `php artisan make:policy`
    - `php artisan make:seeder`
- Use Eloquent ORM instead of raw SQL unless absolutely necessary.
- Use dependency injection in controllers and services.
- Do not place business logic directly in route files.

### 2. Keep code organized

- Group routes by feature or responsibility.
- Keep controllers focused on request/response handling.
- Put business logic in service classes or model methods where appropriate.
- Keep Blade views simple and presentation-focused.

### 2a. Recommended architecture: Service-first, Repository when needed

This project should follow a service-oriented structure:

- `Controller` → handles HTTP input and output
- `Service` → contains business logic and orchestration
- `Repository` → encapsulates data access logic when needed
- `Model` → defines relationships, scopes, and simple business rules

Use this pattern:

- Put validation, workflow orchestration, and domain logic in services.
- Use repositories for complex queries, repeated database logic, or data-access abstraction.
- Do not put business logic directly in controllers.
- Do not create repositories for every tiny model unless data access is genuinely shared or complex.
- Prefer simple Eloquent calls in a service before adding a repository for trivial CRUD.

When a feature is small and simple, a service alone is often enough. Add a repository only when data access is repeated, becomes complex, or needs to be isolated for testing and reuse.

### 3. Database and migrations

- Always use migrations for schema changes.
- Never modify production data manually.
- Use seeders only for initial or dummy data.
- Keep model and migration definitions consistent.

### 4. Input validation

- Use Form Request classes for validation.
- Validate on the server side only.
- Do not rely on frontend validation alone.

### 5. Security

- Use `@csrf` in HTML forms.
- Use authentication and authorization policies for protected features.
- Prevent SQL injection by using Eloquent or query builder methods.
- Use `Hash` for passwords.
- Sanitize and escape output in Blade templates.

### 6. Testing

- Write tests for new features and bug fixes.
- Prefer feature tests or focused unit tests for the changed behavior.
- Run the relevant test set after making changes.
- Examples:
    - `php artisan test`
    - `php artisan test --filter=SomeTestName`

## Local Setup

### Initial install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

### Database

This project uses MySQL as the default database.

Update `.env` with the MySQL connection settings:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=simarsel
DB_USERNAME=root
DB_PASSWORD=
```

Then run:

```bash
php artisan migrate
```

### Frontend assets

```bash
npm install
npm run build
```

### Run the app

```bash
php artisan serve
```

Access:

```text
http://localhost:8000
```

## Common Commands

```bash
php artisan serve
php artisan route:list
php artisan make:model ModelName
php artisan make:controller ControllerName
php artisan make:migration create_table_name
php artisan make:request StoreSomethingRequest
php artisan make:seeder SeederName
php artisan migrate
php artisan migrate:fresh --seed
php artisan test
php artisan optimize
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

## Code Standards

- Follow Laravel naming conventions.
- Keep class, file, method, and variable names clear and consistent.
- Use English names for code symbols unless the user explicitly requests otherwise.
- Keep code clean, concise, and readable.
- Avoid excessive comments; only add them when logic is not obvious.
- Prioritize maintainability and reusability.

## Recommended Patterns

### Controller

```php
public function store(StoreUserRequest $request)
{
    $user = User::create($request->validated());

    return redirect()->route('users.index')->with('success', 'User created successfully.');
}
```

### Model

- Put relationships in the model.
- Use accessors and mutators when needed.
- Avoid putting complex queries directly in Blade views.

### Blade

- Use reusable components for repeated UI.
- Do not put business logic in Blade templates.
- Use native Blade structures such as `@foreach`, `@if`, and `@csrf`.

## AI / Agent Workflow

### Before changing code

1. Read only the files needed to understand the task.
2. Identify the affected routes, controllers, models, and migrations.
3. Check whether similar functionality already exists.
4. Make the smallest relevant change.

### After changing code

1. Run the relevant tests.
2. Check for PHP syntax or runtime errors.
3. Confirm migrations and config remain consistent.
4. Do not change unrelated files.

### When errors occur

- Check the output from `php artisan test` or other relevant Artisan commands.
- Focus on the root cause rather than patching blindly.
- Install missing dependencies with Composer or NPM as needed.

## Git Conventions

- Use clear and descriptive commit messages.
- Use feature or fix branches appropriately.
- Do not commit generated build output or vendor files unless explicitly required.
- Ensure changes are tested before committing.

## General Project Direction

This project is expected to grow as a maintainable Laravel monolith, with clean architecture and room for future features such as:

- user/admin authentication
- role and permission management
- CRUD modules
- admin dashboard
- reporting and export features
- internal API support if needed later

Do not over-engineer this repo into a microservice architecture. Since this is a monolith, the priority is clean structure, manageable scale, and high maintainability.

## Special Rules for AI

- Prioritize Laravel best practices.
- Do not change global configuration without a valid reason.
- If a task affects the database, always consider migrations and seeders.
- If a task touches auth or authorization, account for policy, middleware, and route rules.
- Do not rewrite the default framework structure without a clear justification.
- Keep changes aligned with the needs of the SiMarsel V2 project rather than generic Laravel defaults.

## Recommended Quick Start

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## Final Guidance

This repository should be treated as a modern Laravel monolith built with maintainability and clarity in mind. Use a simple, safe, and scalable approach. Prioritize the features that are truly needed, follow the standard Laravel structure, and ensure every change is tested before being considered complete.
