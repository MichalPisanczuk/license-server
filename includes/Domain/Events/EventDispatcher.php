<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Events;

/**
 * Event Dispatcher for License Server
 * 
 * Provides a robust event system for decoupled communication between
 * different parts of the application.
 */
class EventDispatcher
{
    /** @var array Registered event listeners */
    private array $listeners = [];
    
    /** @var array Event aliases */
    private array $aliases = [];
    
    /** @var bool Whether to use WordPress hooks integration */
    private bool $useWordPressHooks;
    
    /** @var array Event statistics */
    private array $stats = [
        'dispatched' => 0,
        'listeners_called' => 0,
        'errors' => 0
    ];

    public function __construct(bool $useWordPressHooks = true)
    {
        $this->useWordPressHooks = $useWordPressHooks;
        $this->registerDefaultEvents();
    }

    /**
     * Register an event listener.
     *
     * @param string $eventName Event name
     * @param callable $listener Event listener
     * @param int $priority Priority (higher = earlier)
     * @return void
     */
    public function listen(string $eventName, callable $listener, int $priority = 10): void
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }
        
        $this->listeners[$eventName][] = [
            'callback' => $listener,
            'priority' => $priority
        ];
        
        // Sort by priority (higher first)
        usort($this->listeners[$eventName], function ($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        // Also register with WordPress hooks if enabled
        if ($this->useWordPressHooks) {
            add_action('lsr_' . $eventName, $listener, $priority, 10);
        }
    }

    /**
     * Register multiple listeners at once.
     *
     * @param array $listeners Array of event_name => callback pairs
     * @param int $priority Default priority
     */
    public function listenMultiple(array $listeners, int $priority = 10): void
    {
        foreach ($listeners as $eventName => $callback) {
            $this->listen($eventName, $callback, $priority);
        }
    }

    /**
     * Remove an event listener.
     *
     * @param string $eventName Event name
     * @param callable|null $listener Specific listener to remove (null = all)
     * @return bool True if listener was found and removed
     */
    public function removeListener(string $eventName, ?callable $listener = null): bool
    {
        if (!isset($this->listeners[$eventName])) {
            return false;
        }
        
        if ($listener === null) {
            unset($this->listeners[$eventName]);
            return true;
        }
        
        $removed = false;
        foreach ($this->listeners[$eventName] as $key => $registeredListener) {
            if ($registeredListener['callback'] === $listener) {
                unset($this->listeners[$eventName][$key]);
                $removed = true;
            }
        }
        
        // Re-index array
        if ($removed) {
            $this->listeners[$eventName] = array_values($this->listeners[$eventName]);
        }
        
        return $removed;
    }

    /**
     * Dispatch an event to all listeners.
     *
     * @param string $eventName Event name
     * @param mixed $eventData Event data
     * @param bool $stopOnFalse Stop propagation if listener returns false
     * @return EventResult Event result with listener responses
     */
    public function dispatch(string $eventName, $eventData = null, bool $stopOnFalse = false): EventResult
    {
        $this->stats['dispatched']++;
        
        // Resolve alias if exists
        $resolvedEventName = $this->aliases[$eventName] ?? $eventName;
        
        $result = new EventResult($resolvedEventName, $eventData);
        $listeners = $this->listeners[$resolvedEventName] ?? [];
        
        foreach ($listeners as $listenerData) {
            try {
                $this->stats['listeners_called']++;
                
                $response = call_user_func($listenerData['callback'], $eventData, $result);
                $result->addResponse($response);
                
                // Stop propagation if listener returned false and stopOnFalse is enabled
                if ($stopOnFalse && $response === false) {
                    $result->setStopped(true);
                    break;
                }
                
            } catch (\Exception $e) {
                $this->stats['errors']++;
                $result->addError($e);
                
                error_log(sprintf(
                    '[License Server Events] Error in listener for event "%s": %s',
                    $eventName,
                    $e->getMessage()
                ));
            }
        }
        
        // Also dispatch through WordPress hooks if enabled
        if ($this->useWordPressHooks) {
            do_action('lsr_' . $resolvedEventName, $eventData, $result);
        }
        
        return $result;
    }

    /**
     * Dispatch event asynchronously (queued for next request cycle).
     *
     * @param string $eventName Event name
     * @param mixed $eventData Event data
     * @return bool True if queued successfully
     */
    public function dispatchAsync(string $eventName, $eventData = null): bool
    {
        // Use WordPress cron for async dispatch
        if (function_exists('wp_schedule_single_event')) {
            return wp_schedule_single_event(time() + 1, 'lsr_async_event', [
                'event_name' => $eventName,
                'event_data' => $eventData
            ]);
        }
        
        return false;
    }

    /**
     * Check if event has listeners.
     *
     * @param string $eventName Event name
     * @return bool True if event has listeners
     */
    public function hasListeners(string $eventName): bool
    {
        $resolvedEventName = $this->aliases[$eventName] ?? $eventName;
        return !empty($this->listeners[$resolvedEventName]);
    }

    /**
     * Get number of listeners for an event.
     *
     * @param string $eventName Event name
     * @return int Number of listeners
     */
    public function getListenerCount(string $eventName): int
    {
        $resolvedEventName = $this->aliases[$eventName] ?? $eventName;
        return count($this->listeners[$resolvedEventName] ?? []);
    }

    /**
     * Get all registered events.
     *
     * @return array Array of event names
     */
    public function getRegisteredEvents(): array
    {
        return array_keys($this->listeners);
    }

    /**
     * Create an event alias.
     *
     * @param string $alias Alias name
     * @param string $eventName Original event name
     */
    public function alias(string $alias, string $eventName): void
    {
        $this->aliases[$alias] = $eventName;
    }

    /**
     * Get event statistics.
     *
     * @return array Statistics array
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Reset event statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'dispatched' => 0,
            'listeners_called' => 0,
            'errors' => 0
        ];
    }

    /**
     * Register default License Server events.
     */
    private function registerDefaultEvents(): void
    {
        // Create aliases for common events
        $this->alias('license.created', 'license_created');
        $this->alias('license.activated', 'license_activated');
        $this->alias('license.deactivated', 'license_deactivated');
        $this->alias('license.validated', 'license_validated');
        $this->alias('license.expired', 'license_expired');
        $this->alias('license.suspended', 'license_suspended');
        
        $this->alias('activation.created', 'activation_created');
        $this->alias('activation.updated', 'activation_updated');
        $this->alias('activation.removed', 'activation_removed');
        
        $this->alias('release.created', 'release_created');
        $this->alias('release.updated', 'release_updated');
        $this->alias('release.downloaded', 'release_downloaded');
        
        $this->alias('security.threat_detected', 'security_threat_detected');
        $this->alias('security.ip_blocked', 'security_ip_blocked');
        $this->alias('security.suspicious_activity', 'security_suspicious_activity');
        
        // Register async event handler
        if (function_exists('add_action')) {
            add_action('lsr_async_event', [$this, 'handleAsyncEvent']);
        }
    }

    /**
     * Handle asynchronous event dispatch.
     *
     * @param array $eventData Event data with event_name and event_data keys
     */
    public function handleAsyncEvent(array $eventData): void
    {
        if (isset($eventData['event_name'])) {
            $this->dispatch($eventData['event_name'], $eventData['event_data'] ?? null);
        }
    }

    /**
     * Create a new event emitter for a specific context.
     *
     * @param string $context Context name (e.g., 'license', 'security')
     * @return EventEmitter
     */
    public function createEmitter(string $context): EventEmitter
    {
        return new EventEmitter($this, $context);
    }
}

