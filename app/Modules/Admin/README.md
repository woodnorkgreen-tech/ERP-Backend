# Admin Module (Backend)

## Overview
This module provides the API infrastructure for system-wide administrative tasks, primarily focusing on User Management and Role-Based Access Control (RBAC).

## Directory Structure
```
backend/app/Modules/Admin/
├── Http/
│   ├── Controllers/
│   │   ├── PermissionController.php  # Listing system permissions
│   │   ├── RoleController.php        # Role CRUD and Permission sync
│   │   └── UserController.php        # User CRUD, Status, Role Assignment
└── ...
```

## Key Components

### Controllers
- **UserController**:
    - `index`: Paginated list of users with filtering.
    - `availableEmployees`: Fetches employees who don't yet have a user account.
    - `store`: Creates a new user linked to an employee.
    - `update`: Updates user details, roles, and status.
- **RoleController**:
    - Uses `Spatie\Permission\Models\Role`.
    - Handles `syncPermissions` to map granular permissions to roles.
- **PermissionController**:
    - Provides access to the list of permissions defined in the database (synced from `Permissions.php` constants).

### Security
- **Middleware**: All routes are protected by `auth:sanctum`.
- **RBAC**: Uses `permission:` middleware to enforce access based on the logged-in user's roles and permissions.

### Integration
- **HR Module**: Links users to `Employee` models.
- **Spatie Permission**: Fully integrated for role and permission management.

## API Routes
Routes are registered in `backend/routes/api.php` under the `/admin` prefix.
Example endpoints:
- `GET /api/admin/users`
- `POST /api/admin/roles`
- `PUT /api/admin/users/{id}/roles`
