<?php

namespace BIM\ActionLogger\Processors;

use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use BIM\ActionLogger\Contracts\ActionProcessorInterface;
use BIM\ActionLogger\Contracts\CauserInterface;
use BIM\ActionLogger\Traits\HandlesTranslations;
use BIM\ActionLogger\Enums\Action;
use Illuminate\Support\Str;

abstract class BaseActionProcessor implements ActionProcessorInterface
{
    use HandlesTranslations;
    
    /**
     * The activities collection
     */
    protected Collection $activities;

    /**
     * The processed data cache
     */
    protected ?array $processedData = null;
    
    /**
     * Current batch UUID being processed
     */
    protected ?string $currentBatchUuid = null;
    
    /**
     * Supported events for this processor
     */
    protected static array $supportedEvents = [];

    /**
     * The supported action types
     */
    protected static array $supportedActions = [];

    /**
     * Create a new processor instance
     */
    public function __construct(Collection $activities)
    {
        $this->activities = $activities;
    }

    /**
     * Get the processor's supported event types
     */
    public static function getSupportedEvents(): array
    {
        return static::$supportedEvents;
    }

    /**
     * Check if the processor supports the given event type
     */
    public static function supportsEvent(string $eventType): bool
    {
        return in_array($eventType, static::getSupportedEvents());
    }

    /**
     * Get the processor's supported action types
     */
    public static function getSupportedActions(): array
    {
        return static::$supportedActions ?? [];
    }

    /**
     * Check if the processor supports the given action type
     */
    public static function supportsAction(string $actionType): bool
    {
        return in_array($actionType, static::getSupportedActions());
    }


    /**
     * Process the activities and return the processed data
     */
    public function process(): array
    {
        if ($this->processedData !== null) {
            return $this->processedData;
        }

        $this->processedData = $this->processActivities();
        return $this->processedData;
    }

    /**
     * Process specific batch
     */
    public function processBatch(?string $batchUuid = null): array
    {
        $this->currentBatchUuid = $batchUuid;
        
        if ($batchUuid) {
            $batchActivities = $this->activities->where('batch_uuid', $batchUuid);
        } else {
            $batchActivities = $this->activities;
        }
        
        if ($batchActivities->isEmpty()) {
            return [
                'error' => 'No activities found for batch',
                'batch_uuid' => $batchUuid
            ];
        }
        
        $primaryActivity = $batchActivities->first();
        
        // Get the common action from activities
        $commonAction = $this->getCommonAction($batchActivities);
        
        // Extract entities with their changes
        $entitiesWithChanges = $this->extractEntitiesWithChanges($batchActivities);
        $entityCount = count($entitiesWithChanges);
        
        if (empty($entitiesWithChanges)) {
            return [
                'error' => 'No entities extracted from activities',
                'batch_uuid' => $batchUuid,
                'activity_count' => $batchActivities->count(),
                'sample_activity' => [
                    'id' => $primaryActivity->id,
                    'subject_type' => $primaryActivity->subject_type ?? 'null',
                    'subject_id' => $primaryActivity->subject_id ?? 'null',
                    'event' => $primaryActivity->event,
                    'properties' => $primaryActivity->properties,
                ]
            ];
        }
        
        // Generate message
        $shortMessage = Lang::get('activities.batch_message', [
            'causer' => $primaryActivity->causer ? $this->getCauserName($primaryActivity->causer) : 'System',
            'action' => $commonAction,
            'count' => $entityCount,
        ]);
        
        // Build simplified entity information
        $entities = [];
        foreach ($entitiesWithChanges as $entity) {
            // Get translated model name
            $modelType = $entity['type'];
            $modelBaseName = class_basename($modelType);
            $modelKey = $this->translateModelKey($modelType);
            $translatedModelName = Lang::has("activities.models.{$modelKey}") 
                ? Lang::get("activities.models.{$modelKey}") 
                : $modelBaseName;
            
            $entities[] = [
                'type' => $translatedModelName,
                'id' => $entity['id'],
                'action' => $entity['event'],
                'changes' => $this->simplifyChanges($entity['formatted_changes'] ?? [])
            ];
        }
        
        // If no entities after processing, add debug info
        if (empty($entities)) {
            return [
                'error' => 'No entities after processing',
                'batch_uuid' => $batchUuid,
                'raw_entities_count' => count($entitiesWithChanges),
                'sample_raw_entity' => !empty($entitiesWithChanges) ? reset($entitiesWithChanges) : null
            ];
        }
        
        // Prepare result
        return [
            'batch_uuid' => $batchUuid,
            'message' => $shortMessage,
            'causer' => $primaryActivity->causer,
            'causer_type' => $primaryActivity->causer_type,
            'causer_id' => $primaryActivity->causer_id,
            'action' => $commonAction,
            'entities' => $entities,
            'created_at' => $primaryActivity->created_at,
        ];
    }
    
