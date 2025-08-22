#!/usr/bin/bash

# Simple Laravel Development Server Monitor
# Use this for basic development server monitoring

# Configuration
APP_PATH="/c/Users/Eric/Documents/git projects/MessageProcessor/MessageProcessor"  # Change this to your app path
APP_PORT="8000"
APP_HOST="127.0.0.1"
LOG_FILE="$APP_PATH/storage/logs/monitor.log"

# Function to log messages
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$LOG_FILE"
}

# Check if Laravel development server is running
is_server_running() {
    # Check if port is in use
    if command -v lsof >/dev/null 2>&1; then
        lsof -i ":$APP_PORT" >/dev/null 2>&1
    elif command -v netstat >/dev/null 2>&1; then
        # netstat -tlnp | grep ":$APP_PORT " >/dev/null 2>&1
       netstat -an | grep "LISTENING" | grep ":$APP_PORT" >/dev/null 2>&1

    elif command -v ss >/dev/null 2>&1; then
        ss -tlnp | grep ":$APP_PORT " >/dev/null 2>&1
    else
        # Fallback: try to connect to the port
        timeout 5 bash -c "</dev/tcp/$APP_HOST/$APP_PORT" 2>/dev/null
    fi
}

# Check if the application responds to HTTP requests
is_app_responding() {
    if command -v curl >/dev/null 2>&1; then
        curl -s --connect-timeout 5 --max-time 10 "http://$APP_HOST:$APP_PORT" >/dev/null 2>&1
    elif command -v wget >/dev/null 2>&1; then
        wget --spider --timeout=10 --tries=1 "http://$APP_HOST:$APP_PORT" >/dev/null 2>&1
    else
        return 1
    fi
}

# Start Laravel development server
start_server() {
    log "Starting Laravel development server..."

    # Change to app directory
    cd "$APP_PATH" || {
        log "ERROR: Cannot change to app directory: $APP_PATH"
        exit 1
    }

    # Kill any existing artisan serve processes
    # pkill -f "artisan serve" 2>/dev/null
    ps | grep "artisan serve" | grep -v grep | awk '{print $1}' | xargs -r kill


    sleep 2

    # Start the server
    start php artisan serve --host="$APP_HOST" --port="$APP_PORT" >/dev/null 2>&1 &

    # Wait a moment for the server to start
    sleep 5

    # Verify it started
    # if is_server_running; then
    #     log "Laravel development server started successfully on http://$APP_HOST:$APP_PORT"
    #     return 0
    # else
    #     log "ERROR: Failed to start Laravel development server"
    #     return 1
    # fi

    # Wait up to 15 seconds for the server to start
        for i in {1..15}; do
            if is_server_running && is_app_responding; then
                log "Laravel development server started successfully on http://$APP_HOST:$APP_PORT"
                return 0
            fi
            sleep 1
        done

        log "ERROR: Failed to start Laravel development server after waiting"
        return 1
}

# Main monitoring logic
main() {
    # Create log file if it doesn't exist
    mkdir -p "$(dirname "$LOG_FILE")"
    touch "$LOG_FILE"

    log "Checking Laravel application status..."

    # Check if server is running
    if is_server_running; then
        log "Server is running on port $APP_PORT"

        # Check if app is responding
        if is_app_responding; then
            log "Application is responding to HTTP requests"
            echo "✓ Application is running and healthy"
        else
            log "WARNING: Server is running but not responding to HTTP requests"
            echo "⚠ Server running but not responding - attempting restart..."
            start_server
        fi
    else
        log "Server is not running - attempting to start..."
        echo "✗ Server is not running - starting now..."
        start_server
    fi
}

# Handle command line arguments
case "${1:-}" in
    "start")
        start_server
        ;;
    "stop")
        log "Stopping Laravel development server..."
        pkill -f "artisan serve" 2>/dev/null
        log "Server stopped"
        echo "Server stopped"
        ;;
    "restart")
        log "Restarting Laravel development server..."
        pkill -f "artisan serve" 2>/dev/null
        sleep 2
        start_server
        ;;
    "status")
        if is_server_running; then
            echo "✓ Server is running"
            if is_app_responding; then
                echo "✓ Application is responding"
            else
                echo "⚠ Application is not responding"
            fi
        else
            echo "✗ Server is not running"
        fi
        ;;
    "help"|"-h"|"--help")
        echo "Usage: $0 [command]"
        echo ""
        echo "Commands:"
        echo "  start    Start the Laravel development server"
        echo "  stop     Stop the Laravel development server"
        echo "  restart  Restart the Laravel development server"
        echo "  status   Check server status"
        echo "  (none)   Monitor and restart if needed"
        echo ""
        echo "Configuration:"
        echo "  Edit the script to set APP_PATH, APP_PORT, and APP_HOST"
        ;;
    *)
        main
        ;;
esac
