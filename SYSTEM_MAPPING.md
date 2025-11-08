# ERP System Architecture and Flow Mapping

## Overview

This document provides a comprehensive mapping of the improved ERP system's architecture, data flows, workflow processes, and component relationships. The system implements a modular Laravel backend with Vue.js frontend, focusing on enquiry-to-project conversion with manual task assignment workflows.

## Architecture Overview

### System Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Frontend      │    │   API Gateway   │    │   Backend       │
│   (Vue.js)      │◄──►│   (Laravel)     │◄──►│   (Modules)     │
│                 │    │                 │    │                 │
│ - Components    │    │ - Controllers   │    │ - Services      │
│ - Composables   │    │ - Middleware    │    │ - Repositories  │
│ - Views         │    │ - Validation    │    │ - Models        │
└─────────────────┘    └─────────────────┘    └─────────────────┘
                              │
                              ▼
                       ┌─────────────────┐
                       │   Database      │
                       │   (MySQL)       │
                       │                 │
                       │ - Enquiries     │
                       │ - Projects      │
                       │ - Tasks         │
                       │ - Users         │
                       │ - Departments   │
                       └─────────────────┘
```

### Technology Stack

- **Backend**: Laravel 11.x with modular architecture
- **Frontend**: Vue.js 3 with TypeScript
- **Database**: MySQL with Eloquent ORM
- **Authentication**: Laravel Sanctum
- **Authorization**: Spatie Laravel Permission
- **API**: RESTful with JSON responses
- **Notifications**: Laravel Notifications system
- **Queues**: Laravel Queues for background processing

## Domain Boundaries

### Core Modules

1. **ClientService Module**
   - Client management
   - Enquiry creation and management
   - Basic enquiry operations

2. **Projects Module**
   - Project management
   - Workflow orchestration
   - Task assignment and tracking
   - Departmental coordination

3. **Admin Module**
   - User management
   - Role and permission management
   - System configuration

4. **HR Module**
   - Employee management
   - Department management

5. **Finance Module**
   - Budget management
   - Cost analysis
   - Invoice management

### Domain Relationships

```
ClientService ───► Projects ───► Finance
     │                │
     │                ▼
     ▼           Procurement
   Clients       Production
                    │
                    ▼
                 Logistics
```

## Data Flow: Enquiry to Project Completion

### Primary Data Flow

```
Enquiry Creation → Task Generation → Manual Assignment → Status Updates → Project Conversion → Completion
```

### Detailed Flow Diagram

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Enquiry       │     │   Enquiry       │     │   Enquiry       │
│   Created       │────►│   Tasks         │────►│   Tasks         │
│   (Status:      │     │   Generated     │     │   Assigned      │
│    enquiry_logged│     │   (Unassigned) │     │   (Manual)      │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Status        │     │   Workflow      │     │   Status        │
│   Updates       │◄────│   Progression   │────►│   Updates       │
│   (Manual/Auto) │     │   (Task-based)  │     │   (Completion)  │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Quote         │     │   Project       │     │   Project       │
│   Approval      │────►│   Conversion    │────►│   Execution     │
│   (Finance)     │     │   (Auto/Manual) │     │   (Tasks)       │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Project       │     │   Handover      │     │   Archival      │
│   Completion    │────►│   & Delivery    │────►│   & Reporting   │
│   (All tasks    │     │   (Client)      │     │   (System)      │
│    completed)   │     │                 │     │                 │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

### Key Data Entities

#### Enquiry Entity
```php
class Enquiry extends Model
{
    protected $fillable = [
        'date_received', 'expected_delivery_date', 'client_id',
        'title', 'description', 'project_scope', 'priority',
        'status', 'department_id', 'estimated_budget',
        'contact_person', 'assigned_po', 'follow_up_notes',
        'enquiry_number', 'venue', 'site_survey_skipped'
    ];

    // Relationships
    public function client(): BelongsTo
    public function department(): BelongsTo
    public function project(): HasOne
    public function enquiryTasks(): HasMany
}
```

#### Project Entity
```php
class Project extends Model
{
    protected $fillable = [
        'enquiry_id', 'project_id', 'start_date', 'end_date',
        'budget', 'current_phase', 'assigned_users', 'status'
    ];