    /**
     * Simplify formatted changes array by removing raw values and renaming keys
     */
    protected function simplifyChanges(array $formattedChanges): array
    {
        $simplified = [];
        
        foreach ($formattedChanges as $change) {
            // Handle both array formats
            if (isset($change['label'])) {
                // New format with attribute, label, old_value, new_value
                $simplified[] = [
                    'attribute' => $change['label'],
                    'old' => $change['old_value'],
                    'new' => $change['new_value']
                ];
            } elseif (is_array($change) && isset($change['attribute'])) {
                // Already in the correct format
                $simplified[] = [
                    'attribute' => $change['attribute'],
                    'old' => $change['old'],
                    'new' => $change['new']
                ];
            } elseif (is_string($change) && isset($formattedChanges[$change]) && is_array($formattedChanges[$change])) {
                // Old format with key => {old, new}
                $simplified[] = [
                    'attribute' => $this->getAttributeLabel($change),
                    'old' => $formattedChanges[$change]['old'] ?? null,
                    'new' => $formattedChanges[$change]['new'] ?? null
                ];
            }
        }
        
        return $simplified;
    }
    
    /**
     * Get a translated attribute label
     */
    protected function getAttributeLabel(string $key): string
    {
        // Try to get from Laravel's validation attributes
        if (Lang::has("validation.attributes.{$key}")) {
            return Lang::get("validation.attributes.{$key}");
        }
        
        // Convert snake_case to Title Case as fallback
        return ucfirst(str_replace('_', ' ', $key));
    }
    
    /**
     * Process batch activities
     */
    protected function processBatchActivities(Collection $activities): array
    {
        return $activities->map(function (Activity $activity) {
            return $this->processActivity($activity);
        })->all();
    }
    
    /**
     * Format batch message
     */
    public function formatBatchMessage(array $batchData): string
    {
        if (empty($batchData)) {
            return '';
        }
        
        $firstActivity = $batchData[0];
        $causer = $firstActivity['original_activity'] ? 
            $this->getCauser($firstActivity['original_activity']) : 'System';
        $subjectId = $firstActivity['subject_id'] ?? '';
        $event = $this->getEventName($firstActivity['event'] ?? 'updated');
        
        return Lang::get('activities.batch_message', [
            'causer' => $causer,
            'action' => $event,
            'subject_id' => $subjectId,
        ]);
    }
    
    /**
     * Get causer name
     */
    protected function getCauser(Activity $activity): string
    {
        if (!$activity->causer) {
            return __('activities.common.system');
        }
        
        if ($activity->causer instanceof CauserInterface) {
            return $activity->causer->getCauserName();
        }
        
        return $activity->causer->getKey() ?? __('activities.common.unknown');
    }

    /**
     * Get the action class from config
     */
    protected function getActionClass(): string
    {
        return config('action-logger.action_class', Action::class);
    }

    /**
     * Get event name for translation
     */
    protected function getEventName(string $event): string
    {
        // First check if this is a standard action from the Action enum
        try {
            $actionClass = $this->getActionClass();
            $action = $actionClass::from($event);
            return $action->getDisplayName();
        } catch (\ValueError $e) {
            // If not a standard action, try direct translation
            $key = "activities.{$event}";
            
            if (Lang::has($key)) {
                return Lang::get($key);
            }
            
            // Last resort, return the event capitalized
            return ucfirst(str_replace('_', ' ', $event));
        }
    }

    /**
     * Process the activities and return the processed data
     */
    abstract protected function processActivities(): array;

    /**
     * Get the activities collection
     */
    protected function getActivities(): Collection
    {
        return $this->activities;
    }

    /**
     * Clear the processed data cache
     */
    public function clearCache(): void
    {
        $this->processedData = null;
        $this->currentBatchUuid = null;
    }

