#!usr/bin/bash

# Laravel Application Monitor and Restart Script
# This script checks if your Laravel app is running and restarts it if needed

# Configuration - Modify these variables according to your setup
APP_NAME="Laravel Message Parser"
APP_PATH="var/www/puffadder.darth.cloud"  # Change to your actual app path
APP_URL="https://www.puffadder.darth.cloud"    # Change to your app URL
APP_PORT="8000"                   # Change to your app port

# Service names (if using systemd services)
PHP_FPM_SERVICE="php8.2-fpm"      # Adjust PHP version as needed
NGINX_SERVICE="apache2"              # Or apache2
REDIS_SERVICE="redis-server"

# Log files
LOG_FILE="/storage/message_logs/laravel-monitor.log"
ERROR_LOG="/storage/message_logs/laravel-monitor-error.log"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to log messages
log_message() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Function to log errors
log_error() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - ERROR: $1" | tee -a "$ERROR_LOG"
}

# Function to print colored output
print_status() {
    case $2 in
        "OK")
            echo -e "${GREEN}[OK]${NC} $1"
            ;;
        "ERROR")
            echo -e "${RED}[ERROR]${NC} $1"
            ;;
        "WARNING")
            echo -e "${YELLOW}[WARNING]${NC} $1"
            ;;
        "INFO")
            echo -e "${BLUE}[INFO]${NC} $1"
            ;;
    esac
}

# Function to check if a port is listening
check_port() {
    local port=$1
    if command -v netstat >/dev/null 2>&1; then
        netstat -tlnp | grep ":$port " >/dev/null 2>&1
    elif command -v ss >/dev/null 2>&1; then
        ss -tlnp | grep ":$port " >/dev/null 2>&1
    else
        # Fallback using lsof if available
        if command -v lsof >/dev/null 2>&1; then
            lsof -i ":$port" >/dev/null 2>&1
        else
            return 1
        fi
    fi
}

# Function to check HTTP response
check_http_response() {
    local url=$1
    local expected_code=${2:-200}

    if command -v curl >/dev/null 2>&1; then
        local response_code=$(curl -s -o /dev/null -w "%{http_code}" --connect-timeout 10 --max-time 30 "$url")
        [ "$response_code" = "$expected_code" ]
    elif command -v wget >/dev/null 2>&1; then
        wget --spider --timeout=30 --tries=1 "$url" >/dev/null 2>&1
    else
        log_error "Neither curl nor wget is available for HTTP checks"
        return 1
    fi
}

# Function to check if a service is running
check_service() {
    local service_name=$1

    if command -v systemctl >/dev/null 2>&1; then
        systemctl is-active --quiet "$service_name"
    elif command -v service >/dev/null 2>&1; then
        service "$service_name" status >/dev/null 2>&1
    else
        # Fallback: check if process is running
        pgrep "$service_name" >/dev/null 2>&1
    fi
}

# Function to restart a system service
restart_service() {
    local service_name=$1

    print_status "Restarting $service_name..." "INFO"

    if command -v systemctl >/dev/null 2>&1; then
        sudo systemctl restart "$service_name"
        sleep 3
        if systemctl is-active --quiet "$service_name"; then
            print_status "$service_name restarted successfully" "OK"
            log_message "$service_name restarted successfully"
            return 0
        else
            print_status "Failed to restart $service_name" "ERROR"
            log_error "Failed to restart $service_name"
            return 1
        fi
    elif command -v service >/dev/null 2>&1; then
        sudo service "$service_name" restart
        sleep 3
        if service "$service_name" status >/dev/null 2>&1; then
            print_status "$service_name restarted successfully" "OK"
            log_message "$service_name restarted successfully"
            return 0
        else
            print_status "Failed to restart $service_name" "ERROR"
            log_error "Failed to restart $service_name"
            return 1
        fi
    else
        log_error "Cannot restart $service_name - no service manager found"
        return 1
    fi
}

# Function to start Laravel development server
start_laravel_dev() {
    print_status "Starting Laravel development server..." "INFO"

    cd "$APP_PATH" || {
        log_error "Cannot change to app directory: $APP_PATH"
        return 1
    }

    # Kill any existing artisan serve processes
    pkill -f "artisan serve" 2>/dev/null

    # Start Laravel development server in background
    nohup php artisan serve --host=0.0.0.0 --port="$APP_PORT" > /dev/null 2>&1 &

    sleep 5

    if check_port "$APP_PORT"; then
        print_status "Laravel development server started successfully" "OK"
        log_message "Laravel development server started on port $APP_PORT"
        return 0
    else
        print_status "Failed to start Laravel development server" "ERROR"
        log_error "Failed to start Laravel development server on port $APP_PORT"
        return 1
    fi
}

# Function to restart Laravel queue workers
restart_queue_workers() {
    print_status "Restarting Laravel queue workers..." "INFO"

    cd "$APP_PATH" || return 1

    # Restart queue workers
    php artisan queue:restart >/dev/null 2>&1

    # If using supervisor, restart workers
    if command -v supervisorctl >/dev/null 2>&1; then
        sudo supervisorctl restart laravel-worker:* 2>/dev/null
    fi

    print_status "Queue workers restarted" "OK"
    log_message "Laravel queue workers restarted"
}

# Function to clear Laravel caches
clear_laravel_caches() {
    print_status "Clearing Laravel caches..." "INFO"

    cd "$APP_PATH" || return 1

    php artisan config:clear >/dev/null 2>&1
    php artisan route:clear >/dev/null 2>&1
    php artisan view:clear >/dev/null 2>&1
    php artisan cache:clear >/dev/null 2>&1

    print_status "Laravel caches cleared" "OK"
    log_message "Laravel caches cleared"
}