    // Relationships
    public function enquiry(): BelongsTo
    public function projectTasks(): HasMany
}
```

#### EnquiryTask Entity
```php
class EnquiryTask extends Model
{
    protected $fillable = [
        'project_enquiry_id', 'department_id', 'title', 'type',
        'status', 'priority', 'due_date', 'assigned_by',
        'assigned_to', 'assigned_at', 'notes'
    ];
}
```

## Workflow System

### Workflow Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Workflow      │     │   Task          │     │   Assignment    │
│   Templates     │────►│   Generation    │────►│   (Manual)      │
│   (Config)      │     │   (Auto)        │     │   (Project Mgr) │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Task          │     │   Status        │     │   Escalation    │
│   Execution     │◄────│   Tracking      │────►│   (Auto)        │
│   (Users)       │     │   (Manual)      │     │   (Overdue)     │
└─────────────────┘     └─────────────────┘     └─────────────────┘
         │                        │                        │
         ▼                        ▼                        ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Completion    │     │   Validation    │     │   Notification  │
│   Check         │────►│   (Business     │────►│   (Users)       │
│   (Auto)        │     │    Rules)       │     │   (Real-time)   │
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

### Task Templates Configuration

```php
// config/enquiry_workflow.php
'task_templates' => [
    [
        'title' => 'Site Survey',
        'type' => 'survey',
        'notes' => 'Conduct site survey for the enquiry'
    ],
    [
        'title' => 'Design & Concept Development',
        'type' => 'design',
        'notes' => 'Create design concepts and mockups'
    ],
    // ... more templates
]
```

### Workflow States

#### Enquiry Statuses
- `client_registered` - Initial client registration
- `enquiry_logged` - Enquiry created
- `site_survey_completed` - Site survey done
- `design_completed` - Design phase completed
- `design_approved` - Design approved
- `materials_specified` - Materials specified
- `budget_created` - Budget created
- `quote_prepared` - Quote prepared
- `quote_approved` - Quote approved by finance
- `converted_to_project` - Converted to project
- `planning` - Project planning
- `in_progress` - Project execution
- `completed` - Project completed
- `cancelled` - Enquiry/project cancelled

#### Task Statuses
- `pending` - Task created, unassigned
- `in_progress` - Task assigned and being worked on
- `completed` - Task finished
- `cancelled` - Task cancelled

### Escalation Logic

```php
// Automatic priority escalation for overdue tasks
'escalation' => [
    'urgent_threshold_days' => 7,  // 7+ days = urgent
    'high_threshold_days' => 3,    // 3+ days = high
    'medium_threshold_days' => 1,  // 1+ days = high
]
```

## API Endpoints

### Enquiry Management

#### ClientService Module
```
GET    /api/clientservice/enquiries          # List enquiries (paginated)
GET    /api/clientservice/enquiries/{id}     # Get enquiry details
POST   /api/clientservice/enquiries          # Create enquiry
PUT    /api/clientservice/enquiries/{id}     # Update enquiry
DELETE /api/clientservice/enquiries/{id}     # Delete enquiry
```

#### Projects Module
```
GET    /api/projects/enquiries                # List enquiries (paginated)
GET    /api/projects/enquiries/{id}           # Get enquiry details
POST   /api/projects/enquiries                # Create enquiry with workflow
PUT    /api/projects/enquiries/{id}           # Update enquiry
DELETE /api/projects/enquiries/{id}           # Delete enquiry
PUT    /api/projects/enquiries/{id}/phases/{phase}  # Update phase
POST   /api/projects/enquiries/{id}/approve-quote   # Approve quote
POST   /api/projects/enquiries/{id}/convert         # Convert to project
```

### Task Management
```
GET    /api/projects/tasks                    # Get departmental tasks
GET    /api/projects/tasks/{id}               # Get task details
PUT    /api/projects/tasks/{id}/status        # Update task status
PUT    /api/projects/tasks/{id}/assign        # Assign task
PUT    /api/projects/tasks/{id}               # Update task
GET    /api/projects/enquiries/{id}/tasks     # Get enquiry tasks
POST   /api/projects/enquiry-tasks/{id}/assign     # Assign enquiry task
PUT    /api/projects/enquiry-tasks/{id}/reassign   # Reassign enquiry task
GET    /api/projects/enquiry-tasks/{id}/assignment-history  # Get assignment history
PUT    /api/projects/enquiry-tasks/{id}       # Update enquiry task
```

### Dashboard and Analytics
```
GET    /api/projects/dashboard                 # Main dashboard data
GET    /api/projects/dashboard/enquiry-metrics # Enquiry metrics
GET    /api/projects/dashboard/task-metrics    # Task metrics
GET    /api/projects/dashboard/project-metrics # Project metrics
GET    /api/projects/dashboard/recent-activities # Recent activities
GET    /api/projects/dashboard/alerts          # System alerts
```

### Authentication & Authorization
```
POST   /api/register                          # User registration
POST   /api/login                             # User login
POST   /api/logout                            # User logout
GET    /api/user                              # Get authenticated user
GET    /api/user/permissions                  # Get user permissions
```

## Frontend-Backend Interactions

### Component Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Views         │     │   Composables   │     │   Services      │
│   (Vue)         │◄────│   (Logic)       │◄────│   (API Calls)   │
│                 │     │                 │     │                 │
│ - ProjectEnquiries│   │ - useEnquiries  │     │ - Axios         │
│ - TaskDashboard  │   │ - useProjects   │     │ - Interceptors  │
│ - Dashboard      │   │ - useWorkflow   │     │ - Error Handling│
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

### Key Composables

#### useEnquiries
```typescript
// frontend/src/modules/clientService/composables/useEnquiries.ts
export function useEnquiries() {
  const enquiries = ref<Enquiry[]>([])
  const loading = ref(false)
  const error = ref('')

  const fetchEnquiries = async (filters?: EnquiryFilters) => {
    loading.value = true
    try {
      const response = await api.get('/clientservice/enquiries', { params: filters })
      enquiries.value = response.data.data
    } catch (err) {
      error.value = err.message
    } finally {
      loading.value = false
    }
  }

  const createEnquiry = async (data: CreateEnquiryData) => {
    const response = await api.post('/clientservice/enquiries', data)
    return response.data
  }

  const updateEnquiry = async (id: number, data: UpdateEnquiryData) => {
    const response = await api.put(`/clientservice/enquiries/${id}`, data)
    return response.data
  }

  return {
    enquiries: readonly(enquiries),
    loading: readonly(loading),
    error: readonly(error),
    fetchEnquiries,
    createEnquiry,
    updateEnquiry
  }
}
```

### State Management

#### Pinia Store Example
```typescript
// frontend/src/stores/projects.ts
export const useProjectsStore = defineStore('projects', () => {
  const projects = ref<Project[]>([])
  const currentProject = ref<Project | null>(null)

  const fetchProjects = async () => {
    const response = await api.get('/projects/projects')
    projects.value = response.data.data
  }

  const setCurrentProject = (project: Project) => {
    currentProject.value = project
  }

  return {
    projects: readonly(projects),
    currentProject: readonly(currentProject),
    fetchProjects,
    setCurrentProject
  }
})
```

### Real-time Updates

#### Notification System
```typescript
// frontend/src/services/NotificationService.ts
export class NotificationService {
  private ws: WebSocket