    /**
     * Process a single activity and return essential data
     */
    protected function processActivity(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'event' => $activity->event,
            'description' => $activity->description,
            'properties' => [
                'attributes' => $activity->properties['attributes'] ?? [],
                'old' => $activity->properties['old'] ?? [],
            ],
            'batch_uuid' => $activity->batch_uuid,
            'created_at' => $activity->created_at,
            'updated_at' => $activity->updated_at,
            'original_activity' => $activity, // Store reference to original activity
            'formatted_changes' => $this->formatChanges($activity),
        ];
    }
    
    /**
     * Format changes with translations
     */
    protected function formatChanges(Activity $activity): array
    {
        $changes = [];
        $attributes = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];
        
        foreach ($attributes as $key => $newValue) {
            if (isset($old[$key]) && $old[$key] !== $newValue) {
                $changes[$key] = [
                    'label' => $this->translateAttribute($key, $activity->subject_type),
                    'old' => $this->formatAttributeValue($old[$key]),
                    'new' => $this->formatAttributeValue($newValue),
                ];
            }
        }
        
        return $changes;
    }

    /**
     * Format attribute value for display
     */
    protected function formatAttributeValue($value)
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        
        return $value;
    }
    
    /**
     * Get the common model type from activities
     */
    protected function getCommonModelType(Collection $activities): ?string
    {
        $modelTypes = $activities->pluck('subject_type')->unique();
        return $modelTypes->count() === 1 ? $modelTypes->first() : null;
    }
    
    /**
     * Get the common action from activities
     */
    protected function getCommonAction(Collection $activities): string
    {
        $actions = $activities->pluck('event')->unique();
        return $actions->count() === 1 ? $actions->first() : 'modified';
    }
    
    /**
     * Generate a message for the batch
     */
    protected function generateBatchMessage(
        Activity $primaryActivity,
        ?string $commonModelType,
        string $commonAction,
        array $entities
    ): string {
        // Default implementation - should be overridden in subclasses
        $causerName = $primaryActivity->causer ? $this->getCauserName($primaryActivity->causer) : 'System';
        $action = $this->translateAction($commonAction);
        $modelName = $commonModelType ? $this->translateModelName($commonModelType) : 'entity';
        
        $entityIds = collect($entities)->pluck('id')->implode(', #');
        
        if (!empty($entityIds)) {
            return "{$causerName} {$action} {$modelName} #{$entityIds}";
        }
        
        return "{$causerName} {$action} {$modelName}";
    }
    
    /**
     * Get the name of the causer
     */
    protected function getCauserName($causer): string
    {
        return $causer instanceof CauserInterface
            ? $causer->getCauserName()
            : $causer;
    }
    
    /**
     * Extract entities with their changes from activities
     */
    protected function extractEntitiesWithChanges(Collection $batchActivities): array
    {
        $entitiesWithChanges = [];

        // Group activities by subject type and ID
        $groupedActivities = $batchActivities->groupBy(function ($activity) {
            return $activity->subject_type . '|' . $activity->subject_id . '|' . $activity->event;
        });
    
        // Process each group of activities for the same entity
        foreach ($groupedActivities as $entityKey => $activities) {
            [$subjectType, $subjectId] = explode('|', $entityKey);
    
            // Get the most recent activity for basic entity info
            $primaryActivity = $activities->sortByDesc('created_at')->first();
    
            // Initialize entity data
            $entityData = [
                'type' => $subjectType,
                'id' => $subjectId,
                'event' => $primaryActivity->event,
                'changes' => [],
                'formatted_changes' => []
            ];
    
            // Merge changes from all activities for this entity
            foreach ($activities as $activity) {
                $changes = $activity->properties['attributes'] ?? [];
                $old = $activity->properties['old'] ?? [];
    
                // Merge changes
                foreach ($changes as $key => $value) {
                    $entityData['changes'][$key] = $value;
    
                    // Add to formatted changes
                    $entityData['formatted_changes'][] = [
                        'attribute' => $this->getAttributeLabel($key),
                        'old' => $old[$key] ?? null,
                        'new' => $value
                    ];
                }
            }
    
            // Only add the entity if it has changes or if it's a deleted event
                $entitiesWithChanges[] = $entityData;
        }

        return $entitiesWithChanges;
    }
    
    /**
     * Create a model instance
     */
    protected function createModelInstance(string $modelType): ?object
    {
        try {
            return new $modelType();
        } catch (\Throwable $e) {
            return null;
        }
    }
    
    /**
     * Extract changes between attributes
     */
    protected function extractChanges(object $model, array $newAttributes, array $oldAttributes): array
    {
        $changes = [];
        
        foreach ($newAttributes as $key => $newValue) {
            $oldValue = $oldAttributes[$key] ?? null;
            
            if ($oldValue !== $newValue) {
                // Try to use the model's cast methods if possible
                if (method_exists($model, 'getAttribute')) {
                    $model->setRawAttributes([$key => $newValue]);
                    $newCasted = $model->getAttribute($key);
                    
                    $model->setRawAttributes([$key => $oldValue]);
                    $oldCasted = $model->getAttribute($key);
                } else {
                    $newCasted = $newValue;
                    $oldCasted = $oldValue;
                }
                
                $changes[$key] = [
                    'key' => $key,
                    'old' => $oldCasted,
                    'new' => $newCasted,
                    'raw_old' => $oldValue,
                    'raw_new' => $newValue,
                ];
            }
        }
        
        return $changes;
    }
    
    /**
     * Format changes with translations
     */
    protected function formatChangesWithTranslations(string $modelType, object $model, array $changes): array
    {
        $formatted = [];
        
        foreach ($changes as $key => $change) {
            $formatted[] = [
                'attribute' => $key,
                'label' => $this->translateAttribute($key, $modelType),
                'old_value' => $this->formatValue($model, $key, $change['old']),
                'new_value' => $this->formatValue($model, $key, $change['new']),
                'raw_old' => $change['raw_old'],
                'raw_new' => $change['raw_new'],
            ];
        }
        
        return $formatted;
    }
    
    /**
     * Format a value based on the model's formatting
     */
    protected function formatValue(object $model, string $key, $value)
    {
        // Try to use the model's formatting methods if available
        if (method_exists($model, 'formatValue')) {
            return $model->formatValue($key, $value);
        }
        
        if (is_array($value)) {
            return json_encode($value);
        }
        
        if ($value instanceof \DateTime) {
            return $value->format('Y-m-d H:i:s');
        }
        
        return $value;
    }

    /**
     * Get a translation key for a model type
     */
    protected function translateModelKey(string $modelType): string
    {
        $modelName = class_basename($modelType);
        return Str::snake(Str::camel($modelName));
    }
} 