# Function to check Laravel application health
check_laravel_health() {
    local health_issues=0

    print_status "Checking Laravel application health..." "INFO"

    # Check if app directory exists
    if [ ! -d "$APP_PATH" ]; then
        print_status "App directory not found: $APP_PATH" "ERROR"
        log_error "App directory not found: $APP_PATH"
        return 1
    fi

    cd "$APP_PATH" || return 1

    # Check if .env file exists
    if [ ! -f ".env" ]; then
        print_status ".env file not found" "ERROR"
        log_error ".env file not found in $APP_PATH"
        ((health_issues++))
    fi

    # Check if composer dependencies are installed
    if [ ! -d "vendor" ]; then
        print_status "Vendor directory not found - running composer install..." "WARNING"
        composer install --no-dev --optimize-autoloader >/dev/null 2>&1
    fi

    # Check database connection
    if ! php artisan migrate:status >/dev/null 2>&1; then
        print_status "Database connection issue detected" "WARNING"
        log_message "Database connection issue detected"
        ((health_issues++))
    fi

    # Check if Redis is accessible
    if ! php artisan tinker --execute="Redis::ping()" >/dev/null 2>&1; then
        print_status "Redis connection issue detected" "WARNING"
        log_message "Redis connection issue detected"
        ((health_issues++))
    fi

    return $health_issues
}

# Main monitoring function
monitor_application() {
    print_status "Starting $APP_NAME health check..." "INFO"
    log_message "Starting application health check"

    local restart_needed=false
    local issues_found=0

    # Check Redis service
    if ! check_service "$REDIS_SERVICE"; then
        print_status "Redis service is not running" "ERROR"
        restart_service "$REDIS_SERVICE"
        restart_needed=true
        ((issues_found++))
    else
        print_status "Redis service is running" "OK"
    fi

    # Check PHP-FPM service (if using nginx/apache)
    if ! check_service "$PHP_FPM_SERVICE"; then
        print_status "PHP-FPM service is not running" "ERROR"
        restart_service "$PHP_FPM_SERVICE"
        restart_needed=true
        ((issues_found++))
    else
        print_status "PHP-FPM service is running" "OK"
    fi

    # Check web server (nginx/apache)
    if ! check_service "$NGINX_SERVICE"; then
        print_status "Web server service is not running" "ERROR"
        restart_service "$NGINX_SERVICE"
        restart_needed=true
        ((issues_found++))
    else
        print_status "Web server service is running" "OK"
    fi

    # Check if application port is listening
    if ! check_port "$APP_PORT"; then
        print_status "Application not listening on port $APP_PORT" "ERROR"
        start_laravel_dev
        restart_needed=true
        ((issues_found++))
    else
        print_status "Application is listening on port $APP_PORT" "OK"
    fi

    # Check HTTP response
    if ! check_http_response "$APP_URL"; then
        print_status "Application not responding to HTTP requests" "ERROR"
        clear_laravel_caches
        restart_queue_workers
        restart_needed=true
        ((issues_found++))
    else
        print_status "Application responding to HTTP requests" "OK"
    fi

    # Check Laravel application health
    check_laravel_health
    local health_result=$?

    if [ $health_result -gt 0 ]; then
        print_status "Found $health_result Laravel health issues" "WARNING"
        ((issues_found += health_result))
    fi

    # Final status
    if [ $issues_found -eq 0 ]; then
        print_status "All checks passed - application is healthy" "OK"
        log_message "Health check completed - no issues found"
    else
        print_status "Found $issues_found issues - restart actions taken" "WARNING"
        log_message "Health check completed - $issues_found issues found and addressed"
    fi

    return $issues_found
}

# Function to display usage
show_usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  -h, --help     Show this help message"
    echo "  -c, --cron     Run in cron mode (less verbose output)"
    echo "  -v, --verbose  Enable verbose output"
    echo "  -f, --force    Force restart all services"
    echo ""
    echo "Examples:"
    echo "  $0              # Run health check"
    echo "  $0 --cron       # Run in cron mode"
    echo "  $0 --force      # Force restart all services"
}

# Parse command line arguments
CRON_MODE=false
VERBOSE=false
FORCE_RESTART=false

while [[ $# -gt 0 ]]; do
    case $1 in
        -h|--help)
            show_usage
            exit 0
            ;;
        -c|--cron)
            CRON_MODE=true
            shift
            ;;
        -v|--verbose)
            VERBOSE=true
            shift
            ;;
        -f|--force)
            FORCE_RESTART=true
            shift
            ;;
        *)
            echo "Unknown option: $1"
            show_usage
            exit 1
            ;;
    esac
done

# Main execution
main() {
    # Create log files if they don't exist
    touch "$LOG_FILE" "$ERROR_LOG"

    # If force restart is requested
    if [ "$FORCE_RESTART" = true ]; then
        print_status "Force restart requested - restarting all services..." "INFO"
        restart_service "$REDIS_SERVICE"
        restart_service "$PHP_FPM_SERVICE"
        restart_service "$NGINX_SERVICE"
        start_laravel_dev
        clear_laravel_caches
        restart_queue_workers
        exit 0
    fi

    # Run monitoring
    if [ "$CRON_MODE" = true ]; then
        # In cron mode, suppress colored output and reduce verbosity
        monitor_application >/dev/null 2>&1
        exit_code=$?
        if [ $exit_code -gt 0 ]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') - Issues found and addressed" >> "$LOG_FILE"
        fi
    else
        monitor_application
    fi
}

# Check if running as root for service management
if [ "$EUID" -ne 0 ] && [ "$FORCE_RESTART" = true ]; then
    print_status "Note: Running without root privileges - service restarts may fail" "WARNING"
fi

# Run main function
main "$@"