  connect(userId: number) {
    this.ws = new WebSocket(`ws://localhost:6001/notifications?user=${userId}`)

    this.ws.onmessage = (event) => {
      const notification = JSON.parse(event.data)
      // Handle real-time notifications
      this.showNotification(notification)
    }
  }

  showNotification(notification: Notification) {
    // Display toast or update UI
  }
}
```

## Key Components and Patterns

### Command Pattern
```php
// Commands for business logic encapsulation
class CreateEnquiryCommand
{
    public function __construct(public array $data) {}

    public function handle(): Enquiry
    {
        // Business logic here
        return Enquiry::create($this->data);
    }
}
```

### Repository Pattern
```php
// Data access abstraction
class EnquiryRepository
{
    public function findWithRelations(int $id): Enquiry
    {
        return Enquiry::with(['client', 'department', 'enquiryTasks'])->findOrFail($id);
    }

    public function getByDepartment(int $departmentId, array $filters = []): Collection
    {
        return Enquiry::where('department_id', $departmentId)
            ->when($filters['status'], fn($q) => $q->where('status', $filters['status']))
            ->get();
    }
}
```

### Service Layer Pattern
```php
// Business logic orchestration
class EnquiryWorkflowService
{
    public function createWorkflowTasksForEnquiry(ProjectEnquiry $enquiry): void
    {
        $taskTemplates = config('enquiry_workflow.task_templates', []);

        foreach ($taskTemplates as $template) {
            EnquiryTask::create([
                'project_enquiry_id' => $enquiry->id,
                'title' => $template['title'],
                'type' => $template['type'],
                'status' => 'pending',
                // ... other fields
            ]);
        }
    }
}
```

### Observer Pattern
```php
// Event-driven architecture
class EnquiryCreated
{
    public function __construct(public Enquiry $enquiry) {}
}

