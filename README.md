## Setup

### Requirements

- PHP 8.1+
- Composer
- Node.js & NPM
- SQLite (or another database system of your choice)

### Installation

```bash
# Clone the repository (if you haven't already)
git clone [repository-url]
cd laravel-engr-test

# Install PHP dependencies
composer install

# Install JavaScript dependencies
npm install

# Generate application key
php artisan key:generate

# Set up the database
php artisan migrate

# Seed the database with test data
php artisan db:seed
```

### Running the Application

```bash
# Start the Vite development server
npm run dev

# In a separate terminal, start the Laravel server
php artisan serve
```

The application will be available at http://localhost:8000

### Running the Queue Worker

The application uses Laravel's queue system for processing tasks asynchronously. To start the queue worker:

```bash
php artisan queue:work
```

### Scheduling Tasks

To run the scheduler locally (for testing the daily claim batching process):

```bash
php artisan schedule:work
```

### Running Tests

```bash
# Run all tests
php artisan test
```


## Extra Notes

### About the Application

This application is a healthcare claims processing platform that optimizes batching and processing of medical claims between Providers and Insurers. The system aims to minimize processing costs while meeting various constraints.

### Key Features

- **Claim Submission**: Providers can submit claims with encounter date, specialty, priority level, and multiple items
- **Smart Batching**: Claims are batched optimally based on provider, date, specialty, and priority
- **Cost Optimization**: Processing costs are minimized considering factors like time of month, specialty, priority level, and claim value
- **Insurer Notification**: Insurers are notified via email when claims are submitted and batched

### Application Structure

- **Frontend**: Vue.js based claim submission form
- **Backend**: Laravel REST API for claim processing and batching
- **Batching Algorithm**: Sophisticated algorithm for optimizing claim batches (see ALGORITHM_DOCUMENTATION.md)

### API Endpoints

- `POST /api/claims` - Submit a new claim
- `GET /api/claims` - List all claims
- `GET /api/batches` - View batched claims
- `POST /api/process-batches` - Manually trigger batch processing

### Manual Claim Batching

To manually process pending claims into batches:

```bash
php artisan claims:process-daily-batch
```

### Login Credentials

After seeding the database, you can log in with the following test account:

- Email: test@example.com
- Password: password