/**
 * Event Result container.
 */
class EventResult
{
    private string $eventName;
    private $eventData;
    private array $responses = [];
    private array $errors = [];
    private bool $stopped = false;
    private float $startTime;
    private float $endTime;

    public function __construct(string $eventName, $eventData = null)
    {
        $this->eventName = $eventName;
        $this->eventData = $eventData;
        $this->startTime = microtime(true);
    }

    public function addResponse($response): void
    {
        $this->responses[] = $response;
    }

    public function addError(\Exception $error): void
    {
        $this->errors[] = $error;
    }

    public function setStopped(bool $stopped): void
    {
        $this->stopped = $stopped;
        if ($stopped) {
            $this->endTime = microtime(true);
        }
    }

    public function getEventName(): string
    {
        return $this->eventName;
    }

    public function getEventData()
    {
        return $this->eventData;
    }

    public function getResponses(): array
    {
        return $this->responses;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function isStopped(): bool
    {
        return $this->stopped;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function getExecutionTime(): float
    {
        $endTime = $this->endTime ?? microtime(true);
        return round($endTime - $this->startTime, 4);
    }

    public function getSuccessfulResponses(): array
    {
        return array_filter($this->responses, function ($response) {
            return $response !== false && $response !== null;
        });
    }

    public function wasSuccessful(): bool
    {
        return !$this->hasErrors() && !empty($this->getSuccessfulResponses());
    }
}

/**
 * Context-specific event emitter.
 */
class EventEmitter
{
    private EventDispatcher $dispatcher;
    private string $context;

    public function __construct(EventDispatcher $dispatcher, string $context)
    {
        $this->dispatcher = $dispatcher;
        $this->context = $context;
    }

    /**
     * Emit an event with context prefix.
     *
     * @param string $eventName Event name (will be prefixed with context)
     * @param mixed $eventData Event data
     * @param bool $stopOnFalse Stop propagation on false return
     * @return EventResult
     */
    public function emit(string $eventName, $eventData = null, bool $stopOnFalse = false): EventResult
    {
        $fullEventName = $this->context . '.' . $eventName;
        return $this->dispatcher->dispatch($fullEventName, $eventData, $stopOnFalse);
    }

    /**
     * Emit event asynchronously.
     *
     * @param string $eventName Event name
     * @param mixed $eventData Event data
     * @return bool
     */
    public function emitAsync(string $eventName, $eventData = null): bool
    {
        $fullEventName = $this->context . '.' . $eventName;
        return $this->dispatcher->dispatchAsync($fullEventName, $eventData);
    }

    /**
     * Listen for events in this context.
     *
     * @param string $eventName Event name (without context prefix)
     * @param callable $listener Listener callback
     * @param int $priority Priority
     */
    public function listen(string $eventName, callable $listener, int $priority = 10): void
    {
        $fullEventName = $this->context . '.' . $eventName;
        $this->dispatcher->listen($fullEventName, $listener, $priority);
    }

    /**
     * Get the context name.
     *
     * @return string
     */
    public function getContext(): string
    {
        return $this->context;
    }
}

/**
 * Predefined License Server Events.
 */
final class LicenseServerEvents
{
    // License events
    public const LICENSE_CREATED = 'license.created';
    public const LICENSE_ACTIVATED = 'license.activated';
    public const LICENSE_DEACTIVATED = 'license.deactivated';
    public const LICENSE_VALIDATED = 'license.validated';
    public const LICENSE_EXPIRED = 'license.expired';
    public const LICENSE_SUSPENDED = 'license.suspended';
    public const LICENSE_RENEWED = 'license.renewed';
    
    // Activation events
    public const ACTIVATION_CREATED = 'activation.created';
    public const ACTIVATION_UPDATED = 'activation.updated';
    public const ACTIVATION_REMOVED = 'activation.removed';
    public const ACTIVATION_LIMIT_EXCEEDED = 'activation.limit_exceeded';
    
    // Release events
    public const RELEASE_CREATED = 'release.created';
    public const RELEASE_UPDATED = 'release.updated';
    public const RELEASE_DOWNLOADED = 'release.downloaded';
    public const RELEASE_EXPIRED = 'release.expired';
    
    // Security events
    public const SECURITY_THREAT_DETECTED = 'security.threat_detected';
    public const SECURITY_IP_BLOCKED = 'security.ip_blocked';
    public const SECURITY_SUSPICIOUS_ACTIVITY = 'security.suspicious_activity';
    public const SECURITY_RATE_LIMIT_EXCEEDED = 'security.rate_limit_exceeded';
    public const SECURITY_AUTHENTICATION_FAILED = 'security.authentication_failed';
    
    // System events
    public const SYSTEM_CACHE_CLEARED = 'system.cache_cleared';
    public const SYSTEM_CLEANUP_COMPLETED = 'system.cleanup_completed';
    public const SYSTEM_CONFIGURATION_UPDATED = 'system.configuration_updated';
    public const SYSTEM_ERROR_OCCURRED = 'system.error_occurred';
    
    // Order events
    public const ORDER_COMPLETED = 'order.completed';
    public const ORDER_REFUNDED = 'order.refunded';
    public const SUBSCRIPTION_RENEWED = 'subscription.renewed';
    public const SUBSCRIPTION_CANCELLED = 'subscription.cancelled';
    public const SUBSCRIPTION_EXPIRED = 'subscription.expired';
    
    // API events
    public const API_REQUEST_RECEIVED = 'api.request_received';
    public const API_REQUEST_COMPLETED = 'api.request_completed';
    public const API_REQUEST_FAILED = 'api.request_failed';
    public const API_RATE_LIMITED = 'api.rate_limited';
    
    /**
     * Get all defined events.
     *
     * @return array
     */
    public static function getAllEvents(): array
    {
        $reflection = new \ReflectionClass(self::class);
        return array_values($reflection->getConstants());
    }
    
    /**
     * Get events by category.
     *
     * @param string $category Category prefix (e.g., 'license', 'security')
     * @return array
     */
    public static function getEventsByCategory(string $category): array
    {
        $allEvents = self::getAllEvents();
        $prefix = $category . '.';
        
        return array_filter($allEvents, function ($event) use ($prefix) {
            return strpos($event, $prefix) === 0;
        });
    }
}