// Listener
class SendEnquiryNotification
{
    public function handle(EnquiryCreated $event): void
    {
        // Send notifications to relevant users
        Notification::send($users, new EnquiryCreatedNotification($event->enquiry));
    }
}
```

## Security and Permissions

### Role-Based Access Control
```php
// Permission definitions
class Permissions
{
    const ENQUIRY_READ = 'enquiry_read';
    const ENQUIRY_CREATE = 'enquiry_create';
    const ENQUIRY_UPDATE = 'enquiry_update';
    const TASK_ASSIGN = 'task_assign';
    const FINANCE_QUOTE_APPROVE = 'finance_quote_approve';
}
```

### Middleware Protection
```php
// Route protection
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/enquiries', [EnquiryController::class, 'index'])
        ->middleware('permission:' . Permissions::ENQUIRY_READ);
});
```

## Performance Considerations

### Database Optimization
- **Indexing**: Primary keys, foreign keys, and frequently queried columns
- **Eager Loading**: Use `with()` to prevent N+1 queries
- **Pagination**: All list endpoints use pagination
- **Caching**: Laravel Cache for frequently accessed data

### API Optimization
- **Rate Limiting**: Prevent abuse with throttling
- **Response Compression**: Gzip compression for large responses
- **ETags**: Conditional requests for caching
- **Pagination**: Cursor-based pagination for large datasets

### Frontend Optimization
- **Lazy Loading**: Components loaded on demand
- **Virtual Scrolling**: For large lists
- **Service Workers**: Offline capability
- **Code Splitting**: Route-based code splitting

## Monitoring and Logging

### Application Monitoring
```php
// Laravel Telescope for debugging
// Horizon for queue monitoring
// Custom metrics collection
Log::info('Enquiry created', [
    'enquiry_id' => $enquiry->id,
    'user_id' => auth()->id(),
    'timestamp' => now()
]);
```

### Business Metrics
- Enquiry conversion rates
- Task completion times
- Department performance
- User productivity metrics
- System uptime and response times

## Deployment and Scaling

### Environment Configuration
```bash
# Environment variables
APP_ENV=production
DB_CONNECTION=mysql
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
```

### Horizontal Scaling
- **Load Balancer**: Nginx for request distribution
- **Session Storage**: Redis for shared sessions
- **Cache**: Redis cluster for distributed caching
- **Queues**: Redis queues with multiple workers

### Database Scaling
- **Read Replicas**: Separate read and write operations
- **Sharding**: Data partitioning for large datasets
- **Connection Pooling**: Efficient database connections

## Conclusion

This ERP system implements a robust, modular architecture with clear separation of concerns, comprehensive workflow management, and scalable design patterns. The manual task assignment approach ensures better control and accountability, while the event-driven architecture provides flexibility for future enhancements.

Key strengths include:
- Modular Laravel backend with clear domain boundaries
- Comprehensive workflow system with escalation logic
- RESTful API with proper authentication and authorization
- Modern Vue.js frontend with composable architecture
- Real-time notifications and updates
- Scalable database design with proper indexing
- Comprehensive logging and monitoring capabilities

The system successfully transforms client enquiries into completed projects through a well-defined, auditable process with proper stakeholder involvement at each stage.
